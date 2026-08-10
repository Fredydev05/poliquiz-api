<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\AssistantRequest;
use App\Models\Quiz;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\QuizAssistant;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asistente de IA para crear/mejorar quizzes.
 *
 * Responde con SSE (text/event-stream): primero eventos "token" con la prosa a
 * medida que la IA la genera (efecto tipeando), y al final un evento "result"
 * con el payload estructurado YA validado (preguntas/sugerencias/aclaraciones).
 * Los errores del proveedor se emiten como evento "error" (el status ya es 200
 * una vez abierto el stream) — la validación y autorización, en cambio, cortan
 * antes con 422/403 normales.
 */
final class QuizAssistantController extends Controller
{
    public function __invoke(AssistantRequest $request, Quiz $quiz, QuizAssistant $assistant): StreamedResponse
    {
        $message = (string) $request->validated('message');

        return response()->stream(function () use ($quiz, $message, $assistant): void {
            $this->send('start', ['quiz_id' => $quiz->id]);

            try {
                $reply = $assistant->assist(
                    $quiz,
                    $message,
                    fn (string $prose) => $this->send('token', ['text' => $prose]),
                );

                $this->send('result', $reply->toArray());
            } catch (AiProviderException $e) {
                Log::warning('Asistente IA falló', ['quiz' => $quiz->id, 'error' => $e->getMessage()]);
                $this->send('error', ['message' => 'La IA no está disponible en este momento. Probá de nuevo en un rato.']);
            }

            $this->send('done', []);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no', // que nginx no bufferee el stream
        ]);
    }

    /**
     * Emite un evento SSE y fuerza el flush para que llegue al instante.
     *
     * @param  array<string, mixed>  $data
     */
    private function send(string $type, array $data): void
    {
        echo 'data: '.json_encode(['type' => $type, ...$data], JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
}
