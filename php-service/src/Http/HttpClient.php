<?php
declare(strict_types=1);

namespace RecordToQuiz\Http;

use RecordToQuiz\Support\HttpException;

final class HttpClient
{
    /** @param array<string, string> $headers */
    public function request(string $method, string $url, ?string $body = null, array $headers = [], int $timeout = 120): string
    {
        if (!function_exists('curl_init')) {
            throw new HttpException('The PHP cURL extension is required.');
        }
        $handle = curl_init($url);
        if ($handle === false) throw new HttpException('Unable to initialize cURL.');
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_map(static fn(string $k, string $v): string => "$k: $v", array_keys($headers), $headers),
        ]);
        if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false) throw new HttpException("HTTP request failed: $error");
        if ($status < 200 || $status >= 300) throw new HttpException("HTTP $status from $url: " . substr((string)$response, 0, 500));
        return (string)$response;
    }
}
