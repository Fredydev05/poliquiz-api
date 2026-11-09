<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Game\EndGame;
use App\Actions\Game\FinalizeGame;
use App\Enums\GameState;
use App\Models\Game;
use App\Services\Game\GameRepository;
use App\Services\Game\ServerClock;
use Illuminate\Console\Command;
use Throwable;

/**
 * Barrido de salas abandonadas (plan §6). Corre cada 5 min (ver console.php).
 * Cierra partidas 'active' cuya sala en Redis:
 *   - ya no existe, o el PIN ya es de otra partida → marca la fila como aborted
 *   - quedó terminada a medias (ended/aborted sin finalizar) → la finaliza
 *   - existe pero sin actividad reciente → aborta (avisa + limpia Redis)
 *
 * Cada partida se procesa aislada: si una falla, se reporta y el barrido
 * sigue con las demás (y la reintenta en la próxima corrida).
 */
class ExpireAbandonedGames extends Command
{
    protected $signature = 'poliquiz:expire-abandoned';

    protected $description = 'Cierra las partidas abandonadas (sin actividad reciente).';

    public function handle(GameRepository $repo, EndGame $endGame, FinalizeGame $finalize): int
    {
        $thresholdMs = ServerClock::nowMs() - (int) config('poliquiz.abandoned_after') * 1000;
        $cerradas = 0;
        $fallidas = 0;

        // Ignoramos filas recién creadas: entre Game::create y el armado de la
        // sala en Redis hay unos milisegundos sin PIN ni estado.
        Game::where('status', 'active')->where('created_at', '<', now()->subMinute())->cursor()->each(function (Game $game) use ($repo, $endGame, $finalize, $thresholdMs, &$cerradas, &$fallidas): void {
            try {
                $state = $repo->getState($game->pin ?? '');

                // Redis ya expiró, o el PIN se reutilizó para OTRA partida: la
                // sala de esta fila ya no existe → solo marcarla.
                if ($state === null || (string) ($state['game_id'] ?? '') !== (string) $game->id) {
                    // Guardado contra la fila cargada por el cursor: si entre medio
                    // la partida terminó bien ('ended'), no la pisamos.
                    Game::whereKey($game->id)->where('status', 'active')
                        ->update(['status' => 'aborted', 'ended_at' => now()]);
                    $cerradas++;

                    return;
                }

                if ((int) ($state['last_activity'] ?? 0) >= $thresholdMs) {
                    return; // sala viva
                }

                // Alguien ya la llevó a un estado final pero no terminó de
                // finalizarla (se cayó a mitad): completamos ese trabajo.
                if ($state['state'] === GameState::Ended->value || $state['state'] === GameState::Aborted->value) {
                    $finalize->handle($game->pin, $state['state']);
                } else {
                    $endGame->handle($game->pin);
                }
                $cerradas++;
            } catch (Throwable $e) {
                report($e);
                $fallidas++;
            }
        });

        $this->info("Salas abandonadas cerradas: $cerradas".($fallidas ? " · con error: $fallidas" : ''));

        return self::SUCCESS;
    }
}
