<?php

declare(strict_types=1);

use App\Events\Game\QuestionEnded;
use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Game\QuestionTypes\PuzzleQuestion;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
});

/** Puzzle de 3 piezas en el orden correcto A, B, C (ids 0,1,2). */
function crearPuzzle(Quiz $quiz, int $pos): void
{
    $q = $quiz->questions()->create([
        'position' => $pos, 'kind' => 'puzzle', 'title' => 'Ordená A, B, C', 'time_limit' => 20, 'points_mode' => 'standard',
    ]);
    $q->options()->createMany([
        ['position' => 0, 'text' => 'A', 'is_correct' => false],
        ['position' => 1, 'text' => 'B', 'is_correct' => false],
        ['position' => 2, 'text' => 'C', 'is_correct' => false],
    ]);
}

/** Responde con un orden de ids y devuelve el /me del jugador tras el cierre. */
function responderOrden(string $pin, string $token, array $order): array
{
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    jugar($token)->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'order' => $order])->assertOk();

    return jugar($token)->getJson("/api/v1/games/$pin/me")->json();
}

it('gives full points and marks correct for the exact order', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearPuzzle($quiz, 0));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    $me = responderOrden($pin, $tokens['J1'], [0, 1, 2]); // orden correcto

    expect($me['last_result']['is_correct'])->toBeTrue()
        ->and($me['score'])->toBeGreaterThan(0);
});

it('gives partial points for a partly correct order', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearPuzzle($quiz, 0));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    // [1,0,2] → solo la pieza en la posición 2 (id 2) está bien = 1/3 correcto.
    $me = responderOrden($pin, $tokens['J1'], [1, 0, 2]);

    expect($me['last_result']['is_correct'])->toBeFalse()  // no está TODO bien
        ->and($me['score'])->toBeGreaterThan(0);           // pero suma crédito parcial
});

it('rejects an order that is not a permutation of the pieces', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearPuzzle($quiz, 0));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');

    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'order' => [0, 1]]) // falta una pieza
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_ORDER');
});

it('reveals the correct order to the host', function () {
    Event::fake([QuestionEnded::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearPuzzle($quiz, 0));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    responderOrden($pin, $tokens['J1'], [0, 1, 2]);

    Event::assertDispatched(QuestionEnded::class, function (QuestionEnded $e) {
        $reveal = $e->broadcastWith()['reveal'];

        return $reveal['kind'] === 'order'
            && $reveal['correct_order'] === [
                ['position' => 1, 'text' => 'A'],
                ['position' => 2, 'text' => 'B'],
                ['position' => 3, 'text' => 'C'],
            ]
            && $reveal['fully_correct'] === 1;
    });
});

it('shuffles the pieces for the player without giving away the order', function () {
    // playerView baraja (con tope de intentos): nunca deja el orden identidad.
    $question = [
        'kind' => 'puzzle', 'title' => 'x', 'time_limit' => 20, 'points_mode' => 'standard',
        'options' => [
            ['id' => 0, 'text' => 'A', 'is_correct' => false],
            ['id' => 1, 'text' => 'B', 'is_correct' => false],
            ['id' => 2, 'text' => 'C', 'is_correct' => false],
        ],
    ];
    $ids = collect((new PuzzleQuestion)->playerView($question)['options'])->pluck('id')->all();

    expect($ids)->not->toBe([0, 1, 2])       // no está en el orden correcto
        ->and(collect($ids)->sort()->values()->all())->toBe([0, 1, 2]); // pero están todas
});

it('requires 2 to 4 pieces when saving a puzzle', function () {
    $host = User::factory()->create();

    comoDocente($host)->postJson('/api/v1/quizzes', [
        'title' => 'x',
        'questions' => [
            ['kind' => 'puzzle', 'title' => 'q', 'time_limit' => 20, 'options' => [
                ['text' => 'Solo una', 'is_correct' => false],
            ]],
        ],
    ])->assertStatus(422);
});
