<?php

declare(strict_types=1);

use App\Events\Game\GameEnded;
use App\Events\Game\GetReadyShown;
use App\Events\Game\LeaderboardShown;
use App\Events\Game\QuestionEnded;
use App\Events\Game\QuestionStarted;
use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/*
 * Estos tests corren la cola en sync ejecutando los jobs a mano (Bus::fake)
 * para controlar el tiempo. Redis real en base 15.
 */
beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
});

/** Sala con quiz de 2 preguntas + N jugadores. Devuelve contexto completo. */
function armarPartida(int $jugadores = 2): array
{
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();
    Question::factory()->for($quiz)->count(2)->sequence(
        ['position' => 0, 'time_limit' => 20],
        ['position' => 1, 'time_limit' => 10, 'points_mode' => 'double'],
    )->create();

    $pin = comoDocente($host)
        ->postJson('/api/v1/games', ['quiz_id' => $quiz->id])->json('pin');

    $tokens = [];
    foreach (range(1, $jugadores) as $i) {
        $tokens["J$i"] = test()->postJson("/api/v1/games/$pin/join", ['nickname' => "J$i"])
            ->json('player_token');
    }

    return [$host, $pin, $tokens];
}

/** Avanza get_ready → question ejecutando el StartQuestionJob capturado. */
function lanzarPregunta(string $pin): void
{
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    app(App\Actions\Game\LaunchQuestion::class)->start($pin, $uuid);
}

/**
 * Request como jugador con guards LIMPIOS. En producción cada request es una
 * app nueva; en tests los guards cachean al último usuario resuelto, así que
 * hay que olvidarlos para que el X-Player-Token de ESTE request cuente.
 */
function jugar(string $token)
{
    app('auth')->forgetGuards();

    return test()->withHeader('X-Player-Token', $token);
}

/** Request como docente restaurando el guard default (espejo de jugar()). */
function comoDocente(App\Models\User $user)
{
    app('auth')->forgetGuards();
    config(['auth.defaults.guard' => 'web']);

    return test()->actingAs($user);
}

it('runs start → get_ready → question with server timestamps', function () {
    Event::fake([GetReadyShown::class, QuestionStarted::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin] = armarPartida();

    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('get_ready');
    Event::assertDispatched(GetReadyShown::class);
    Bus::assertDispatched(StartQuestionJob::class);

    lanzarPregunta($pin);
    expect(Redis::hget("game:$pin", 'state'))->toBe('question');
    Event::assertDispatched(QuestionStarted::class, function (QuestionStarted $e) {
        // Las opciones del broadcast NO llevan is_correct (anti-trampa).
        return collect($e->broadcastWith()['options'])->every(fn ($o) => ! array_key_exists('is_correct', $o))
            && $e->broadcastWith()['ends_at_ms'] > $e->broadcastWith()['server_now_ms'];
    });
    Bus::assertDispatched(CloseQuestionJob::class);
});

it('rejects starting with no players', function () {
    Bus::fake();
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();
    Question::factory()->for($quiz)->create();
    $pin = comoDocente($host)->postJson('/api/v1/games', ['quiz_id' => $quiz->id])->json('pin');

    comoDocente($host)->postJson("/api/v1/games/$pin/start")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'NO_PLAYERS');
});

