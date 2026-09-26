# Python ML service

This service exposes raw HTTP endpoints so the PHP application does not share
Python code or business logic with it.

## Setup

Linux, WSL2, or a Linux container is recommended. Install FFmpeg and create a
virtual environment:

```sh
sudo apt-get install ffmpeg
python3 -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
uvicorn main:app --host 0.0.0.0 --port 8000
```

The first request downloads the Silero VAD and faster-whisper model weights.
Configure the model before starting the service:

```sh
WHISPER_MODEL=medium WHISPER_DEVICE=cpu WHISPER_COMPUTE_TYPE=int8 \
  uvicorn main:app --host 0.0.0.0 --port 8000
```

On a CUDA host, use `WHISPER_MODEL=large-v3`,
`WHISPER_DEVICE=cuda`, and `WHISPER_COMPUTE_TYPE=float16`.
When no device is specified, CUDA is selected when PyTorch reports it as
available; otherwise CPU is used. CPU inference uses all detected CPU cores by
default; override this with `WHISPER_CPU_THREADS`. The selected model,
device, and compute type are logged at service startup. The service uses
Vietnamese when the client passes `language=vi`.

## Endpoints

- `GET /health` reports the selected Whisper configuration.
- `POST /vad` accepts a raw audio/video request body and returns speech
  intervals.
- `POST /transcribe` accepts a raw audio/video request body and returns the
  transcript plus timestamped chunks. VAD uses a 60-second soft boundary and
  a 75-second hard maximum.

Example:

```sh
curl -X POST --data-binary @sample.wav \
  -H "Content-Type: application/octet-stream" \
  http://127.0.0.1:8000/transcribe
```
