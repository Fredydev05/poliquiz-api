<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Enums\PointsMode;
use App\Enums\QuestionKind;
use App\Events\Game\AnswerReceived;
use App\Events\Game\QuestionEnded;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;
use App\Services\Game\QuestionTypes\QuestionType;
use App\Services\Game\QuestionTypes\QuestionTypeResolver;
use App\Services\Game\ServerClock;
use App\Services\Scoring\ScoreCalculator;
use Throwable;

/**
 * Cierre AUTORITATIVO de la pregunta en juego (por timeout o por
 * "todos respondieron"). Idempotente y seguro ante concurrencia:
 *
 *  1. question → results es un compare-and-set atómico: un solo cierre gana.
 *  2. Puntuar y revelar lo hace quien tiene el candado de cierre (con dueño y
 *     vencimiento en Redis: si el proceso muere, se libera solo).
 *  3. Los puntajes se aplican en un único paso atómico, marcado con el
 *     question_uuid: pase lo que pase, se suman UNA sola vez.
 *  4. Si algo falla después del paso 1 (p. ej. se cae el broadcast), la
 *     pregunta queda "cerrada pero sin revelar" y el CloseQuestionJob la
 *     retoma en su reintento (modo $resume) hasta emitir QuestionEnded.
 */
class CloseQuestion
{
    public const CLOSED = 'closed';

    public const NOOP = 'noop';

    /** Otro proceso tiene el candado de cierre ahora mismo: reintentar luego. */
    public const BUSY = 'busy';

