<?php
declare(strict_types=1);

use RecordToQuiz\Http\HttpClient;
use RecordToQuiz\Http\VadClient;
use RecordToQuiz\Http\WhisperClient;
use RecordToQuiz\Llm\Gemini;
use RecordToQuiz\Pipeline\PipelineLive;
use RecordToQuiz\Pipeline\UploadPipeline;
use RecordToQuiz\Support\Config;
use RecordToQuiz\Support\Metrics;

require dirname(__DIR__) . '/src/Support/Exceptions.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'RecordToQuiz\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require $file;
    }
});

$opts = [];
$files = [];
$valueOptions = ['retries', 'metrics', 'output'];
for ($index = 1; $index < $argc; $index++) {
    $argument = $argv[$index];
    if ($argument === '--help') {
        $opts['help'] = true;
        continue;
    }
    if ($argument === '--parallel') {
        throw new InvalidArgumentException('Parallel mode has been removed; files are processed sequentially.');
    }
    if ($argument === '--live') {
        $opts['live'] = true;
        continue;
    }
    if (str_starts_with($argument, '--')) {
        [$name, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);
        if (!in_array($name, $valueOptions, true)) {
            throw new InvalidArgumentException("Unknown option: --$name");
        }
        if ($value === null) {
            $index++;
            if (!isset($argv[$index]) || str_starts_with($argv[$index], '--')) {
                throw new InvalidArgumentException("Option --$name requires a value.");
            }
            $value = $argv[$index];
        }
        $opts[$name] = $value;
        continue;
    }
    $files[] = $argument;
}
if (isset($opts['help']) || $files === []) {
    fwrite(STDERR, "Usage: record-to-quiz.php [--live] [--retries=N] [--metrics=FILE] [--output=FILE] AUDIO...\n");
    exit($files === [] && !isset($opts['help']) ? 2 : 0);
}
$http = new HttpClient();
$serviceUrl = Config::env('WHISPER_URL', 'http://127.0.0.1:8000');
$whisper = new WhisperClient($http, $serviceUrl);
$llm = new Gemini(Config::env('GEMINI_API_KEY'), dirname(__DIR__) . '/skills/skill.md', Config::env('GEMINI_MODEL', 'gemini-1.5-flash'));
$metrics = new Metrics();
$retries = (int) ($opts['retries'] ?? 2);
if (isset($opts['live'])) {
    if (count($files) !== 1) {
        throw new InvalidArgumentException('Live simulation expects exactly one input audio/video file.');
    }
    $pipeline = new PipelineLive(new VadClient($http, $serviceUrl), $whisper, $llm, $retries);
    try {
        $result = $pipeline->run($files[0], $metrics);
    } catch (Throwable $error) {
        if (isset($opts['metrics'])) $metrics->write((string) $opts['metrics']);
        fwrite(STDERR, 'Pipeline failed: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
} else {
    $pipeline = new UploadPipeline($whisper, $llm, $retries);
    try {
        $result = $pipeline->run($files, $metrics);
    } catch (Throwable $error) {
        if (isset($opts['metrics'])) $metrics->write((string) $opts['metrics']);
        fwrite(STDERR, 'Pipeline failed: ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
}
if (isset($opts['metrics'])) $metrics->write((string)$opts['metrics']);
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (isset($opts['output'])) {
    if (file_put_contents((string)$opts['output'], $json . PHP_EOL) === false) throw new RuntimeException('Unable to write output.');
} else echo $json . PHP_EOL;
foreach (($result['results'] ?? []) as $item) {
    if (isset($item['error'])) {
        exit(1);
    }
}
