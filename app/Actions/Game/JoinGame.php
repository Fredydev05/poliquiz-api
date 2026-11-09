<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Exceptions\GameException;
use App\Services\Game\GameRepository;
use App\Services\Players\NicknameValidator;
use Illuminate\Support\Str;

/**
 * Un estudiante entra a la sala con PIN + apodo.
 * Devuelve su token de sesión (para reconexión y auth de canales WS).
 *
 * Estado, candado, cupo y apodo único se verifican y reservan en UN paso
 * atómico: dos alumnos que entran a la vez con "Juan" no pueden quedar los
 * dos, ni 100 joins simultáneos pasarse del máximo.
 */
class JoinGame
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly NicknameValidator $nicknames,
    ) {}

    /** @return array{player_token:string, player_uuid:string, nickname:string, game:array} */
    public function handle(string $pin, string $rawNickname): array
    {
        $state = $this->repo->getState($pin) ?? throw GameException::pinNotFound();

        // Chequeos rápidos para responder sin validar el apodo; el que manda
        // es el alta atómica de abajo (puede cambiar en el medio).
        if ($state['state'] !== GameState::Lobby->value) {
            throw GameException::alreadyStarted();
        }
        if (($state['locked'] ?? '0') === '1') {
            throw GameException::gameLocked();
        }

        ['nickname' => $nickname, 'normalized' => $normalized] = $this->nicknames->validate($rawNickname);

        $uuid = (string) Str::uuid();
        $token = Str::random(48);

        $result = $this->repo->joinPlayer($pin, $uuid, $nickname, $normalized, (int) config('poliquiz.max_players'));

        match ($result) {
            'OK' => null,
            'PIN_NOT_FOUND' => throw GameException::pinNotFound(),
            'ALREADY_STARTED' => throw GameException::alreadyStarted(),
            'GAME_LOCKED' => throw GameException::gameLocked(),
            'ROOM_FULL' => throw GameException::roomFull(),
            'NICKNAME_TAKEN' => throw GameException::nicknameTaken(),
            default => throw new \UnexpectedValueException("Alta de jugador: resultado inesperado '$result'."),
        };

        $this->repo->createSession($token, $pin, $uuid);

        return [
            'player_token' => $token,
            'player_uuid' => $uuid,
            'nickname' => $nickname,
            'game' => [
                'pin' => $pin,
                'state' => 'lobby', // el alta solo es posible en el lobby
                'quiz_title' => $state['quiz_title'] ?? '',
            ],
        ];
    }
}
