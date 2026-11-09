<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GameController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\PlayerGameController;
use App\Http\Controllers\Api\V1\QuizAssistantController;
use App\Http\Controllers\Api\V1\QuizController;
use App\Http\Controllers\Api\V1\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 — POLI Quiz
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // Healthcheck: usado por el frontend, CI y el monitoreo del VPS.
    Route::get('/health', fn () => response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]));

    // ---- Auth de docentes (Sanctum · tokens Bearer) ----
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // ---- Quizzes (CRUD del docente) ----
        Route::apiResource('quizzes', QuizController::class);
        Route::post('/quizzes/{quiz}/duplicate', [QuizController::class, 'duplicate']);
        Route::post('/media', [MediaController::class, 'store'])->middleware('throttle:30,1');

        // ---- Asistente de IA (crear/mejorar quizzes) · SSE streaming ----
        // Rate-limit acotado: cada consulta cuesta tokens al proveedor.
        Route::post('/quizzes/{quiz}/assistant', QuizAssistantController::class)
            ->middleware('throttle:15,1');

        // ---- Reportes post-partida ----
        Route::get('/reports/games', [ReportController::class, 'index']);
        Route::get('/reports/games/{game}', [ReportController::class, 'show']);
        Route::get('/reports/games/{game}/questions', [ReportController::class, 'questions']);
        Route::get('/reports/games/{game}/players', [ReportController::class, 'players']);

        // ---- Partidas: acciones del anfitrión ----
        Route::post('/games', [GameController::class, 'store']);
        Route::get('/games/{pin}/state', [GameController::class, 'state']);
        Route::post('/games/{pin}/start', [GameController::class, 'start']);
        Route::post('/games/{pin}/next', [GameController::class, 'next']);
        Route::post('/games/{pin}/end', [GameController::class, 'end']);
        Route::post('/games/{pin}/lock', [GameController::class, 'lock']);
        Route::post('/games/{pin}/unlock', [GameController::class, 'unlock']);
        Route::delete('/games/{pin}/players/{playerUuid}', [GameController::class, 'kick']);
    });

    // ---- Partidas: jugador invitado ----
    Route::post('/games/{pin}/join', [PlayerGameController::class, 'join'])
        ->middleware('throttle:game-join');
    Route::middleware('auth:player')->group(function (): void {
        Route::get('/games/{pin}/me', [PlayerGameController::class, 'me']);
        Route::post('/games/{pin}/answers', [PlayerGameController::class, 'answer'])
            ->middleware('throttle:60,1');
    });
});
