<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Enums\QuestionKind;
use App\Events\Game\GameEnded;
use App\Events\Game\LeaderboardShown;
use App\Exceptions\GameException;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;
use App\Services\Game\ServerClock;

/**
 * "Siguiente" del docente — avanza la máquina según el estado actual:
 *   lobby        → get_ready(0)                      (= start)
 *   results      → leaderboard  (o podium si era la última)
 *   leaderboard  → get_ready(n+1)
 *   podium       → ended (cierra la sala)
 *
 * Además destraba la sala si un job se perdió (cola caída, deploy…):
 *   get_ready / question vencidos → arranca / cierra la pregunta
 *   ended / aborted que quedó sin finalizar → reintenta la finalización
 */
class AdvanceGame
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameStateMachine $machine,
        private readonly LaunchQuestion $launcher,
        private readonly ShowSlide $slideShower,
        private readonly FinalizeGame $finalize,
        private readonly CloseQuestion $closeQuestion,
    ) {}

    /** Margen antes de que el docente pueda destrabar algo "vencido" (ms). */
    private const STUCK_AFTER_MS = 3000;

    public function handle(string $pin): array
    {
        $state = $this->repo->getState($pin) ?? throw GameException::pinNotFound();
        $current = GameState::from($state['state']);
        $index = (int) $state['current_index'];
        $isLast = $index >= (int) $state['questions_total'] - 1;

        match ($current) {
            GameState::Lobby => $this->start($pin),
            GameState::Results => $this->afterResults($pin, $state, $isLast),
            GameState::Leaderboard => $this->goToItem($pin, GameState::Leaderboard, $index + 1),
            GameState::Slide => $this->goToItem($pin, GameState::Slide, $index + 1),
            GameState::Podium => $this->end($pin),
            GameState::GetReady => $this->unstickGetReady($pin, $state),
            GameState::Question => $this->unstickQuestion($pin, $state),
            GameState::Ended, GameState::Aborted => $this->retryFinalize($pin, $state),
        };

        return $this->repo->getState($pin) ?? ['state' => GameState::Ended->value];
    }

    /**
     * results → leaderboard/podium, pero recién cuando los puntajes de la
     * pregunta están aplicados: si no, el ranking saldría sin esa ronda y el
     * cierre pendiente ya no podría completarse.
     */
    private function afterResults(string $pin, array $state, bool $isLast): void
    {
        // (closed_at solo existe en cierres del código actual: una sala que
        // venía jugándose desde antes del deploy no tiene esta marca.)
        if (isset($state['closed_at']) && ($state['scored_question'] ?? '') !== ($state['question_uuid'] ?? '')) {
            throw new GameException('RESULTS_PENDING', 'Todavía se están calculando los resultados. Probá de nuevo en un instante.');
        }

        $isLast ? $this->podium($pin) : $this->leaderboard($pin);
    }

    /** El StartQuestionJob nunca llegó: el docente arranca la pregunta. */
    private function unstickGetReady(string $pin, array $state): void
    {
        if (ServerClock::nowMs() < (int) ($state['ready_until'] ?? PHP_INT_MAX) + self::STUCK_AFTER_MS) {
            throw GameException::wrongState('(la pregunta está por empezar)');
        }
        $this->launcher->start($pin, $state['question_uuid']);
    }

    /** El CloseQuestionJob nunca llegó (o falló): el docente cierra la pregunta. */
    private function unstickQuestion(string $pin, array $state): void
    {
        $deadline = (int) $state['ends_at'] + (int) config('poliquiz.answer_grace_ms');
        if (ServerClock::nowMs() < $deadline + self::STUCK_AFTER_MS) {
            throw GameException::wrongState('(la pregunta sigue en juego)');
        }
        $this->closeQuestion->handle($pin, $state['question_uuid'], resume: true);
    }

    /**
     * Quedó en ended/aborted pero sin finalizar (p. ej. se cortó la base al
     * persistir). Solo si ya no hay nadie finalizando (estado "frío").
     */
    private function retryFinalize(string $pin, array $state): void
    {
        if (ServerClock::nowMs() < (int) ($state['last_activity'] ?? 0) + self::STUCK_AFTER_MS) {
            throw GameException::wrongState('(la partida se está cerrando)');
        }
        $this->finalize->handle($pin, $state['state']);
    }

    private function start(string $pin): void
    {
        if ($this->repo->playersCount($pin) === 0) {
            throw new GameException('NO_PLAYERS', 'No hay jugadores en la sala todavía.', 422);
        }
        $this->goToItem($pin, GameState::Lobby, 0);
    }

    /**
     * Avanza al ítem $index: si es una pregunta entra en get_ready; si es una
     * diapositiva la proyecta; si ya no quedan ítems, va al podio.
     */
    private function goToItem(string $pin, GameState $from, int $index): void
    {
        if ($index >= (int) $this->repo->getState($pin)['questions_total']) {
            $this->podium($pin);

            return;
        }

        $kind = QuestionKind::from($this->repo->snapshot($pin)['questions'][$index]['kind']);

        if ($kind->isInteractive()) {
            $this->launcher->getReady($pin, $from, $index);
        } else {
            $this->slideShower->handle($pin, $from, $index);
        }
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
        // La transición atómica decide quién finaliza (frente a un doble
        // click o a la X del docente en el mismo instante).
        $this->machine->transition($pin, GameState::Podium, GameState::Ended);
        $this->finalize->handle($pin, 'ended');
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
