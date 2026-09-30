# Record to Quiz

Record to Quiz turns an audio or video recording into a transcript and quiz.
The Python service provides Silero VAD and faster-whisper over HTTP. The PHP
CLI orchestrates the audio processing and calls Gemini to generate the quiz.

This guide describes the supported local setup on Windows using WSL2 Ubuntu.
Processing is sequential: there is no PHP worker pool or `pcntl_fork()`
requirement. Live mode replays an audio/video file in real time; it does not
record from a microphone.

## 1. Install WSL2 and Ubuntu (Windows)

Open PowerShell as Administrator and run:

```powershell
wsl --install -d Ubuntu
```

Restart Windows if requested, open Ubuntu from the Start menu, and create a
Linux username and password. Check that Ubuntu is using WSL2 from PowerShell:

```powershell
wsl -l -v
```

If Ubuntu shows version 1, convert it:

```powershell
wsl --set-version Ubuntu 2
```

## 2. Install system tools (Ubuntu)

Run this in Ubuntu:

```bash
sudo apt update
sudo apt install -y php-cli php-curl php-mbstring ffmpeg python3-venv python3-pip
```

Check the tools and PHP cURL extension:

```bash
php -v
php -m | grep -i '^curl$'
python3 --version
ffmpeg -version
```

Use PHP 8.1 or later. The `php -m` command must print `curl`.

## 3. Open the project in WSL

For the Windows checkout at
`D:\University\semester7\DACN\record-to-quiz`, the WSL path is:

```bash
cd /mnt/d/University/semester7/DACN/record-to-quiz
```

The repository includes `recordings/sample.wav`. Confirm it is accessible:

```bash
ls -lh recordings/sample.wav
ffprobe -v error -show_entries format=duration \
  -of default=noprint_wrappers=1:nokey=1 recordings/sample.wav
```

Use a recording with clear speech for testing; a silent or music-only file
cannot produce a useful transcript.

## 4. Install Python dependencies (first-time setup only)

In the first Ubuntu terminal:

```bash
cd /mnt/d/University/semester7/DACN/record-to-quiz/ml-service
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
pip install -r requirements.txt
```

This installs Python packages; it does not install the Whisper model. Do not
repeat these commands each time you start the service. For later runs, activate
the existing environment with `source .venv/bin/activate`.

## 5. Start the Python service and download the model

In the first Ubuntu terminal, with the virtual environment activated, run:

```bash
cd /mnt/d/University/semester7/DACN/record-to-quiz/ml-service
HF_HUB_DISABLE_XET=1 \
WHISPER_MODEL=small \
WHISPER_DEVICE=cpu \
WHISPER_COMPUTE_TYPE=int8 \
WHISPER_CPU_THREADS=4 \
uvicorn main:app --host 0.0.0.0 --port 8000
```

The service loads one `small` Whisper model using CPU `int8`. On its first
start, it downloads the model weights from Hugging Face and then loads them
into memory. This can take several minutes depending on the connection and is
separate from installing Python packages. `HF_HUB_DISABLE_XET=1` is included
because it allowed the model download to make progress in this setup. Keep this
terminal open.

While startup is in progress, Uvicorn may show `Waiting for application
startup.` That does not mean the API is ready. Wait until it prints
`Application startup complete`. Do not start PHP before then.

To check progress from a second Ubuntu terminal, run:

```bash
watch -n 10 'du -sh ~/.cache/huggingface/hub/models--Systran--faster-whisper-small 2>/dev/null'
```

The cache should grow while the model downloads. Press `Ctrl+C` to stop
`watch`; this does not stop the service. After the model is cached, later
starts reuse it, but still load it into memory. Do not delete the cache or
reinstall Python packages to restart the service.

## 6. Check the Python API

In a second Ubuntu terminal:

```bash
curl http://127.0.0.1:8000/health
```

The response should be JSON with `"status":"ok"`, `"model":"small"`,
`"device":"cpu"`, and `"compute_type":"int8"`.

You can also test transcription directly:

```bash
cd /mnt/d/University/semester7/DACN/record-to-quiz
curl -X POST --data-binary @recordings/sample.wav \
  -H "Content-Type: application/octet-stream" \
  "http://127.0.0.1:8000/transcribe?language=vi"
```

