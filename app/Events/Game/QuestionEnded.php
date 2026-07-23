<?php

declare(strict_types=1);

namespace App\Events\Game;

/**
 * La pregunta cerró: se revelan las correctas y la distribución de votos.
 * Cada jugador compara su elección local y consulta /me para sus puntos.
 */
class QuestionEnded extends GameBroadcastEvent
{
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly int $index,
        public readonly array $correctOptionIds,
        public readonly array $distribution, // [{option_id, count}]
    ) {
        parent::__construct($pin);
    }

    public function broadcastWith(): array
    {
        return [
            'state_version' => $this->stateVersion,
            'index' => $this->index,
            'correct_option_ids' => $this->correctOptionIds,
            'distribution' => $this->distribution,
        ];
    }
}
