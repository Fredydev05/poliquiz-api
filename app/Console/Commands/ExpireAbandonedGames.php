<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Game\EndGame;
use App\Models\Game;
use App\Services\Game\GameRepository;
use Illuminate\Console\Command;

/**
 * Barrido de salas abandonadas (plan §6). Corre cada 5 min (ver console.php).
 * Cierra partidas 'active' cuya sala en Redis:
 *   - ya no existe (el TTL la limpió)  → marca la fila como aborted
 *   - existe pero sin actividad reciente → aborta (avisa + limpia Redis)
 */
class ExpireAbandonedGames extends Command
{
    protected $signature = 'poliquiz:expire-abandoned';

    protected $description = 'Cierra las partidas abandonadas (sin actividad reciente).';

    public function handle(GameRepository $repo, EndGame $endGame): int
    {
        $thresholdMs = (int) (microtime(true) * 1000) - (int) config('poliquiz.abandoned_after') * 1000;
        $cerradas = 0;

        Game::where('status', 'active')->cursor()->each(function (Game $game) use ($repo, $endGame, $thresholdMs, &$cerradas): void {
            $state = $repo->getState($game->pin ?? '');

            // Redis ya expiró: la fila quedó huérfana → solo marcarla.
            if ($state === null) {
                $game->update(['status' => 'aborted', 'ended_at' => now()]);
                $cerradas++;

                return;
            }

            if ((int) ($state['last_activity'] ?? 0) < $thresholdMs) {
                $endGame->handle($game->pin);
                $cerradas++;
            }
        });

        $this->info("Salas abandonadas cerradas: $cerradas");

        return self::SUCCESS;
    }
}
