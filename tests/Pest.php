<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/*
|--------------------------------------------------------------------------
| Helpers de partidas (compartidos por los tests de juego)
|--------------------------------------------------------------------------
*/

use App\Actions\Game\LaunchQuestion;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

/** Sala con quiz de 2 preguntas + N jugadores. Devuelve [host, pin, tokens]. */
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

/** Avanza get_ready → question ejecutando el StartQuestionJob a mano. */
function lanzarPregunta(string $pin): void
{
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    app(LaunchQuestion::class)->start($pin, $uuid);
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
function comoDocente(User $user)
{
    app('auth')->forgetGuards();
    config(['auth.defaults.guard' => 'web']);

    return test()->actingAs($user);
}
