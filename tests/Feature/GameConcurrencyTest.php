<?php

declare(strict_types=1);

use App\Actions\Game\CloseQuestion;
use App\Actions\Game\EndGame;
use App\Actions\Game\FinalizeGame;
use App\Actions\Game\LaunchQuestion;
use App\Enums\GameState;
use App\Events\Game\GameEnded;
use App\Events\Game\QuestionEnded;
use App\Exceptions\GameException;
use App\Jobs\CloseQuestionJob;
use App\Jobs\StartQuestionJob;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Question;
use App\Models\Quiz;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/*
 * Carreras del game loop. PHP no ejecuta en paralelo dentro de un test, así
 * que cada caso fuerza el intercalado peligroso a mano (por ejemplo, dejando
 * la sala como la dejaría un proceso que ganó el candado y todavía no
 * terminó) y verifica que el resultado sea el correcto.
 */

beforeEach(function () {
    config(['database.redis.default.database' => 15]);
    Redis::connection()->client()->flushdb();
    Bus::fake([StartQuestionJob::class, CloseQuestionJob::class]);
});

/** Sala con la pregunta 0 en juego. Devuelve [host, pin, tokens, question_uuid]. */
function preguntaEnJuego(int $jugadores = 2): array
{
    [$host, $pin, $tokens] = armarPartida($jugadores);
    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();
    lanzarPregunta($pin);

    return [$host, $pin, $tokens, Redis::hget("game:$pin", 'question_uuid')];
}

function responder0(string $pin, string $token, string $uuid, int $opcion = 0)
{
    return jugar($token)->postJson("/api/v1/games/$pin/answers",
        ['question_index' => 0, 'question_uuid' => $uuid, 'selected' => [$opcion]]);
}

function puntajes(string $pin): array
{
    return array_map(fn (array $p) => $p['score'], app(GameRepository::class)->players($pin));
}

/* ───────────────────── 1. Doble cierre / doble puntaje ───────────────────── */

it('lets exactly one caller win a state transition', function () {
    [, $pin] = preguntaEnJuego();
    $repo = app(GameRepository::class);

    $primero = $repo->compareAndTransition($pin, 'question', 'results');
    $segundo = $repo->compareAndTransition($pin, 'question', 'results');

    expect($primero['ok'])->toBeTrue()
        ->and($segundo)->toBe(['ok' => false, 'current' => 'results']);
});

it('applies the scores of a question exactly once', function () {
    [, $pin, $tokens, $uuid] = preguntaEnJuego(1);
    responder0($pin, $tokens['J1'], $uuid); // único jugador → cierra y puntúa
    $antes = puntajes($pin);
    expect(array_values($antes)[0])->toBeGreaterThan(0);

    // Un segundo proceso que llegó a calcular los mismos puntajes no los suma.
    $jugadores = app(GameRepository::class)->players($pin);
    $duplicado = array_map(fn ($p) => [...$p, 'score' => $p['score'] * 2], $jugadores);

    expect(app(GameRepository::class)->applyScores($pin, 0, $uuid, $duplicado, []))->toBeFalse()
        ->and(puntajes($pin))->toBe($antes);
});

it('does not double score when a second closer arrives while the first is mid-flight', function () {
    Event::fake([QuestionEnded::class]);
    [, $pin, , $uuid] = preguntaEnJuego(1);
    $repo = app(GameRepository::class);
    Redis::hset("game:$pin:answers:0", array_key_first($repo->players($pin)),
        json_encode(['selected' => [0], 'received_at' => 0, 'response_ms' => 1000]));

    // El "ganador" ya tomó la transición y el candado, y sigue trabajando.
    $repo->compareAndTransition($pin, 'question', 'results', ['closed_at' => '1']);
    $repo->acquireLease("close:$pin:$uuid", 15000);

    $closer = app(CloseQuestion::class);
    expect($closer->handle($pin, $uuid))->toBe(CloseQuestion::NOOP)                 // cierre inline: se retira
        ->and($closer->handle($pin, $uuid, resume: true))->toBe(CloseQuestion::BUSY) // job: reintenta luego
        ->and(array_values(puntajes($pin))[0])->toBe(0);
    Event::assertNotDispatched(QuestionEnded::class);
});

