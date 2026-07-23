<?php

declare(strict_types=1);

use App\Auth\GuestPlayer;
use App\Models\User;
use App\Services\Game\GameRepository;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canales de broadcasting (plan §4)
|--------------------------------------------------------------------------
| presence-game.{pin}      → host + jugadores de ESA sala (roster en vivo)
| private-game.{pin}.host  → solo el docente anfitrión
*/

Broadcast::channel('game.{pin}', function (User|GuestPlayer $user, string $pin): array|false {
    $state = app(GameRepository::class)->getState($pin);
    if ($state === null) {
        return false;
    }

    // Docente: solo el anfitrión de ESTA sala.
    if ($user instanceof User) {
        if ((int) $state['host_id'] !== $user->id) {
            return false;
        }

        return ['uuid' => 'host', 'nickname' => $user->name, 'role' => 'host'];
    }

    // Jugador: su psession debe pertenecer a esta sala.
    if ($user->pin !== $pin) {
        return false;
    }

    return ['uuid' => $user->uuid, 'nickname' => $user->nickname, 'role' => 'player'];
});

Broadcast::channel('game.{pin}.host', function (User|GuestPlayer $user, string $pin): bool {
    $state = app(GameRepository::class)->getState($pin);

    return $state !== null
        && $user instanceof User
        && (int) $state['host_id'] === $user->id;
});