    /** Vencimiento del candado si su dueño muere sin liberarlo. */
    private const LEASE_MS = 15000;

    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameStateMachine $machine,
        private readonly ScoreCalculator $calculator,
        private readonly QuestionTypeResolver $types,
    ) {}

    /**
     * @param  bool  $resume  true desde el job (o el docente destrabando): si la
     *                        pregunta quedó cerrada a medias, termina el trabajo.
     * @return string CLOSED | NOOP | BUSY
     */
    public function handle(string $pin, string $questionUuid, bool $resume = false): string
    {
        $state = $this->repo->getState($pin);
        if ($state === null || ($state['question_uuid'] ?? '') !== $questionUuid) {
            return self::NOOP; // pregunta vieja o sala destruida
        }

        // Resolvemos todo lo del tipo ANTES de tomar el candado: si algo acá
        // fallara, el estado sigue en 'question' y el reintento puede cerrar.
        $index = (int) $state['current_index'];
        $question = $this->repo->snapshot($pin)['questions'][$index];
        $type = $this->types->for(QuestionKind::from($question['kind']));

        $version = $this->machine->tryTransition($pin, GameState::Question, GameState::Results, [
            'closed_at' => (string) ServerClock::nowMs(),
        ]);

        if ($version === false) {
            if (! $resume) {
                return self::NOOP; // otro cierre ganó: él termina el trabajo
            }

            // Solo se retoma mientras la sala sigue en el resultado de ESTA
            // pregunta: si se está cerrando (ended/aborted) no tocamos
            // puntajes que otro proceso podría estar persistiendo.
            $state = $this->repo->getState($pin);
            if (! $this->pendingReveal($state, $questionUuid)) {
                return self::NOOP;
            }
            $version = (int) $state['state_version'];
        }

        $lease = $this->repo->acquireLease("close:$pin:$questionUuid", self::LEASE_MS);
        if ($lease === null) {
            // Otro proceso está puntuando/revelando: el job vuelve a mirar
            // después; un cierre inline simplemente se retira.
            return $resume ? self::BUSY : self::NOOP;
        }

        try {
            // Pudo terminar entre nuestra lectura y el candado.
            if (! $this->pendingReveal($this->repo->getState($pin), $questionUuid)) {
                return self::NOOP;
            }

            // Solo cuentan los jugadores que siguen en la sala (un expulsado no
            // puntúa ni aparece en la distribución).
            $players = $this->repo->players($pin);
            $answers = array_intersect_key($this->repo->answers($pin, $index), $players);

            $this->score($pin, $index, $questionUuid, $question, $type, $players, $answers);

            QuestionEnded::dispatch($pin, $version, $index, $type->reveal($answers, $question));
            $this->repo->updateState($pin, ['revealed_question' => $questionUuid]);

            return self::CLOSED;
        } finally {
            $this->repo->releaseLease("close:$pin:$questionUuid", $lease);
        }
    }

    /**
     * Avisa el progreso de respuestas y, si ya respondió todo el roster,
     * adelanta el cierre. Lo usan la respuesta de un jugador y la expulsión
     * (sacar al último que faltaba también completa la ronda).
     *
     * Es O(1): la expulsión borra la respuesta del expulsado en el mismo paso
     * atómico, así que los dos contadores de Redis ya son exactos.
     *
     * El cierre adelantado es best-effort: si falla, el CloseQuestionJob por
     * timeout lo retoma, así que no le devolvemos el error a quien respondió.
     *
     * @param  array<string,string>|null  $state  estado ya leído (evita releerlo)
     */
    public function closeIfEveryoneAnswered(string $pin, ?array $state = null): void
    {
        $state ??= $this->repo->getState($pin);
        if ($state === null || $state['state'] !== GameState::Question->value) {
            return;
        }

        $total = $this->repo->playersCount($pin);
        $answered = min($total, $this->repo->answersCount($pin, (int) $state['current_index']));

        try {
            AnswerReceived::dispatch($pin, $answered, $total); // contador del host: cosmético
        } catch (Throwable $e) {
            report($e);
        }

        if ($answered >= $total) {
            try {
                $this->handle($pin, $state['question_uuid']);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** ¿La sala está en el resultado de ESTA pregunta y todavía sin revelar? */
    private function pendingReveal(?array $state, string $questionUuid): bool
    {
        return $state !== null
            && $state['state'] === GameState::Results->value
            && ($state['question_uuid'] ?? '') === $questionUuid
            && ($state['revealed_question'] ?? '') !== $questionUuid;
    }

    /**
     * Calcula puntos y rachas de TODOS los jugadores (los que no respondieron
     * cortan su racha) y los aplica en un único paso atómico e idempotente.
     */
    private function score(string $pin, int $index, string $questionUuid, array $question, QuestionType $type, array $players, array $answers): void
    {
        $timeLimitMs = $question['time_limit'] * 1000;
        $mode = PointsMode::from($question['points_mode']);
        $scored = $type->isScored();

        $newPlayers = [];
        $enriched = [];

        foreach ($players as $uuid => $player) {
            $answer = $answers[$uuid] ?? null;

            ['ratio' => $ratio, 'correct' => $isCorrect] = $answer !== null
                ? $type->grade($answer, $question)
                : ['ratio' => 0.0, 'correct' => false];

            // Un tipo no puntuable (encuesta) no suma ni corta la racha: no hay
            // respuesta "incorrecta" en una opinión.
            ['points' => $points, 'streak' => $streak] = $scored
                ? $this->calculator->score(
                    $ratio,
                    $answer['response_ms'] ?? $timeLimitMs,
                    $timeLimitMs,
                    $mode,
                    (int) $player['streak'],
                )
                : ['points' => 0, 'streak' => (int) $player['streak']];

            $newPlayers[$uuid] = [
                ...$player,
                'score' => (int) $player['score'] + $points,
                'streak' => $streak,
                'last_gain' => $points,
                'last_correct' => $isCorrect,
                'last_ratio' => $ratio,
                'last_scored' => $scored,
                'last_answered' => $answer !== null,
            ];

            // Respuesta enriquecida (para /me y la persistencia en Postgres).
            if ($answer !== null) {
                $enriched[$uuid] = [
                    ...$answer,
                    'is_correct' => $isCorrect,
                    'points' => $points,
                    'streak_after' => $streak,
                ];
            }
        }

        $this->repo->applyScores($pin, $index, $questionUuid, $newPlayers, $enriched);
    }
}
