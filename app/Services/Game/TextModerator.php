<?php

declare(strict_types=1);

namespace App\Services\Game;

use Illuminate\Support\Str;

/**
 * Filtro de groserías para el texto libre que se PROYECTA en el aula (nube de
 * palabras, pregunta abierta, lluvia de ideas). Compara palabras completas ya
 * normalizadas (sin acentos ni mayúsculas, y sin 's' final), así
 * "computadora" no cae por contener "puta".
 */
final class TextModerator
{
    /** @var array<string, true> */
    private array $blocked;

    public function __construct()
    {
        $this->blocked = array_fill_keys(
            array_map(fn (string $w) => $this->stem($w), config('poliquiz.text_blocklist', [])),
            true,
        );
    }

    public function isClean(string $text): bool
    {
        foreach (preg_split('/[^a-z0-9]+/', self::normalize($text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if (isset($this->blocked[$this->stem($word)])) {
                return false;
            }
        }

        return true;
    }

    /** minúsculas + sin acentos + espacios colapsados (agrupar "Paz", "paz ", "PÁZ"). */
    public static function normalize(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii($text))));
    }

    /** Plural simple: "putos" y "puto" son la misma palabra. */
    private function stem(string $word): string
    {
        $word = self::normalize($word);

        return strlen($word) > 3 && str_ends_with($word, 's') ? substr($word, 0, -1) : $word;
    }
}
