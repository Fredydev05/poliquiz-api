<?php

declare(strict_types=1);

use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\PlayerAnswer;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
});

/**
 * Juega una partida completa de 2 preguntas con 2 jugadores y la termina.
 * J1 acierta ambas (rápido), J2 falla ambas. Devuelve [host, game].
 */
function responder(string $pin, array $tokens, int $q): void
{
    lanzarPregunta($pin); // get_ready → question
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => $q, 'question_uuid' => $uuid, 'selected' => [0]]); // acierta
    jugar($tokens['J2'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => $q, 'question_uuid' => $uuid, 'selected' => [1]]); // falla
    // Ambos respondieron → cierre automático → results.
}

function jugarPartidaCompleta(): array
{
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(2);
    $next = fn () => comoDocente($host)->postJson("/api/v1/games/$pin/next");

    $next();                       // lobby → get_ready(0)
    responder($pin, $tokens, 0);   // question(0) → results(0)
    $next();                       // results → leaderboard
    $next();                       // leaderboard → get_ready(1)
    responder($pin, $tokens, 1);   // question(1) → results(1)
    $next();                       // results → podium (última)
    $next();                       // podium → ended (persiste)

    return [$host, Game::where('pin', $pin)->first()];
}

it('persists players and answers to postgres when the game ends', function () {
    [, $game] = jugarPartidaCompleta();

    expect($game->refresh()->status)->toBe('ended')
        ->and(Redis::hgetall("game:{$game->pin}"))->toBe([]) // Redis limpio
        ->and(GamePlayer::where('game_id', $game->id)->count())->toBe(2)
        ->and(PlayerAnswer::whereIn('game_player_id', $game->players->pluck('id'))->count())->toBe(4);

    // J1 ganó (2 aciertos) → rank 1; J2 rank 2 con 0 aciertos.
    $ranking = $game->players()->orderBy('final_rank')->get();
    expect($ranking[0]->nickname)->toBe('J1')
        ->and($ranking[0]->answers_correct)->toBe(2)
        ->and($ranking[0]->final_score)->toBeGreaterThan(0)
        ->and($ranking[1]->nickname)->toBe('J2')
        ->and($ranking[1]->answers_correct)->toBe(0)
        ->and($ranking[1]->final_score)->toBe(0);
});

it('serves the game summary report to its host', function () {
    [$host, $game] = jugarPartidaCompleta();

    comoDocente($host)->getJson("/api/v1/reports/games/{$game->id}")
        ->assertOk()
        ->assertJsonPath('players_count', 2)
        ->assertJsonPath('questions_count', 2)
        ->assertJsonPath('podium.0.nickname', 'J1')
        ->assertJson(fn ($json) => $json
            ->where('accuracy', 50) // 2 aciertos de 4 posibles
            ->etc());
});

it('serves per-question stats with option distribution', function () {
    [$host, $game] = jugarPartidaCompleta();

    $response = comoDocente($host)->getJson("/api/v1/reports/games/{$game->id}/questions")
        ->assertOk();

    $q0 = $response->json('questions.0');
    expect($q0['answered'])->toBe(2)
        ->and($q0['correct'])->toBe(1)      // solo J1
        ->and($q0['accuracy'])->toBe(50)
        // La opción 0 (correcta) la eligió 1; la opción 1, el otro.
        ->and(collect($q0['options'])->firstWhere('id', 0)['count'])->toBe(1)
        ->and(collect($q0['options'])->firstWhere('id', 1)['count'])->toBe(1);
});

it('serves per-player answer detail', function () {
    [$host, $game] = jugarPartidaCompleta();

    $response = comoDocente($host)->getJson("/api/v1/reports/games/{$game->id}/players")
        ->assertOk();

    $j1 = collect($response->json('players'))->firstWhere('nickname', 'J1');
    expect($j1['rank'])->toBe(1)
        ->and($j1['answers'])->toHaveCount(2)
        ->and($j1['answers'][0]['is_correct'])->toBeTrue();
});

it('lists the game in the host history', function () {
    [$host, $game] = jugarPartidaCompleta();

    comoDocente($host)->getJson('/api/v1/reports/games')
        ->assertOk()
        ->assertJsonPath('data.0.id', $game->id)
        ->assertJsonPath('data.0.status', 'ended');
});

it('forbids reading reports of other hosts', function () {
    [, $game] = jugarPartidaCompleta();
    $intruso = User::factory()->create();

    comoDocente($intruso)->getJson("/api/v1/reports/games/{$game->id}")->assertForbidden();
    comoDocente($intruso)->getJson("/api/v1/reports/games/{$game->id}/questions")->assertForbidden();
});

it('persists partial results when the host aborts mid-game', function () {
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
    [$host, $pin, $tokens] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [0]]);

    // El docente aborta con la X.
    comoDocente($host)->postJson("/api/v1/games/$pin/end")->assertNoContent();

    $game = Game::where('pin', $pin)->first();
    expect($game->status)->toBe('aborted')
        ->and(GamePlayer::where('game_id', $game->id)->count())->toBe(1)
        ->and($game->players()->first()->answers_correct)->toBe(1);
});
