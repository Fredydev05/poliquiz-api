<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Auth\GuestPlayer;
use App\Enums\GameState;
use App\Enums\QuestionKind;
use App\Events\Game\AnswerReceived;
use App\Exceptions\GameException;
use App\Services\Game\GameRepository;
use App\Services\Game\QuestionTypes\QuestionTypeResolver;

/**
 * Registra la respuesta de un jugador con validación server-side completa
 * (plan §6): estado, pregunta vigente, ventana de tiempo y unicidad.
 * El tiempo de respuesta se mide con el reloj DEL SERVIDOR.
 *
 * La forma de la respuesta (opciones, texto, orden) la valida la estrategia
 * del tipo de pregunta — este action solo hace los controles comunes.
 */
class SubmitAnswer
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly CloseQuestion $closeQuestion,
        private readonly QuestionTypeResolver $types,
    ) {}

    /** @param  array<string, mixed>  $payload  respuesta cruda del jugador (según tipo) */
    public function handle(GuestPlayer $player, string $pin, int $questionIndex, string $questionUuid, array $payload): void
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

        // La estrategia del tipo valida y normaliza la respuesta (ids de opción,
        // texto o un orden), y lanza GameException si es inválida.
        $question = $this->repo->snapshot($pin)['questions'][$questionIndex];
        $answerFields = $this->types->for(QuestionKind::from($question['kind']))
            ->normalizeAnswer($payload, $question);

        $recorded = $this->repo->recordAnswer($pin, $questionIndex, $player->uuid, [
            ...$answerFields,
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
