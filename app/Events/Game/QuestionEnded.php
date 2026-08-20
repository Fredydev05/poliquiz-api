<?php

declare(strict_types=1);

namespace App\Events\Game;

/**
 * La pregunta cerró: se revela el resultado. El payload `reveal` es específico
 * del tipo (options | text | order) y trae TODO lo que el host necesita para
 * pintar los resultados. Cada jugador consulta /me para sus puntos.
 */
class QuestionEnded extends GameBroadcastEvent
{
    /**
     * @param  array<string, mixed>  $reveal  {kind, ...} según el tipo de pregunta
     */
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly int $index,
        public readonly array $reveal,
    ) {
        parent::__construct($pin);
    }

    public function broadcastWith(): array
    {
        return [
            'state_version' => $this->stateVersion,
            'index' => $this->index,
            'reveal' => $this->reveal,
        ];
    }
}
