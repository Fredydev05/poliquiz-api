<?php

declare(strict_types=1);

use App\Events\Game\GameLocked;
use App\Events\Game\PlayerKicked;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/*
 * Los tests de partida usan Redis REAL (base 15, aislada del dev que usa 0).
 * Cada test arranca con la base limpia.
 */
beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
});

/** Crea host+quiz con preguntas y abre la sala. Devuelve [host, pin]. */
function abrirSala(): array
{
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();
    Question::factory()->for($quiz)->count(2)->sequence(
        ['position' => 0],
        ['position' => 1],
    )->create();

    $pin = test()->actingAs($host)
        ->postJson('/api/v1/games', ['quiz_id' => $quiz->id])
        ->assertCreated()
        ->json('pin');

    return [$host, $pin];
}

it('creates a game with a 6-digit pin and lobby state in redis', function () {
    [, $pin] = abrirSala();

    expect($pin)->toMatch('/^\d{6}$/');
    expect(Redis::hget("game:$pin", 'state'))->toBe('lobby');
    expect(Redis::get("pin:$pin"))->not->toBeNull();
});

it('rejects hosting a quiz without questions', function () {
    $host = User::factory()->create();
    $quiz = Quiz::factory()->for($host)->create();

    $this->actingAs($host)
        ->postJson('/api/v1/games', ['quiz_id' => $quiz->id])
        ->assertUnprocessable();
});

it('lets a player join and returns a session token', function () {
    [, $pin] = abrirSala();

    $response = $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Vale']);

    $response->assertCreated()
        ->assertJsonStructure(['player_token', 'player_uuid', 'nickname', 'game' => ['pin', 'state', 'quiz_title']]);

    // El token sirve para consultar el propio estado (guard 'player').
    $token = $response->json('player_token');
    $this->getJson("/api/v1/games/$pin/me", ['X-Player-Token' => $token])
        ->assertOk()
        ->assertJsonPath('nickname', 'Vale')
        ->assertJsonPath('state', 'lobby');
});

it('rejects joining with a wrong pin', function () {
    $this->postJson('/api/v1/games/000000/join', ['nickname' => 'Vale'])
        ->assertNotFound()
        ->assertJsonPath('code', 'PIN_NOT_FOUND');
});

it('rejects duplicate nicknames case-insensitively', function () {
    [, $pin] = abrirSala();

    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Vale'])->assertCreated();
    $this->postJson("/api/v1/games/$pin/join", ['nickname' => ' VALE '])
        ->assertStatus(409)
        ->assertJsonPath('code', 'NICKNAME_TAKEN');
});

it('rejects blocked or malformed nicknames', function () {
    [, $pin] = abrirSala();

    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'x'])->assertUnprocessable();
    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'admin2026'])->assertUnprocessable();
});

it('locks the room and rejects new joins while locked', function () {
    Event::fake([GameLocked::class]);
    [$host, $pin] = abrirSala();

    $this->actingAs($host)->postJson("/api/v1/games/$pin/lock")->assertOk();
    Event::assertDispatched(GameLocked::class, fn ($e) => $e->pin === $pin && $e->locked === true);

    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Tarde'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'GAME_LOCKED');

    $this->actingAs($host)->postJson("/api/v1/games/$pin/unlock")->assertOk();
    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Tarde'])->assertCreated();
});

it('kicks a player, frees the nickname and invalidates the session', function () {
    Event::fake([PlayerKicked::class]);
    [$host, $pin] = abrirSala();

    $join = $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Nico'])->json();

    $this->actingAs($host)
        ->deleteJson("/api/v1/games/$pin/players/{$join['player_uuid']}")
        ->assertNoContent();

    Event::assertDispatched(PlayerKicked::class, fn ($e) => $e->playerUuid === $join['player_uuid']);

    // Su token ya no autentica…
    $this->getJson("/api/v1/games/$pin/me", ['X-Player-Token' => $join['player_token']])
        ->assertUnauthorized();
    // …y el apodo queda libre.
    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Nico'])->assertCreated();
});

it('forbids managing a game you do not host', function () {
    [, $pin] = abrirSala();
    $intruso = User::factory()->create();

    $this->actingAs($intruso)->postJson("/api/v1/games/$pin/lock")
        ->assertForbidden();
    $this->actingAs($intruso)->getJson("/api/v1/games/$pin/state")
        ->assertForbidden();
});

it('returns the roster in the host state endpoint', function () {
    [$host, $pin] = abrirSala();
    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Vale']);
    $this->postJson("/api/v1/games/$pin/join", ['nickname' => 'Nico']);

    $this->actingAs($host)->getJson("/api/v1/games/$pin/state")
        ->assertOk()
        ->assertJsonPath('state', 'lobby')
        ->assertJsonCount(2, 'players');
});

it('lets a whole classroom behind one IP join the room', function () {
    [, $pin] = abrirSala();

    // 40 alumnos con la misma IP pública (wifi de la facultad).
    foreach (range(1, 40) as $i) {
        $this->postJson("/api/v1/games/$pin/join", ['nickname' => "Alumno$i"])->assertCreated();
    }

    expect(Redis::hlen("game:$pin:players"))->toBe(40);
});

it('lets several classrooms behind the same IP join their rooms in the same minute', function () {
    config(['poliquiz.max_players' => 10]); // límite por sala = 20/min

    [, $sala1] = abrirSala();
    [, $sala2] = abrirSala();
    foreach ([$sala1, $sala2] as $pin) {
        foreach (range(1, 10) as $i) {
            $this->postJson("/api/v1/games/$pin/join", ['nickname' => "Alumno$i"])->assertCreated();
        }
    }

    // Pero una misma sala no se puede martillar sin límite desde una IP.
    foreach (range(1, 10) as $i) {
        $this->postJson("/api/v1/games/$sala1/join", ['nickname' => "Extra$i"]); // sala llena: 422
    }
    $this->postJson("/api/v1/games/$sala1/join", ['nickname' => 'Otro'])->assertStatus(429);
});
