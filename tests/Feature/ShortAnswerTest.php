<?php

declare(strict_types=1);

use App\Events\Game\QuestionEnded;
use App\Events\Game\QuestionStarted;
use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
});

/** Pregunta "escribe la respuesta": las opciones son respuestas aceptadas. */
function crearShort(Quiz $quiz, int $pos, array $aceptadas): void
{
    $q = $quiz->questions()->create([
        'position' => $pos, 'kind' => 'short', 'title' => 'Capital de Paraguay', 'time_limit' => 20, 'points_mode' => 'standard',
    ]);
    $q->options()->createMany(
        collect($aceptadas)->map(fn (string $t, int $i) => ['position' => $i, 'text' => $t, 'is_correct' => true])->all()
    );
}

/** Responde con texto y devuelve el /me del jugador tras el cierre. */
function responderTexto(string $pin, string $token, string $texto): array
{
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    jugar($token)->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'text' => $texto])->assertOk();

    return jugar($token)->getJson("/api/v1/games/$pin/me")->json();
}

it('accepts a written answer ignoring case and accents', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearShort($quiz, 0, ['Asunción']));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    // "asuncion" (minúscula, sin acento) cuenta como correcta.
    $me = responderTexto($pin, $tokens['J1'], 'asuncion');

    expect(Redis::hget("game:$pin", 'state'))->toBe('results')
        ->and($me['score'])->toBeGreaterThan(0)
        ->and($me['last_result']['is_correct'])->toBeTrue();
});

it('rejects a wrong written answer with 0 points', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearShort($quiz, 0, ['Asunción']));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    $me = responderTexto($pin, $tokens['J1'], 'Lima');

    expect($me['score'])->toBe(0)->and($me['last_result']['is_correct'])->toBeFalse();
});

it('accepts any of several accepted answers', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearShort($quiz, 0, ['Asunción', 'Nuestra Señora de la Asunción']));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    $me = responderTexto($pin, $tokens['J1'], 'nuestra señora de la asuncion');

    expect($me['last_result']['is_correct'])->toBeTrue();
});

it('never sends the accepted answers to players', function () {
    Event::fake([QuestionStarted::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin] = salaCon(fn (Quiz $quiz) => crearShort($quiz, 0, ['Asunción']));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    // El "escribe la respuesta" no manda opciones (las aceptadas no viajan).
    Event::assertDispatched(QuestionStarted::class,
        fn (QuestionStarted $e) => ! array_key_exists('options', $e->broadcastWith()));
});

it('reveals the accepted answers and what players typed', function () {
    Event::fake([QuestionEnded::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearShort($quiz, 0, ['Asunción']));

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    responderTexto($pin, $tokens['J1'], 'Asunción');

    Event::assertDispatched(QuestionEnded::class, function (QuestionEnded $e) {
        $reveal = $e->broadcastWith()['reveal'];

        return $reveal['kind'] === 'text'
            && $reveal['accepted'] === ['Asunción']
            && $reveal['answers'][0]['text'] === 'Asunción'
            && $reveal['answers'][0]['correct'] === true;
    });
});

it('requires at least one accepted answer when saving a short question', function () {
    $host = User::factory()->create();

    comoDocente($host)->postJson('/api/v1/quizzes', [
        'title' => 'x',
        'questions' => [
            ['kind' => 'short', 'title' => '¿Capital?', 'time_limit' => 20, 'options' => []],
        ],
    ])->assertStatus(422);
});
