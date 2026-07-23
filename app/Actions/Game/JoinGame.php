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

        if ($state['state'] !== GameState::Lobby->value) {
            throw GameException::alreadyStarted();
        }
        if (($state['locked'] ?? '0') === '1') {
            throw GameException::gameLocked();
        }
        if ($this->repo->playersCount($pin) >= (int) config('poliquiz.max_players')) {
            throw GameException::roomFull();
        }

        ['nickname' => $nickname, 'normalized' => $normalized] = $this->nicknames->validate($rawNickname);

        if ($this->repo->nicknameTaken($pin, $normalized)) {
            throw GameException::nicknameTaken();
        }

        $uuid = (string) Str::uuid();
        $token = Str::random(48);

        $this->repo->addPlayer($pin, $uuid, $nickname, $normalized);
        $this->repo->createSession($token, $pin, $uuid);

        return [
            'player_token' => $token,
            'player_uuid' => $uuid,
            'nickname' => $nickname,
            'game' => [
                'pin' => $pin,
                'state' => $state['state'],
                'quiz_title' => $state['quiz_title'] ?? '',
            ],
        ];
    }
}
