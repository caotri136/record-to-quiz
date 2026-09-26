<?php
declare(strict_types=1);

namespace RecordToQuiz\Pipeline;

use RecordToQuiz\Http\WhisperClient;
use RecordToQuiz\Llm\Llm;
use RecordToQuiz\Support\Metrics;
use RecordToQuiz\Support\PipelineException;

final class UploadPipeline
{
    public function __construct(private readonly WhisperClient $whisper, private readonly Llm $llm, private readonly int $retries = 2) {}

    /** @return array<string, mixed> */
    public function run(array $files, bool $parallel = false, int $workers = 2, int $queueLimit = 8, ?Metrics $metrics = null): array
    {
        if ($parallel && !function_exists('pcntl_fork')) throw new PipelineException('Parallel mode requires pcntl (Linux/macOS/WSL).');
        $metrics ??= new Metrics(); $metrics->start(count($files));
        $serviceLimit = max(1, (int) (getenv('WHISPER_MAX_CONCURRENCY') ?: 1));
        $workers = max(1, min($workers, $serviceLimit));
        $metrics->setWorkers($parallel ? min($workers, max(1, count($files))) : 1);
        $results = $parallel ? $this->parallel($files, $workers, $queueLimit, $metrics) : $this->sequential($files, $metrics);
        $metrics->finish();
        return ['results' => $results, 'metrics' => $metrics->json()];
    }

