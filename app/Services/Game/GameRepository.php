<?php

declare(strict_types=1);

namespace App\Services\Game;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * ÚNICO punto de acceso al estado efímero de partidas en Redis (plan §3).
 * Keys:
 *   pin:{pin}                → game_id (SET NX: garantía de PIN único activo)
 *   game:{pin}               → HASH estado de la sala
 *   game:{pin}:players       → HASH player_uuid → json{nickname, score, streak, connected}
 *   game:{pin}:nicknames     → SET nicknames normalizados
 *   game:{pin}:answers:{i}   → HASH player_uuid → json respuesta (F3)
 *   game:{pin}:lb            → ZSET score → player_uuid (F4)
 *   psession:{token}         → json{pin, player_uuid} (reconexión, F5)
 */
class GameRepository
{
    /*
     * Scripts Lua: Redis ejecuta cada uno de forma ATÓMICA (nada se intercala).
     * Son la base de la concurrencia de la partida: reemplazan secuencias
     * "leer → chequear → escribir" que dos requests podían intercalar.
     */

    /** CAS de estado. KEYS: game · ARGV: from, to, now_ms, [campo, valor]… → {1, version} | {0, estado_actual|''} */
    private const LUA_TRANSITION = <<<'LUA'
        local cur = redis.call('HGET', KEYS[1], 'state')
        if not cur then return {0, ''} end
        if cur ~= ARGV[1] then return {0, cur} end
        for i = 4, #ARGV, 2 do redis.call('HSET', KEYS[1], ARGV[i], ARGV[i + 1]) end
        redis.call('HSET', KEYS[1], 'state', ARGV[2], 'last_activity', ARGV[3])
        return {1, redis.call('HINCRBY', KEYS[1], 'state_version', 1)}
        LUA;

    /**
     * Pasa a un estado terminal desde cualquier estado no terminal (desde el
     * podio es 'ended': la partida se jugó entera). KEYS: game · ARGV:
     * terminal, now_ms → {1, version, previo} | {0, estado|''}
     */
    private const LUA_CLAIM_TERMINAL = <<<'LUA'
        local cur = redis.call('HGET', KEYS[1], 'state')
        if not cur then return {0, ''} end
        if cur == 'ended' or cur == 'aborted' then return {0, cur} end
        local target = ARGV[1]
        if cur == 'podium' then target = 'ended' end
        redis.call('HSET', KEYS[1], 'state', target, 'last_activity', ARGV[2])
        return {1, redis.call('HINCRBY', KEYS[1], 'state_version', 1), cur}
        LUA;

    /**
     * Registra la respuesta SOLO si esa pregunta sigue abierta y el jugador
     * sigue en la sala. KEYS: game, answers, players · ARGV: question_uuid,
     * index, player_uuid, json, ttl → 1 ok · 0 ya respondió · -1 cerrada · -2 expulsado
     */
    private const LUA_RECORD_ANSWER = <<<'LUA'
        local st = redis.call('HMGET', KEYS[1], 'state', 'question_uuid', 'current_index')
        if st[1] ~= 'question' or st[2] ~= ARGV[1] or st[3] ~= ARGV[2] then return -1 end
        if redis.call('HEXISTS', KEYS[3], ARGV[3]) == 0 then return -2 end
        if redis.call('HSETNX', KEYS[2], ARGV[3], ARGV[4]) == 0 then return 0 end
        redis.call('EXPIRE', KEYS[2], ARGV[5])
        return 1
        LUA;

    /**
     * Aplica el puntaje de una pregunta UNA sola vez (marca scored_question) y
     * solo a jugadores que siguen en la sala; si la sala ya no existe, no
     * escribe nada. KEYS: game, players, lb, answers · ARGV: question_uuid,
     * json{players, scores, answers}, ttl → 1 aplicado · 0 ya estaba · -1 sin sala
     */
    private const LUA_APPLY_SCORES = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then return -1 end
        if redis.call('HGET', KEYS[1], 'scored_question') == ARGV[1] then return 0 end
        local data = cjson.decode(ARGV[2])
        for uuid, pjson in pairs(data.players) do
            if redis.call('HEXISTS', KEYS[2], uuid) == 1 then
                redis.call('HSET', KEYS[2], uuid, pjson)
                redis.call('ZADD', KEYS[3], data.scores[uuid], uuid)
                local a = data.answers[uuid]
                if a then redis.call('HSET', KEYS[4], uuid, a) end
            end
        end
        redis.call('EXPIRE', KEYS[3], ARGV[3])
        redis.call('HSET', KEYS[1], 'scored_question', ARGV[1])
        return 1
        LUA;

