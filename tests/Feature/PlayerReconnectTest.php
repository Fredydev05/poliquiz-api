<?php

declare(strict_types=1);

use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Services\Game\GameRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;

/*
 * Reconexión del jugador (plan §6): GET /me devuelve el estado exacto para
 * que el celular re-pinte la pantalla correcta tras un refresh, en cada fase.
 * Reusa los helpers armarPartida/lanzarPregunta/jugar/comoDocente de GameLoopTest.
 */
beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
});

it('restores the running question WITHOUT correct answers on refresh', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    $me = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->assertOk()->json();

    expect($me['state'])->toBe('question')
        ->and($me['answered'])->toBeFalse()
        ->and($me['question'])->not->toBeNull()
        ->and($me['question']['options'])->toHaveCount(4)
        // Anti-trampa: la reconexión NUNCA revela las correctas.
        ->and(collect($me['question']['options'])->every(fn ($o) => ! array_key_exists('is_correct', $o)))->toBeTrue()
        ->and($me['ends_at_ms'])->toBeGreaterThan($me['server_now_ms']);
});

it('marks answered=true on refresh after the player already answered', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(2); // 2 jugadores: no cierra al responder 1
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');

    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]])->assertOk();

    $me = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->json();
    expect($me['state'])->toBe('question')->and($me['answered'])->toBeTrue();
});

it('returns the personal result on refresh during the reveal', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]]); // cierra (único jugador)

    $me = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->json();
    expect($me['state'])->toBe('results')
        ->and($me['last_result']['is_correct'])->toBeTrue()
        ->and($me['score'])->toBeGreaterThan(0)
        ->and($me['rank'])->toBe(1);
});

it('invalidates the session once the room is destroyed', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [, $pin, $tokens] = armarPartida(1);

    // Fin de partida: destroy() borra la sala; el jugador ya vio el podio por
    // WS. Una reconexión tardía cae en 401 y el front lo lleva al login
    // (resume() trata 401 y 410 igual: limpia sesión y muestra el ingreso).
    app(GameRepository::class)->destroy($pin);

    jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->assertUnauthorized();
});
