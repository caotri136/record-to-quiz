<?php
declare(strict_types=1);

namespace RecordToQuiz\Pipeline;

use RecordToQuiz\Http\VadClient;
use RecordToQuiz\Http\WhisperClient;
use RecordToQuiz\Llm\Llm;
use RecordToQuiz\Support\Metrics;
use RecordToQuiz\Support\PipelineException;

/**
 * Replays an audio file in real time through FFmpeg to exercise the live
 * producer/consumer path. The FFmpeg capture is spooled to disk so VAD HTTP
 * latency cannot cause audio frames to be dropped.
 */
final class PipelineLive
{
    private const SAMPLE_RATE = 16_000;
    private const BYTES_PER_SECOND = self::SAMPLE_RATE * 2;

    public function __construct(
        private readonly VadClient $vad,
        private readonly WhisperClient $whisper,
        private readonly Llm $llm,
        private readonly int $retries = 1,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(
        string $audioFile,
        int $requestedWorkers = 2,
        int $queueLimit = 4,
        ?Metrics $metrics = null,
    ): array {
        if (!function_exists('pcntl_fork')) {
            throw new PipelineException('Live mode requires pcntl_fork (Linux/macOS/WSL2), unavailable in native Windows PHP.');
        }
        if (!function_exists('stream_socket_pair')) {
            throw new PipelineException('Live mode requires Unix socketpair support.');
        }
        if (!is_file($audioFile) || !is_readable($audioFile)) {
            throw new PipelineException("Audio file is missing or unreadable: $audioFile");
        }
        if ($requestedWorkers < 1 || $queueLimit < 1) {
            throw new PipelineException('Worker count and queue limit must be positive integers.');
        }

        $metrics ??= new Metrics();
        $metrics->start(0);
        $serviceConcurrency = max(1, (int) (getenv('WHISPER_MAX_CONCURRENCY') ?: 1));
        $workerCount = min($requestedWorkers, $serviceConcurrency);
        $metrics->setWorkers($workerCount);

        $rawPath = tempnam(sys_get_temp_dir(), 'rtq-live-raw-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'rtq-live-err-');
        if ($rawPath === false || $stderrPath === false) {
            foreach ([$rawPath, $stderrPath] as $temporaryPath) {
                if (is_string($temporaryPath) && is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }
            throw new PipelineException('Unable to create temporary live-capture files.');
        }

        $capture = null;
        $captureHandle = null;
        $workerSockets = [];
        $workerPids = [];
        $workerBuffers = [];
        $workerBusy = [];
        $workerJobs = [];
        $queue = [];
        $activePcm = '';
        $activeStart = 0.0;
        $elapsed = 0.0;
        $sequence = 0;
        $totalJobs = 0;
        $results = [];
        $pendingFiles = [];
        $sourceFinished = false;
        $captureExitCode = null;
        $lastVadCheck = -INF;
        $vadInterval = max(0.5, (float) (getenv('LIVE_VAD_CHECK_SECONDS') ?: 2.0));
        $silenceSeconds = max(0.1, (float) (getenv('LIVE_SILENCE_SECONDS') ?: 0.8));

        try {
            $this->startWorkers($workerCount, $workerSockets, $workerPids, $workerBuffers, $workerBusy, $workerJobs);
            $captureHandle = fopen($rawPath, 'wb');
            if ($captureHandle === false) {
                throw new PipelineException('Unable to open the live audio spool file.');
            }
            $stderrHandle = fopen($stderrPath, 'wb');
            if ($stderrHandle === false) {
                throw new PipelineException('Unable to open FFmpeg diagnostics file.');
            }
            $capture = proc_open([
                'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error', '-re',
                '-i', $audioFile, '-vn', '-ac', '1', '-ar', (string) self::SAMPLE_RATE,
                '-f', 's16le', '-acodec', 'pcm_s16le', 'pipe:1',
            ], [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => $captureHandle,
                2 => $stderrHandle,
            ], $pipes);
            fclose($captureHandle);
            fclose($stderrHandle);
            $captureHandle = null;
            if (!is_resource($capture)) {
                throw new PipelineException('Unable to start FFmpeg. Install FFmpeg and ensure it is on PATH.');
            }

            $rawInput = fopen($rawPath, 'rb');
            if ($rawInput === false) {
                throw new PipelineException('Unable to read the live audio spool.');
            }
            $readOffset = 0;
            $frameBytes = self::BYTES_PER_SECOND;

            while (!$sourceFinished) {
                clearstatcache(true, $rawPath);
                $available = max(0, (int) filesize($rawPath) - $readOffset);
                $captureStatus = proc_get_status($capture);
                if (!$captureStatus['running'] && $captureStatus['exitcode'] >= 0) {
                    $captureExitCode = $captureStatus['exitcode'];
                }
                $sourceFinished = !$captureStatus['running'] && $available === 0;

                if ($available > 0 && ($available >= $frameBytes || !$captureStatus['running'])) {
                    $readBytes = min($available, $frameBytes);
                    $frame = fread($rawInput, $readBytes);
                    if ($frame === false) {
                        throw new PipelineException('Unable to read captured audio data.');
                    }
                    if ($frame !== '') {
                        $readOffset += strlen($frame);
                        $activePcm .= $frame;
                        $elapsed = $readOffset / self::BYTES_PER_SECOND;
                    }
                } else {
                    $this->pollWorkers($workerSockets, $workerPids, $workerBuffers, $workerBusy, $workerJobs, $queue, $pendingFiles, $results, $metrics);
                    usleep(50_000);
                    continue;
                }

                $duration = strlen($activePcm) / self::BYTES_PER_SECOND;
                if ($duration >= 60.0 && $duration - $lastVadCheck >= $vadInterval) {
                    $lastVadCheck = $duration;
                    $intervals = $this->detectSpeech($activePcm, $pendingFiles, $metrics);
                    $latestSpeechEnd = $intervals === [] ? null : (float) $intervals[array_key_last($intervals)]['end'];
                    if ($latestSpeechEnd !== null
                        && $latestSpeechEnd >= 60.0
                        && $duration - $latestSpeechEnd >= $silenceSeconds) {
                        $this->cutAndQueue(
                            $activePcm, $latestSpeechEnd, $activeStart, $sequence, $queue,
                            $totalJobs, $workerSockets, $workerPids, $workerBuffers, $workerBusy,
                            $workerJobs, $pendingFiles, $results, $metrics, $queueLimit
                        );
                        $activeStart += $latestSpeechEnd;
                        $lastVadCheck = -INF;
                    }
                }

                $duration = strlen($activePcm) / self::BYTES_PER_SECOND;
                if ($duration >= 75.0) {
                    $this->cutAndQueue(
                        $activePcm, 75.0, $activeStart, $sequence, $queue,
                        $totalJobs, $workerSockets, $workerPids, $workerBuffers, $workerBusy,
                        $workerJobs, $pendingFiles, $results, $metrics, $queueLimit
                    );
                    $activeStart += 75.0;
                    $lastVadCheck = -INF;
                }

                $this->dispatchAvailable($queue, $workerSockets, $workerBusy, $workerJobs);
                $this->pollWorkers($workerSockets, $workerPids, $workerBuffers, $workerBusy, $workerJobs, $queue, $pendingFiles, $results, $metrics);
            }
            fclose($rawInput);
            $rawInput = null;
            if ($captureExitCode !== 0) {
                $diagnostics = trim((string) file_get_contents($stderrPath));
                throw new PipelineException('FFmpeg failed to decode the live input' . ($diagnostics !== '' ? ': ' . $diagnostics : '.'));
            }

            if ($activePcm !== '') {
                $intervals = $this->detectSpeech($activePcm, $pendingFiles, $metrics);
                if ($intervals !== []) {
                    $this->queuePcm($activePcm, $activeStart, $elapsed, $sequence, $queue, $totalJobs, $pendingFiles);
                }
            }
            $metrics->setJobs($totalJobs);
            $this->dispatchAvailable($queue, $workerSockets, $workerBusy, $workerJobs);
            while ($queue !== [] || in_array(true, $workerBusy, true)) {
                $this->dispatchAvailable($queue, $workerSockets, $workerBusy, $workerJobs);
                $this->pollWorkers($workerSockets, $workerPids, $workerBuffers, $workerBusy, $workerJobs, $queue, $pendingFiles, $results, $metrics, 1.0);
            }

            usort($results, static fn(array $left, array $right): int => $left['sequence_number'] <=> $right['sequence_number']);
            foreach ($results as $result) {
                if (isset($result['error'])) {
                    throw new PipelineException(
                        'Transcription failed for chunk ' . $result['chunk_id'] . ': ' . $result['error']
                    );
                }
            }
            $transcriptParts = [];
            foreach ($results as $result) {
                $transcriptParts[] = sprintf(
                    '[%s - %s] %s',
                    self::formatTime((float) $result['start']),
                    self::formatTime((float) $result['end']),
                    $result['text'],
                );
            }
            $transcript = implode("\n", $transcriptParts);
            if ($transcript === '') {
                throw new PipelineException('VAD found no speech in the live recording.');
            }
            $llmResult = $this->generateWithRetry($transcript, $metrics);
            $metrics->finish();
            return [
                'transcript' => $transcript,
                'quiz' => $llmResult,
                'chunks' => $results,
                'metrics' => $metrics->json(),
            ];
        } catch (\Throwable $error) {
            $metrics->finish();
            throw $error;
        } finally {
            if (is_resource($captureHandle)) {
                fclose($captureHandle);
            }
            if (is_resource($capture)) {
                $status = proc_get_status($capture);
                if ($status['running']) {
                    proc_terminate($capture);
                }
                proc_close($capture);
            }
            foreach ($workerSockets as $socket) {
                if (is_resource($socket)) {
                    @fwrite($socket, "{\"stop\":true}\n");
                    fclose($socket);
                }
            }
            foreach ($workerPids as $pid) {
                pcntl_waitpid($pid, $status);
            }
            foreach (array_unique(array_merge([$rawPath, $stderrPath], $pendingFiles)) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @param array<int, resource> $sockets
     * @param array<int, int> $pids
     * @param array<int, string> $buffers
     * @param array<int, bool> $busy
     * @param array<int, array<string, mixed>|null> $jobs
     */
    private function startWorkers(int $count, array &$sockets, array &$pids, array &$buffers, array &$busy, array &$jobs): void
    {
        for ($worker = 0; $worker < $count; $worker++) {
            $buffers[$worker] = '';
            $busy[$worker] = false;
            $jobs[$worker] = null;
            $this->spawnWorker($worker, $sockets, $pids);
        }
    }

    /** @param array<int, resource> $sockets
     *  @param array<int, int> $pids
     */
    private function spawnWorker(int $worker, array &$sockets, array &$pids): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new PipelineException('Unable to create worker IPC socket pair.');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($pair[0]);
            fclose($pair[1]);
            throw new PipelineException('Unable to fork a live transcription worker.');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            foreach ($sockets as $inheritedSocket) {
                if (is_resource($inheritedSocket)) {
                    fclose($inheritedSocket);
                }
            }
            $this->workerLoop($pair[1]);
        }
        fclose($pair[1]);
        stream_set_blocking($pair[0], false);
        $sockets[$worker] = $pair[0];
        $pids[$worker] = $pid;
    }

    /** @param resource $socket */
    private function workerLoop($socket): never
    {
        while (($line = fgets($socket)) !== false) {
            try {
                $job = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (($job['stop'] ?? false) === true) {
                    break;
                }
                $started = microtime(true);
                $lastError = null;
                $text = '';
                $stageTimings = ['vad' => 0.0, 'stt' => 0.0];
                try {
                    $transcription = $this->whisper->transcribeDetailed((string) $job['path']);
                    $text = $transcription['text'];
                    $stageTimings = $transcription['timings_ms'];
                } catch (\Throwable $error) {
                    $lastError = $error->getMessage();
                }
                $message = [
                    'chunk_id' => $job['chunk_id'],
                    'sequence_number' => $job['sequence_number'],
                    'start' => $job['start'],
                    'end' => $job['end'],
                    'text' => $text,
                    'error' => $lastError,
                    'timings_ms' => $stageTimings,
                    'worker_duration_ms' => (microtime(true) - $started) * 1000,
                ];
            } catch (\Throwable $error) {
                $message = ['error' => $error->getMessage(), 'fatal' => true];
            }
            $encoded = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            $offset = 0;
            while ($offset < strlen($encoded)) {
                $written = fwrite($socket, substr($encoded, $offset));
                if ($written === false || $written === 0) {
                    fclose($socket);
                    exit(1);
                }
                $offset += $written;
            }
        }
        fclose($socket);
        exit(0);
    }

    /**
     * @param array<int, resource> $sockets
     * @param array<int, int> $pids
     * @param array<int, string> $buffers
     * @param array<int, bool> $busy
     * @param array<int, array<string, mixed>|null> $jobs
     * @param list<array<string, mixed>> $queue
     * @param list<string> $pendingFiles
     * @param list<array<string, mixed>> $results
     */
    private function pollWorkers(
        array &$sockets,
        array &$pids,
        array &$buffers,
        array &$busy,
        array &$jobs,
        array &$queue,
        array &$pendingFiles,
        array &$results,
        Metrics $metrics,
        float $timeoutSeconds = 0.0,
    ): void {
        $read = array_values($sockets);
        $write = null;
        $except = null;
        if ($read !== []) {
            $seconds = (int) floor($timeoutSeconds);
            $microseconds = (int) (($timeoutSeconds - $seconds) * 1_000_000);
            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
            if ($ready === false) {
                throw new PipelineException('Failed while waiting for transcription worker responses.');
            }
            foreach ($read as $readySocket) {
                $worker = array_search($readySocket, $sockets, true);
                if ($worker === false) {
                    continue;
                }
                $bytes = fread($readySocket, 65_536);
                if ($bytes === false || ($bytes === '' && feof($readySocket))) {
                    $this->recoverDeadWorker((int) $worker, $sockets, $pids, $buffers, $busy, $jobs, $queue, $pendingFiles, $results, $metrics);
                    continue;
                }
                $buffers[$worker] .= $bytes;
                while (($newline = strpos($buffers[$worker], "\n")) !== false) {
                    $line = substr($buffers[$worker], 0, $newline);
                    $buffers[$worker] = substr($buffers[$worker], $newline + 1);
                    $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $job = $jobs[$worker];
                    if ($job !== null) {
                        $this->recordWorkerResult($message, $job, $queue, $pendingFiles, $results, $metrics);
                    }
                    $busy[$worker] = false;
                    $jobs[$worker] = null;
                }
            }
        } elseif ($timeoutSeconds > 0) {
            usleep((int) ($timeoutSeconds * 1_000_000));
        }
        $this->dispatchAvailable($queue, $sockets, $busy, $jobs);
    }

    /**
     * @param array<int, resource> $sockets
     * @param array<int, bool> $busy
     * @param array<int, array<string, mixed>|null> $jobs
     * @param list<array<string, mixed>> $queue
     */
    private function dispatchAvailable(array &$queue, array &$sockets, array &$busy, array &$jobs): void
    {
        foreach ($sockets as $worker => $socket) {
            if ($busy[$worker] || $queue === []) {
                continue;
            }
            $job = array_shift($queue);
            $line = json_encode($job, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
            $written = fwrite($socket, $line);
            if ($written === false || $written !== strlen($line)) {
                throw new PipelineException("Unable to send chunk {$job['chunk_id']} to worker $worker.");
            }
            $jobs[$worker] = $job;
            $busy[$worker] = true;
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $job
     * @param list<array<string, mixed>> $queue
     * @param list<string> $pendingFiles
     * @param list<array<string, mixed>> $results
     */
    private function recordWorkerResult(array $message, array $job, array &$queue, array &$pendingFiles, array &$results, Metrics $metrics): void
    {
        if (!empty($message['error'])) {
            $this->retryOrFail($job, (string) $message['error'], $queue, $pendingFiles, $results, $metrics);
            return;
        }
        if (isset($job['path']) && is_file((string) $job['path'])) {
            unlink((string) $job['path']);
            $pendingFiles = array_values(array_filter($pendingFiles, static fn(string $path): bool => $path !== $job['path']));
        }
        foreach (($message['timings_ms'] ?? []) as $stage => $duration) {
            $metrics->addStageTime((string) $stage, (float) $duration);
        }
        $metrics->success();
        $results[] = [
            'chunk_id' => (string) $job['chunk_id'],
            'sequence_number' => (int) $job['sequence_number'],
            'start' => (float) $job['start'],
            'end' => (float) $job['end'],
            'text' => (string) ($message['text'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @param list<array<string, mixed>> $queue
     * @param list<string> $pendingFiles
     * @param list<array<string, mixed>> $results
     */
    private function retryOrFail(array $job, string $error, array &$queue, array &$pendingFiles, array &$results, Metrics $metrics): void
    {
        $job['attempt'] = (int) ($job['attempt'] ?? 0) + 1;
        if ($job['attempt'] <= $this->retries) {
            $queue[] = $job;
            $metrics->retry();
            return;
        }
        $metrics->failure();
        $results[] = [
            'chunk_id' => (string) $job['chunk_id'],
            'sequence_number' => (int) $job['sequence_number'],
            'start' => (float) $job['start'],
            'end' => (float) $job['end'],
            'text' => '',
            'error' => $error,
        ];
        if (is_file((string) $job['path'])) {
            unlink((string) $job['path']);
            $pendingFiles = array_values(array_filter($pendingFiles, static fn(string $path): bool => $path !== $job['path']));
        }
    }

    /**
     * @param array<int, resource> $sockets
     * @param array<int, string> $buffers
     * @param array<int, bool> $busy
     * @param array<int, array<string, mixed>|null> $jobs
     * @param list<array<string, mixed>> $queue
     * @param list<string> $pendingFiles
     */
    private function recoverDeadWorker(
        int $worker,
        array &$sockets,
        array &$pids,
        array &$buffers,
        array &$busy,
        array &$jobs,
        array &$queue,
        array &$pendingFiles,
        array &$results,
        Metrics $metrics,
    ): void {
        $job = $jobs[$worker] ?? null;
        if ($job !== null) {
            $this->retryOrFail($job, 'Worker process exited unexpectedly.', $queue, $pendingFiles, $results, $metrics);
        }
        if (is_resource($sockets[$worker] ?? null)) {
            fclose($sockets[$worker]);
        }
        if (isset($pids[$worker])) {
            pcntl_waitpid($pids[$worker], $status, WNOHANG);
        }
        unset($sockets[$worker]);
        $buffers[$worker] = '';
        $busy[$worker] = false;
        $jobs[$worker] = null;
        $this->spawnWorker($worker, $sockets, $pids);
    }

    /** @param list<string> $pendingFiles
     *  @return list<array{start: float, end: float}>
     */
    private function detectSpeech(string $pcm, array &$pendingFiles, Metrics $metrics): array
    {
        $path = $this->writeWave($pcm, $pendingFiles);
        $started = microtime(true);
        try {
            $intervals = $this->vad->segments($path);
            $metrics->addStageTime('vad', (microtime(true) - $started) * 1000);
            return $intervals;
        } catch (\Throwable $error) {
            $metrics->addStageTime('vad', (microtime(true) - $started) * 1000);
            throw $error;
        } finally {
            if (is_file($path)) {
                unlink($path);
                $pendingFiles = array_values(array_filter($pendingFiles, static fn(string $item): bool => $item !== $path));
            }
        }
    }

    /**
     * @param list<string> $pendingFiles
     * @param list<array<string, mixed>> $queue
     */
    private function cutAndQueue(
        string &$pcm,
        float $cutSeconds,
        float $startSeconds,
        int &$sequence,
        array &$queue,
        int &$totalJobs,
        array &$sockets,
        array &$pids,
        array &$buffers,
        array &$busy,
        array &$jobs,
        array &$pendingFiles,
        array &$results,
        Metrics $metrics,
        int $queueLimit,
    ): void {
        $cutBytes = min(strlen($pcm), (int) floor($cutSeconds * self::BYTES_PER_SECOND / 2) * 2);
        if ($cutBytes <= 0) {
            return;
        }
        $chunkPcm = substr($pcm, 0, $cutBytes);
        $pcm = substr($pcm, $cutBytes);
        $endSeconds = $startSeconds + ($cutBytes / self::BYTES_PER_SECOND);
        $speech = $this->detectSpeech($chunkPcm, $pendingFiles, $metrics);
        if ($speech !== []) {
            $this->queuePcm($chunkPcm, $startSeconds, $endSeconds, $sequence, $queue, $totalJobs, $pendingFiles);
        }
        $this->dispatchAvailable($queue, $sockets, $busy, $jobs);
        while (count($queue) >= $queueLimit) {
            $this->pollWorkers($sockets, $pids, $buffers, $busy, $jobs, $queue, $pendingFiles, $results, $metrics, 0.25);
        }
    }

    /**
     * @param list<array<string, mixed>> $queue
     * @param list<string> $pendingFiles
     */
    private function queuePcm(string $pcm, float $start, float $end, int &$sequence, array &$queue, int &$totalJobs, array &$pendingFiles): void
    {
        $path = $this->writeWave($pcm, $pendingFiles);
        $sequence++;
        $totalJobs++;
        $queue[] = [
            'chunk_id' => sprintf('chunk-%06d', $sequence),
            'sequence_number' => $sequence,
            'start' => $start,
            'end' => $end,
            'path' => $path,
            'attempt' => 0,
        ];
    }

    /** @param list<string> $pendingFiles */
    private function writeWave(string $pcm, array &$pendingFiles): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rtq-live-chunk-');
        if ($path === false) {
            throw new PipelineException('Unable to create a temporary audio chunk.');
        }
        $length = strlen($pcm);
        $wave = 'RIFF' . pack('V', 36 + $length) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, self::SAMPLE_RATE, self::BYTES_PER_SECOND, 2, 16) . 'data' . pack('V', $length) . $pcm;
        if (file_put_contents($path, $wave) === false) {
            unlink($path);
            throw new PipelineException('Unable to write a temporary audio chunk.');
        }
        $pendingFiles[] = $path;
        return $path;
    }

    private function generateWithRetry(string $transcript, Metrics $metrics): array
    {
        $lastError = null;
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $started = microtime(true);
            try {
                $result = $this->llm->generateQuiz($transcript);
                $metrics->addStageTime('llm', (microtime(true) - $started) * 1000);
                $metrics->addLlmUsage($this->llm->lastUsage());
                return $result;
            } catch (\Throwable $error) {
                $metrics->addStageTime('llm', (microtime(true) - $started) * 1000);
                $metrics->addLlmUsage($this->llm->lastUsage());
                $lastError = $error;
                if ($attempt < $this->retries) {
                    $metrics->retry();
                    usleep(200_000 * (1 << $attempt));
                }
            }
        }
        throw new PipelineException('LLM generation failed after retries: ' . ($lastError?->getMessage() ?? 'unknown error'), previous: $lastError);
    }

    private static function formatTime(float $seconds): string
    {
        $total = max(0, (int) floor($seconds));
        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}