    /**
     * Alta atómica de jugador: estado, candado, cupo y apodo único en un paso.
     * KEYS: game, players, nicknames · ARGV: uuid, json, normalizado, max, ttl, now_ms
     */
    private const LUA_JOIN = <<<'LUA'
        local st = redis.call('HMGET', KEYS[1], 'state', 'locked')
        if not st[1] then return 'PIN_NOT_FOUND' end
        if st[1] ~= 'lobby' then return 'ALREADY_STARTED' end
        if st[2] == '1' then return 'GAME_LOCKED' end
        if redis.call('HLEN', KEYS[2]) >= tonumber(ARGV[4]) then return 'ROOM_FULL' end
        if redis.call('SADD', KEYS[3], ARGV[3]) == 0 then return 'NICKNAME_TAKEN' end
        redis.call('HSET', KEYS[2], ARGV[1], ARGV[2])
        redis.call('EXPIRE', KEYS[2], ARGV[5])
        redis.call('EXPIRE', KEYS[3], ARGV[5])
        redis.call('HSET', KEYS[1], 'last_activity', ARGV[6])
        return 'OK'
        LUA;

    /** HSET solo si la sala existe (no resucita una sala ya destruida). KEYS: game · ARGV: campo, valor… */
    private const LUA_HSET_IF_EXISTS = <<<'LUA'
        if redis.call('EXISTS', KEYS[1]) == 0 then return 0 end
        for i = 1, #ARGV, 2 do redis.call('HSET', KEYS[1], ARGV[i], ARGV[i + 1]) end
        return 1
        LUA;

    /** Expulsión atómica: roster, apodo, ranking y su respuesta a la pregunta en curso. KEYS: game, players, nicknames, lb · ARGV: uuid, normalizado */
    private const LUA_REMOVE_PLAYER = <<<'LUA'
        redis.call('HDEL', KEYS[2], ARGV[1])
        redis.call('SREM', KEYS[3], ARGV[2])
        redis.call('ZREM', KEYS[4], ARGV[1])
        local idx = redis.call('HGET', KEYS[1], 'current_index')
        if idx and tonumber(idx) >= 0 then
            redis.call('HDEL', KEYS[1] .. ':answers:' .. idx, ARGV[1])
        end
        return 1
        LUA;

    /**
     * Ejecuta un script Lua. phpredis NO lanza excepción si el script falla
     * (error de Lua, OOM…): devuelve false. Ningún script nuestro devuelve
     * nil, así que false siempre es un error y lo convertimos en excepción
     * (si no, un fallo se leería como "ya aplicado" o "ya respondió").
     */
    private function script(string $lua, int $numKeys, string ...$args): mixed
    {
        $client = Redis::connection()->client();
        $client->clearLastError();

        $result = Redis::eval($lua, $numKeys, ...$args);

        if ($result === false) {
            throw new RuntimeException('Script Lua de la partida falló: '.($client->getLastError() ?? 'error desconocido'));
        }

        return $result;
    }

    private function ttl(): int
    {
        return (int) config('poliquiz.game_ttl');
    }

    private function nowMs(): string
    {
        return (string) ServerClock::nowMs();
    }

    /* ------------------------------- PIN ------------------------------- */

    /** Reserva un PIN de 6 dígitos único entre salas activas. */
    public function reservePin(string $gameId): string
    {
        do {
            $pin = (string) random_int(100000, 999999);
            // SET NX: solo triunfa si el PIN no está tomado.
            $reserved = Redis::set("pin:$pin", $gameId, 'EX', $this->ttl(), 'NX');
        } while (! $reserved);

        return $pin;
    }

    /** Libera una reserva de PIN solo si sigue siendo de esta partida. */
    public function releasePin(string $pin, string $gameId): void
    {
        $this->script(
            "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0",
            1, "pin:$pin", $gameId,
        );
    }

    /* ----------------------------- Estado ----------------------------- */

    public function initGame(string $pin, array $state): void
    {
        Redis::hmset("game:$pin", $state);
        Redis::expire("game:$pin", $this->ttl());
    }

    /** @return array<string,string>|null */
    public function getState(string $pin): ?array
    {
        $state = Redis::hgetall("game:$pin");

        return $state === [] ? null : $state;
    }

    /**
     * Escribe campos del estado SOLO si la sala sigue existiendo: un HSET
     * tardío (tras destroy) recrearía la key sin TTL y quedaría huérfana.
     */
    public function updateState(string $pin, array $fields): bool
    {
        $args = [];
        foreach ($fields as $field => $value) {
            $args[] = (string) $field;
            $args[] = (string) $value;
        }

        return (int) $this->script(self::LUA_HSET_IF_EXISTS, 1, "game:$pin", ...$args) === 1;
    }

