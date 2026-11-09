<?php

declare(strict_types=1);

namespace App\Events\Game;

use App\Services\Game\ServerClock;

/**
 * La pregunta quedó en juego. Viaja la vista del jugador (`playerView` de la
 * estrategia): nunca incluye datos de la correcta, y en "escribe la respuesta"
 * ni siquiera trae opciones (anti-trampa).
 */
class QuestionStarted extends GameBroadcastEvent
{
    /**
     * @param  array<string, mixed>  $playerView  ya filtrado por el tipo de pregunta
     */
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly int $index,
        public readonly int $total,
        public readonly string $questionUuid,
        public readonly array $playerView,
        public readonly int $endsAtMs,
    ) {
        parent::__construct($pin);
    }

    public function broadcastWith(): array
    {
        return [
            'state_version' => $this->stateVersion,
            'index' => $this->index,
            'total' => $this->total,
            'question_uuid' => $this->questionUuid,
            ...$this->playerView, // kind, title, image_url, time_limit, points_mode, options?
            'ends_at_ms' => $this->endsAtMs,
            'server_now_ms' => ServerClock::nowMs(),
        ];
    }
}
