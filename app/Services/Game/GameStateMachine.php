<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Enums\GameState;
use App\Exceptions\GameException;

/**
 * Transiciones de estado de la partida (plan §5).
 * Única puerta para cambiar `state`: valida la transición contra el enum,
 * incrementa `state_version` y renueva el TTL de la sala.
 *
 * La transición es un compare-and-set ATÓMICO en Redis: si dos llamadas
 * compiten por la misma transición (dos cierres de pregunta, un doble click
 * en "Siguiente"), exactamente una gana. Por eso sirve de candado.
 */
class GameStateMachine
{
    public function __construct(private readonly GameRepository $repo) {}

    /**
     * Ejecuta from→to y devuelve la nueva state_version.
     *
     * @param  array<string,string>  $extra  campos extra a escribir junto al estado
     *
     * @throws GameException si el estado actual no es $from o no permite $to
     */
    public function transition(string $pin, GameState $from, GameState $to, array $extra = []): int
    {
        if (! $from->canTransitionTo($to)) {
            throw GameException::wrongState("({$from->value} → {$to->value})");
        }

        $result = $this->repo->compareAndTransition($pin, $from->value, $to->value, $extra);

        if (! $result['ok']) {
            throw $result['current'] === null
                ? GameException::pinNotFound()
                : GameException::wrongState("({$result['current']} → {$to->value})");
        }

        $this->repo->touch($pin);

        return $result['version'];
    }

    /** Variante tolerante para jobs idempotentes: false en vez de excepción. */
    public function tryTransition(string $pin, GameState $from, GameState $to, array $extra = []): int|false
    {
        try {
            return $this->transition($pin, $from, $to, $extra);
        } catch (GameException) {
            return false;
        }
    }

    /**
     * Termina la partida desde cualquier estado no terminal (aborto del
     * docente o del barrido). Solo UN llamador la obtiene; los demás reciben
     * null y no deben persistir ni destruir nada.
     *
     * @return array{version: int, previous: GameState}|null
     */
    public function claimEnd(string $pin, GameState $terminal = GameState::Aborted): ?array
    {
        $claim = $this->repo->claimTerminal($pin, $terminal->value);

        return $claim === null ? null : [
            'version' => $claim['version'],
            'previous' => GameState::from($claim['previous']),
        ];
    }
}
