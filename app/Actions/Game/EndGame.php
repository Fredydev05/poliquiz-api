<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Events\Game\GameEnded;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;
use App\Services\Game\ServerClock;
use Throwable;

/**
 * Cierre de la partida por el docente (la X del host) o por el barrido de
 * salas abandonadas: avisa a todos, guarda lo jugado y desarma la sala.
 *
 * Antes de tocar nada "reclama" el final de forma atómica: si el docente y
 * el barrido (o un "Siguiente" en el podio) llegan a la vez, solo uno
 * persiste y destruye; los demás no hacen nada.
 */
class EndGame
{
    /** Un final reclamado hace más de esto y sin terminar se considera caído (ms). */
    private const STALE_CLAIM_MS = 3000;

    public function __construct(
        private readonly GameStateMachine $machine,
        private readonly FinalizeGame $finalize,
        private readonly GameRepository $repo,
    ) {}

    public function handle(string $pin): void
    {
        $claim = $this->machine->claimEnd($pin);
        if ($claim === null) {
            $this->retryStaleFinalize($pin);

            return;
        }

        // En el podio la partida ya se jugó entera: cerrarla no es abortarla
        // (y los celulares ya están mostrando el resultado final).
        if ($claim['previous'] === GameState::Podium) {
            $this->finalize->handle($pin, 'ended');

            return;
        }

        // El aviso es best-effort: lo importante es guardar lo jugado.
        try {
            GameEnded::dispatch($pin, $claim['version'], [], aborted: true);
        } catch (Throwable $e) {
            report($e);
        }

        $this->finalize->handle($pin, 'aborted');
    }

    /**
     * Ya estaba en ended/aborted: si quien la cerraba se cayó a mitad (estado
     * "frío"), el docente puede reintentar con la X en vez de esperar al
     * barrido. Si la finalización está en curso, no hacemos nada.
     */
    private function retryStaleFinalize(string $pin): void
    {
        $state = $this->repo->getState($pin);
        if ($state === null || ! in_array($state['state'], [GameState::Ended->value, GameState::Aborted->value], true)) {
            return;
        }
        if (ServerClock::nowMs() - (int) ($state['last_activity'] ?? 0) < self::STALE_CLAIM_MS) {
            return;
        }

        $this->finalize->handle($pin, $state['state']);
    }
}
