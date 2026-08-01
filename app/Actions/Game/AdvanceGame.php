<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Events\Game\GameEnded;
use App\Events\Game\LeaderboardShown;
use App\Exceptions\GameException;
use App\Models\Game;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;

/**
 * "Siguiente" del docente — avanza la máquina según el estado actual:
 *   lobby        → get_ready(0)                      (= start)
 *   results      → leaderboard  (o podium si era la última)
 *   leaderboard  → get_ready(n+1)
 *   podium       → ended (cierra la sala)
 */
class AdvanceGame
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameStateMachine $machine,
        private readonly LaunchQuestion $launcher,
        private readonly PersistGameResults $persist,
    ) {}

    public function handle(string $pin): array
    {
        $state = $this->repo->getState($pin) ?? throw GameException::pinNotFound();
        $current = GameState::from($state['state']);
        $index = (int) $state['current_index'];
        $isLast = $index >= (int) $state['questions_total'] - 1;

        match ($current) {
            GameState::Lobby => $this->start($pin),
            GameState::Results => $isLast ? $this->podium($pin) : $this->leaderboard($pin),
            GameState::Leaderboard => $isLast ? $this->podium($pin) : $this->launcher->getReady($pin, GameState::Leaderboard, $index + 1),
            GameState::Podium => $this->end($pin),
            default => throw GameException::wrongState("(next no aplica en {$current->value})"),
        };

        return $this->repo->getState($pin) ?? ['state' => GameState::Ended->value];
    }

    private function start(string $pin): void
    {
        if ($this->repo->playersCount($pin) === 0) {
            throw new GameException('NO_PLAYERS', 'No hay jugadores en la sala todavía.', 422);
        }
        $this->launcher->getReady($pin, GameState::Lobby, 0);
    }

    private function leaderboard(string $pin): void
    {
        $version = $this->machine->transition($pin, GameState::Results, GameState::Leaderboard);
        LeaderboardShown::dispatch($pin, $version, $this->topConDelta($pin, 5));
    }

    private function podium(string $pin): void
    {
        // Desde results o leaderboard, según dónde estemos parados.
        $from = GameState::from($this->repo->getState($pin)['state']);
        $version = $this->machine->transition($pin, $from, GameState::Podium);
        GameEnded::dispatch($pin, $version, $this->topConDelta($pin, 3));
    }

    /** podium → ended: persiste resultados, marca la fila y desarma la sala. */
    private function end(string $pin): void
    {
        $state = $this->repo->getState($pin);
        $this->machine->transition($pin, GameState::Podium, GameState::Ended);

        // Persistir ANTES de destruir: es la última chance de leer Redis.
        $this->persist->handle($pin);

        Game::whereKey($state['game_id'])->update(['status' => 'ended', 'ended_at' => now()]);
        $this->repo->destroy($pin);
    }

    /** Top N del ZSET + datos del jugador (nickname, delta de la última). */
    private function topConDelta(string $pin, int $count): array
    {
        return array_map(function (array $entry) use ($pin): array {
            $player = $this->repo->player($pin, $entry['uuid']) ?? [];

            return [
                'uuid' => $entry['uuid'],
                'nickname' => $player['nickname'] ?? '?',
                'score' => $entry['score'],
                'delta' => $player['last_gain'] ?? 0,
            ];
        }, $this->repo->top($pin, $count));
    }
}
