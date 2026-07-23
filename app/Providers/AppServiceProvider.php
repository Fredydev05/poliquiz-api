<?php

namespace App\Providers;

use App\Auth\GuestPlayer;
use App\Services\Game\GameRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         * Guard 'player': autentica jugadores invitados por el header
         * X-Player-Token contra su psession de Redis. Devuelve un
         * GuestPlayer (sin tocar la base de datos) o null.
         */
        Auth::viaRequest('player-token', function (Request $request): ?GuestPlayer {
            $token = $request->header('X-Player-Token');
            if (! $token) {
                return null;
            }

            $repo = app(GameRepository::class);
            $session = $repo->session($token);
            if ($session === null) {
                return null;
            }

            $player = $repo->player($session['pin'], $session['player_uuid']);
            if ($player === null) {
                return null; // expulsado o sala destruida
            }

            return new GuestPlayer(
                uuid: $session['player_uuid'],
                nickname: $player['nickname'],
                pin: $session['pin'],
                token: $token,
            );
        });
    }
}
