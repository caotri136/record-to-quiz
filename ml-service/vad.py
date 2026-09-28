"""Silero VAD wrapper for mono, float32 audio."""

from __future__ import annotations

from typing import Any

import numpy as np


class VADError(RuntimeError):
    """Raised when the VAD cannot be loaded or used."""


class SileroVAD:
    """Small wrapper around the official ``silero-vad`` package."""

    def __init__(self, sampling_rate: int = 16_000, threshold: float = 0.5) -> None:
        self.sampling_rate = sampling_rate
        self.threshold = threshold
        self._model: Any | None = None
        self._get_speech_timestamps: Any | None = None

    def load(self) -> None:
        if self._model is not None:
            return
        try:
            from silero_vad import get_speech_timestamps, load_silero_vad
        except ImportError as exc:
            raise VADError(
                "Silero VAD is unavailable. Install dependencies with "
                "`pip install -r ml-service/requirements.txt`."
            ) from exc
        try:
            self._model = load_silero_vad()
            self._get_speech_timestamps = get_speech_timestamps
        except Exception as exc:  # model download/runtime errors
            raise VADError(f"Unable to load Silero VAD model: {exc}") from exc

    def speech_timestamps(self, audio: np.ndarray) -> list[tuple[float, float]]:
        """Return speech intervals in seconds for a mono waveform."""
        self.load()
        if audio.ndim != 1 or audio.size == 0:
            raise ValueError("audio must be a non-empty mono waveform")
        waveform = np.asarray(audio, dtype=np.float32)
        try:
            import torch
            result = self._get_speech_timestamps(  # type: ignore[misc]
                torch.from_numpy(waveform), self._model, sampling_rate=self.sampling_rate,
                threshold=self.threshold, return_seconds=False,
            )
        except ImportError as exc:
            raise VADError("PyTorch is required by Silero VAD") from exc
        except Exception as exc:
            raise VADError(f"Silero VAD failed: {exc}") from exc
        return [
            (float(item["start"]) / self.sampling_rate,
             float(item["end"]) / self.sampling_rate)
            for item in result
        ]