it('takes over a close whose owner died once its lease expires', function () {
    Event::fake([QuestionEnded::class]);
    [, $pin, , $uuid] = preguntaEnJuego(1);
    $repo = app(GameRepository::class);
    $repo->compareAndTransition($pin, 'question', 'results', ['closed_at' => '1']);
    $repo->acquireLease("close:$pin:$uuid", 50); // el dueño murió sin liberarlo

    usleep(80_000);

    expect(app(CloseQuestion::class)->handle($pin, $uuid, resume: true))->toBe(CloseQuestion::CLOSED)
        ->and(Redis::hget("game:$pin", 'revealed_question'))->toBe($uuid);
    Event::assertDispatchedTimes(QuestionEnded::class, 1);
});

/* ─────────────── 2. Respuesta aceptada pero nunca puntuada ─────────────── */

it('rejects an answer that arrives after the close already read the answers', function () {
    [, $pin, , $uuid] = preguntaEnJuego(2);
    $repo = app(GameRepository::class);
    $j1 = array_key_first($repo->players($pin));

    // El cierre gana justo entre el chequeo de estado de SubmitAnswer y el registro.
    $repo->compareAndTransition($pin, 'question', 'results');

    expect($repo->recordAnswer($pin, 0, $uuid, $j1, ['selected' => [0]]))->toBe(GameRepository::ANSWER_CLOSED)
        ->and(Redis::hlen("game:$pin:answers:0"))->toBe(0); // no quedó "aceptada" sin puntuar
});

it('rejects answers for a stale question or from a removed player', function () {
    [, $pin, , $uuid] = preguntaEnJuego(2);
    $repo = app(GameRepository::class);
    $j1 = array_key_first($repo->players($pin));

    expect($repo->recordAnswer($pin, 0, 'otra-pregunta', $j1, []))->toBe(GameRepository::ANSWER_CLOSED)
        ->and($repo->recordAnswer($pin, 0, $uuid, 'jugador-inexistente', []))->toBe(GameRepository::ANSWER_NOT_IN_ROOM)
        ->and($repo->recordAnswer($pin, 0, $uuid, $j1, ['selected' => [0]]))->toBe(GameRepository::ANSWER_RECORDED)
        ->and($repo->recordAnswer($pin, 0, $uuid, $j1, ['selected' => [1]]))->toBe(GameRepository::ANSWER_DUPLICATE);
});

/* ───────────────── 3. Expulsados y "todos respondieron" ───────────────── */

it('does not count a kicked player\'s answer towards closing the question', function () {
    Event::fake([QuestionEnded::class]);
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(3);
    $uuids = array_keys(app(GameRepository::class)->players($pin));

    responder0($pin, $tokens['J1'], $uuid)->assertOk();
    comoDocente($host)->deleteJson("/api/v1/games/$pin/players/{$uuids[0]}")->assertSuccessful();
    responder0($pin, $tokens['J2'], $uuid)->assertOk();

    // Roster = J2, J3 y solo respondió J2: la pregunta sigue abierta para J3.
    expect(Redis::hget("game:$pin", 'state'))->toBe('question');

    responder0($pin, $tokens['J3'], $uuid, 1)->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('results')
        ->and(Redis::hexists("game:$pin:players", $uuids[0]))->toBeFalse(); // no resucita

    // El expulsado tampoco aparece en la distribución.
    Event::assertDispatched(QuestionEnded::class, fn (QuestionEnded $e) => collect($e->broadcastWith()['reveal']['distribution'])->sum('count') === 2);
});

it('closes the question when the host kicks the last player who had not answered', function () {
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(2);
    $uuids = array_keys(app(GameRepository::class)->players($pin));

    responder0($pin, $tokens['J1'], $uuid)->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('question');

    comoDocente($host)->deleteJson("/api/v1/games/$pin/players/{$uuids[1]}")->assertSuccessful();

    expect(Redis::hget("game:$pin", 'state'))->toBe('results');
});

/* ───────────── 4. Fallo después del candado (broadcast caído) ───────────── */