    /**
     * Compare-and-set del estado: pasa de $from a $to SOLO si el estado actual
     * sigue siendo $from, en un único paso atómico (con $extra y last_activity).
     *
     * @param  array<string,string>  $extra
     * @return array{ok: true, version: int}|array{ok: false, current: ?string}
     */
    public function compareAndTransition(string $pin, string $from, string $to, array $extra = []): array
    {
        $args = [$from, $to, $this->nowMs()];
        foreach ($extra as $field => $value) {
            $args[] = (string) $field;
            $args[] = (string) $value;
        }

        [$ok, $value] = $this->script(self::LUA_TRANSITION, 1, "game:$pin", ...$args);

        return $ok === 1
            ? ['ok' => true, 'version' => (int) $value]
            : ['ok' => false, 'current' => $value === '' ? null : (string) $value];
    }

    /**
     * Lleva la sala a un estado terminal (ended/aborted) desde CUALQUIER estado
     * no terminal. Solo un llamador gana: el resto recibe null.
     *
     * @return array{version: int, previous: string}|null
     */
    public function claimTerminal(string $pin, string $terminal): ?array
    {
        $result = $this->script(self::LUA_CLAIM_TERMINAL, 1, "game:$pin", $terminal, $this->nowMs());

        return $result[0] === 1 ? ['version' => (int) $result[1], 'previous' => (string) $result[2]] : null;
    }

    /**
     * Renueva el TTL de todas las keys de la sala, incluida la reserva del PIN
     * (si expirara con la sala viva, otra partida podría tomar el mismo PIN).
     * Va en un pipeline: un solo viaje a Redis.
     */
    public function touch(string $pin): void
    {
        $keys = [...$this->allKeys($pin), "pin:$pin"];
        $ttl = $this->ttl();

        Redis::pipeline(function ($pipe) use ($keys, $ttl): void {
            foreach ($keys as $key) {
                $pipe->expire($key, $ttl);
            }
        });
    }

    /* ---------------------------- Jugadores ---------------------------- */

    /**
     * Alta atómica (estado lobby, sala sin candado, cupo y apodo libre se
     * verifican y reservan en un solo paso). Devuelve 'OK' o el código de error.
     */
    public function joinPlayer(string $pin, string $uuid, string $nickname, string $normalized, int $maxPlayers): string
    {
        $json = json_encode(['nickname' => $nickname, 'score' => 0, 'streak' => 0, 'connected' => true]);

        return (string) $this->script(
            self::LUA_JOIN, 3,
            "game:$pin", "game:$pin:players", "game:$pin:nicknames",
            $uuid, $json, $normalized, (string) $maxPlayers, (string) $this->ttl(), $this->nowMs(),
        );
    }

    public function removePlayer(string $pin, string $uuid, string $normalized): void
    {
        $this->script(
            self::LUA_REMOVE_PLAYER, 4,
            "game:$pin", "game:$pin:players", "game:$pin:nicknames", "game:$pin:lb",
            $uuid, $normalized,
        );
    }

    /** @return array<string,array> player_uuid → datos */
    public function players(string $pin): array
    {
        return array_map(
            fn (string $json) => json_decode($json, true),
            Redis::hgetall("game:$pin:players")
        );
    }

    public function player(string $pin, string $uuid): ?array
    {
        $json = Redis::hget("game:$pin:players", $uuid);

        return $json ? json_decode($json, true) : null;
    }

    public function playersCount(string $pin): int
    {
        return (int) Redis::hlen("game:$pin:players");
    }

    /* ----------------------- Snapshot del quiz ----------------------- */

    /** Snapshot completo del quiz que se está jugando (guardado al crear). */
    public function snapshot(string $pin): ?array
    {
        $json = Redis::hget("game:$pin", 'quiz_snapshot');

        return $json ? json_decode($json, true) : null;
    }

    /* -------------------- Respuestas de una pregunta -------------------- */

    public const ANSWER_RECORDED = 1;

    public const ANSWER_DUPLICATE = 0;

    public const ANSWER_CLOSED = -1;

    public const ANSWER_NOT_IN_ROOM = -2;

