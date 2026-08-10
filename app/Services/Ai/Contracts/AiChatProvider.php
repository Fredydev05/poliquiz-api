<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\Exceptions\AiProviderException;

/**
 * Contrato de un proveedor de chat con IA (Groq, OpenAI, Claude, …).
 *
 * Deliberadamente mínimo: recibe mensajes estilo chat y va entregando el texto
 * de a pedazos por un callback (streaming). Toda la lógica de negocio —
 * guardrails, armado del prompt, validación de la salida— vive fuera, en
 * QuizAssistant, para que cambiar de proveedor no toque nada de eso.
 */
interface AiChatProvider
{
    /**
     * Stream de una respuesta de chat.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  callable(string $delta): void  $onDelta  Se invoca por cada fragmento de texto.
     * @return string El texto completo acumulado (por comodidad del llamador).
     *
     * @throws AiProviderException
     */
    public function streamChat(array $messages, callable $onDelta, float $temperature = 0.4): string;
}
