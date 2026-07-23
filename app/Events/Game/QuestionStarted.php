<?php

declare(strict_types=1);

namespace App\Events\Game;

/**
 * La pregunta quedó en juego. Las opciones van SIN is_correct: la correcta
 * jamás viaja al cliente hasta la revelación (anti-trampa).
 */
class QuestionStarted extends GameBroadcastEvent
{
    public function __construct(
        string $pin,
        public readonly int $stateVersion,
        public readonly int $index,
        public readonly int $total,
        public readonly string $questionUuid,
        public readonly array $question, // del snapshot (CON is_correct: se filtra abajo)
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
            'kind' => $this->question['kind'],
            'title' => $this->question['title'],
            'image_url' => $this->question['image_url'] ?? null,
            'time_limit' => $this->question['time_limit'],
            'points_mode' => $this->question['points_mode'],
            'options' => array_map(
                fn (array $o) => ['id' => $o['id'], 'text' => $o['text']], // sin is_correct
                $this->question['options'],
            ),
            'ends_at_ms' => $this->endsAtMs,
            'server_now_ms' => (int) (microtime(true) * 1000),
        ];
    }
}
