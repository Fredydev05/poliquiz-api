<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Máquina de estados de la partida (plan §5).
 * lobby → get_ready → question → results → leaderboard → (…loop | podium) → ended
 */
enum GameState: string
{
    case Lobby = 'lobby';
    case GetReady = 'get_ready';
    case Question = 'question';
    case Results = 'results';
    case Leaderboard = 'leaderboard';
    case Podium = 'podium';
    case Ended = 'ended';
    case Aborted = 'aborted';

    /** Transiciones válidas desde cada estado (se consulta en F3). */
    public function canTransitionTo(self $next): bool
    {
        $allowed = match ($this) {
            self::Lobby => [self::GetReady, self::Aborted],
            self::GetReady => [self::Question, self::Aborted],
            self::Question => [self::Results, self::Aborted],
            self::Results => [self::Leaderboard, self::Podium, self::Aborted],
            self::Leaderboard => [self::GetReady, self::Podium, self::Aborted],
            self::Podium => [self::Ended],
            self::Ended, self::Aborted => [],
        };

        return in_array($next, $allowed, true);
    }
}
