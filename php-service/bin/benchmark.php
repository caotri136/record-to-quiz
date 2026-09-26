<?php
declare(strict_types=1);

$files = array_values(array_filter(array_slice($argv, 1), static fn(string $arg): bool => !str_starts_with($arg, '--')));
if (!$files) {
    fwrite(STDERR, "Usage: benchmark.php AUDIO...\n");
    exit(2);
}
$script = __DIR__ . '/record-to-quiz.php';
$seqMetrics = getcwd() . DIRECTORY_SEPARATOR . 'benchmark-sequential.json';
$parMetrics = getcwd() . DIRECTORY_SEPARATOR . 'benchmark-parallel.json';
$quoted = implode(' ', array_map('escapeshellarg', $files));
$commands = [
    PHP_BINARY . ' ' . escapeshellarg($script) . ' --metrics=' . escapeshellarg($seqMetrics) . ' ' . $quoted,
    PHP_BINARY . ' ' . escapeshellarg($script) . ' --parallel --metrics=' . escapeshellarg($parMetrics) . ' ' . $quoted,
];
foreach ($commands as $command) {
    passthru($command, $status);
    if ($status !== 0) exit($status);
}
$sequential = json_decode((string)file_get_contents($seqMetrics), true, 512, JSON_THROW_ON_ERROR);
$parallel = json_decode((string)file_get_contents($parMetrics), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(['sequential' => $sequential, 'parallel' => $parallel], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
