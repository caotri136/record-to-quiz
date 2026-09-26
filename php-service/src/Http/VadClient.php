<?php
declare(strict_types=1);

namespace RecordToQuiz\Http;

use RecordToQuiz\Support\HttpException;

final class VadClient
{
    public function __construct(private readonly HttpClient $http, private readonly string $baseUrl) {}

    /** @return list<array{start: float, end: float}> */
    public function segments(string $audioPath): array
    {
        if (!is_file($audioPath)) throw new HttpException("Audio file not found: $audioPath");
        $body = file_get_contents($audioPath);
        if ($body === false) throw new HttpException("Unable to read audio file: $audioPath");
        $data = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/vad', $body, ['Content-Type' => 'application/octet-stream']);
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $segments = $decoded['segments'] ?? $decoded;
        if (!is_array($segments)) throw new HttpException('VAD response does not contain segments.');
        return array_values(array_map(static fn(array $s): array => ['start' => (float)$s['start'], 'end' => (float)$s['end']], $segments));
    }
}
