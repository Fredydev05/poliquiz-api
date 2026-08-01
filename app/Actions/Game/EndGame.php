<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Events\Game\GameEnded;
use App\Models\Game;
use App\Services\Game\GameRepository;

/**
 * Aborto de la partida por el docente (la X del host): avisa a todos,
 * marca la fila permanente y desarma la sala en Redis.
 */
class EndGame
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly PersistGameResults $persist,
    ) {}

    public function handle(string $pin): void
    {
        $state = $this->repo->getState($pin);
        if ($state === null) {
            return; // ya estaba cerrada: idempotente
        }

        GameEnded::dispatch($pin, (int) $state['state_version'] + 1, [], aborted: true);

        // Guardamos lo jugado hasta el aborto (reportes parciales).
        $this->persist->handle($pin);

        Game::whereKey($state['game_id'])->update(['status' => 'aborted', 'ended_at' => now()]);
        $this->repo->destroy($pin);
    }
}