it('recovers a close whose broadcast failed: the job reveals it without re-scoring', function () {
    [, $pin, $tokens, $uuid] = preguntaEnJuego(1);

    // El primer QuestionEnded falla (Reverb caído); los siguientes andan.
    $fallas = 1;
    $emitidos = 0;
    Event::listen(QuestionEnded::class, function () use (&$fallas, &$emitidos) {
        if ($fallas-- > 0) {
            throw new RuntimeException('Reverb no responde');
        }
        $emitidos++;
    });

    // El jugador NO ve un error: su respuesta quedó registrada y puntuada.
    responder0($pin, $tokens['J1'], $uuid)->assertOk();
    $puntaje = array_values(puntajes($pin))[0];
    expect(Redis::hget("game:$pin", 'state'))->toBe('results')
        ->and($puntaje)->toBeGreaterThan(0)
        ->and(Redis::hget("game:$pin", 'revealed_question'))->toBeFalse();

    // El job de timeout lo retoma (el candado se liberó al fallar).
    $job = (new CloseQuestionJob($pin, $uuid))->withFakeQueueInteractions();
    $job->handle(app(CloseQuestion::class), app(GameRepository::class));

    $job->assertNotReleased();
    expect($emitidos)->toBe(1)
        ->and(Redis::hget("game:$pin", 'revealed_question'))->toBe($uuid)
        ->and(array_values(puntajes($pin))[0])->toBe($puntaje); // sin doble puntaje

    // Un reintento más es un no-op.
    (new CloseQuestionJob($pin, $uuid))->withFakeQueueInteractions()->handle(app(CloseQuestion::class), app(GameRepository::class));
    expect($emitidos)->toBe(1);
});

it('re-queues the timeout job while another close is still in progress', function () {
    [, $pin, , $uuid] = preguntaEnJuego(1);
    app(GameRepository::class)->compareAndTransition($pin, 'question', 'results', ['closed_at' => '1']);
    app(GameRepository::class)->acquireLease("close:$pin:$uuid", 15000);

    $job = (new CloseQuestionJob($pin, $uuid))->withFakeQueueInteractions();
    $job->handle(app(CloseQuestion::class), app(GameRepository::class));

    $job->assertReleased(3);
});

/* ─────────────────── 5. Fin de partida en paralelo ─────────────────── */

it('lets only one caller claim the end of a game', function () {
    [, $pin] = preguntaEnJuego();
    $machine = app(GameStateMachine::class);

    expect($machine->claimEnd($pin))->not->toBeNull()
        ->and($machine->claimEnd($pin))->toBeNull();
});

it('never resurrects redis keys when a close lands after the room was destroyed', function () {
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(2);
    responder0($pin, $tokens['J1'], $uuid)->assertOk();
    $repo = app(GameRepository::class);
    $jugadores = $repo->players($pin);

    comoDocente($host)->postJson("/api/v1/games/$pin/end")->assertSuccessful();

    // El cierre atrasado intenta escribir puntajes y estado.
    expect($repo->applyScores($pin, 0, $uuid, $jugadores, []))->toBeFalse()
        ->and($repo->updateState($pin, ['revealed_question' => $uuid]))->toBeFalse()
        ->and(app(CloseQuestion::class)->handle($pin, $uuid, resume: true))->toBe(CloseQuestion::NOOP)
        ->and(Redis::connection()->client()->keys("*game:$pin*"))->toBe([]);
});

it('persists once when the host ends the game twice', function () {
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(2);
    responder0($pin, $tokens['J1'], $uuid)->assertOk();
    $gameId = Redis::hget("game:$pin", 'game_id');

    app(EndGame::class)->handle($pin);
    app(EndGame::class)->handle($pin); // segundo click / barrido simultáneo

    expect(GamePlayer::where('game_id', $gameId)->count())->toBe(2)
        ->and(Game::find($gameId)->status)->toBe('aborted');
});

