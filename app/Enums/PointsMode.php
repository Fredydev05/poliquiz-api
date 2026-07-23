<?php

declare(strict_types=1);

namespace App\Enums;

/** Modo de puntaje por pregunta (estilo Kahoot). */
enum PointsMode: string
{
    case Standard = 'standard'; // 500–1000 según velocidad
    case Double = 'double';     // ×2
    case None = 'none';         // registra acierto pero no suma

    public function multiplier(): int
    {
        return match ($this) {
            self::Standard => 1,
            self::Double => 2,
            self::None => 0,
        };
    }
}
