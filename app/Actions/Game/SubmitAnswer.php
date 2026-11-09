<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Auth\GuestPlayer;
use App\Enums\GameState;
use App\Enums\QuestionKind;
use App\Exceptions\GameException;
use App\Services\Game\GameRepository;
use App\Services\Game\QuestionTypes\QuestionTypeResolver;
use App\Services\Game\ServerClock;

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
            // Si ESA pregunta se acaba de cerrar, es una respuesta tardía.
            throw ($state['question_uuid'] ?? '') === $questionUuid
                ? GameException::timeExpired()
                : GameException::wrongState('No hay una pregunta en juego.');
        }
        if (($state['question_uuid'] ?? '') !== $questionUuid || (int) $state['current_index'] !== $questionIndex) {
            throw new GameException('STALE_QUESTION', 'Esa pregunta ya no está en juego.');
        }

        $nowMs = ServerClock::nowMs();
        $endsAt = (int) $state['ends_at'];
        if ($nowMs > $endsAt + (int) config('poliquiz.answer_grace_ms')) {
            throw GameException::timeExpired();
        }

        // La estrategia del tipo valida y normaliza la respuesta (ids de opción,
        // texto o un orden), y lanza GameException si es inválida.
        $question = $this->repo->snapshot($pin)['questions'][$questionIndex];
        $answerFields = $this->types->for(QuestionKind::from($question['kind']))
            ->normalizeAnswer($payload, $question);

        // Los controles de arriba dan mensajes claros; el AUTORITATIVO es este:
        // se registra atómicamente solo si la pregunta sigue abierta. Así una
        // respuesta nunca entra después de que el cierre leyó las respuestas.
        $result = $this->repo->recordAnswer($pin, $questionIndex, $questionUuid, $player->uuid, [
            ...$answerFields,
            'received_at' => $nowMs,
            'response_ms' => max(0, $nowMs - (int) $state['question_started_at']),
        ]);

        match ($result) {
            GameRepository::ANSWER_RECORDED => null,
            GameRepository::ANSWER_DUPLICATE => throw new GameException('ALREADY_ANSWERED', 'Ya respondiste esta pregunta.'),
            GameRepository::ANSWER_NOT_IN_ROOM => throw new GameException('NOT_IN_ROOM', 'Ya no estás en esta sala.', 401),
            default => throw GameException::timeExpired(),
        };

        $this->closeQuestion->closeIfEveryoneAnswered($pin, $state);
    }
}