it('accepts valid answers and rejects duplicates, stale and invalid ones', function () {
    Event::fake([QuestionEnded::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(2);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');

    $payload = ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]];

    // Respuesta válida
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers", $payload)
        ->assertOk()->assertJsonPath('received', true);

    // Duplicada → 409
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers", $payload)
        ->assertStatus(409)->assertJsonPath('code', 'ALREADY_ANSWERED');

    // question_uuid viejo → 409 STALE
    jugar($tokens['J2'])->postJson("/api/v1/games/$pin/answers", [...$payload, 'question_uuid' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('code', 'STALE_QUESTION');

    // Opción inexistente → 422
    jugar($tokens['J2'])->postJson("/api/v1/games/$pin/answers", [...$payload, 'selected' => [9]])
        ->assertUnprocessable()->assertJsonPath('code', 'INVALID_OPTIONS');
});

it('rejects answers after the time window', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');

    // Simular que el tiempo ya venció moviendo ends_at al pasado.
    Redis::hset("game:$pin", 'ends_at', (string) ((int) (microtime(true) * 1000) - 1000));

    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TIME_EXPIRED');
});

it('closes early when everyone answered and scores correctly', function () {
    Event::fake([QuestionEnded::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(2);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    $payload = ['question_index' => 0, 'question_uuid' => $uuid];

    // J1 acierta (opción 0 es la correcta de la factory), J2 falla.
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers", [...$payload, 'selected' => [0]]);
    expect(Redis::hget("game:$pin", 'state'))->toBe('question'); // aún falta J2

    jugar($tokens['J2'])->postJson("/api/v1/games/$pin/answers", [...$payload, 'selected' => [1]]);

    // Todos respondieron → cierre inmediato sin esperar el job.
    expect(Redis::hget("game:$pin", 'state'))->toBe('results');
    Event::assertDispatched(QuestionEnded::class, function (QuestionEnded $e) {
        $with = $e->broadcastWith();

        return $with['correct_option_ids'] === [0]
            && collect($with['distribution'])->firstWhere('option_id', 0)['count'] === 1
            && collect($with['distribution'])->firstWhere('option_id', 1)['count'] === 1;
    });

    // Puntos: J1 entre 500 y 1000, J2 exactamente 0.
    $me1 = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->json();
    $me2 = jugar($tokens['J2'])->getJson("/api/v1/games/$pin/me")->json();
    expect($me1['score'])->toBeGreaterThanOrEqual(500)->toBeLessThanOrEqual(1000)
        ->and($me1['last_result']['is_correct'])->toBeTrue()
        ->and($me2['score'])->toBe(0)
        ->and($me2['last_result']['is_correct'])->toBeFalse()
        ->and($me1['rank'])->toBe(1);
});

it('is idempotent: a late CloseQuestionJob after early close is a no-op', function () {
    Event::fake([QuestionEnded::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');

    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]]);
    expect(Redis::hget("game:$pin", 'state'))->toBe('results');
    $scoreTrasCierre = json_decode(Redis::hget("game:$pin:players", array_key_first(app(App\Services\Game\GameRepository::class)->players($pin))), true)['score'] ?? null;

    // El job por timeout llega tarde: no debe re-puntuar ni re-emitir.
    app(App\Actions\Game\CloseQuestion::class)->handle($pin, $uuid);
    Event::assertDispatchedTimes(QuestionEnded::class, 1);
    $players = app(App\Services\Game\GameRepository::class)->players($pin);
    expect(array_values($players)[0]['score'])->toBe($scoreTrasCierre);
});

it('advances results → leaderboard → question 2 → podium → ended', function () {
    Event::fake([LeaderboardShown::class, GameEnded::class, QuestionEnded::class]);
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]]); // cierra (único jugador)

    // results → leaderboard
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('leaderboard');
    Event::assertDispatched(LeaderboardShown::class, fn ($e) => $e->top[0]['nickname'] === 'J1' && $e->top[0]['delta'] > 0);

    // leaderboard → pregunta 2 (get_ready) → question
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'current_index'))->toBe('1');
    lanzarPregunta($pin);
    $uuid2 = Redis::hget("game:$pin", 'question_uuid');
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 1, 'question_uuid' => $uuid2, 'selected' => [0]]);
    expect(Redis::hget("game:$pin", 'state'))->toBe('results');

    // era la última → podium
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('podium');
    Event::assertDispatched(GameEnded::class, fn ($e) => $e->podium[0]['nickname'] === 'J1' && ! $e->aborted);

    // podium → ended: fila permanente marcada y Redis desarmado.
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hgetall("game:$pin"))->toBe([])
        ->and(App\Models\Game::where('pin', $pin)->first()->status)->toBe('ended');
});
