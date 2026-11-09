<?php

declare(strict_types=1);

use App\Events\Game\QuestionEnded;
use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Models\Game;
use App\Models\PlayerAnswer;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Game\GameRepository;
use App\Services\Game\TextModerator;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/* Nube de palabras, pregunta abierta y lluvia de ideas: texto libre, sin puntaje. */

beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
});

function crearLibre(Quiz $quiz, string $kind, int $pos = 0): void
{
    $quiz->questions()->create([
        'position' => $pos, 'kind' => $kind, 'title' => "Pregunta $kind", 'time_limit' => 30, 'points_mode' => 'standard',
    ]);
}

/** Sala con UNA pregunta libre en juego y N jugadores. */
function salaLibre(string $kind, int $jugadores): array
{
    [$host, $pin, $tokens] = salaCon(fn (Quiz $quiz) => crearLibre($quiz, $kind), jugadores: $jugadores);
    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);

    return [$host, $pin, $tokens, Redis::hget("game:$pin", 'question_uuid')];
}

function enviar(string $pin, string $token, string $uuid, array $payload)
{
    return jugar($token)->postJson("/api/v1/games/$pin/answers", ['question_index' => 0, 'question_uuid' => $uuid, ...$payload]);
}

/** Reveal emitido en QuestionEnded. */
function revealEmitido(): array
{
    $reveal = null;
    Event::assertDispatched(QuestionEnded::class, function (QuestionEnded $e) use (&$reveal) {
        $reveal = $e->broadcastWith()['reveal'];

        return true;
    });

    return $reveal;
}

it('builds a word cloud grouping the same word regardless of case and accents', function () {
    Event::fake([QuestionEnded::class]);
    [, $pin, $tokens, $uuid] = salaLibre('wordcloud', 4);

    enviar($pin, $tokens['J1'], $uuid, ['text' => 'Energía'])->assertOk();
    enviar($pin, $tokens['J2'], $uuid, ['text' => 'energia '])->assertOk();
    enviar($pin, $tokens['J3'], $uuid, ['text' => 'Sol'])->assertOk();
    enviar($pin, $tokens['J4'], $uuid, ['text' => 'ENERGÍA'])->assertOk(); // todos respondieron → cierra

    $reveal = revealEmitido();
    expect($reveal['kind'])->toBe('cloud')
        ->and(TextModerator::normalize($reveal['words'][0]['text']))->toBe('energia')
        ->and($reveal['words'][0]['count'])->toBe(3)
        ->and($reveal['words'][1])->toBe(['text' => 'Sol', 'count' => 1])
        ->and($reveal['hidden'])->toBe(0);
});

it('hides profanity from the projected screen without false positives', function () {
    Event::fake([QuestionEnded::class]);
    [, $pin, $tokens, $uuid] = salaLibre('wordcloud', 3);

    enviar($pin, $tokens['J1'], $uuid, ['text' => 'Pelotudos'])->assertOk(); // plural también
    enviar($pin, $tokens['J2'], $uuid, ['text' => 'computadora'])->assertOk(); // contiene "puta" pero es limpia
    enviar($pin, $tokens['J3'], $uuid, ['text' => 'MIERDA'])->assertOk();

    $reveal = revealEmitido();
    expect(collect($reveal['words'])->pluck('text')->all())->toBe(['computadora'])
        ->and($reveal['hidden'])->toBe(2);

    $mod = new TextModerator;
    expect($mod->isClean('Clase de matemática'))->toBeTrue()
        ->and($mod->isClean('qué culo'))->toBeFalse();
});

it('limits each type to its own answer length', function () {
    [, $pin, $tokens, $uuid] = salaLibre('wordcloud', 2);

    enviar($pin, $tokens['J1'], $uuid, ['text' => str_repeat('a', 26)])
        ->assertStatus(422)->assertJsonPath('code', 'INVALID_ANSWER');
    enviar($pin, $tokens['J1'], $uuid, ['text' => '   '])->assertStatus(422);
    enviar($pin, $tokens['J1'], $uuid, ['text' => str_repeat('a', 25)])->assertOk();
});

it('shows open answers as a wall and accepts long text', function () {
    Event::fake([QuestionEnded::class]);
    [, $pin, $tokens, $uuid] = salaLibre('open', 2);
    $larga = str_repeat('Las plantas producen oxígeno. ', 8); // ~240 caracteres

    enviar($pin, $tokens['J1'], $uuid, ['text' => trim($larga)])->assertOk();
    enviar($pin, $tokens['J2'], $uuid, ['text' => 'Para hacer fotosíntesis'])->assertOk();

    $reveal = revealEmitido();
    expect($reveal['kind'])->toBe('open')
        ->and($reveal['total'])->toBe(2)
        ->and(collect($reveal['answers'])->pluck('text'))->toContain('Para hacer fotosíntesis');
});

it('takes up to 3 ideas per student and groups them on the board', function () {
    Event::fake([QuestionEnded::class]);
    [, $pin, $tokens, $uuid] = salaLibre('brainstorm', 2);

    enviar($pin, $tokens['J1'], $uuid, ['ideas' => ['a', 'b', 'c', 'd']])->assertStatus(422); // más de 3
    enviar($pin, $tokens['J1'], $uuid, ['ideas' => ['', '  ']])->assertStatus(422);           // vacías
    enviar($pin, $tokens['J1'], $uuid, ['ideas' => ['Reciclar', 'reciclar', 'Plantar árboles']])->assertOk(); // repetida → 1
    enviar($pin, $tokens['J2'], $uuid, ['ideas' => ['RECICLAR', 'Usar bici']])->assertOk();

    $reveal = revealEmitido();
    expect($reveal['kind'])->toBe('ideas')
        ->and(TextModerator::normalize($reveal['ideas'][0]['text']))->toBe('reciclar')
        ->and($reveal['ideas'][0]['count'])->toBe(2)
        ->and($reveal['total'])->toBe(4);
});

