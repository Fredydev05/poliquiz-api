<?php

declare(strict_types=1);

use App\Actions\Game\CloseQuestion;
use App\Events\Game\GameEnded;
use App\Events\Game\GetReadyShown;
use App\Events\Game\QuestionEnded;
use App\Events\Game\SlideShown;
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

function crearSlide(Quiz $quiz, int $pos, string $title, string $body): void
{
    $quiz->questions()->create([
        'position' => $pos, 'kind' => 'slide', 'title' => $title, 'body' => $body, 'time_limit' => null,
    ]);
}

function crearPollSimple(Quiz $quiz, int $pos): void
{
    $q = $quiz->questions()->create([
        'position' => $pos, 'kind' => 'poll', 'title' => '¿Color favorito?', 'time_limit' => 20, 'points_mode' => 'standard',
    ]);
    $q->options()->createMany([
        ['position' => 0, 'text' => 'Rojo', 'is_correct' => false],
        ['position' => 1, 'text' => 'Azul', 'is_correct' => false],
    ]);
}

function crearQuizSimple(Quiz $quiz, int $pos): void
{
    $q = $quiz->questions()->create([
        'position' => $pos, 'kind' => 'quiz', 'title' => '¿2+2?', 'time_limit' => 20, 'points_mode' => 'standard',
    ]);
    $q->options()->createMany([
        ['position' => 0, 'text' => '4', 'is_correct' => true],
        ['position' => 1, 'text' => '5', 'is_correct' => false],
    ]);
}

it('shows a slide and waits for the host to advance to the next item', function () {
    Event::fake([SlideShown::class, GetReadyShown::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);

    [$host, $pin] = salaCon(function (Quiz $quiz) {
        crearSlide($quiz, 0, 'Introducción', '¡Bienvenidos a POLI Quiz!');
        crearQuizSimple($quiz, 1);
    });

    // start → el primer ítem es una slide: se proyecta (sin timer ni job).
    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('slide');
    Event::assertDispatched(SlideShown::class, fn (SlideShown $e) => $e->broadcastWith()['body'] === '¡Bienvenidos a POLI Quiz!');
    Bus::assertNotDispatched(StartQuestionJob::class);

    // next → el segundo ítem es una pregunta: entra en get_ready.
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('get_ready')
        ->and(Redis::hget("game:$pin", 'current_index'))->toBe('1');
    Event::assertDispatched(GetReadyShown::class);
});

it('advances to the podium when a slide is the last item', function () {
    Event::fake([SlideShown::class, GameEnded::class]);
    Bus::fake();

    [$host, $pin] = salaCon(fn (Quiz $quiz) => crearSlide($quiz, 0, 'Gracias', 'Fin de la clase'));

    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('slide');

    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('podium');
    Event::assertDispatched(GameEnded::class);
});

it('reveals the question text before showing the options', function () {
    Event::fake([GetReadyShown::class]);
    Bus::fake([StartQuestionJob::class]);

    [$host, $pin] = salaCon(fn (Quiz $quiz) => crearQuizSimple($quiz, 0));

    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();

    Event::assertDispatched(GetReadyShown::class, function (GetReadyShown $e) {
        $with = $e->broadcastWith();

        // El reveal manda el enunciado (para leerlo) pero NUNCA las opciones.
        return $with['title'] === '¿2+2?'
            && array_key_exists('image_url', $with)
            && ! array_key_exists('options', $with);
    });
});

it('scores nobody on a poll and reveals the distribution without a correct answer', function () {
    Event::fake([QuestionEnded::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);

    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearPollSimple($quiz, 0), jugadores: 1);

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');

    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]])->assertOk();

    // Único jugador respondió → cierre inmediato.
    expect(Redis::hget("game:$pin", 'state'))->toBe('results');
    Event::assertDispatched(QuestionEnded::class, function (QuestionEnded $e) {
        $reveal = $e->broadcastWith()['reveal'];

        return $reveal['correct_option_ids'] === [] // encuesta: no hay correcta
            && collect($reveal['distribution'])->firstWhere('option_id', 0)['count'] === 1;
    });

    // El jugador no suma puntos (la encuesta no puntúa).
    $me = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->json();
    expect($me['score'])->toBe(0)->and($me['last_result']['is_correct'])->toBeFalse();
});

it('keeps everyone\'s streak on a poll and reports a neutral result', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);

    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearPollSimple($quiz, 0), jugadores: 2);

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    // Ambos vienen con racha 3 de preguntas anteriores.
    foreach (Redis::hgetall("game:$pin:players") as $uuid => $json) {
        Redis::hset("game:$pin:players", $uuid, json_encode([...json_decode($json, true), 'streak' => 3]));
    }

    // J1 vota, J2 no: al cerrar, NINGUNO debe perder la racha.
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [1]])->assertOk();
    app(CloseQuestion::class)->handle($pin, $uuid);

    $votó = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->json();
    $noVotó = jugar($tokens['J2'])->getJson("/api/v1/games/$pin/me")->json();

    expect($votó['streak'])->toBe(3)
        ->and($noVotó['streak'])->toBe(3)
        ->and($votó['last_result']['scored'])->toBeFalse()
        ->and($votó['last_result']['answered'])->toBeTrue()
        ->and($noVotó['last_result']['answered'])->toBeFalse();
});

it('requires a body when saving a slide', function () {
    $host = User::factory()->create();

    comoDocente($host)->postJson('/api/v1/quizzes', [
        'title' => 'Con diapositiva',
        'questions' => [
            ['kind' => 'slide', 'title' => 'Intro'], // sin body
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('questions.0.body');
});

it('saves a slide (no options, no time_limit) and a poll (no correct option)', function () {
    $host = User::factory()->create();

    comoDocente($host)->postJson('/api/v1/quizzes', [
        'title' => 'Mixto',
        'questions' => [
            ['kind' => 'slide', 'title' => 'Intro', 'body' => 'Bienvenidos'],
            ['kind' => 'poll', 'title' => '¿Color?', 'time_limit' => 20, 'options' => [
                ['text' => 'Rojo', 'is_correct' => false],
                ['text' => 'Azul', 'is_correct' => false],
            ]],
        ],
    ])->assertCreated()->assertJsonPath('data.questions.0.kind', 'slide')
        ->assertJsonPath('data.questions.0.body', 'Bienvenidos')
        ->assertJsonPath('data.questions.1.kind', 'poll');
});