it('closing the room from the podium finishes the game instead of aborting it', function () {
    Event::fake([GameEnded::class]);
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(1);
    responder0($pin, $tokens['J1'], $uuid);                             // results(0)
    comoDocente($host)->postJson("/api/v1/games/$pin/next");            // leaderboard
    comoDocente($host)->postJson("/api/v1/games/$pin/next");            // get_ready(1)
    lanzarPregunta($pin);
    jugar($tokens['J1'])->postJson("/api/v1/games/$pin/answers", ['question_index' => 1,
        'question_uuid' => Redis::hget("game:$pin", 'question_uuid'), 'selected' => [0]]);
    comoDocente($host)->postJson("/api/v1/games/$pin/next");            // podium
    expect(Redis::hget("game:$pin", 'state'))->toBe('podium');
    $gameId = Redis::hget("game:$pin", 'game_id');

    comoDocente($host)->postJson("/api/v1/games/$pin/end")->assertSuccessful(); // la X en el podio

    expect(Game::find($gameId)->status)->toBe('ended');
    Event::assertNotDispatched(GameEnded::class, fn (GameEnded $e) => $e->aborted);
});

it('rejects a double "next" instead of advancing twice', function () {
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(1);
    responder0($pin, $tokens['J1'], $uuid);

    // Los dos clicks leyeron 'results'; el segundo llega cuando ya se avanzó.
    app(GameStateMachine::class)->transition($pin, GameState::Results, GameState::Leaderboard);

    expect(fn () => app(GameStateMachine::class)->transition($pin, GameState::Results, GameState::Leaderboard))
        ->toThrow(GameException::class);
});

/* ──────────────────────── 6. Barrido robusto ──────────────────────── */

function envejecer(string $pin): void
{
    $viejo = (int) (microtime(true) * 1000) - (config('poliquiz.abandoned_after') + 60) * 1000;
    Redis::hset("game:$pin", 'last_activity', (string) $viejo);
}

it('keeps sweeping other games when one of them fails', function () {
    [, $pinMalo] = preguntaEnJuego(1);
    [, $pinBueno] = preguntaEnJuego(1);
    envejecer($pinMalo);
    envejecer($pinBueno);

    // EndGame explota solo para una de las salas.
    app()->instance(EndGame::class, new class(app(GameStateMachine::class), app(FinalizeGame::class), app(GameRepository::class), $pinMalo) extends EndGame
    {
        public function __construct(GameStateMachine $m, FinalizeGame $f, GameRepository $r, private string $malo)
        {
            parent::__construct($m, $f, $r);
        }

        public function handle(string $pin): void
        {
            if ($pin === $this->malo) {
                throw new RuntimeException('boom');
            }
            parent::handle($pin);
        }
    });

    $this->travel(2)->minutes(); // el barrido ignora filas de menos de 1 min
    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful();

    expect(Game::where('pin', $pinBueno)->first()->status)->toBe('aborted')
        ->and(Game::where('pin', $pinMalo)->first()->status)->toBe('active'); // se reintenta en la próxima corrida
});

it('finalizes a game whose end was claimed but never finished', function () {
    [, $pin, $tokens, $uuid] = preguntaEnJuego(1);
    responder0($pin, $tokens['J1'], $uuid);
    $gameId = Redis::hget("game:$pin", 'game_id');

    app(GameStateMachine::class)->claimEnd($pin); // el proceso que cerraba murió acá
    envejecer($pin);

    $this->travel(2)->minutes(); // el barrido ignora filas de menos de 1 min
    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful();

    expect(Game::find($gameId)->status)->toBe('aborted')
        ->and(GamePlayer::where('game_id', $gameId)->count())->toBe(1) // se guardó lo jugado
        ->and(Redis::hgetall("game:$pin"))->toBe([]);
});

it('aborts a row whose pin now points to a different room, without touching that room', function () {
    [, $pin] = preguntaEnJuego(1);
    $fila = Game::where('pin', $pin)->first();
    Redis::hset("game:$pin", 'game_id', 'otra-partida'); // el PIN ya es de otra sala

    $this->travel(2)->minutes(); // el barrido ignora filas de menos de 1 min
    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful();

    expect($fila->fresh()->status)->toBe('aborted')
        ->and(Redis::hget("game:$pin", 'state'))->toBe('question'); // la otra sala sigue viva
});

