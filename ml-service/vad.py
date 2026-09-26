"""Silero VAD and bounded audio chunking.

The chunker prefers boundaries near 60 seconds, but never emits a chunk longer
than 75 seconds.  It is deliberately independent of an audio file decoder:
callers provide a mono, float32 waveform.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Iterable

import numpy as np


class VADError(RuntimeError):
    """Raised when the VAD cannot be loaded or used."""


@dataclass(frozen=True)
class AudioChunk:
    """A bounded section of audio and its position in the source."""

    audio: np.ndarray
    start: float
    end: float


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

    def chunk(self, audio: np.ndarray, *, soft_limit: float = 60.0,
              hard_limit: float = 75.0) -> list[AudioChunk]:
        """Split audio at VAD silence, with soft and hard duration limits."""
        if soft_limit <= 0 or hard_limit < soft_limit:
            raise ValueError("hard_limit must be >= a positive soft_limit")
        waveform = np.asarray(audio, dtype=np.float32)
        speech = self.speech_timestamps(waveform)
        if not speech:
            return []
        return split_audio_chunks(waveform, self.sampling_rate, speech,
                                  soft_limit=soft_limit, hard_limit=hard_limit)


def split_audio_chunks(
    audio: np.ndarray, sampling_rate: int, speech: Iterable[tuple[float, float]],
    *, soft_limit: float = 60.0, hard_limit: float = 75.0,
) -> list[AudioChunk]:
    """Build chunks from speech intervals.

    A silence boundary closest to the 60s target is selected when possible.
    Long uninterrupted speech is cut at exactly the 75s hard limit.
    """
    intervals = sorted((max(0.0, start), max(start, end))
                       for start, end in speech if end > start)
    if not intervals:
        return []
    chunks: list[AudioChunk] = []
    cursor = intervals[0][0]
    interval_index = 0
    total = len(audio) / sampling_rate
    while cursor < intervals[-1][1]:
        limit = min(cursor + hard_limit, total)
        target = min(cursor + soft_limit, limit)
        candidates: list[float] = []
        for index in range(interval_index, len(intervals)):
            start, end = intervals[index]
            if start >= cursor and start <= limit:
                candidates.append(start)
            if end > cursor and end <= limit:
                candidates.append(end)
        boundary = min(candidates, key=lambda value: abs(value - target)) if candidates else limit
        if boundary <= cursor:
            boundary = limit
        start_sample = int(round(cursor * sampling_rate))
        end_sample = min(len(audio), int(round(boundary * sampling_rate)))
        chunks.append(AudioChunk(audio=audio[start_sample:end_sample].copy(),
                                 start=cursor, end=boundary))
        cursor = boundary
        while interval_index < len(intervals) and intervals[interval_index][1] <= cursor:
            interval_index += 1
        while interval_index < len(intervals) and intervals[interval_index][0] < cursor:
            interval_index += 1
    return chunks
