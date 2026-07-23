<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Events\Game\GetReadyShown;
use App\Events\Game\QuestionStarted;
use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;
use Illuminate\Support\Str;

/**
 * Secuencia de lanzamiento de la pregunta $index:
 *  1) fase get_ready (3 s de countdown, broadcast GetReadyShown)
 *  2) StartQuestionJob (delay 3 s) → fase question, fija ends_at del reloj
 *     DEL SERVIDOR, broadcast QuestionStarted
 *  3) CloseQuestionJob (delay time_limit + margen) → cierre autoritativo
 */
class LaunchQuestion
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameStateMachine $machine,
    ) {}

    /** Paso 1 — entra en get_ready (desde lobby o leaderboard). */
    public function getReady(string $pin, GameState $from, int $index): void
    {
        $questionUuid = (string) Str::uuid();
        $readyMs = (int) config('poliquiz.get_ready_seconds') * 1000;

        $version = $this->machine->transition($pin, $from, GameState::GetReady, [
            'current_index' => (string) $index,
            'question_uuid' => $questionUuid,
        ]);

        $total = (int) $this->repo->getState($pin)['questions_total'];
        GetReadyShown::dispatch($pin, $version, $index, $total, $readyMs);

        StartQuestionJob::dispatch($pin, $questionUuid)
            ->delay(now()->addMilliseconds($readyMs));
    }

    /** Paso 2 — get_ready → question (lo ejecuta StartQuestionJob). */
    public function start(string $pin, string $questionUuid): void
    {
        $state = $this->repo->getState($pin);
        // Idempotencia: solo si seguimos en ESTA pregunta y en get_ready.
        if ($state === null || ($state['question_uuid'] ?? '') !== $questionUuid) {
            return;
        }

        $index = (int) $state['current_index'];
        $question = $this->repo->snapshot($pin)['questions'][$index] ?? null;
        if ($question === null) {
            return;
        }

        $nowMs = (int) (microtime(true) * 1000);
        $endsAtMs = $nowMs + $question['time_limit'] * 1000;

        $version = $this->machine->tryTransition($pin, GameState::GetReady, GameState::Question, [
            'question_started_at' => (string) $nowMs,
            'ends_at' => (string) $endsAtMs,
        ]);
        if ($version === false) {
            return;
        }

        QuestionStarted::dispatch(
            $pin,
            $version,
            $index,
            (int) $state['questions_total'],
            $questionUuid,
            $question,
            $endsAtMs,
        );

        // Cierre por tiempo (el "todos respondieron" puede adelantarlo).
        CloseQuestionJob::dispatch($pin, $questionUuid)
            ->delay(now()->addMilliseconds($question['time_limit'] * 1000 + 500));
    }
}
