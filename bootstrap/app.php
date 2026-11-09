<?php

use App\Exceptions\GameException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autorización de canales WS: acepta al docente (sanctum) O a un
    // jugador invitado (guard 'player' vía X-Player-Token).
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum,player']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Rechazos de dominio esperables (apodo tomado, respuesta tardía...):
        // ya se le responden al cliente con su código. Loguearlos como ERROR
        // llenaría el log en cada clase y escondería los errores reales.
        $exceptions->dontReport(GameException::class);
    })->create();
