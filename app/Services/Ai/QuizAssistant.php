<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Quiz;
use App\Services\Ai\Contracts\AiChatProvider;

/**
 * Orquesta una consulta al asistente de IA de punta a punta:
 *   modo → prompt → streaming (filtrando el JSON de la prosa) → parseo → validación.
 *
 * La prosa se reenvía en vivo por el callback $onProse (efecto "tipeando"); el
 * bloque JSON del final se retiene, se parsea y se valida contra las reglas del
 * quiz, y vuelve como un AssistantReply ya seguro para el frontend.
 */
final class QuizAssistant
{
    public function __construct(
        private readonly AiChatProvider $provider,
        private readonly QuizAssistantPrompt $prompt,
        private readonly GeneratedQuestionValidator $questionValidator,
    ) {}

    /**
     * @param  callable(string $prose): void  $onProse  Recibe la prosa a medida que llega.
     */
    public function assist(Quiz $quiz, string $message, callable $onProse): AssistantReply
    {
        $mode = AssistantMode::forQuiz($quiz);
        $messages = $this->prompt->build($mode, $quiz, $message);

        $sentinel = QuizAssistantPrompt::SENTINEL;
        $running = '';
        $emitted = 0;      // cuánta prosa ya se reenvió
        $sentinelAt = null; // posición del separador (una vez visto)

        // Wrapper de streaming: reenvía solo la prosa (antes del separador),
        // reteniendo una cola del tamaño del separador por si quedó partido
        // entre dos chunks TCP.
        $this->provider->streamChat($messages, function (string $delta) use (
            &$running, &$emitted, &$sentinelAt, $sentinel, $onProse
        ): void {
            $running .= $delta;
            if ($sentinelAt !== null) {
                return; // ya pasamos a zona JSON: nada más que tipear
            }

            $pos = strpos($running, $sentinel);
            if ($pos !== false) {
                if ($pos > $emitted) {
                    $onProse(substr($running, $emitted, $pos - $emitted));
                }
                $emitted = $pos;
                $sentinelAt = $pos;

                return;
            }

            $safeEnd = strlen($running) - (strlen($sentinel) - 1);
            // No cortar un carácter UTF-8 a la mitad (rompería json_encode del
            // fragmento y se perdería texto, p.ej. una "é" partida entre chunks).
            while ($safeEnd > $emitted && (ord($running[$safeEnd]) & 0xC0) === 0x80) {
                $safeEnd--;
            }
            if ($safeEnd > $emitted) {
                $onProse(substr($running, $emitted, $safeEnd - $emitted));
                $emitted = $safeEnd;
            }
        });

        // Si nunca apareció el separador, todo era prosa: vaciamos la cola.
        if ($sentinelAt === null && strlen($running) > $emitted) {
            $onProse(substr($running, $emitted));
        }

        return $this->buildReply($mode, $running, $sentinelAt, $sentinel);
    }

    private function buildReply(AssistantMode $mode, string $full, ?int $sentinelAt, string $sentinel): AssistantReply
    {
        $prose = trim($sentinelAt === null ? $full : substr($full, 0, $sentinelAt));
        $json = $sentinelAt === null
            ? null
            : $this->extractJson(substr($full, $sentinelAt + strlen($sentinel)));

        $action = AssistantAction::tryFromString($json['action'] ?? null);

        // Sin JSON válido o sin acción reconocida: mostramos la prosa, sin payload.
        if ($json === null || $action === null) {
            return new AssistantReply($mode, AssistantAction::Reject, $prose !== '' ? $prose : 'No pude generar una respuesta válida. Reformulá el pedido, por favor.');
        }

        return match ($action) {
            AssistantAction::Generate => new AssistantReply(
                $mode, $action, $prose,
                questions: $this->questionValidator->valid($json['questions'] ?? null),
            ),
            AssistantAction::Clarify => new AssistantReply(
                $mode, $action, $prose,
                clarifications: $this->sanitizeClarifications($json['clarifications'] ?? null),
            ),
            AssistantAction::Suggest => new AssistantReply(
                $mode, $action, $prose,
                suggestions: $this->sanitizeSuggestions($json['suggestions'] ?? null),
            ),
            AssistantAction::Reject => new AssistantReply($mode, $action, $prose),
        };
    }

    /**
     * Extrae el primer objeto JSON balanceado del texto (tolera fences ``` y
     * texto suelto alrededor).
     *
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array<int, string> */
    private function sanitizeClarifications($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($q) => is_string($q) && trim($q) !== '')
            ->map(fn ($q) => mb_substr(trim($q), 0, 200))
            ->take(5)
            ->values()
            ->all();
    }

    /** @return array<int, array{question_index: int, type: string, message: string}> */
    private function sanitizeSuggestions($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->filter(fn ($s) => is_array($s) && isset($s['message']) && trim((string) $s['message']) !== '')
            ->map(fn ($s) => [
                'question_index' => max(0, (int) ($s['question_index'] ?? 0)),
                'type' => mb_substr(trim((string) ($s['type'] ?? 'sugerencia')), 0, 40),
                'message' => mb_substr(trim((string) $s['message']), 0, 300),
            ])
            ->take(20)
            ->values()
            ->all();
    }
}
