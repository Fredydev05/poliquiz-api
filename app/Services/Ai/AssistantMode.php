<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Quiz;

/**
 * Postura del asistente, decidida por el ESTADO del quiz (no por el prompt):
 *  - Create → el quiz está vacío: espera una descripción para generar.
 *  - Assist → ya tiene preguntas: lee ese contexto y sugiere/corrige.
 */
enum AssistantMode: string
{
    case Create = 'create';
    case Assist = 'assist';

    public static function forQuiz(Quiz $quiz): self
    {
        return $quiz->questions()->exists() ? self::Assist : self::Create;
    }
}
