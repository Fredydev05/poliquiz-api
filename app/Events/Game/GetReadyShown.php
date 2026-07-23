<?php

declare(strict_types=1);

namespace App\Events\Game;

/** Countdown de "¡Preparate!" antes de cada pregunta (3 s). */
class GetReadyShown extends GameBroadcastEvent
{
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly int $index,
        public readonly int $total,
        public readonly int $startsInMs,
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
        ];
    }
}
