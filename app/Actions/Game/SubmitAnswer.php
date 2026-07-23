<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Auth\GuestPlayer;
use App\Enums\GameState;
use App\Events\Game\AnswerReceived;
use App\Exceptions\GameException;
use App\Services\Game\GameRepository;

/**
 * Registra la respuesta de un jugador con validación server-side completa
 * (plan §6): estado, pregunta vigente, ventana de tiempo y unicidad.
 * El tiempo de respuesta se mide con el reloj DEL SERVIDOR.
 */
class SubmitAnswer
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly CloseQuestion $closeQuestion,
    ) {}

    /** @param  int[]  $selected  ids de opciones elegidas */
    public function handle(GuestPlayer $player, string $pin, int $questionIndex, string $questionUuid, array $selected): void
    {
        $state = $this->repo->getState($pin) ?? throw GameException::pinNotFound();

        if ($state['state'] !== GameState::Question->value) {
            throw GameException::wrongState('No hay una pregunta en juego.');
        }
        if (($state['question_uuid'] ?? '') !== $questionUuid || (int) $state['current_index'] !== $questionIndex) {
            throw new GameException('STALE_QUESTION', 'Esa pregunta ya no está en juego.');
        }

        $nowMs = (int) (microtime(true) * 1000);
        $endsAt = (int) $state['ends_at'];
        if ($nowMs > $endsAt + (int) config('poliquiz.answer_grace_ms')) {
            throw new GameException('TIME_EXPIRED', 'El tiempo de esta pregunta ya terminó.', 422);
        }

        // Las opciones deben existir en la pregunta vigente.
        $question = $this->repo->snapshot($pin)['questions'][$questionIndex];
        $validIds = array_map(fn (array $o) => $o['id'], $question['options']);
        $selected = array_values(array_unique(array_map(intval(...), $selected)));
        if ($selected === [] || array_diff($selected, $validIds) !== []) {
            throw new GameException('INVALID_OPTIONS', 'Opciones inválidas para esta pregunta.', 422);
        }

        $recorded = $this->repo->recordAnswer($pin, $questionIndex, $player->uuid, [
            'selected' => $selected,
            'received_at' => $nowMs,
            'response_ms' => max(0, $nowMs - (int) $state['question_started_at']),
        ]);
        if (! $recorded) {
            throw new GameException('ALREADY_ANSWERED', 'Ya respondiste esta pregunta.');
        }

        $answered = $this->repo->answersCount($pin, $questionIndex);
        $total = $this->repo->playersCount($pin);
        AnswerReceived::dispatch($pin, $answered, $total);

        // Si respondieron todos, el cierre se adelanta (idempotente frente
        // al CloseQuestionJob que vendrá por timeout).
        if ($answered >= $total) {
            $this->closeQuestion->handle($pin, $questionUuid);
        }
    }
}