it('picks another pin when redis lost a room whose row is still active', function () {
    [, $pinHuerfano] = preguntaEnJuego(1);
    Redis::connection()->client()->flushdb(); // Redis reiniciado: la fila quedó 'active' con ese PIN

    // La primera reserva sale justo ese PIN (Redis ya no lo conoce).
    app()->instance(GameRepository::class, new class($pinHuerfano) extends GameRepository
    {
        private bool $primera = true;

        public function __construct(private string $pinRepetido) {}

        public function reservePin(string $gameId): string
        {
            if ($this->primera) {
                $this->primera = false;
                Redis::set("pin:{$this->pinRepetido}", $gameId);

                return $this->pinRepetido;
            }

            return parent::reservePin($gameId);
        }
    });

    $quiz = Quiz::factory()->create();
    Question::factory()->for($quiz)->create();
    $res = comoDocente($quiz->user)->postJson('/api/v1/games', ['quiz_id' => $quiz->id])->assertCreated();

    expect($res->json('pin'))->not->toBe($pinHuerfano)
        ->and(Redis::exists("pin:$pinHuerfano"))->toBe(0)                  // la reserva fallida se liberó
        ->and(Redis::hget('game:'.$res->json('pin'), 'state'))->toBe('lobby');
});

/* ─────────────── 7. PIN reservado mientras la sala vive ─────────────── */

it('renews the pin reservation on every transition', function () {
    [$host, $pin] = armarPartida(1);
    Redis::expire("pin:$pin", 30); // a punto de expirar

    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();

    expect(Redis::ttl("pin:$pin"))->toBeGreaterThan(30);
});

/* ─────────────────── 8. Alta atómica de jugadores ─────────────────── */

it('claims nickname and seat atomically on join', function () {
    [, $pin] = armarPartida(1);
    $repo = app(GameRepository::class);

    expect($repo->joinPlayer($pin, 'u-1', 'Juan', 'juan', 100))->toBe('OK')
        ->and($repo->joinPlayer($pin, 'u-2', 'JUAN', 'juan', 100))->toBe('NICKNAME_TAKEN')
        ->and($repo->joinPlayer($pin, 'u-3', 'Ana', 'ana', 2))->toBe('ROOM_FULL') // J1 + Juan = 2
        ->and($repo->joinPlayer('000000', 'u-4', 'Leo', 'leo', 100))->toBe('PIN_NOT_FOUND')
        ->and(Redis::scard("game:$pin:nicknames"))->toBe(2);         // el rechazado no reservó apodo

    Redis::hset("game:$pin", 'locked', '1');
    expect($repo->joinPlayer($pin, 'u-5', 'Leo', 'leo', 100))->toBe('GAME_LOCKED');
});

/* ───────────── 9. Limpieza acotada a las preguntas del quiz ───────────── */

it('expires and destroys only the answer keys the quiz actually has', function () {
    [, $pin, $tokens, $uuid] = preguntaEnJuego(1);
    responder0($pin, $tokens['J1'], $uuid);
    Redis::hset("game:$pin:answers:1", 'x', '{}');

    app(GameRepository::class)->touch($pin);
    expect(Redis::ttl("game:$pin:answers:0"))->toBeGreaterThan(0)
        ->and(Redis::ttl("game:$pin:answers:1"))->toBeGreaterThan(0);

    app(GameRepository::class)->destroy($pin);
    expect(Redis::connection()->client()->keys("*game:$pin*"))->toBe([])
        ->and(Redis::exists("pin:$pin"))->toBe(0);
});

/* ──────── 10. La cola trunca el delay a segundos: los jobs esperan ──────── */

