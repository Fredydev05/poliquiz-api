<?php

declare(strict_types=1);

namespace App\Events\Game;

/**
 * Fase de reveal antes de la pregunta: se muestra el ENUNCIADO (título + imagen,
 * sin opciones) durante `starts_in_ms` para que los alumnos lean, y al terminar
 * arrancan las opciones y el timer (QuestionStarted).
 */
class GetReadyShown extends GameBroadcastEvent
{
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly int $index,
        public readonly int $total,
        public readonly int $startsInMs,
        public readonly string $title,
        public readonly ?string $imageUrl,
    ) {
        parent::__construct($pin);
    }

    public function broadcastWith(): array
    {
        return [
            'state_version' => $this->stateVersion,
            'index' => $this->index,
            'total' => $this->total,
            'starts_in_ms' => $this->startsInMs,
            'title' => $this->title,
            'image_url' => $this->imageUrl,
        ];
    }
}
