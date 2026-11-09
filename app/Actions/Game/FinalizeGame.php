<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Models\Game;
use App\Services\Game\GameRepository;

/**
 * Último paso de toda partida (terminada o abortada): vuelca los resultados
 * a Postgres, marca la fila permanente y desarma la sala en Redis.
 *
 * Solo la ejecuta quien ganó la transición a ended/aborted (o el barrido,
 * si ese cierre quedó a medias). Cada paso es idempotente, así que repetirla
 * tras un fallo no duplica nada.
 */
class FinalizeGame
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly PersistGameResults $persist,
    ) {}

    /** @param  'ended'|'aborted'  $status */
    public function handle(string $pin, string $status): void
    {
        $state = $this->repo->getState($pin);
        if ($state === null) {
            return; // ya desarmada
        }

        // Persistir ANTES de destruir: es la última chance de leer Redis.
        $this->persist->handle($pin);

        Game::whereKey($state['game_id'])
            ->where('status', 'active')
            ->update(['status' => $status, 'ended_at' => now()]);

        $this->repo->destroy($pin);
    }
}
