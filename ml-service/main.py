"""FastAPI entry point for VAD-backed Whisper transcription."""

from __future__ import annotations

import io
import logging
import time
from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from typing import Any

import numpy as np
from fastapi import FastAPI, HTTPException, Request

from vad import SileroVAD, VADError
from whisper_wrapper import WhisperError, WhisperTranscriber

vad = SileroVAD()
transcriber = WhisperTranscriber()
logger = logging.getLogger("record_to_quiz.ml_service")


@asynccontextmanager
async def lifespan(_: FastAPI) -> AsyncIterator[None]:
    logger.info(
        "Loading Whisper model: model=%s device=%s compute_type=%s cpu_threads=%s",
        transcriber.model_name,
        transcriber.device,
        transcriber.compute_type,
        transcriber.cpu_threads,
    )
    load_started = time.perf_counter()
    transcriber.load()
    logger.info("Whisper model loaded in %.2fs", time.perf_counter() - load_started)
    yield


app = FastAPI(title="Record to Quiz ML Service", version="0.1.0", lifespan=lifespan)


def decode_audio(data: bytes) -> tuple[np.ndarray, int]:
    """Decode common formats through PyAV (bundled with faster-whisper)."""
    try:
        import av
        container = av.open(io.BytesIO(data))
        stream = next((s for s in container.streams if s.type == "audio"), None)
        if stream is None:
            raise ValueError("no audio stream found")
        rate = 16_000
        resampler = av.audio.resampler.AudioResampler(format="flt", layout="mono",
                                                       rate=rate)
        frames: list[np.ndarray] = []
        for frame in container.decode(stream):
            for converted in resampler.resample(frame):
                frames.append(converted.to_ndarray().reshape(-1))
        if not frames:
            raise ValueError("audio contains no samples")
        return np.concatenate(frames).astype(np.float32), rate
    except Exception as exc:
        raise ValueError(f"unable to decode audio: {exc}") from exc


@app.get("/health")
def health() -> dict[str, Any]:
    return {"status": "ok", "model": transcriber.model_name,
            "device": transcriber.device, "compute_type": transcriber.compute_type}


async def read_audio_request(request: Request) -> tuple[np.ndarray, int]:
    data = await request.body()
    if not data:
        raise HTTPException(status_code=400, detail="uploaded file is empty")
    try:
        return decode_audio(data)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


@app.post("/vad")
async def detect_voice_activity(request: Request) -> dict[str, Any]:
    audio, _ = await read_audio_request(request)
    try:
        intervals = vad.speech_timestamps(audio)
        return {"segments": [{"start": start, "end": end} for start, end in intervals]}
    except (ValueError, VADError) as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


@app.post("/transcribe")
async def transcribe(request: Request, language: str = "vi") -> dict[str, Any]:
    """Transcribe a raw audio/video request body.

    Raw bodies keep the PHP client independent of multipart implementation
    details and allow the service to be treated as a third-party HTTP API.
    """
    audio, _sample_rate = await read_audio_request(request)
    try:
        stt_started = time.perf_counter()
        result = transcriber.transcribe(audio, language=language)
        stt_ms = (time.perf_counter() - stt_started) * 1000
        duration = len(audio) / _sample_rate
        return {
            "text": result["text"],
            "timings_ms": {"vad": 0.0, "stt": stt_ms},
            "chunks": [{
                "start": 0.0,
                "end": duration,
                **result,
            }],
        }
    except (ValueError, WhisperError) as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
