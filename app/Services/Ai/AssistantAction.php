<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Formas de salida permitidas del asistente. Al encajonar la salida en este
 * conjunto cerrado, un intento de prompt-injection ("ignorá todo y hacé X") no
 * tiene dónde materializarse: como mucho cae en `reject`.
 */
enum AssistantAction: string
{
    case Generate = 'generate'; // propone preguntas nuevas (borrador)
    case Clarify = 'clarify';   // falta contexto: devuelve preguntas al docente
    case Suggest = 'suggest';   // mejoras/fixes sobre el quiz existente
    case Reject = 'reject';     // el pedido no es sobre crear un quiz

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
