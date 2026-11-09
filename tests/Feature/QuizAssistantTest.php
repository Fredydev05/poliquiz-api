<?php

declare(strict_types=1);

use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatProvider;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\QuizAssistantPrompt;

/*
 * Asistente de IA. Bindeamos un proveedor FALSO en el container: emite un
 * guion fijo (prosa + separador + JSON) por chunks chicos, así ejercitamos el
 * parseo/validación real SIN llamar a Groq ni depender de la red.
 */

/** Proveedor falso: reproduce un guion o lanza un error. */
function fakeProvider(?string $script = null, ?Throwable $throw = null): void
{
    app()->instance(AiChatProvider::class, new class($script, $throw) implements AiChatProvider
    {
        public function __construct(private ?string $script, private ?Throwable $throw) {}

        public function streamChat(array $messages, callable $onDelta, float $temperature = 0.4): string
        {
            if ($this->throw) {
                throw $this->throw;
            }
            // Chunks de 6 chars: fuerza que el separador quede partido entre eventos.
            foreach (str_split($this->script, 6) as $chunk) {
                $onDelta($chunk);
            }

            return $this->script;
        }
    });
}

/** Junta la prosa + JSON en el formato que espera el asistente. */
function guion(string $prose, array $json): string
{
    return $prose."\n".QuizAssistantPrompt::SENTINEL."\n".json_encode($json);
}

/** Parsea los eventos SSE del stream en una lista de arrays. */
function eventos(string $sse): array
{
    return collect(explode("\n\n", trim($sse)))
        ->filter(fn ($l) => str_starts_with($l, 'data: '))
        ->map(fn ($l) => json_decode(substr($l, 6), true))
        ->values()
        ->all();
}

function preguntaGenerada(array $overrides = []): array
{
    return array_merge([
        'kind' => 'quiz',
        'title' => '¿Capital de Paraguay?',
        'time_limit' => 20,
        'points_mode' => 'standard',
        'options' => [
            ['text' => 'Asunción', 'is_correct' => true],
            ['text' => 'Lima', 'is_correct' => false],
            ['text' => 'Quito', 'is_correct' => false],
        ],
    ], $overrides);
}

it('generates questions from a prompt on an empty quiz (create mode)', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    fakeProvider(guion('¡Listo! Preparé una pregunta.', [
        'action' => 'generate',
        'questions' => [preguntaGenerada()],
    ]));

    $sse = comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'Creá un quiz de geografía'])
        ->assertOk()
        ->streamedContent();

    $events = eventos($sse);
    $result = collect($events)->firstWhere('type', 'result');

    expect($result['mode'])->toBe('create');
    expect($result['action'])->toBe('generate');
    expect($result['questions'])->toHaveCount(1);
    expect($result['questions'][0]['title'])->toBe('¿Capital de Paraguay?');
});

it('streams the prose as token events without leaking the JSON block', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    fakeProvider(guion('Generé tu pregunta.', [
        'action' => 'generate',
        'questions' => [preguntaGenerada()],
    ]));

    $sse = comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'dale'])
        ->streamedContent();

    $prosa = collect(eventos($sse))
        ->where('type', 'token')
        ->pluck('text')
        ->implode('');

    expect($prosa)->toContain('Generé tu pregunta.');
    // El separador y el JSON NUNCA deben aparecer en la prosa tipeada.
    expect($prosa)->not->toContain(QuizAssistantPrompt::SENTINEL);
    expect($prosa)->not->toContain('"action"');
});

it('drops invalid questions and keeps the valid ones', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    fakeProvider(guion('Acá van.', [
        'action' => 'generate',
        'questions' => [
            preguntaGenerada(),
            preguntaGenerada(['time_limit' => 7]),          // tiempo inválido → se descarta
            preguntaGenerada(['kind' => 'poll', 'options' => [ // tipo fuera de la lista blanca
                ['text' => 'A', 'is_correct' => false],
                ['text' => 'B', 'is_correct' => false],
            ]]),
            preguntaGenerada(['options' => ['A', 'B']]),     // opciones con forma rota
            preguntaGenerada(['options' => [                 // quiz con 0 correctas → se descarta
                ['text' => 'A', 'is_correct' => false],
                ['text' => 'B', 'is_correct' => false],
            ]]),
        ],
    ]));

    $result = collect(eventos(comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'generá'])
        ->streamedContent()))->firstWhere('type', 'result');

    expect($result['questions'])->toHaveCount(1);
});

it('suggests fixes when the quiz already has questions (assist mode)', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();
    Question::factory()->for($quiz)->create();

    fakeProvider(guion('Revisé tu quiz.', [
        'action' => 'suggest',
        'suggestions' => [
            ['question_index' => 0, 'type' => 'claridad', 'message' => 'La pregunta 1 es ambigua.'],
        ],
    ]));

    $result = collect(eventos(comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'revisá'])
        ->streamedContent()))->firstWhere('type', 'result');

    expect($result['mode'])->toBe('assist');
    expect($result['action'])->toBe('suggest');
    expect($result['suggestions'])->toHaveCount(1);
});

it('asks clarifying questions when the prompt is too vague', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    fakeProvider(guion('Necesito un poco más de info.', [
        'action' => 'clarify',
        'clarifications' => ['¿Sobre qué tema?', '¿Qué nivel?'],
    ]));

    $result = collect(eventos(comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'creá algo'])
        ->streamedContent()))->firstWhere('type', 'result');

    expect($result['action'])->toBe('clarify');
    expect($result['clarifications'])->toHaveCount(2);
});

it('emits an error event when the provider fails', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    fakeProvider(throw: new AiProviderException('boom'));

    $events = eventos(comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'hola'])
        ->assertOk()
        ->streamedContent());

    expect(collect($events)->pluck('type'))->toContain('error');
});

it('forbids using the assistant on someone else\'s quiz', function () {
    $host = User::factory()->create();
    $intruso = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    fakeProvider(guion('hola', ['action' => 'reject']));

    comoDocente($intruso)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'hola'])
        ->assertForbidden();
});

it('validates the prompt', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => ''])
        ->assertStatus(422);

    comoDocente($host)
        ->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => str_repeat('a', 2001)])
        ->assertStatus(422);
});

it('requires authentication', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    $this->postJson("/api/v1/quizzes/{$quiz->id}/assistant", ['message' => 'hola'])
        ->assertUnauthorized();
});
