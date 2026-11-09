<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Error de dominio de partida. Siempre responde {message, code} con el
 * código de error del contrato de API (plan §4).
 */
class GameException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }

    /* ---- Fábricas con los códigos del contrato ---- */

    public static function pinNotFound(): self
    {
        return new self('PIN_NOT_FOUND', 'No existe una sala activa con ese PIN.', 404);
    }

    public static function gameLocked(): self
    {
        return new self('GAME_LOCKED', 'La sala está bloqueada por el docente.');
    }

    public static function alreadyStarted(): self
    {
        return new self('ALREADY_STARTED', 'La partida ya comenzó: no se puede entrar.');
    }

    public static function nicknameTaken(): self
    {
        return new self('NICKNAME_TAKEN', 'Ese apodo ya está en uso en esta sala.');
    }

    public static function roomFull(): self
    {
        return new self('ROOM_FULL', 'La sala alcanzó el máximo de jugadores.', 422);
    }

    public static function timeExpired(): self
    {
        return new self('TIME_EXPIRED', 'El tiempo de esta pregunta ya terminó.', 422);
    }

    public static function wrongState(string $detail = ''): self
    {
        return new self('WRONG_STATE', trim('La acción no es válida en el estado actual de la partida. '.$detail));
    }

    public static function forbidden(): self
    {
        return new self('FORBIDDEN', 'No sos el anfitrión de esta partida.', 403);
    }
}
