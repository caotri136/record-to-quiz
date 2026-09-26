<?php
declare(strict_types=1);

namespace RecordToQuiz\Llm;

interface Llm
{
    /** @return array<string, mixed> */
    public function generateQuiz(string $transcript): array;

    /** @return array{input_tokens: int, output_tokens: int, api_calls: int, estimated_cost_usd: float|null} */
    public function lastUsage(): array;
}
