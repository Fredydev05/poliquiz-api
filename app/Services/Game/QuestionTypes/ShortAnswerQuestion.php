<?php

declare(strict_types=1);

namespace App\Services\Game\QuestionTypes;

use App\Exceptions\GameException;
use Illuminate\Support\Str;

/**
 * "Escribe la respuesta": el alumno tipea texto en su celular. Las "opciones"
 * de la pregunta son las RESPUESTAS ACEPTADAS (el docente puede cargar varias).
 *
 * El match es normalizado-exacto: ignora mayúsculas, acentos y espacios de más,
 * pero NO tolera errores de tipeo (decisión de diseño: predecible, sin falsos
 * positivos). Las respuestas aceptadas jamás viajan al jugador (anti-trampa).
 */
final class ShortAnswerQuestion implements QuestionType
{
    public function isInteractive(): bool
    {
        return true;
    }

    public function isScored(): bool
    {
        return true;
    }

    public function normalizeAnswer(array $payload, array $question): array
    {
        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '' || mb_strlen($text) > 120) {
            throw new GameException('INVALID_ANSWER', 'Escribí una respuesta válida.', 422);
        }

        return ['text' => $text]; // se guarda el texto crudo (para el reporte)
    }

    public function grade(array $answer, array $question): array
    {
        $typed = $this->normalize((string) ($answer['text'] ?? ''));
        $correct = $typed !== '' && in_array($typed, $this->acceptedNormalized($question), true);

        return ['ratio' => $correct ? 1.0 : 0.0, 'correct' => $correct];
    }

    public function playerView(array $question): array
    {
        return [
            'kind' => $question['kind'],
            'title' => $question['title'],
            'image_url' => $question['image_url'] ?? null,
            'time_limit' => $question['time_limit'],
            'points_mode' => $question['points_mode'],
            // sin 'options': las respuestas aceptadas NO se envían al jugador
        ];
    }

    public function reveal(array $answers, array $question): array
    {
        $acceptedNorm = $this->acceptedNormalized($question);

        // Agrupa lo que tipearon (por forma normalizada), conservando un texto
        // representativo para mostrar, con su conteo y si contó como correcta.
        $groups = [];
        foreach ($answers as $a) {
            $raw = trim((string) ($a['text'] ?? ''));
            if ($raw === '') {
                continue;
            }
            $norm = $this->normalize($raw);
            $groups[$norm] ??= ['text' => $raw, 'count' => 0, 'correct' => in_array($norm, $acceptedNorm, true)];
            $groups[$norm]['count']++;
        }

        $typed = array_values($groups);
        usort($typed, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'kind' => 'text',
            'accepted' => array_map(fn (array $o): string => $o['text'], $question['options']),
            'answers' => $typed, // [{text, count, correct}]
        ];
    }

    /** @return list<string> respuestas aceptadas ya normalizadas */
    private function acceptedNormalized(array $question): array
    {
        return array_map(fn (array $o): string => $this->normalize($o['text']), $question['options']);
    }

    /** minúsculas + sin acentos (Str::ascii translitera) + espacios colapsados. */
    private function normalize(string $s): string
    {
        return preg_replace('/\s+/', ' ', Str::lower(Str::ascii(trim($s))));
    }
}
