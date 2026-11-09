<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipos de pregunta. El enum es la fuente única de sus reglas (opciones,
 * correctas, si puntúa); cada tipo tiene su estrategia en QuestionTypes/.
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
    case WordCloud = 'wordcloud'; // el alumno escribe 1 palabra corta → nube; no puntúa
    case Open = 'open';           // respuesta libre (hasta 250 car.) → muro; no puntúa
    case Brainstorm = 'brainstorm'; // hasta 3 ideas por alumno → tablero agrupado; no puntúa

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
            self::Poll, self::Slide, self::WordCloud, self::Open, self::Brainstorm => false,
        };
    }

    /** Forma de la respuesta del jugador (guía al frontend). */
    public function answerShape(): string
    {
        return match ($this) {
            self::Quiz, self::TrueFalse, self::Multi, self::Poll => 'options',
            self::Short, self::WordCloud, self::Open => 'text',
            self::Brainstorm => 'ideas',
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
            self::Poll, self::Slide, self::WordCloud, self::Open, self::Brainstorm => [0, 0],
        };
    }

    /** ¿Cuántas opciones admite este tipo? [min, max] (short: aceptadas · puzzle: piezas) */
    public function optionRange(): array
    {
        return match ($this) {
            self::TrueFalse => [2, 2],
            self::Quiz, self::Multi, self::Poll, self::Puzzle => [2, 4],
            self::Short => [1, 4],
            // Respuesta libre: no hay opciones que cargar.
            self::Slide, self::WordCloud, self::Open, self::Brainstorm => [0, 0],
        };
    }
}
