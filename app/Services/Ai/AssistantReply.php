<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Resultado estructurado y YA VALIDADO del asistente, listo para el frontend.
 *
 * @param  array<int, array<string, mixed>>  $questions  Preguntas válidas (action=generate).
 * @param  array<int, string>  $clarifications  Preguntas al docente (action=clarify).
 * @param  array<int, array<string, mixed>>  $suggestions  Sugerencias (action=suggest).
 */
final readonly class AssistantReply
{
    public function __construct(
        public AssistantMode $mode,
        public AssistantAction $action,
        public string $message,
        public array $questions = [],
        public array $clarifications = [],
        public array $suggestions = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'action' => $this->action->value,
            'message' => $this->message,
            'questions' => $this->questions,
            'clarifications' => $this->clarifications,
            'suggestions' => $this->suggestions,
        ];
    }
}
