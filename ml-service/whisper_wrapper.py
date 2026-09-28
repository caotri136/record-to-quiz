"""Environment-configured faster-whisper wrapper."""

from __future__ import annotations

import os
from typing import Any

import numpy as np


class WhisperError(RuntimeError):
    """Raised for model configuration or transcription failures."""


class WhisperTranscriber:
    def __init__(self, model_name: str | None = None, device: str | None = None,
                 compute_type: str | None = None) -> None:
        self.device = (device or os.getenv("WHISPER_DEVICE", "cpu")).lower()
        self.model_name = model_name or os.getenv("WHISPER_MODEL", "small")
        self.compute_type = compute_type or os.getenv("WHISPER_COMPUTE_TYPE", "int8")
        self.cpu_threads = int(os.getenv("WHISPER_CPU_THREADS", "4"))
        self._model: Any | None = None

    def load(self) -> None:
        if self._model is not None:
            return
        try:
            from faster_whisper import WhisperModel
        except ImportError as exc:
            raise WhisperError(
                "faster-whisper is unavailable. Install ml-service/requirements.txt."
            ) from exc
        try:
            options: dict[str, Any] = {
                "device": self.device,
                "compute_type": self.compute_type,
            }
            if self.device == "cpu":
                options["cpu_threads"] = self.cpu_threads
            self._model = WhisperModel(self.model_name, **options)
        except Exception as exc:
            raise WhisperError(
                f"Unable to load Whisper model '{self.model_name}' "
                f"(device={self.device}, compute_type={self.compute_type}): {exc}"
            ) from exc

    def transcribe(self, audio: np.ndarray, *, language: str | None = None) -> dict[str, Any]:
        self.load()
        try:
            segments, info = self._model.transcribe(audio, language=language,
                                                    vad_filter=True,
                                                    vad_parameters={
                                                        "threshold": 0.6,
                                                        "min_speech_duration_ms": 250,
                                                        "min_silence_duration_ms": 500,
                                                        "speech_pad_ms": 200,
                                                    })
            items = [{"start": float(s.start), "end": float(s.end),
                      "text": s.text.strip()} for s in segments]
            return {"text": " ".join(item["text"] for item in items).strip(),
                    "segments": items, "language": info.language}
        except Exception as exc:
            raise WhisperError(f"Whisper transcription failed: {exc}") from exc
