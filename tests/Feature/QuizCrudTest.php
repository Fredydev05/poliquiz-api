<?php

declare(strict_types=1);

use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;

/** Payload válido mínimo para crear/actualizar un quiz. */
function quizPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Algoritmos I',
        'questions' => [
            [
                'kind' => 'quiz',
                'title' => '¿Complejidad de la búsqueda binaria?',
                'time_limit' => 20,
                'points_mode' => 'standard',
                'options' => [
                    ['text' => 'O(n)', 'is_correct' => false],
                    ['text' => 'O(log n)', 'is_correct' => true],
                    ['text' => 'O(n²)', 'is_correct' => false],
                ],
            ],
            [
                'kind' => 'true_false',
                'title' => 'HTML es un lenguaje de programación.',
                'time_limit' => 10,
                'points_mode' => 'double',
                'options' => [
                    ['text' => 'Verdadero', 'is_correct' => false],
                    ['text' => 'Falso', 'is_correct' => true],
                ],
            ],
        ],
    ], $overrides);
}

it('creates a quiz with nested questions atomically', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/quizzes', quizPayload());

    $response->assertCreated()
        ->assertJsonPath('data.title', 'Algoritmos I')
        ->assertJsonCount(2, 'data.questions')
        ->assertJsonPath('data.questions.1.kind', 'true_false');

    expect(Question::count())->toBe(2);
});

it('rejects a quiz question without a correct option', function () {
    $user = User::factory()->create();
    $payload = quizPayload();
    $payload['questions'][0]['options'] = [
        ['text' => 'A', 'is_correct' => false],
        ['text' => 'B', 'is_correct' => false],
    ];

    $this->actingAs($user)->postJson('/api/v1/quizzes', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('questions.0.options');
});

it('rejects invalid time limits', function () {
    $user = User::factory()->create();
    $payload = quizPayload();
    $payload['questions'][0]['time_limit'] = 17;

    $this->actingAs($user)->postJson('/api/v1/quizzes', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('questions.0.time_limit');
});

it('updates a quiz replacing its content atomically', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();
    Question::factory()->for($quiz)->create();

    $payload = quizPayload(['title' => 'Título nuevo']);
    $this->actingAs($user)->putJson("/api/v1/quizzes/{$quiz->id}", $payload)
        ->assertOk()
        ->assertJsonPath('data.title', 'Título nuevo')
        ->assertJsonCount(2, 'data.questions');

    expect($quiz->refresh()->questions)->toHaveCount(2);
});

it('forbids editing quizzes of other users', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $quiz = Quiz::factory()->for($owner)->create();

    $this->actingAs($intruder)->putJson("/api/v1/quizzes/{$quiz->id}", quizPayload())
        ->assertForbidden();
    $this->actingAs($intruder)->deleteJson("/api/v1/quizzes/{$quiz->id}")
        ->assertForbidden();
});

it('allows viewing and duplicating public quizzes of others', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $quiz = Quiz::factory()->for($owner)->public()->create();
    Question::factory()->for($quiz)->create();

    $this->actingAs($other)->getJson("/api/v1/quizzes/{$quiz->id}")->assertOk();

    $response = $this->actingAs($other)->postJson("/api/v1/quizzes/{$quiz->id}/duplicate");
    $response->assertCreated();
    expect($response->json('data.title'))->toContain('(copia)')
        ->and($response->json('data.is_mine'))->toBeTrue()
        ->and($response->json('data.questions'))->toHaveCount(1);
});

it('keeps the slide body when duplicating a quiz', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();
    $quiz->questions()->create([
        'position' => 0, 'kind' => 'slide', 'title' => 'Intro', 'body' => 'Bienvenidos', 'time_limit' => null,
    ]);

    $this->actingAs($user)->postJson("/api/v1/quizzes/{$quiz->id}/duplicate")
        ->assertCreated()
        ->assertJsonPath('data.questions.0.kind', 'slide')
        ->assertJsonPath('data.questions.0.body', 'Bienvenidos');
});

it('soft deletes quizzes', function () {
    $user = User::factory()->create();
    $quiz = Quiz::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/quizzes/{$quiz->id}")->assertNoContent();
    expect(Quiz::withTrashed()->find($quiz->id)->trashed())->toBeTrue();
});

it('lists own quizzes by default', function () {
    $user = User::factory()->create();
    Quiz::factory()->for($user)->count(2)->create();
    Quiz::factory()->create(); // de otro usuario

    $this->actingAs($user)->getJson('/api/v1/quizzes')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
