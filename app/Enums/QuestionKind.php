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
    case Poll = 'poll';           // 2-4 opciones, SIN correcta, no puntúa (encuesta)
    case Short = 'short';         // el alumno ESCRIBE; las "opciones" son respuestas aceptadas
    case Puzzle = 'puzzle';       // ORDENAR piezas; las opciones van en el orden correcto
    case Slide = 'slide';         // no interactiva: solo se proyecta, sin timer ni puntos

    /** ¿Recibe respuestas y corre timer? (una diapositiva no). */
    public function isInteractive(): bool
    {
        return $this !== self::Slide;
    }

    /** ¿Suma puntos? (encuesta y diapositiva no). */
    public function isScored(): bool
    {
        return match ($this) {
            self::Quiz, self::TrueFalse, self::Multi, self::Short, self::Puzzle => true,
            self::Poll, self::Slide => false,
        };
    }

    /** Forma de la respuesta del jugador (guía al frontend). */
    public function answerShape(): string
    {
        return match ($this) {
            self::Quiz, self::TrueFalse, self::Multi, self::Poll => 'options',
            self::Short => 'text',
            self::Puzzle => 'order',
            self::Slide => 'none',
        };
    }

    /** ¿Cuántas correctas exige este tipo? [min, max] (short: aceptadas · puzzle: n/a) */
    public function correctRange(): array
    {
        return match ($this) {
            self::Quiz, self::TrueFalse => [1, 1],
            self::Multi, self::Short => [1, 4],
            self::Puzzle => [0, 4], // el orden es la respuesta, no un flag "correcta"
            self::Poll, self::Slide => [0, 0],
        };
    }

    /** ¿Cuántas opciones admite este tipo? [min, max] (short: aceptadas · puzzle: piezas) */
    public function optionRange(): array
    {
        return match ($this) {
            self::TrueFalse => [2, 2],
            self::Quiz, self::Multi, self::Poll, self::Puzzle => [2, 4],
            self::Short => [1, 4],
            self::Slide => [0, 0],
        };
    }
}
