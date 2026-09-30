<?php
declare(strict_types=1);

namespace RecordToQuiz\Pipeline;

use RecordToQuiz\Http\VadClient;
use RecordToQuiz\Http\WhisperClient;
use RecordToQuiz\Llm\Llm;
use RecordToQuiz\Support\Metrics;
use RecordToQuiz\Support\PipelineException;

/**
 * Replays an audio file in real time and transcribes finalized chunks in order.
 * FFmpeg continues writing to the spool while the single PHP process handles STT.
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
    public function run(string $audioFile, ?Metrics $metrics = null): array
    {
        if (!is_file($audioFile) || !is_readable($audioFile)) {
            throw new PipelineException("Audio file is missing or unreadable: $audioFile");
        }

        $metrics ??= new Metrics();
        $metrics->start(0);
        $metrics->setWorkers(1);

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
        $rawInput = null;
        $pendingFiles = [];
        $activePcm = '';
        $activeStart = 0.0;
        $elapsed = 0.0;
        $sequence = 0;
        $totalJobs = 0;
        $results = [];
        $sourceFinished = false;
        $captureExitCode = null;
        $lastVadCheck = -INF;
        $vadInterval = max(0.5, (float) (getenv('LIVE_VAD_CHECK_SECONDS') ?: 2.0));
        $silenceSeconds = max(0.1, (float) (getenv('LIVE_SILENCE_SECONDS') ?: 0.8));

        try {
            $captureHandle = fopen($rawPath, 'wb');
            if ($captureHandle === false) {
                throw new PipelineException('Unable to open the live audio spool.');
            }
            $stderrHandle = fopen($stderrPath, 'wb');
            if ($stderrHandle === false) {
                throw new PipelineException('Unable to open FFmpeg diagnostics.');
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
                    $frame = fread($rawInput, min($available, $frameBytes));
                    if ($frame === false) {
                        throw new PipelineException('Unable to read captured audio data.');
                    }
                    if ($frame !== '') {
                        $readOffset += strlen($frame);
                        $activePcm .= $frame;
                        $elapsed = $readOffset / self::BYTES_PER_SECOND;
                    }
                } else {
                    usleep(50_000);
                    continue;
                }

                $duration = strlen($activePcm) / self::BYTES_PER_SECOND;
                if ($duration >= 60.0 && $duration - $lastVadCheck >= $vadInterval) {
                    $lastVadCheck = $duration;
                    $intervals = $this->detectSpeech($activePcm, $pendingFiles, $metrics);
                    $latestSpeechEnd = $intervals === []
                        ? null
                        : (float) $intervals[array_key_last($intervals)]['end'];
                    if ($latestSpeechEnd !== null
                        && $latestSpeechEnd >= 60.0
                        && $duration - $latestSpeechEnd >= $silenceSeconds) {
                        $this->cutAndTranscribe(
                            $activePcm,
                            $latestSpeechEnd,
                            $activeStart,
                            $sequence,
                            $totalJobs,
                            $pendingFiles,
                            $results,
                            $metrics,
                            true,
                        );
                        $activeStart += $latestSpeechEnd;
                        $lastVadCheck = -INF;
                    }
                }

                $duration = strlen($activePcm) / self::BYTES_PER_SECOND;
                if ($duration >= 75.0) {
                    $this->cutAndTranscribe(
                        $activePcm,
                        75.0,
                        $activeStart,
                        $sequence,
                        $totalJobs,
                        $pendingFiles,
                        $results,
                        $metrics,
                    );
                    $activeStart += 75.0;
                    $lastVadCheck = -INF;
                }
            }

            fclose($rawInput);
            $rawInput = null;
            $closeCode = proc_close($capture);
            $capture = null;
            if ($captureExitCode === null) {
                $captureExitCode = $closeCode;
            }
            if ($captureExitCode !== 0) {
                $diagnostics = trim((string) file_get_contents($stderrPath));
                throw new PipelineException(
                    'FFmpeg failed to decode the live input' .
                    ($diagnostics !== '' ? ': ' . $diagnostics : '.')
                );
            }

            if ($activePcm !== '') {
                $intervals = $this->detectSpeech($activePcm, $pendingFiles, $metrics);
                if ($intervals !== []) {
                    $this->transcribeChunk(
                        $activePcm,
                        $activeStart,
                        $elapsed,
                        ++$sequence,
                        $totalJobs,
                        $pendingFiles,
                        $results,
                        $metrics,
                    );
                }
            }

            $metrics->setJobs($totalJobs);
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

            $quiz = $this->generateWithRetry($transcript, $metrics);
            $metrics->finish();
            return [
                'transcript' => $transcript,
                'quiz' => $quiz,
                'chunks' => $results,
                'metrics' => $metrics->json(),
            ];
        } catch (\Throwable $error) {
            $metrics->finish();
            throw $error;
        } finally {
            if (is_resource($rawInput)) {
                fclose($rawInput);
            }
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
            foreach (array_unique(array_merge([$rawPath, $stderrPath], $pendingFiles)) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    /**
     * @param list<string> $pendingFiles
     * @return list<array{start: float, end: float}>
     */
    private function detectSpeech(string $pcm, array &$pendingFiles, Metrics $metrics): array
    {
        $path = $this->writeWave($pcm, $pendingFiles);
        $started = microtime(true);
        try {
            return $this->vad->segments($path);
        } finally {
            $metrics->addStageTime('vad', (microtime(true) - $started) * 1000);
            $this->removeTemporaryFile($path, $pendingFiles);
        }
    }

    /**
     * @param list<string> $pendingFiles
     * @param list<array<string, mixed>> $results
     */
    private function cutAndTranscribe(
        string &$pcm,
        float $cutSeconds,
        float $startSeconds,
        int &$sequence,
        int &$totalJobs,
        array &$pendingFiles,
        array &$results,
        Metrics $metrics,
        bool $speechAlreadyDetected = false,
    ): void {
        $cutBytes = min(strlen($pcm), (int) floor($cutSeconds * self::BYTES_PER_SECOND));
        $cutBytes -= $cutBytes % 2;
        if ($cutBytes <= 0) {
            return;
        }
        $chunkPcm = substr($pcm, 0, $cutBytes);
        $pcm = substr($pcm, $cutBytes);
        $endSeconds = $startSeconds + ($cutBytes / self::BYTES_PER_SECOND);
        if ($speechAlreadyDetected || $this->detectSpeech($chunkPcm, $pendingFiles, $metrics) !== []) {
            $this->transcribeChunk(
                $chunkPcm,
                $startSeconds,
                $endSeconds,
                ++$sequence,
                $totalJobs,
                $pendingFiles,
                $results,
                $metrics,
            );
        }
    }

    /**
     * @param list<string> $pendingFiles
     * @param list<array<string, mixed>> $results
     */
    private function transcribeChunk(
        string $pcm,
        float $start,
        float $end,
        int $sequence,
        int &$totalJobs,
        array &$pendingFiles,
        array &$results,
        Metrics $metrics,
    ): void {
        $path = $this->writeWave($pcm, $pendingFiles);
        $totalJobs++;
        $lastError = null;
        try {
            for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
                $started = microtime(true);
                try {
                    $transcription = $this->whisper->transcribeDetailed($path);
                    $metrics->addStageTime('stt', (microtime(true) - $started) * 1000);
                    $metrics->success();
                    $results[] = [
                        'chunk_id' => sprintf('chunk-%06d', $sequence),
                        'sequence_number' => $sequence,
                        'start' => $start,
                        'end' => $end,
                        'text' => $transcription['text'],
                    ];
                    fwrite(STDERR, sprintf(
                        "[%s - %s] chunk-%06d: %s\n",
                        self::formatTime($start),
                        self::formatTime($end),
                        $sequence,
                        $transcription['text'],
                    ));
                    fflush(STDERR);
                    return;
                } catch (\Throwable $error) {
                    $metrics->addStageTime('stt', (microtime(true) - $started) * 1000);
                    $lastError = $error;
                    if ($attempt < $this->retries) {
                        $metrics->retry();
                        usleep(200_000 * (1 << $attempt));
                    }
                }
            }
            $metrics->failure();
            throw new PipelineException(
                'Transcription failed for chunk ' . sprintf('chunk-%06d', $sequence) .
                ': ' . ($lastError?->getMessage() ?? 'unknown error'),
                0,
                $lastError,
            );
        } finally {
            $this->removeTemporaryFile($path, $pendingFiles);
        }
    }

    /** @param list<string> $pendingFiles */
    private function writeWave(string $pcm, array &$pendingFiles): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rtq-live-chunk-');
        if ($path === false) {
            throw new PipelineException('Unable to create a temporary audio chunk.');
        }
        $length = strlen($pcm);
        $wave = 'RIFF' . pack('V', 36 + $length) . 'WAVEfmt ' .
            pack('VvvVVvv', 16, 1, 1, self::SAMPLE_RATE, self::BYTES_PER_SECOND, 2, 16) .
            'data' . pack('V', $length) . $pcm;
        if (file_put_contents($path, $wave) === false) {
            unlink($path);
            throw new PipelineException('Unable to write a temporary audio chunk.');
        }
        $pendingFiles[] = $path;
        return $path;
    }

    /** @param list<string> $pendingFiles */
    private function removeTemporaryFile(string $path, array &$pendingFiles): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new PipelineException("Unable to remove temporary audio file: $path");
        }
        $pendingFiles = array_values(array_filter(
            $pendingFiles,
            static fn(string $pendingPath): bool => $pendingPath !== $path,
        ));
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
        throw new PipelineException(
            'LLM generation failed after retries: ' . ($lastError?->getMessage() ?? 'unknown error'),
            0,
            $lastError,
        );
    }

    private static function formatTime(float $seconds): string
    {
        $total = max(0, (int) floor($seconds));
        return sprintf('%02d:%02d:%02d', intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
    }
}
