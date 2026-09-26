# Record to Quiz

The project contains a Python ML HTTP service (`ml-service/`) and a modular
PHP CLI orchestrator (`php-service/`). PHP calls VAD and Whisper over HTTP,
then calls Gemini using the external prompt in `php-service/skills/skill.md`.
The CLI writes structured output and per-run performance/token metrics.

## Windows setup with WSL2

Native Windows PHP does not support `pcntl_fork()`. Sequential upload mode can
run natively, but live simulation and parallel mode require Linux/macOS. On
Windows, use WSL2 with Ubuntu. In an elevated PowerShell, run:

```powershell
wsl --install -d Ubuntu
```

Restart if requested, launch Ubuntu from the Start menu, and create the Linux
username and password. In the Ubuntu terminal, install PHP CLI and the
required tools:

```sh
sudo apt update
sudo apt install -y php-cli php-curl php-mbstring ffmpeg python3-venv
php -v
php -m | grep -E 'curl|pcntl'
ffmpeg -version
```

PHP must be version 8.1 or later and list both `curl` and `pcntl` in the CLI
modules. If `pcntl` is missing, install/reinstall the Ubuntu PHP CLI package
matching the PHP version and check again. The Python service may run in the
same WSL2 environment or in a separate Linux container.

Follow [ml-service/README.md](./ml-service/README.md) to install and start the
Python service. In a second WSL terminal, run the PHP commands:

```sh
cd /mnt/d/University/semester7/DACN/record-to-quiz/php-service
export GEMINI_API_KEY='your-key'
export WHISPER_URL='http://127.0.0.1:8000'
php bin/record-to-quiz.php ../recordings/sample.wav --metrics metrics.json
php bin/record-to-quiz.php --parallel --workers=4 ../recordings/*.wav
php bin/record-to-quiz.php --live --workers=2 --queue=4 ../recordings/sample.wav
```

Live mode uses FFmpeg to replay the input file in real time, so FFmpeg must be
installed in the Linux/WSL environment. No microphone device is required for
this simulation.

## Worker sizing and metrics

`--workers` is the PHP-side upper bound, not a CPU-core-based setting. The
effective count is capped by `WHISPER_MAX_CONCURRENCY`, which defaults to `1`
because the Python service currently uses one synchronous Whisper model
instance. Increase that limit only after configuring and benchmarking the
Python service to serve that many simultaneous inference requests.

Metrics include total processing time, VAD time, STT time, LLM time, retries,
and success/failure counts. Gemini usage metadata provides input/output token
counts and API call counts. Set both
`GEMINI_INPUT_USD_PER_MILLION` and `GEMINI_OUTPUT_USD_PER_MILLION` to the
applicable rates for the configured model to compute estimated cost; otherwise
the cost field is `null`.

`GEMINI_API_KEY` is required. `GEMINI_MODEL` defaults to `gemini-1.5-flash`;
`WHISPER_URL` defaults to `http://127.0.0.1:8000`. Use `--retries`, `--queue`,
`--output`, and `--metrics` to configure runs. Composer is optional because
the PHP service has no third-party package dependencies.

## Architecture

`VadClient` and `WhisperClient` call the Python service over HTTP. `Llm` is
implemented by `Gemini`, which loads the versionable system prompt from disk.
`UploadPipeline` supports sequential and bounded forked processing.
`PipelineLive` spools real-time FFmpeg capture to disk, uses HTTP VAD scans to
find pause boundaries, and dispatches finalized chunks to transcription
workers over socketpairs. The disk spool allows capture to continue while VAD
or Whisper requests are in progress. Live soft-cut scans begin after 60
seconds; a 0.8-second VAD silence is used by default to qualify a pause, and
the hard maximum is 75 seconds. `LIVE_SILENCE_SECONDS` and
`LIVE_VAD_CHECK_SECONDS` (default 2 seconds) can tune the pause behavior.
Both modes reassemble output in chunk order before requesting quiz generation.
