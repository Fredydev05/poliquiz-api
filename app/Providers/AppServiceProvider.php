<?php

namespace App\Providers;

use App\Auth\GuestPlayer;
use App\Services\Game\GameRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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
         * Alta de jugadores. Un aula entera (o varias a la vez) suele salir por
         * UNA sola IP (wifi de la facultad / NAT), así que no limitamos las
         * altas exitosas por IP en general, sino:
         *  - por IP y sala: alcanza para llenarla (max_players) con reintentos
         *  - PINs inexistentes por IP: es lo que hace quien barre PINs al azar;
         *    un alumno que se equivoca al tipear no llega ni cerca
         */
        RateLimiter::for('game-join', function (Request $request) {
            $ip = $request->ip();

            return [
                Limit::perMinute((int) config('poliquiz.max_players') * 2)->by("join:$ip|".$request->route('pin')),
                Limit::perMinute(60)->by("join-miss:$ip")
                    ->after(fn ($response) => $response->getStatusCode() === 404),
            ];
        });

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
