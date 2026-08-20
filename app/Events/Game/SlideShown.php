<?php

declare(strict_types=1);

namespace App\Events\Game;

/**
 * Se proyectó una diapositiva. No hay timer ni respuestas: el host la deja en
 * pantalla para explicar y avanza con "Siguiente". Los jugadores solo ven un
 * aviso pasivo ("mirá la pantalla").
 */
class SlideShown extends GameBroadcastEvent
{
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly int $index,
        public readonly int $total,
        public readonly string $title,
        public readonly ?string $body,
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
            'title' => $this->title,
            'body' => $this->body,
            'image_url' => $this->imageUrl,
        ];
    }
}
