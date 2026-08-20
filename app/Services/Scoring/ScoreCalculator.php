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
     * @param  float  $ratio  qué tan correcta fue la respuesta, 0..1. Binario en
     *                        la mayoría de tipos (0 o 1); parcial en puzzle
     *                        (piezas en su lugar / total). La racha SOLO sigue
     *                        con acierto completo (ratio = 1).
     * @param  int  $streakBefore  racha ANTES de esta pregunta
     * @return array{points:int, streak:int} puntos totales y racha resultante
     */
    public function score(
        float $ratio,
        int $responseMs,
        int $timeLimitMs,
        PointsMode $mode,
        int $streakBefore,
    ): array {
        $ratio = min(1.0, max(0.0, $ratio));
        if ($ratio <= 0.0) {
            return ['points' => 0, 'streak' => 0]; // la racha se corta
        }

        // La racha solo avanza con acierto COMPLETO; un parcial suma puntos
        // pero no continúa la racha.
        $streak = $ratio >= 1.0 ? $streakBefore + 1 : 0;

        if ($mode === PointsMode::None) {
            return ['points' => 0, 'streak' => $streak];
        }

        // Velocidad: responder al instante vale 1000, en el límite vale 500.
        $speed = min(1.0, max(0.0, $responseMs / max(1, $timeLimitMs)));
        $base = (int) round((1 - $speed / 2) * self::BASE_MAX * $ratio);

        // Bonus de racha: aplica desde la 2ª correcta consecutiva (solo si la
        // racha sigue viva, o sea con acierto completo).
        $bonus = $streak >= 1 ? min(($streak - 1) * self::STREAK_BONUS_STEP, self::STREAK_BONUS_CAP) : 0;

        return [
            'points' => $base * $mode->multiplier() + $bonus,
            'streak' => $streak,
        ];
    }
}
