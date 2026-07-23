<?php

declare(strict_types=1);

namespace App\Events\Game;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Progreso de respuestas — SOLO para el canal privado del host
 * ("12 de 28 respondieron"). No revela quién ni qué respondió.
 */
class AnswerReceived implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $pin,
        public readonly int $answeredCount,
        public readonly int $playersCount,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('game.'.$this->pin.'.host');
    }

    public function broadcastAs(): string
    {
        return 'AnswerReceived';
    }

    public function broadcastWith(): array
    {
        return [
            'answered_count' => $this->answeredCount,
            'players_count' => $this->playersCount,
        ];
    }
}