The response should contain transcript text. The first `/vad` request may
additionally load Silero VAD.

## 7. Configure Gemini

The PHP pipeline requires a Gemini API key. Obtain one from Google AI Studio
and set it in the second Ubuntu terminal where PHP will run:

```bash
export GEMINI_API_KEY='PASTE_YOUR_GEMINI_API_KEY_HERE'
export WHISPER_URL='http://127.0.0.1:8000'
```

Do not put the key in source code or commit it. These environment variables
must be set again in a newly opened terminal. Verify that the key is set
without displaying its value:

```bash
test -n "$GEMINI_API_KEY" && echo "Gemini key is set"
```

The CLI defaults to Gemini model `gemini-3.1-flash-lite`. The optional
`GEMINI_MODEL` environment variable can override it.

## 8. Run upload mode

In the second Ubuntu terminal:

```bash
cd /mnt/d/University/semester7/DACN/record-to-quiz/php-service
php bin/record-to-quiz.php ../recordings/sample.wav \
  --metrics metrics.json \
  --output output.json
```

The generated quiz is saved to `php-service/output.json`; metrics are saved
to `php-service/metrics.json`. Omit `--output output.json` to print the result
to the terminal:

```bash
php bin/record-to-quiz.php ../recordings/sample.wav --metrics metrics.json
```

Multiple recordings can be passed in one command. They are processed one at a
time:

```bash
php bin/record-to-quiz.php \
  ../recordings/sample.wav \
  ../recordings/another.wav
```

## 9. Run live simulation

Live mode replays one existing audio/video file through FFmpeg in real time.
It is not microphone recording. In the same PHP terminal:

```bash
cd /mnt/d/University/semester7/DACN/record-to-quiz/php-service
php bin/record-to-quiz.php --live ../recordings/sample.wav \
  --metrics live-metrics.json \
  --output live-output.json
```

For a meaningful live chunking test, use a recording longer than 60 seconds.
Chunks are transcribed sequentially and reassembled in order before quiz
generation. FFmpeg must remain installed and available in WSL.

## Metrics and options

Metrics include stage timings for VAD, STT, and LLM, along with retries,
success/failure counts, Gemini API calls, and token usage when returned by the
API. `started_at` and `finished_at` are ISO-8601 timestamps; their Unix values
are available in `started_at_epoch` and `finished_at_epoch`. The
`processing_time_seconds` object reports STT, Gemini, and total processing time.
To calculate estimated Gemini cost, set both model-appropriate rates:

```bash
export GEMINI_INPUT_USD_PER_MILLION='YOUR_INPUT_RATE'
export GEMINI_OUTPUT_USD_PER_MILLION='YOUR_OUTPUT_RATE'
```

Without both rates, estimated cost is `null`. The supported CLI options are:

- `--live`: run live file-replay mode; provide exactly one input file.
- `--retries=N`: set retry count (default: 2).
- `--metrics=FILE`: write metrics JSON.
- `--output=FILE`: write result JSON instead of printing it.

Parallel mode and the old `--parallel`, `--workers`, and `--queue` options are
not supported.

## Troubleshooting

- **Uvicorn remains at `Waiting for application startup`:** the app loads the
  Whisper model before becoming ready. Check model cache progress using the
  `watch` command above. Keep `HF_HUB_DISABLE_XET=1` set when starting the
  service. No `pip install` is needed just because the model is downloading.
- **`curl` to `/health` fails or PHP reports connection refused:** ensure the
  service reached `Application startup complete` and that
  `WHISPER_URL=http://127.0.0.1:8000` is set in the PHP terminal.
- **PHP reports missing cURL:** install `php-curl` in Ubuntu and check
  `php -m | grep -i '^curl$'`.
- **Gemini authentication or quota error:** check that `GEMINI_API_KEY` is
  set in the same terminal running PHP and that the API key/model has access.
- **Audio decode or FFmpeg error:** verify the path with `ls -lh`, inspect the
  file with `ffprobe`, and check `ffmpeg -version`.
- **No speech segments or empty transcript:** test with clear spoken audio.
