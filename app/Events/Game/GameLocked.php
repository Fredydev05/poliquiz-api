<?php

declare(strict_types=1);

namespace App\Events\Game;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/** El docente bloqueó/desbloqueó la sala (nadie más entra mientras esté locked). */
class GameLocked implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $pin,
        public readonly bool $locked,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('game.'.$this->pin);
    }

    /** Nombre corto del evento: el cliente escucha '.GameLocked'. */
    public function broadcastAs(): string
    {
        return 'GameLocked';
    }

    public function broadcastWith(): array
    {
        return ['locked' => $this->locked];
    }
}
