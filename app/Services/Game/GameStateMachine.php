<?php

declare(strict_types=1);

namespace App\Services\Game;

use App\Enums\GameState;
use App\Exceptions\GameException;

/**
 * Transiciones de estado de la partida (plan §5).
 * Única puerta para cambiar `state`: valida la transición contra el enum,
 * incrementa `state_version` y renueva el TTL de la sala.
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
        $state = $this->repo->getState($pin) ?? throw GameException::pinNotFound();
        $current = GameState::from($state['state']);

        if ($current !== $from || ! $current->canTransitionTo($to)) {
            throw GameException::wrongState("({$current->value} → {$to->value})");
        }

        $this->repo->updateState($pin, [...$extra, 'state' => $to->value]);
        $version = $this->repo->bumpVersion($pin);
        $this->repo->touch($pin);

        return $version;
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
}
