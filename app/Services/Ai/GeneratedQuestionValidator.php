<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\PointsMode;
use App\Enums\QuestionKind;

/**
 * Valida y normaliza las preguntas que devuelve la IA, aplicando las MISMAS
 * reglas que el editor manual (el enum QuestionKind es la fuente única de sus
 * rangos). Es tolerante: descarta las preguntas inválidas y conserva las
 * buenas, en vez de rechazar toda la generación por un solo error.
 */
final class GeneratedQuestionValidator
{
    private const TIME_LIMITS = [5, 10, 20, 30, 60, 90, 120, 240];

    /** Los únicos tipos que el prompt le permite generar a la IA (lista blanca). */
    private const GENERATABLE = [QuestionKind::Quiz, QuestionKind::TrueFalse, QuestionKind::Multi];

    public function __construct(private readonly int $maxQuestions) {}

    /**
     * @param  mixed  $raw  Lo que vino en questions[] del JSON de la IA.
     * @return array<int, array<string, mixed>> Preguntas válidas y normalizadas (formato API).
     */
    public function valid($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $question) {
            if (count($out) >= $this->maxQuestions) {
                break;
            }
            $clean = $this->validateOne($question);
            if ($clean !== null) {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function validateOne($q): ?array
    {
        if (! is_array($q)) {
            return null;
        }

        $kind = QuestionKind::tryFrom((string) ($q['kind'] ?? ''));
        $title = trim((string) ($q['title'] ?? ''));
        $timeLimit = $q['time_limit'] ?? null;

        // Lista blanca: si el modelo inventa otro tipo (encuesta, diapositiva...)
        // aunque el enum lo conozca, la pregunta se descarta.
        if (! in_array($kind, self::GENERATABLE, true) || $title === '' || mb_strlen($title) > 250) {
            return null;
        }
        if (! in_array($timeLimit, self::TIME_LIMITS, true)) {
            return null;
        }

        $options = $this->validateOptions($q['options'] ?? null, $kind);
        if ($options === null) {
            return null;
        }

        $points = PointsMode::tryFrom((string) ($q['points_mode'] ?? 'standard')) ?? PointsMode::Standard;

        return [
            'kind' => $kind->value,
            'title' => $title,
            'time_limit' => $timeLimit,
            'points_mode' => $points->value,
            'options' => $options,
        ];
    }

    /**
     * @return array<int, array{text: string, is_correct: bool}>|null
     */
    private function validateOptions($raw, QuestionKind $kind): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $options = [];
        foreach ($raw as $opt) {
            if (! is_array($opt)) {
                continue; // "options": ["a", "b"] u otra forma rota
            }
            $text = trim((string) ($opt['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 120) {
                continue; // opción vacía o gigante: se descarta
            }
            $options[] = ['text' => $text, 'is_correct' => (bool) ($opt['is_correct'] ?? false)];
        }

        [$minOpt, $maxOpt] = $kind->optionRange();
        if (count($options) < $minOpt || count($options) > $maxOpt) {
            return null;
        }

        $correct = count(array_filter($options, fn ($o) => $o['is_correct']));
        [$minOk, $maxOk] = $kind->correctRange();
        if ($correct < $minOk || $correct > $maxOk) {
            return null;
        }

        return $options;
    }
}
