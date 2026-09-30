<?php
declare(strict_types=1);

namespace RecordToQuiz\Support;

final class Metrics
{
    /** @var array<string, mixed> */
    private array $data = [
        'started_at' => null,
        'finished_at' => null,
        'started_at_epoch' => null,
        'finished_at_epoch' => null,
        'jobs' => 0,
        'successes' => 0,
        'failures' => 0,
        'retries' => 0,
        'workers' => 1,
        'duration_ms' => 0.0,
        'processing_time_seconds' => ['stt' => 0.0, 'gemini' => 0.0, 'total' => 0.0],
        'stage_timings_ms' => ['vad' => 0.0, 'stt' => 0.0, 'llm' => 0.0],
        'llm' => [
            'api_calls' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'estimated_cost_usd' => 0.0,
        ],
    ];

    private float $startedAtEpoch = 0.0;

    public function start(int $jobs): void
    {
        $this->startedAtEpoch = microtime(true);
        $this->data['started_at'] = self::formatTimestamp($this->startedAtEpoch);
        $this->data['started_at_epoch'] = $this->startedAtEpoch;
        $this->data['jobs'] = $jobs;
    }

    public function finish(): void
    {
        $finishedAtEpoch = microtime(true);
        $this->data['finished_at'] = self::formatTimestamp($finishedAtEpoch);
        $this->data['finished_at_epoch'] = $finishedAtEpoch;
        $this->data['duration_ms'] = ($finishedAtEpoch - $this->startedAtEpoch) * 1000;
        $this->data['processing_time_seconds'] = [
            'stt' => round($this->data['stage_timings_ms']['stt'] / 1000, 3),
            'gemini' => round($this->data['stage_timings_ms']['llm'] / 1000, 3),
            'total' => round($this->data['duration_ms'] / 1000, 3),
        ];
    }

    public function addStageTime(string $stage, float $durationMs): void
    {
        if (!array_key_exists($stage, $this->data['stage_timings_ms'])) {
            throw new \InvalidArgumentException("Unknown metrics stage: $stage");
        }
        $this->data['stage_timings_ms'][$stage] += max(0.0, $durationMs);
    }

    /** @param array{input_tokens?: int, output_tokens?: int, api_calls?: int, estimated_cost_usd?: float|null} $usage */
    public function addLlmUsage(array $usage): void
    {
        $this->data['llm']['api_calls'] += max(0, (int) ($usage['api_calls'] ?? 0));
        $this->data['llm']['input_tokens'] += max(0, (int) ($usage['input_tokens'] ?? 0));
        $this->data['llm']['output_tokens'] += max(0, (int) ($usage['output_tokens'] ?? 0));

        $cost = $usage['estimated_cost_usd'] ?? null;
        if ($cost === null && (int) ($usage['api_calls'] ?? 0) > 0) {
            $this->data['llm']['estimated_cost_usd'] = null;
        } elseif ($this->data['llm']['estimated_cost_usd'] !== null) {
            $this->data['llm']['estimated_cost_usd'] += max(0.0, (float) $cost);
        }
    }

    public function success(): void
    {
        $this->data['successes']++;
    }

    public function failure(): void
    {
        $this->data['failures']++;
    }

    public function retry(): void
    {
        $this->data['retries']++;
    }

    public function setWorkers(int $workers): void
    {
        $this->data['workers'] = max(1, $workers);
    }

    public function setJobs(int $jobs): void
    {
        $this->data['jobs'] = max(0, $jobs);
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return $this->data;
    }

    public function write(string $path): void
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Cannot write metrics: $path");
        }
    }

    private static function formatTimestamp(float $timestamp): string
    {
        $date = \DateTimeImmutable::createFromFormat('U.u', number_format($timestamp, 6, '.', ''));
        if ($date === false) {
            throw new \RuntimeException('Unable to format metrics timestamp.');
        }
        return $date
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d\TH:i:s.uP');
    }
}
