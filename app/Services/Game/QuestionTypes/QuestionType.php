<?php

declare(strict_types=1);

namespace App\Services\Game\QuestionTypes;

use App\Exceptions\GameException;

/**
 * Estrategia por tipo de pregunta. Encapsula TODO lo que varía entre tipos:
 * cómo se valida la respuesta del jugador, cómo se califica, qué ve el jugador
 * (sin las correctas) y cómo se arma la distribución para el host.
 *
 * El game loop (SubmitAnswer, CloseQuestion) delega acá en vez de asumir que
 * una respuesta son ids de opción — así sumar "escribe la respuesta" o
 * "rompecabezas" no toca el core, solo agrega una implementación.
 */
interface QuestionType
{
    /** Slide = false: no recibe respuestas ni corre timer. */
    public function isInteractive(): bool;

    /** Poll/Slide = false: no suma puntos. */
    public function isScored(): bool;

    /**
     * Valida el payload del jugador y devuelve los campos de la respuesta a
     * persistir (sin el timing, que lo agrega SubmitAnswer).
     *
     * @param  array<string, mixed>  $payload  crudo del request
     * @param  array<string, mixed>  $question  pregunta del snapshot
     * @return array<string, mixed>
     *
     * @throws GameException si la respuesta es inválida
     */
    public function normalizeAnswer(array $payload, array $question): array;

    /**
     * Califica una respuesta ya normalizada.
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, mixed>  $question
     * @return array{ratio: float, correct: bool} ratio 0..1 (parcial en puzzle)
     */
    public function grade(array $answer, array $question): array;

    /**
     * Lo que viaja al JUGADOR: nunca incluye qué opción/orden es la correcta.
     *
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public function playerView(array $question): array;

    /**
     * Payload de REVELACIÓN para la pantalla del host: todo lo que necesita
     * para pintar los resultados de la pregunta, autocontenido y etiquetado con
     * `kind` (options | text | order). Acá es cuando SÍ se revela la correcta.
     *
     * @param  array<string, array<string, mixed>>  $answers  uuid => respuesta
     * @param  array<string, mixed>  $question
     * @return array<string, mixed>
     */
    public function reveal(array $answers, array $question): array;
}