    private function one(string $file): array
    {
        $last = null;
        $stageMetrics = ['vad' => 0.0, 'stt' => 0.0, 'llm' => 0.0];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'api_calls' => 0, 'estimated_cost_usd' => 0.0];
        $retryCount = 0;
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $llmAttempted = false;
            try {
                $transcription = $this->whisper->transcribeDetailed($file);
                $transcript = $transcription['text'];
                $stageMetrics['vad'] += $transcription['timings_ms']['vad'];
                $stageMetrics['stt'] += $transcription['timings_ms']['stt'];
                $llmStarted = microtime(true);
                $llmAttempted = true;
                $quiz = $this->llm->generateQuiz($transcript);
                $stageMetrics['llm'] += (microtime(true) - $llmStarted) * 1000;
                $this->mergeUsage($usage, $this->llm->lastUsage());
                return ['file' => $file, 'transcript' => $transcript, 'quiz' => $quiz, '_metrics' => $stageMetrics, '_llm_usage' => $usage, '_retries' => $retryCount];
            } catch (\Throwable $e) {
                $last = $e;
                if ($llmAttempted) {
                    $stageMetrics['llm'] += (microtime(true) - $llmStarted) * 1000;
                    $this->mergeUsage($usage, $this->llm->lastUsage());
                }
                if ($attempt < $this->retries) {
                    $retryCount++;
                    usleep(200000 * (1 << $attempt));
                }
            }
        }
        return ['file' => $file, 'error' => $last?->getMessage() ?? 'Unknown failure', '_metrics' => $stageMetrics, '_llm_usage' => $usage, '_retries' => $retryCount];
    }

    private function sequential(array $files, Metrics $metrics): array
    {
        $out = [];
        foreach ($files as $file) {
            $result = $this->one((string) $file);
            $this->recordResult($result, $metrics);
            $out[] = $this->publicResult($result);
        }
        return $out;
    }

    private function parallel(array $files, int $workers, int $queueLimit, Metrics $metrics): array
    {
        if ($files === []) {
            return [];
        }
        $workers = max(1, min($workers, count($files)));
        $queueLimit = max(1, $queueLimit);
        $children = [];
        $sockets = [];
        $buffers = array_fill(0, $workers, '');
        $queue = array_values($files);
        $results = [];
        for ($i = 0; $i < $workers; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            if ($pair === false) throw new PipelineException('Unable to create IPC socket pair.');
            $pid = pcntl_fork();
            if ($pid === -1) throw new PipelineException('Unable to fork worker.');
            if ($pid === 0) {
                fclose($pair[0]);
                foreach ($sockets as $inheritedSocket) {
                    if (is_resource($inheritedSocket)) fclose($inheritedSocket);
                }
                while (($line = fgets($pair[1])) !== false) {
                    $job = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (($job['stop'] ?? false) === true) break;
                    $result = $this->one((string)$job['file']);
                    $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n";
                    $this->writeMessage($pair[1], $encoded, 'upload worker result');
                }
                fclose($pair[1]); exit(0);
            }
            fclose($pair[1]);
            stream_set_blocking($pair[0], false);
            $children[$i] = $pid;
            $sockets[$i] = $pair[0];
        }
        $inFlight = 0;
        $pending = array_fill(0, $workers, 0);
        while ($queue !== [] || $inFlight > 0) {
            foreach ($sockets as $i => $socket) {
                while ($queue && $pending[$i] < $queueLimit) {
                    $line = json_encode(['file' => array_shift($queue)], JSON_THROW_ON_ERROR) . "\n";
                    $this->writeMessage($socket, $line, "upload worker $i job");
                    $inFlight++;
                    $pending[$i]++;
                }
            }
            if ($inFlight === 0) {
                continue;
            }
            $read = array_values($sockets);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 100_000);
            if ($ready === false) {
                throw new PipelineException('Unable to wait for upload worker results.');
            }
            foreach ($read as $readySocket) {
                $i = array_search($readySocket, $sockets, true);
                if ($i === false) {
                    continue;
                }
                $bytes = fread($readySocket, 65_536);
                if ($bytes === false || ($bytes === '' && feof($readySocket))) {
                    throw new PipelineException("Upload worker $i exited before completing its assigned jobs.");
                }
                $buffers[$i] .= $bytes;
                while (($newline = strpos($buffers[$i], "\n")) !== false) {
                    $line = substr($buffers[$i], 0, $newline);
                    $buffers[$i] = substr($buffers[$i], $newline + 1);
                    $result = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    $this->recordResult($result, $metrics);
                    $results[] = $this->publicResult($result);
                    $inFlight--;
                    $pending[$i]--;
                }
            }
        }
        foreach ($sockets as $socket) {
            $this->writeMessage($socket, "{\"stop\":true}\n", 'upload worker shutdown');
            fclose($socket);
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }
        return $results;
    }

    /** @param resource $socket */
    private function writeMessage($socket, string $message, string $context): void
    {
        $offset = 0;
        $length = strlen($message);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($message, $offset));
            if ($written === false) {
                throw new PipelineException("Unable to write $context to worker IPC.");
            }
            if ($written === 0) {
                $read = [];
                $write = [$socket];
                $except = [];
                $ready = @stream_select($read, $write, $except, 1);
                if ($ready === false) {
                    throw new PipelineException("Unable to wait for worker IPC while sending $context.");
                }
                continue;
            }
            $offset += $written;
        }
    }

    /** @param array<string, mixed> $result */
    private function recordResult(array $result, Metrics $metrics): void
    {
        foreach (($result['_metrics'] ?? []) as $stage => $duration) {
            $metrics->addStageTime((string) $stage, (float) $duration);
        }
        $metrics->addLlmUsage($result['_llm_usage'] ?? []);
        for ($i = 0; $i < (int) ($result['_retries'] ?? 0); $i++) {
            $metrics->retry();
        }
        if (isset($result['error'])) {
            $metrics->failure();
            return;
        }
        $metrics->success();
    }

    /** @param array<string, mixed> $result
     *  @return array<string, mixed>
     */
    private function publicResult(array $result): array
    {
        unset($result['_metrics'], $result['_llm_usage']);
        return $result;
    }

    /** @param array<string, mixed> $total
     *  @param array<string, mixed> $next
     */
    private function mergeUsage(array &$total, array $next): void
    {
        $total['input_tokens'] += $next['input_tokens'];
        $total['output_tokens'] += $next['output_tokens'];
        $total['api_calls'] += $next['api_calls'];
        if ($next['estimated_cost_usd'] === null) {
            $total['estimated_cost_usd'] = null;
        } elseif ($total['estimated_cost_usd'] !== null) {
            $total['estimated_cost_usd'] += $next['estimated_cost_usd'];
        }
    }
}
