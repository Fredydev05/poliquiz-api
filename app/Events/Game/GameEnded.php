<?php

declare(strict_types=1);

namespace App\Events\Game;

/** Fin de partida: podio final (o aborto por parte del docente). */
class GameEnded extends GameBroadcastEvent
{
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly array $podium, // [{uuid, nickname, score}] top 3
        public readonly bool $aborted = false,
    ) {
        parent::__construct($pin);
    }

    public function broadcastWith(): array
    {
        return [
            'state_version' => $this->stateVersion,
            'podium' => $this->podium,
            'aborted' => $this->aborted,
        ];
    }
}