    /**
     * Registra la respuesta atómicamente contra el estado: solo entra si ESA
     * pregunta sigue abierta (nunca después de que el cierre leyó las
     * respuestas), el jugador sigue en la sala y no había respondido (HSETNX).
     *
     * @return int una de las constantes ANSWER_*
     */
    public function recordAnswer(string $pin, int $index, string $questionUuid, string $uuid, array $data): int
    {
        return (int) $this->script(
            self::LUA_RECORD_ANSWER, 3,
            "game:$pin", "game:$pin:answers:$index", "game:$pin:players",
            $questionUuid, (string) $index, $uuid, json_encode($data), (string) $this->ttl(),
        );
    }

    /**
     * Aplica jugadores, ranking y respuestas enriquecidas de una pregunta en un
     * solo paso, exactamente una vez por question_uuid (ver LUA_APPLY_SCORES).
     *
     * @param  array<string,array>  $players  uuid → datos nuevos del jugador
     * @param  array<string,array>  $answers  uuid → respuesta enriquecida
     * @return bool true si este llamado lo aplicó
     */
    public function applyScores(string $pin, int $index, string $questionUuid, array $players, array $answers): bool
    {
        $payload = [
            'players' => (object) array_map(fn (array $p) => json_encode($p), $players),
            'scores' => (object) array_map(fn (array $p) => (string) (int) $p['score'], $players),
            'answers' => (object) array_map(fn (array $a) => json_encode($a), $answers),
        ];

        return (int) $this->script(
            self::LUA_APPLY_SCORES, 4,
            "game:$pin", "game:$pin:players", "game:$pin:lb", "game:$pin:answers:$index",
            $questionUuid, json_encode($payload), (string) $this->ttl(),
        ) === 1;
    }

    public function answersCount(string $pin, int $index): int
    {
        return (int) Redis::hlen("game:$pin:answers:$index");
    }

    /**
     * Candado con dueño y vencimiento (lo libera Redis aunque el proceso
     * muera). Lo usa el cierre de pregunta: quien lo tiene puntúa y revela.
     * Devuelve el token de dueño, o null si otro lo tiene.
     */
    public function acquireLease(string $name, int $ttlMs): ?string
    {
        $token = Str::random(20);

        return Redis::set("lease:$name", $token, 'PX', $ttlMs, 'NX') ? $token : null;
    }

    /** Libera el candado solo si sigue siendo nuestro. */
    public function releaseLease(string $name, string $token): void
    {
        $this->script("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0", 1, "lease:$name", $token);
    }

    /** @return array<string,array> player_uuid → respuesta */
    public function answers(string $pin, int $index): array
    {
        return array_map(
            fn (string $json) => json_decode($json, true),
            Redis::hgetall("game:$pin:answers:$index")
        );
    }

    /* --------------------------- Leaderboard --------------------------- */

    /** @return array<int,array{uuid:string,score:int}> mayor a menor */
    public function top(string $pin, int $count): array
    {
        $raw = Redis::zrevrange("game:$pin:lb", 0, $count - 1, ['withscores' => true]);
        $result = [];
        foreach ($raw as $uuid => $score) {
            $result[] = ['uuid' => $uuid, 'score' => (int) $score];
        }

        return $result;
    }

    /** Posición (1º, 2º, …) de un jugador en el ranking. */
    public function rank(string $pin, string $uuid): ?int
    {
        $rank = Redis::zrevrank("game:$pin:lb", $uuid);

        return $rank === false || $rank === null ? null : $rank + 1;
    }

    /* ------------------------ Sesiones de jugador ------------------------ */

    public function createSession(string $token, string $pin, string $uuid): void
    {
        Redis::setex("psession:$token", $this->ttl(), json_encode([
            'pin' => $pin,
            'player_uuid' => $uuid,
        ]));
    }

    /** @return array{pin:string, player_uuid:string}|null */
    public function session(string $token): ?array
    {
        $json = Redis::get("psession:$token");

        return $json ? json_decode($json, true) : null;
    }

    public function deleteSession(string $token): void
    {
        Redis::del("psession:$token");
    }

    /* ----------------------------- Limpieza ----------------------------- */

    public function destroy(string $pin): void
    {
        Redis::del([...$this->allKeys($pin), "pin:$pin"]);
    }

    private function allKeys(string $pin): array
    {
        $keys = ["game:$pin", "game:$pin:players", "game:$pin:nicknames", "game:$pin:lb"];

        // Una key de respuestas por pregunta del quiz. Si la sala ya no tiene
        // estado (no sabemos cuántas), cubrimos el máximo posible.
        $total = Redis::hget("game:$pin", 'questions_total');
        $total = $total === false || $total === null ? 100 : max(0, (int) $total);
        for ($i = 0; $i < $total; $i++) {
            $keys[] = "game:$pin:answers:$i";
        }

        return $keys;
    }
}
