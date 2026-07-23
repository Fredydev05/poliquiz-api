<?php

declare(strict_types=1);

namespace App\Services\Game;

use Illuminate\Support\Facades\Redis;

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
    private function ttl(): int
    {
        return (int) config('poliquiz.game_ttl');
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

    public function updateState(string $pin, array $fields): void
    {
        Redis::hmset("game:$pin", $fields);
    }

    /** Incrementa la versión de estado y la devuelve (orden total de eventos). */
    public function bumpVersion(string $pin): int
    {
        return (int) Redis::hincrby("game:$pin", 'state_version', 1);
    }

    /** Renueva el TTL de todas las keys de la sala (en cada transición). */
    public function touch(string $pin): void
    {
        foreach ($this->allKeys($pin) as $key) {
            Redis::expire($key, $this->ttl());
        }
    }

    /* ---------------------------- Jugadores ---------------------------- */

    public function addPlayer(string $pin, string $uuid, string $nickname, string $normalized): void
    {
        Redis::hset("game:$pin:players", $uuid, json_encode([
            'nickname' => $nickname,
            'score' => 0,
            'streak' => 0,
            'connected' => true,
        ]));
        Redis::sadd("game:$pin:nicknames", $normalized);
        Redis::expire("game:$pin:players", $this->ttl());
        Redis::expire("game:$pin:nicknames", $this->ttl());
    }

    public function removePlayer(string $pin, string $uuid, string $normalized): void
    {
        Redis::hdel("game:$pin:players", $uuid);
        Redis::srem("game:$pin:nicknames", $normalized);
        Redis::zrem("game:$pin:lb", $uuid);
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

    public function updatePlayer(string $pin, string $uuid, array $data): void
    {
        Redis::hset("game:$pin:players", $uuid, json_encode($data));
    }

    public function playersCount(string $pin): int
    {
        return (int) Redis::hlen("game:$pin:players");
    }

    public function nicknameTaken(string $pin, string $normalized): bool
    {
        return (bool) Redis::sismember("game:$pin:nicknames", $normalized);
    }

    /* ----------------------- Snapshot del quiz ----------------------- */

    /** Snapshot completo del quiz que se está jugando (guardado al crear). */
    public function snapshot(string $pin): ?array
    {
        $json = Redis::hget("game:$pin", 'quiz_snapshot');

        return $json ? json_decode($json, true) : null;
    }

    /* -------------------- Respuestas de una pregunta -------------------- */

    /**
     * Registra la respuesta con HSETNX: si el jugador ya respondió esta
     * pregunta devuelve false (imposible responder dos veces).
     */
    public function recordAnswer(string $pin, int $index, string $uuid, array $data): bool
    {
        $ok = (bool) Redis::hsetnx("game:$pin:answers:$index", $uuid, json_encode($data));
        if ($ok) {
            Redis::expire("game:$pin:answers:$index", $this->ttl());
        }

        return $ok;
    }

    /** Sobrescribe una respuesta ya registrada (para enriquecerla al cerrar). */
    public function enrichAnswer(string $pin, int $index, string $uuid, array $data): void
    {
        Redis::hset("game:$pin:answers:$index", $uuid, json_encode($data));
    }

    /** @return array<string,array> player_uuid → respuesta */
    public function answers(string $pin, int $index): array
    {
        return array_map(
            fn (string $json) => json_decode($json, true),
            Redis::hgetall("game:$pin:answers:$index")
        );
    }

    public function answersCount(string $pin, int $index): int
    {
        return (int) Redis::hlen("game:$pin:answers:$index");
    }

    /* --------------------------- Leaderboard --------------------------- */

    public function setScore(string $pin, string $uuid, int $score): void
    {
        Redis::zadd("game:$pin:lb", $score, $uuid);
        Redis::expire("game:$pin:lb", $this->ttl());
    }

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
        // answers:{i} de hasta 100 preguntas: se borran por patrón al destruir.
        foreach (range(0, 99) as $i) {
            $keys[] = "game:$pin:answers:$i";
        }

        return $keys;
    }
}
