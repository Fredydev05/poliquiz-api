<?php

declare(strict_types=1);

namespace App\Services\Game\QuestionTypes;

use App\Exceptions\GameException;

/**
 * Diapositiva: no es una pregunta, es contenido que el docente proyecta entre
 * preguntas para explicar o dar contexto. No recibe respuestas, no corre timer
 * y no puntúa; el host la deja en pantalla y avanza con "Siguiente".
 */
final class SlideItem implements QuestionType
{
    public function isInteractive(): bool
    {
        return false;
    }

    public function isScored(): bool
    {
        return false;
    }

    public function normalizeAnswer(array $payload, array $question): array
    {
        throw new GameException('NOT_INTERACTIVE', 'Una diapositiva no recibe respuestas.', 422);
    }

    public function grade(array $answer, array $question): array
    {
        return ['ratio' => 0.0, 'correct' => false];
    }

    public function playerView(array $question): array
    {
        return [
            'kind' => $question['kind'],
            'title' => $question['title'],
            'body' => $question['body'] ?? null,
            'image_url' => $question['image_url'] ?? null,
        ];
    }

    public function reveal(array $answers, array $question): array
    {
        return ['kind' => 'slide']; // una diapositiva no llega a revelarse como pregunta
    }
}
