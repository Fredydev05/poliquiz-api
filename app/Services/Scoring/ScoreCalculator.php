<?php

declare(strict_types=1);

namespace App\Services\Scoring;

use App\Enums\PointsMode;

/**
 * Fórmula de puntos estilo Kahoot (plan §6):
 *   base = round((1 − (response_ms / time_limit_ms) / 2) × 1000)  → 500..1000
 *   double ×2 · none = 0 (registra el acierto igual)
 *   racha: +100 × nivel desde la 2ª correcta consecutiva, tope +500
 */
class ScoreCalculator
{
    private const BASE_MAX = 1000;

    private const STREAK_BONUS_STEP = 100;

    private const STREAK_BONUS_CAP = 500;

    /**
     * @param  int  $streakBefore  racha ANTES de esta pregunta
     * @return array{points:int, streak:int} puntos totales y racha resultante
     */
    public function score(
        bool $isCorrect,
        int $responseMs,
        int $timeLimitMs,
        PointsMode $mode,
        int $streakBefore,
    ): array {
        if (! $isCorrect) {
            return ['points' => 0, 'streak' => 0]; // la racha se corta
        }

        $streak = $streakBefore + 1;

        if ($mode === PointsMode::None) {
            return ['points' => 0, 'streak' => $streak];
        }

        // Velocidad: responder al instante vale 1000, en el límite vale 500.
        $ratio = min(1.0, max(0.0, $responseMs / max(1, $timeLimitMs)));
        $base = (int) round((1 - $ratio / 2) * self::BASE_MAX);

        // Bonus de racha: aplica desde la 2ª correcta consecutiva.
        $bonus = min(($streak - 1) * self::STREAK_BONUS_STEP, self::STREAK_BONUS_CAP);

        return [
            'points' => $base * $mode->multiplier() + $bonus,
            'streak' => $streak,
        ];
    }
}
