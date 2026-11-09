<?php

declare(strict_types=1);

use App\Models\Game;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
});

function salaAbierta(): array
{
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();
    Question::factory()->for($quiz)->create();
    $pin = comoDocente($host)->postJson('/api/v1/games', ['quiz_id' => $quiz->id])->json('pin');

    return [$host, $pin];
}

it('keeps recently active games untouched', function () {
    [, $pin] = salaAbierta();

    $this->travel(2)->minutes(); // el barrido ignora filas de menos de 1 min
    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful();

    expect(Redis::hget("game:$pin", 'state'))->toBe('lobby')
        ->and(Game::where('pin', $pin)->first()->status)->toBe('active');
});

it('aborts games with no recent activity', function () {
    [, $pin] = salaAbierta();

    // Envejecer la actividad más allá del umbral.
    $viejo = (int) (microtime(true) * 1000) - (config('poliquiz.abandoned_after') + 60) * 1000;
    Redis::hset("game:$pin", 'last_activity', (string) $viejo);

    $this->travel(2)->minutes(); // el barrido ignora filas de menos de 1 min
    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful();

    // Sala desarmada en Redis y fila marcada como abortada.
    expect(Redis::hgetall("game:$pin"))->toBe([])
        ->and(Game::where('pin', $pin)->first()->status)->toBe('aborted');
});

it('marks orphan games as aborted when their redis room already expired', function () {
    [, $pin] = salaAbierta();
    Redis::del("game:$pin"); // el TTL ya limpió la sala, la fila quedó activa

    $this->travel(2)->minutes(); // el barrido ignora filas de menos de 1 min
    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful();

    expect(Game::where('pin', $pin)->first()->status)->toBe('aborted');
});
