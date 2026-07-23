<?php

declare(strict_types=1);

namespace App\Services\Players;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

/**
 * Normaliza y valida apodos de jugadores: longitud, caracteres permitidos
 * y lista de bloqueo por subcadena (sin acentos, case-insensitive).
 */
class NicknameValidator
{
    /** @return array{nickname:string, normalized:string} */
    public function validate(string $raw): array
    {
        // Normalización visual: colapsa espacios múltiples.
        $nickname = trim(preg_replace('/\s+/u', ' ', $raw) ?? '');

        $min = (int) config('poliquiz.nickname.min');
        $max = (int) config('poliquiz.nickname.max');

        if (mb_strlen($nickname) < $min || mb_strlen($nickname) > $max) {
            throw ValidationException::withMessages([
                'nickname' => ["El apodo debe tener entre $min y $max caracteres."],
            ]);
        }

        if (! preg_match('/^[\p{L}\p{N} _\-]+$/u', $nickname)) {
            throw ValidationException::withMessages([
                'nickname' => ['El apodo solo puede tener letras, números, espacios, guiones y guiones bajos.'],
            ]);
        }

        // Clave de unicidad y filtro: minúsculas, sin acentos ni separadores.
        $normalized = str_replace([' ', '_', '-'], '', Str::lower(Str::ascii($nickname)));

        foreach (config('poliquiz.nickname.blocklist') as $blocked) {
            if (str_contains($normalized, $blocked)) {
                throw ValidationException::withMessages([
                    'nickname' => ['Ese apodo no está permitido: elegí otro.'],
                ]);
            }
        }

        return ['nickname' => $nickname, 'normalized' => $normalized];
    }
}
