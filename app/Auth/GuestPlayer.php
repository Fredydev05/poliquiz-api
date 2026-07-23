<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "Usuario" efímero de un jugador invitado, reconstruido desde su psession
 * de Redis por el guard 'player' (X-Player-Token). No toca la base de datos.
 */
class GuestPlayer implements Authenticatable
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $nickname,
        public readonly string $pin,
        public readonly string $token,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'uuid';
    }

    public function getAuthIdentifier(): string
    {
        return $this->uuid;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