it('does not score free text nor break streaks, and tells the phone it was not scored', function () {
    [, $pin, $tokens, $uuid] = salaLibre('open', 1);
    $j1 = array_key_first(app(GameRepository::class)->players($pin));
    Redis::hset("game:$pin:players", $j1, json_encode([...json_decode(Redis::hget("game:$pin:players", $j1), true), 'streak' => 2]));

    enviar($pin, $tokens['J1'], $uuid, ['text' => 'Mi opinión'])->assertOk();

    $me = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->json();
    expect($me['score'])->toBe(0)
        ->and($me['streak'])->toBe(2)
        ->and($me['last_result']['scored'])->toBeFalse()
        ->and($me['last_result']['answered'])->toBeTrue();
});

it('sends the limits to the phone and never options', function () {
    [, $pin, $tokens] = salaLibre('brainstorm', 1);

    $q = jugar($tokens['J1'])->getJson("/api/v1/games/$pin/me")->json('question');

    expect($q)->toMatchArray(['kind' => 'brainstorm', 'max_entries' => 3, 'max_length' => 60])
        ->and($q)->not->toHaveKey('options');
});

it('keeps the reveal when the projector reloads during the results', function () {
    [$host, $pin, $tokens, $uuid] = salaLibre('wordcloud', 1);
    enviar($pin, $tokens['J1'], $uuid, ['text' => 'Agua']);

    comoDocente($host)->getJson("/api/v1/games/$pin/state")
        ->assertJsonPath('state', 'results')
        ->assertJsonPath('reveal.kind', 'cloud')
        ->assertJsonPath('reveal.words.0.text', 'Agua');
});

it('persists free text and ideas when the game ends', function () {
    [$host, $pin, $tokens, $uuid] = salaLibre('brainstorm', 1);
    enviar($pin, $tokens['J1'], $uuid, ['ideas' => ['Reciclar', 'Compostar']]);
    $gameId = Redis::hget("game:$pin", 'game_id');

    comoDocente($host)->postJson("/api/v1/games/$pin/next"); // results → podium
    comoDocente($host)->postJson("/api/v1/games/$pin/next"); // podium → ended

    expect(Game::find($gameId)->status)->toBe('ended')
        ->and(PlayerAnswer::first()->selected)->toBe(['Reciclar', 'Compostar']);
});

it('saves the new types without options and requires a time limit', function () {
    $host = User::factory()->create();

    comoDocente($host)->postJson('/api/v1/quizzes', [
        'title' => 'Opiniones',
        'questions' => [
            ['kind' => 'wordcloud', 'title' => 'Una palabra', 'time_limit' => 30],
            ['kind' => 'open', 'title' => 'Contame', 'time_limit' => 60, 'options' => []],
            ['kind' => 'brainstorm', 'title' => 'Ideas', 'time_limit' => 90],
        ],
    ])->assertCreated()->assertJsonPath('data.questions.2.kind', 'brainstorm');

    comoDocente($host)->postJson('/api/v1/quizzes', [
        'title' => 'Mal',
        'questions' => [
            ['kind' => 'wordcloud', 'title' => 'Sin tiempo'],
            ['kind' => 'open', 'title' => 'Con opciones', 'time_limit' => 30, 'options' => [['text' => 'x', 'is_correct' => false]]],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors(['questions.0.time_limit', 'questions.1.options']);
});

it('reports what students wrote and ignores unscored questions for accuracy', function () {
    [$host, $pin, $tokens] = salaCon(function (Quiz $quiz) {
        crearLibre($quiz, 'open', 0);
        $q = $quiz->questions()->create(['position' => 1, 'kind' => 'quiz', 'title' => '¿2+2?', 'time_limit' => 20, 'points_mode' => 'standard']);
        $q->options()->createMany([['position' => 0, 'text' => '4', 'is_correct' => true], ['position' => 1, 'text' => '5', 'is_correct' => false]]);
    }, jugadores: 1);
    $next = fn () => comoDocente($host)->postJson("/api/v1/games/$pin/next");

    comoDocente($host)->postJson("/api/v1/games/$pin/start");
    lanzarPregunta($pin);
    enviar($pin, $tokens['J1'], Redis::hget("game:$pin", 'question_uuid'), ['text' => 'Me gustó la clase']);
    $next();                                                    // leaderboard
    $next();                                                    // get_ready(1)
    lanzarPregunta($pin);
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers", ['question_index' => 1,
        'question_uuid' => Redis::hget("game:$pin", 'question_uuid'), 'selected' => [0]]); // acierta
    $next();                                                    // podium
    $gameId = Redis::hget("game:$pin", 'game_id');
    $next();                                                    // ended

    // 1 de 1 preguntas puntuables acertada: 100% (la abierta no cuenta).
    comoDocente($host)->getJson("/api/v1/reports/games/$gameId")
        ->assertJsonPath('accuracy', 100)
        ->assertJsonPath('hardest_question.title', '¿2+2?');

    comoDocente($host)->getJson("/api/v1/reports/games/$gameId/questions")
        ->assertJsonPath('questions.0.scored', false)
        ->assertJsonPath('questions.0.accuracy', null)
        ->assertJsonPath('questions.0.texts.0', ['text' => 'Me gustó la clase', 'count' => 1])
        ->assertJsonPath('questions.1.accuracy', 100)
        ->assertJsonPath('questions.1.texts', null);
});
