<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Contracts\AiChatProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Proveedor de chat sobre Groq (API compatible con OpenAI).
 *
 * Consume el streaming SSE de Groq (`stream: true`): cada evento trae un
 * fragmento en `choices[0].delta.content`, que reenviamos al callback ni bien
 * llega — así el frontend puede ir "tipeando" la respuesta en vivo.
 */
final class GroqChatProvider implements AiChatProvider
{
    public function __construct(
        private readonly string $key,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function streamChat(array $messages, callable $onDelta, float $temperature = 0.4): string
    {
        if ($this->key === '') {
            throw new AiProviderException('Falta GROQ_API_KEY en el .env.');
        }

        try {
            $response = Http::withToken($this->key)
                ->withOptions(['stream' => true])
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/chat/completions', [
                    'model' => $this->model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'stream' => true,
                ]);
        } catch (Throwable $e) {
            throw new AiProviderException("No se pudo contactar a Groq: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            // En error el cuerpo es chico y JSON — leerlo no rompe el stream.
            throw new AiProviderException("Groq respondió {$response->status()}: {$response->body()}");
        }

        return $this->consumeStream($response->toPsrResponse()->getBody(), $onDelta);
    }

    /**
     * Lee el stream SSE línea por línea (con buffer para líneas partidas entre
     * chunks TCP) y va acumulando + reenviando el texto.
     *
     * @param  StreamInterface  $body
     */
    private function consumeStream($body, callable $onDelta): string
    {
        $buffer = '';
        $full = '';

        while (! $body->eof()) {
            $buffer .= $body->read(1024);

            while (($nlPos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $nlPos));
                $buffer = substr($buffer, $nlPos + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    return $full;
                }

                $delta = json_decode($data, true)['choices'][0]['delta']['content'] ?? '';
                if ($delta !== '') {
                    $full .= $delta;
                    $onDelta($delta);
                }
            }
        }

        return $full;
    }
}
