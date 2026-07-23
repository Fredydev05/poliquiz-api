<?php

declare(strict_types=1);

namespace App\Events\Game;

/** Top 5 entre preguntas, con los puntos ganados en la última (delta). */
class LeaderboardShown extends GameBroadcastEvent
{
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly array $top, // [{uuid, nickname, score, delta}]
    ) {
        parent::__construct($pin);
    }

    public function broadcastWith(): array
    {
        return [
            'state_version' => $this->stateVersion,
            'top' => $this->top,
        ];
    }
}
