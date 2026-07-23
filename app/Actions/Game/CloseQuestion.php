<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Enums\PointsMode;
use App\Events\Game\QuestionEnded;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;
use App\Services\Scoring\ScoreCalculator;

/**
 * Cierre AUTORITATIVO de la pregunta en juego (por timeout o por
 * "todos respondieron"). Idempotente: dos cierres del mismo question_uuid
 * producen un solo efecto.
 *
 * Aquí se calculan puntos y rachas de TODOS los jugadores (los que no
 * respondieron cortan su racha) y se actualiza el leaderboard.
 */
class CloseQuestion
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameStateMachine $machine,
        private readonly ScoreCalculator $calculator,
    ) {}

    public function handle(string $pin, string $questionUuid): void
    {
        $state = $this->repo->getState($pin);
        if ($state === null || ($state['question_uuid'] ?? '') !== $questionUuid) {
            return; // pregunta vieja o sala destruida: no-op
        }

        // La transición atómica ES el candado de idempotencia: el segundo
        // cierre encuentra state=results y tryTransition devuelve false.
        $version = $this->machine->tryTransition($pin, GameState::Question, GameState::Results);
        if ($version === false) {
            return;
        }

        $index = (int) $state['current_index'];
        $question = $this->repo->snapshot($pin)['questions'][$index];
        $timeLimitMs = $question['time_limit'] * 1000;
        $mode = PointsMode::from($question['points_mode']);

        $correctIds = array_values(array_map(
            fn (array $o) => $o['id'],
            array_filter($question['options'], fn (array $o) => $o['is_correct']),
        ));

        $answers = $this->repo->answers($pin, $index);
        $distribution = [];

        // Puntúa a TODOS los jugadores del roster.
        foreach ($this->repo->players($pin) as $uuid => $player) {
            $answer = $answers[$uuid] ?? null;

            $isCorrect = false;
            if ($answer !== null) {
                $selected = array_map(intval(...), $answer['selected']);
                sort($selected);
                $isCorrect = $selected === $correctIds;

                foreach ($selected as $optionId) {
                    $distribution[$optionId] = ($distribution[$optionId] ?? 0) + 1;
                }
            }

            ['points' => $points, 'streak' => $streak] = $this->calculator->score(
                $isCorrect,
                $answer['response_ms'] ?? $timeLimitMs,
                $timeLimitMs,
                $mode,
                (int) $player['streak'],
            );

            $player['score'] = (int) $player['score'] + $points;
            $player['streak'] = $streak;
            $player['last_gain'] = $points;
            $player['last_correct'] = $isCorrect;
            $this->repo->updatePlayer($pin, $uuid, $player);
            $this->repo->setScore($pin, $uuid, $player['score']);

            // Enriquecer la respuesta (para /me y la persistencia de F6).
            if ($answer !== null) {
                $this->repo->enrichAnswer($pin, $index, $uuid, [
                    ...$answer,
                    'is_correct' => $isCorrect,
                    'points' => $points,
                    'streak_after' => $streak,
                ]);
            }
        }

        // Distribución completa: opciones sin votos van con 0.
        $fullDistribution = array_map(
            fn (array $o) => ['option_id' => $o['id'], 'count' => $distribution[$o['id']] ?? 0],
            $question['options'],
        );

        QuestionEnded::dispatch($pin, $version, $index, $correctIds, $fullDistribution);
    }
}
