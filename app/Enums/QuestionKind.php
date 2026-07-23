<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de pregunta jugables en v1 (todos con respuesta correcta y puntos).
 * Los tipos de opinión (encuesta, nube, etc.) llegan en v2: el enum es el
 * único lugar a extender junto con sus reglas de validación.
 */
enum QuestionKind: string
{
    case Quiz = 'quiz';           // 2-4 opciones, exactamente 1 correcta
    case TrueFalse = 'true_false'; // 2 opciones fijas, 1 correcta
    case Multi = 'multi';         // 2-4 opciones, 1 o más correctas

    /** ¿Cuántas correctas exige este tipo? [min, max] */
    public function correctRange(): array
    {
        return match ($this) {
            self::Quiz, self::TrueFalse => [1, 1],
            self::Multi => [1, 4],
        };
    }

    /** ¿Cuántas opciones admite este tipo? [min, max] */
    public function optionRange(): array
    {
        return match ($this) {
            self::TrueFalse => [2, 2],
            self::Quiz, self::Multi => [2, 4],
        };
    }
}
