<?php

declare(strict_types=1);

namespace App\Services\Game\QuestionTypes;

use App\Enums\QuestionKind;
use App\Exceptions\GameException;
use App\Services\Game\TextModerator;

/**
 * Tipos de respuesta LIBRE y sin puntaje, que se proyectan en el aula:
 *
 *   wordcloud  → una palabra o frase corta; el host la muestra como nube
 *                (más repetida = más grande)
 *   open       → respuesta libre larga; el host la muestra como muro
 *   brainstorm → hasta 3 ideas por alumno; el host las agrupa por similitud
 *
 * Como el texto se ve en el proyector, el reveal oculta las respuestas con
 * groserías (y le informa al docente cuántas ocultó).
 */
final class FreeTextQuestion implements QuestionType
{
    /** Límites por tipo: largo de cada texto y cantidad de entradas por alumno. */
    private const LIMITS = [
        'wordcloud' => ['max_length' => 25, 'max_entries' => 1],
        'open' => ['max_length' => 250, 'max_entries' => 1],
        'brainstorm' => ['max_length' => 60, 'max_entries' => 3],
    ];

    /** Cuántos ítems como mucho entran en la pantalla del proyector. */
    private const MAX_SHOWN = 60;

    public function __construct(
        private readonly QuestionKind $kind,
        private readonly TextModerator $moderator,
    ) {}

    public function isInteractive(): bool
    {
        return true;
    }

    public function isScored(): bool
    {
        return false;
    }

    public function normalizeAnswer(array $payload, array $question): array
    {
        ['max_length' => $max, 'max_entries' => $entries] = self::LIMITS[$this->kind->value];

        if ($this->kind === QuestionKind::Brainstorm) {
            $raw = is_array($payload['ideas'] ?? null) ? $payload['ideas'] : [];
            $ideas = [];
            foreach ($raw as $idea) {
                $idea = trim((string) $idea);
                if ($idea !== '' && mb_strlen($idea) <= $max) {
                    $ideas[TextModerator::normalize($idea)] ??= $idea; // sin repetidas del mismo alumno
                }
            }
            if ($ideas === [] || count($ideas) > $entries) {
                throw new GameException('INVALID_ANSWER', "Escribí entre 1 y $entries ideas (hasta $max caracteres cada una).", 422);
            }

            return ['ideas' => array_values($ideas)];
        }

        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > $max) {
            throw new GameException('INVALID_ANSWER', "Escribí una respuesta de hasta $max caracteres.", 422);
        }

        return ['text' => $text];
    }

    public function grade(array $answer, array $question): array
    {
        return ['ratio' => 0.0, 'correct' => false]; // opinión: no hay correcta
    }

    public function playerView(array $question): array
    {
        return [
            'kind' => $question['kind'],
            'title' => $question['title'],
            'image_url' => $question['image_url'] ?? null,
            'time_limit' => $question['time_limit'],
            'points_mode' => $question['points_mode'],
            // El celular arma el formulario con estos límites.
            ...self::LIMITS[$this->kind->value],
        ];
    }

    public function reveal(array $answers, array $question): array
    {
        // En orden de llegada: el muro se lee cronológico y cada grupo se
        // muestra como lo escribió el primero (Redis no garantiza el orden).
        usort($answers, fn (array $a, array $b) => ($a['received_at'] ?? 0) <=> ($b['received_at'] ?? 0));

        $texts = [];
        foreach ($answers as $a) {
            foreach ($this->kind === QuestionKind::Brainstorm ? ($a['ideas'] ?? []) : [$a['text'] ?? ''] as $t) {
                $t = trim((string) $t);
                if ($t !== '') {
                    $texts[] = $t;
                }
            }
        }

        $clean = array_values(array_filter($texts, fn (string $t) => $this->moderator->isClean($t)));
        $hidden = count($texts) - count($clean);

        // Pregunta abierta: cada respuesta es única, se muestran tal cual.
        if ($this->kind === QuestionKind::Open) {
            return [
                'kind' => 'open',
                'answers' => array_map(fn (string $t) => ['text' => $t], array_slice($clean, 0, self::MAX_SHOWN)),
                'total' => count($clean),
                'hidden' => $hidden,
            ];
        }

        // Nube e ideas: se agrupan las iguales (sin importar mayúsculas/acentos).
        $groups = [];
        foreach ($clean as $t) {
            $groups[TextModerator::normalize($t)] ??= ['text' => $t, 'count' => 0];
            $groups[TextModerator::normalize($t)]['count']++;
        }
        $items = array_values($groups);
        usort($items, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return [
            'kind' => $this->kind === QuestionKind::WordCloud ? 'cloud' : 'ideas',
            ($this->kind === QuestionKind::WordCloud ? 'words' : 'ideas') => array_slice($items, 0, self::MAX_SHOWN),
            'total' => count($clean),
            'hidden' => $hidden,
        ];
    }
}
