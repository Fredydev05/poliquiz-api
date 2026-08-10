<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Contracts\AiChatProvider;
use App\Services\Ai\GeneratedQuestionValidator;
use App\Services\Ai\GroqChatProvider;
use App\Services\Ai\QuizAssistantPrompt;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Cablea el asistente de IA. El proveedor concreto se elige por config
 * (AI_PROVIDER); sumar OpenAI/Claude es agregar un case acá — nada más del
 * dominio (prompt, validación, controller) se entera.
 */
final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AiChatProvider::class, function (): AiChatProvider {
            $provider = config('services.ai_assistant.provider');

            return match ($provider) {
                'groq' => new GroqChatProvider(
                    key: (string) config('services.groq.key'),
                    baseUrl: rtrim((string) config('services.groq.base_url'), '/'),
                    model: (string) config('services.groq.model'),
                    timeout: (int) config('services.groq.timeout'),
                ),
                default => throw new InvalidArgumentException("Proveedor de IA no soportado: {$provider}"),
            };
        });

        $maxQuestions = (int) config('services.ai_assistant.max_questions', 10);

        $this->app->bind(
            QuizAssistantPrompt::class,
            fn () => new QuizAssistantPrompt($maxQuestions),
        );
        $this->app->bind(
            GeneratedQuestionValidator::class,
            fn () => new GeneratedQuestionValidator($maxQuestions),
        );
    }
}
