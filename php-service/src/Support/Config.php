<?php
declare(strict_types=1);

namespace RecordToQuiz\Support;

final class Config
{
    public static function env(string $name, ?string $default = null): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            if ($default !== null) return $default;
            throw new ConfigurationException("Missing environment variable: $name");
        }
        return $value;
    }
}
