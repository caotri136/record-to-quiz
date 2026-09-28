<?php
declare(strict_types=1);

$files = array_values(array_filter(array_slice($argv, 1), static fn(string $arg): bool => !str_starts_with($arg, '--')));
if (!$files) {
    fwrite(STDERR, "Usage: benchmark.php AUDIO...\n");
    exit(2);
}
$script = __DIR__ . '/record-to-quiz.php';
$metricsPath = getcwd() . DIRECTORY_SEPARATOR . 'benchmark-sequential.json';
$quoted = implode(' ', array_map('escapeshellarg', $files));
$command = PHP_BINARY . ' ' . escapeshellarg($script) .
    ' --metrics=' . escapeshellarg($metricsPath) . ' ' . $quoted;
passthru($command, $status);
if ($status !== 0) {
    exit($status);
}
$metrics = json_decode((string) file_get_contents($metricsPath), true, 512, JSON_THROW_ON_ERROR);
echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
