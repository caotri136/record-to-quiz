<?php
declare(strict_types=1);

namespace RecordToQuiz\Pipeline;

use RecordToQuiz\Http\WhisperClient;
use RecordToQuiz\Llm\Llm;
use RecordToQuiz\Support\Metrics;

final class UploadPipeline
{
    public function __construct(
        private readonly WhisperClient $whisper,
        private readonly Llm $llm,
        private readonly int $retries = 2,
    ) {
    }

    /** @param list<string> $files
     *  @return array<string, mixed>
     */
    public function run(array $files, ?Metrics $metrics = null): array
    {
        $metrics ??= new Metrics();
        $metrics->start(count($files));
        $metrics->setWorkers(1);
        $results = [];
        foreach ($files as $file) {
            $result = $this->processFile($file);
            $this->recordResult($result, $metrics);
            unset($result['_metrics'], $result['_llm_usage']);
            $results[] = $result;
        }
        $metrics->finish();
        return ['results' => $results, 'metrics' => $metrics->json()];
    }

    /** @return array<string, mixed> */
    private function processFile(string $file): array
    {
        $stageMetrics = ['vad' => 0.0, 'stt' => 0.0, 'llm' => 0.0];
        $usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'api_calls' => 0,
            'estimated_cost_usd' => 0.0,
        ];
        $retryCount = 0;
        $transcriptDurationSeconds = null;

        $sttStarted = microtime(true);
        try {
            $transcription = $this->whisper->transcribeDetailed($file);
            $transcript = $transcription['text'];
            $transcriptDurationSeconds = $transcription['duration_seconds'];
            $stageMetrics['vad'] += $transcription['timings_ms']['vad'];
            $stageMetrics['stt'] += $transcription['timings_ms']['stt'];
        } catch (\Throwable $error) {
            $stageMetrics['stt'] += (microtime(true) - $sttStarted) * 1000;
            return [
                'file' => $file,
                'error' => $error->getMessage(),
                '_metrics' => $stageMetrics,
                '_llm_usage' => $usage,
                '_retries' => 0,
            ];
        }

        $lastError = null;
        for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
            $started = microtime(true);
            try {
                $quiz = $this->llm->generateQuiz($transcript, $transcriptDurationSeconds);
                $stageMetrics['llm'] += (microtime(true) - $started) * 1000;
                $this->mergeUsage($usage, $this->llm->lastUsage());
                return [
                    'file' => $file,
                    'transcript' => $transcript,
                    'quiz' => $quiz,
                    '_metrics' => $stageMetrics,
                    '_llm_usage' => $usage,
                    '_retries' => $retryCount,
                ];
            } catch (\Throwable $error) {
                $lastError = $error;
                $stageMetrics['llm'] += (microtime(true) - $started) * 1000;
                $this->mergeUsage($usage, $this->llm->lastUsage());
                if ($attempt < $this->retries) {
                    $retryCount++;
                    usleep(200_000 * (1 << $attempt));
                }
            }
        }

        return [
            'file' => $file,
            'error' => $lastError?->getMessage() ?? 'Unknown failure',
            '_metrics' => $stageMetrics,
            '_llm_usage' => $usage,
            '_retries' => $retryCount,
        ];
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