it('never closes a question before its deadline plus the network grace', function () {
    Event::fake([QuestionEnded::class]);
    [, $pin, , $uuid] = preguntaEnJuego(1);
    $gracia = config('poliquiz.answer_grace_ms');
    $cerrar = fn () => tap((new CloseQuestionJob($pin, $uuid))->withFakeQueueInteractions())
        ->handle(app(CloseQuestion::class), app(GameRepository::class));

    // La cola entregó el job ~0,5 s antes: se re-encola SIN dormir el worker.
    Redis::hset("game:$pin", 'ends_at', (string) ((int) (microtime(true) * 1000) + 200));
    $inicio = microtime(true);
    $cerrar()->assertReleased(1);
    expect(microtime(true) - $inicio)->toBeLessThan(0.1)
        ->and(Redis::hget("game:$pin", 'state'))->toBe('question');

    // Faltan unos pocos ms: espera en el lugar y cierra justo después del límite.
    $limite = (int) (microtime(true) * 1000) + 60;
    Redis::hset("game:$pin", 'ends_at', (string) ($limite - $gracia));
    $cerrar()->assertNotReleased();
    expect(Redis::hget("game:$pin", 'state'))->toBe('results')
        ->and((int) Redis::hget("game:$pin", 'closed_at'))->toBeGreaterThanOrEqual($limite);
});

it('re-queues the close job when the deadline is still far away', function () {
    [, $pin, , $uuid] = preguntaEnJuego(1);
    Redis::hset("game:$pin", 'ends_at', (string) ((int) (microtime(true) * 1000) + 9000));

    $job = (new CloseQuestionJob($pin, $uuid))->withFakeQueueInteractions();
    $job->handle(app(CloseQuestion::class), app(GameRepository::class));

    $job->assertReleased();
    expect(Redis::hget("game:$pin", 'state'))->toBe('question');
});

it('keeps the full get-ready reveal even if the queue delivers the start job early', function () {
    [$host, $pin] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();
    $uuid = Redis::hget("game:$pin", 'question_uuid');
    $hasta = (int) Redis::hget("game:$pin", 'ready_until');
    expect($hasta)->toBeGreaterThan((int) (microtime(true) * 1000) + 3000); // reveal de ~5 s

    // Entregado "a tiempo" según la cola pero con el reveal todavía corriendo.
    $job = (new StartQuestionJob($pin, $uuid))->withFakeQueueInteractions();
    $job->handle(app(LaunchQuestion::class), app(GameRepository::class));
    $job->assertReleased();
    expect(Redis::hget("game:$pin", 'state'))->toBe('get_ready');

    // Ya vencido el reveal, arranca la pregunta.
    Redis::hset("game:$pin", 'ready_until', '1');
    (new StartQuestionJob($pin, $uuid))->withFakeQueueInteractions()
        ->handle(app(LaunchQuestion::class), app(GameRepository::class));
    expect(Redis::hget("game:$pin", 'state'))->toBe('question');
});

it('reports a late answer to a question that just closed as TIME_EXPIRED', function () {
    [, $pin, $tokens, $uuid] = preguntaEnJuego(2);
    app(GameRepository::class)->compareAndTransition($pin, 'question', 'results');

    responder0($pin, $tokens['J1'], $uuid)->assertStatus(422)->assertJsonPath('code', 'TIME_EXPIRED');
});

/* ─────────── 11. Hallazgos de la segunda revisión (sobre estos fixes) ─────────── */

it('does not let the host leave the results until the scores are applied', function () {
    [$host, $pin, , $uuid] = preguntaEnJuego(1);
    // Cerrada (CAS ganado) pero el que cerraba murió antes de puntuar.
    app(GameRepository::class)->compareAndTransition($pin, 'question', 'results', ['closed_at' => '1']);

    comoDocente($host)->postJson("/api/v1/games/$pin/next")
        ->assertStatus(409)->assertJsonPath('code', 'RESULTS_PENDING');

    // El job retoma, puntúa y revela: recién ahí se puede avanzar.
    (new CloseQuestionJob($pin, $uuid))->withFakeQueueInteractions()
        ->handle(app(CloseQuestion::class), app(GameRepository::class));
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('leaderboard');
});

it('lets a room that was mid-game before this deploy leave the results', function () {
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(1);
    responder0($pin, $tokens['J1'], $uuid);
    Redis::hdel("game:$pin", 'closed_at', 'scored_question'); // cerrada por el código viejo

    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
});

