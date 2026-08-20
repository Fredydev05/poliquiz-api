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
    case Slide = 'slide';
    case Podium = 'podium';
    case Ended = 'ended';
    case Aborted = 'aborted';

    /**
     * Transiciones válidas desde cada estado. El "próximo ítem" puede ser una
     * pregunta (→ get_ready) o una diapositiva (→ slide); por eso lobby,
     * leaderboard y slide pueden ir a cualquiera de los dos.
     */
    public function canTransitionTo(self $next): bool
    {
        $allowed = match ($this) {
            self::Lobby => [self::GetReady, self::Slide, self::Aborted],
            self::GetReady => [self::Question, self::Aborted],
            self::Question => [self::Results, self::Aborted],
            self::Results => [self::Leaderboard, self::Podium, self::Aborted],
            self::Leaderboard => [self::GetReady, self::Slide, self::Podium, self::Aborted],
            self::Slide => [self::GetReady, self::Slide, self::Podium, self::Aborted],
            self::Podium => [self::Ended],
            self::Ended, self::Aborted => [],
        };

        return in_array($next, $allowed, true);
    }
}
