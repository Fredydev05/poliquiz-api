<?php

declare(strict_types=1);

namespace App\Events\Game;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * El docente expulsó a un jugador. Lo escuchan todos: el expulsado se va
 * a la pantalla de inicio; el host lo quita del roster.
 */
class PlayerKicked implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly string $pin,
        public readonly string $playerUuid,
    ) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('game.'.$this->pin);
    }

    /** Nombre corto del evento: el cliente escucha '.PlayerKicked'. */
    public function broadcastAs(): string
    {
        return 'PlayerKicked';
    }

    public function broadcastWith(): array
    {
        return ['uuid' => $this->playerUuid];
    }
}