it('lets the host unstick a room whose queue jobs never ran', function () {
    Event::fake([QuestionEnded::class]);
    [$host, $pin] = armarPartida(1);
    comoDocente($host)->postJson("/api/v1/games/$pin/start")->assertOk();

    // get_ready todavía corriendo: no se puede saltar.
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertStatus(409);

    // El StartQuestionJob se perdió y el reveal ya venció de sobra.
    Redis::hset("game:$pin", 'ready_until', '1');
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('question');

    // Pregunta en juego: no se puede cerrar antes de tiempo…
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertStatus(409);

    // …pero si el CloseQuestionJob tampoco llegó, el docente la cierra.
    Redis::hset("game:$pin", 'ends_at', '1');
    comoDocente($host)->postJson("/api/v1/games/$pin/next")->assertOk();
    expect(Redis::hget("game:$pin", 'state'))->toBe('results');
    Event::assertDispatchedTimes(QuestionEnded::class, 1);
});

it('lets the host retry ending a game whose finalization crashed', function () {
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(1);
    responder0($pin, $tokens['J1'], $uuid);
    $gameId = Redis::hget("game:$pin", 'game_id');

    app(GameStateMachine::class)->claimEnd($pin); // el proceso murió después de reclamar
    comoDocente($host)->postJson("/api/v1/games/$pin/end")->assertSuccessful();
    expect(Game::find($gameId)->status)->toBe('active'); // recién reclamado: puede seguir en curso

    Redis::hset("game:$pin", 'last_activity', '1');           // ya "frío"
    comoDocente($host)->postJson("/api/v1/games/$pin/end")->assertSuccessful();
    expect(Game::find($gameId)->status)->toBe('aborted')
        ->and(GamePlayer::where('game_id', $gameId)->count())->toBe(1)
        ->and(Redis::hgetall("game:$pin"))->toBe([]);
});

it('never lets the sweeper overwrite a game that finished in the meantime', function () {
    [, $pin] = preguntaEnJuego(1);
    $game = Game::where('pin', $pin)->first();
    Redis::connection()->client()->flushdb(); // sala ya desarmada…
    Game::whereKey($game->id)->update(['status' => 'ended']); // …porque terminó bien

    // El barrido trabaja con la fila "vieja" que leyó su cursor.
    $this->travel(2)->minutes();
    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful();

    expect($game->fresh()->status)->toBe('ended');
});

it('ignores a game that is being created right now', function () {
    $quiz = Quiz::factory()->create();
    $recien = Game::create(['user_id' => $quiz->user_id, 'quiz_id' => $quiz->id, 'quiz_snapshot' => [], 'status' => 'active']);

    $this->artisan('poliquiz:expire-abandoned')->assertSuccessful(); // sin PIN ni sala todavía

    expect($recien->fresh()->status)->toBe('active');
});

it('turns a failing Lua script into an exception instead of a silent false', function () {
    $repo = app(GameRepository::class);
    $script = (new ReflectionClass($repo))->getMethod('script');

    expect(fn () => $script->invoke($repo, "return redis.call('NO_EXISTE')", 0))
        ->toThrow(RuntimeException::class);
});

it('keeps the answered counter exact after kicking someone who had answered', function () {
    [$host, $pin, $tokens, $uuid] = preguntaEnJuego(3);
    $uuids = array_keys(app(GameRepository::class)->players($pin));
    responder0($pin, $tokens['J1'], $uuid);

    comoDocente($host)->deleteJson("/api/v1/games/$pin/players/{$uuids[0]}")->assertSuccessful();

    expect(app(GameRepository::class)->answersCount($pin, 0))->toBe(0)
        ->and(comoDocente($host)->getJson("/api/v1/games/$pin/state")->json('answered_count'))->toBe(0);
});

it('throttles PIN guessing from one IP without blocking a classroom', function () {
    [, $pin] = armarPartida(1);

    foreach (range(1, 60) as $i) {
        $this->postJson('/api/v1/games/'.str_pad((string) $i, 6, '9', STR_PAD_LEFT).'/join', ['nickname' => "x$i"])
            ->assertNotFound();
    }
    $this->postJson('/api/v1/games/123123/join', ['nickname' => 'y'])->assertStatus(429);
});
