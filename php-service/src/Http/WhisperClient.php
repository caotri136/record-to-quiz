<?php
declare(strict_types=1);

namespace RecordToQuiz\Http;

use RecordToQuiz\Support\HttpException;

final class WhisperClient
{
    private const REQUEST_TIMEOUT_SECONDS = 600;

    public function __construct(private readonly HttpClient $http, private readonly string $baseUrl) {}

    public function transcribe(string $audioPath): string
    {
        return $this->transcribeDetailed($audioPath)['text'];
    }

    /** @return array{text: string, duration_seconds: float, timings_ms: array{vad: float, stt: float}} */
    public function transcribeDetailed(string $audioPath): array
    {
        if (!is_file($audioPath)) throw new HttpException("Audio file not found: $audioPath");
        $body = file_get_contents($audioPath);
        if ($body === false) throw new HttpException("Unable to read audio file: $audioPath");
        $data = $this->http->request(
            'POST',
            rtrim($this->baseUrl, '/') . '/transcribe?language=vi',
            $body,
            ['Content-Type' => 'application/octet-stream'],
            self::REQUEST_TIMEOUT_SECONDS,
        );
        $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $text = $decoded['text'] ?? null;
        if (!is_string($text)) throw new HttpException('Whisper response does not contain text.');
        $timings = $decoded['timings_ms'] ?? [];
        $chunks = $decoded['chunks'] ?? [];
        return [
            'text' => $text,
            'duration_seconds' => max(0.0, (float) ($chunks[0]['end'] ?? 0.0)),
            'timings_ms' => [
                'vad' => max(0.0, (float) ($timings['vad'] ?? 0.0)),
                'stt' => max(0.0, (float) ($timings['stt'] ?? 0.0)),
            ],
        ];
    }
}
