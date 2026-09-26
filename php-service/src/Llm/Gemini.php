<?php
declare(strict_types=1);

namespace RecordToQuiz\Llm;

use RecordToQuiz\Support\HttpException;

final class Gemini implements Llm
{
    private string $skill;
    /** @var array{input_tokens: int, output_tokens: int, api_calls: int, estimated_cost_usd: float|null} */
    private array $usage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'api_calls' => 0,
        'estimated_cost_usd' => null,
    ];

    public function __construct(private readonly string $apiKey, string $skillPath, private readonly string $model = 'gemini-1.5-flash')
    {
        $this->skill = file_get_contents($skillPath) ?: throw new \InvalidArgumentException("Cannot read skill file: $skillPath");
    }

    public function generateQuiz(string $transcript): array
    {
        $this->usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'api_calls' => 1,
            'estimated_cost_usd' => null,
        ];
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . rawurlencode($this->apiKey);
        $payload = json_encode([
            'systemInstruction' => ['parts' => [['text' => $this->skill]]],
            'contents' => [['parts' => [['text' => "Transcript:\n" . $transcript]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => self::responseSchema(),
            ],
        ], JSON_THROW_ON_ERROR);
        $ch = curl_init($url);
        if ($ch === false) throw new HttpException('Unable to initialize Gemini request.');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
        $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $error = curl_error($ch); curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) throw new HttpException("Gemini request failed ($status): $error");
        $decoded = json_decode((string)$raw, true, 512, JSON_THROW_ON_ERROR);
        $usageMetadata = $decoded['usageMetadata'] ?? [];
        $inputTokens = (int) ($usageMetadata['promptTokenCount'] ?? 0);
        $outputTokens = (int) ($usageMetadata['candidatesTokenCount'] ?? 0);
        $inputRate = getenv('GEMINI_INPUT_USD_PER_MILLION');
        $outputRate = getenv('GEMINI_OUTPUT_USD_PER_MILLION');
        $estimatedCost = null;
        if ($inputRate !== false && $inputRate !== '' && $outputRate !== false && $outputRate !== '') {
            if (!is_numeric($inputRate) || !is_numeric($outputRate)
                || (float) $inputRate < 0 || (float) $outputRate < 0) {
                throw new \InvalidArgumentException('Gemini token rates must be non-negative numeric values.');
            }
            $estimatedCost = ($inputTokens * (float) $inputRate + $outputTokens * (float) $outputRate) / 1_000_000;
        }
        $this->usage = [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'api_calls' => 1,
            'estimated_cost_usd' => $estimatedCost,
        ];
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text)) throw new HttpException('Gemini response did not contain generated text.');
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text;
        $result = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new HttpException('Gemini returned non-object JSON.');
        $this->validateResult($result);
        return $result;
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }

    /** @return array<string, mixed> */
    private static function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'summary' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'main_points' => [
                            'type' => 'ARRAY',
                            'items' => ['type' => 'STRING'],
                            'minItems' => 1,
                            'maxItems' => 7,
                        ],
                        'short_summary' => ['type' => 'STRING'],
                    ],
                    'required' => ['main_points', 'short_summary'],
                ],
                'segments' => [
                    'type' => 'ARRAY',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'segment_index' => ['type' => 'INTEGER'],
                            'start_time' => ['type' => 'STRING'],
                            'end_time' => ['type' => 'STRING'],
                            'segment_summary' => ['type' => 'STRING'],
                            'quiz' => [
                                'type' => 'ARRAY',
                                'minItems' => 1,
                                'maxItems' => 3,
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'question' => ['type' => 'STRING'],
                                        'options' => [
                                            'type' => 'ARRAY',
                                            'minItems' => 4,
                                            'maxItems' => 4,
                                            'items' => ['type' => 'STRING'],
                                        ],
                                        'correct_answer' => ['type' => 'STRING'],
                                        'explanation' => ['type' => 'STRING'],
                                    ],
                                    'required' => ['question', 'options', 'correct_answer', 'explanation'],
                                ],
                            ],
                        ],
                        'required' => ['segment_index', 'start_time', 'end_time', 'segment_summary', 'quiz'],
                    ],
                ],
            ],
            'required' => ['summary', 'segments'],
        ];
    }

    /** @param array<string, mixed> $result */
    private function validateResult(array $result): void
    {
        if (array_diff(array_keys($result), ['summary', 'segments']) !== []
            || !isset($result['summary'], $result['segments'])
            || !is_array($result['summary'])
            || !is_array($result['segments'])
            || array_diff(array_keys($result['summary']), ['main_points', 'short_summary']) !== []
            || !isset($result['summary']['main_points'], $result['summary']['short_summary'])
            || !is_array($result['summary']['main_points'])
            || !array_is_list($result['summary']['main_points'])
            || count($result['summary']['main_points']) < 1
            || count($result['summary']['main_points']) > 7
            || array_filter($result['summary']['main_points'], static fn(mixed $point): bool => !is_string($point)) !== []
            || !is_string($result['summary']['short_summary'])) {
            throw new HttpException('Gemini JSON does not match the required summary/segments schema.');
        }

        if (!array_is_list($result['segments']) || $result['segments'] === []) {
            throw new HttpException('Gemini returned no ordered segments.');
        }
        foreach ($result['segments'] as $index => $segment) {
            if (!is_array($segment)
                || array_diff(array_keys($segment), ['segment_index', 'start_time', 'end_time', 'segment_summary', 'quiz']) !== []
                || !is_int($segment['segment_index'] ?? null)
                || $segment['segment_index'] !== $index + 1
                || !is_string($segment['start_time'] ?? null)
                || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $segment['start_time'])
                || !is_string($segment['end_time'] ?? null)
                || !preg_match('/^\d{2}:\d{2}:\d{2}$/', $segment['end_time'])
                || !is_string($segment['segment_summary'] ?? null)
                || !is_array($segment['quiz'] ?? null)
                || !array_is_list($segment['quiz'])
                || $segment['quiz'] === []
                || count($segment['quiz']) > 3) {
                throw new HttpException('Gemini returned a segment that does not match the required schema.');
            }
            foreach ($segment['quiz'] as $question) {
                if (!is_array($question)
                    || array_diff(array_keys($question), ['question', 'options', 'correct_answer', 'explanation']) !== []
                    || !is_string($question['question'] ?? null)
                    || !is_array($question['options'] ?? null)
                    || count($question['options']) !== 4
                    || !array_is_list($question['options'])
                    || array_filter($question['options'], static fn(mixed $option): bool => !is_string($option)) !== []
                    || count(array_unique($question['options'])) !== 4
                    || !is_string($question['correct_answer'] ?? null)
                    || !in_array($question['correct_answer'], $question['options'], true)
                    || !is_string($question['explanation'] ?? null)) {
                    throw new HttpException('Gemini returned a quiz question that does not match the required schema.');
                }
            }
        }
    }
}
