<?php

declare(strict_types=1);

namespace App\Events\Game;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base de los eventos de partida: canal de presencia de la sala y nombre
 * corto (el cliente escucha '.NombreDelEvento'). Cada evento concreto solo
 * define su payload en broadcastWith().
 */
abstract class GameBroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly string $pin) {}

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel('game.'.$this->pin);
    }

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }
}
