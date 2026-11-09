<?php

declare(strict_types=1);

namespace App\Services\Game;

/**
 * Reloj del servidor en milisegundos: la única fuente de "ahora" del game
 * loop (ends_at, closed_at, ready_until, last_activity…). El cliente nunca
 * aporta timestamps.
 */
final class ServerClock
{
    public static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
