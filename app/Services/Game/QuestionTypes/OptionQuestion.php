<?php

declare(strict_types=1);

namespace App\Services\Game\QuestionTypes;

use App\Exceptions\GameException;

/**
 * Preguntas basadas en opciones: quiz (1 correcta), true_false, multi (varias
 * correctas) y —desde la Fase 1— poll (encuesta, sin correctas).
 *
 * La respuesta del jugador son ids de opción (`selected`). El acierto es
 * binario: el conjunto elegido tiene que ser EXACTAMENTE el de las correctas.
 */
final class OptionQuestion implements QuestionType
{
    public function __construct(
        private readonly bool $scored = true,
    ) {}

    public function isInteractive(): bool
    {
        return true;
    }

    public function isScored(): bool
    {
        return $this->scored;
    }

    public function normalizeAnswer(array $payload, array $question): array
    {
        $validIds = array_map(fn (array $o): int => $o['id'], $question['options']);
        $selected = array_values(array_unique(array_map(intval(...), $payload['selected'] ?? [])));

        if ($selected === [] || array_diff($selected, $validIds) !== []) {
            throw new GameException('INVALID_OPTIONS', 'Opciones inválidas para esta pregunta.', 422);
        }

        return ['selected' => $selected];
    }

    public function grade(array $answer, array $question): array
    {
        // Una encuesta no tiene correcta: nunca "acierta" (y no puntúa).
        if (! $this->scored) {
            return ['ratio' => 0.0, 'correct' => false];
        }

        $selected = array_map(intval(...), $answer['selected'] ?? []);
        sort($selected);
        $isCorrect = $selected === $this->correctIds($question);

        return ['ratio' => $isCorrect ? 1.0 : 0.0, 'correct' => $isCorrect];
    }

    public function playerView(array $question): array
    {
        return [
            'kind' => $question['kind'],
            'title' => $question['title'],
            'image_url' => $question['image_url'] ?? null,
            'time_limit' => $question['time_limit'],
            'points_mode' => $question['points_mode'],
            'options' => array_map(
                fn (array $o): array => ['id' => $o['id'], 'text' => $o['text']], // sin is_correct
                $question['options'],
            ),
        ];
    }

    public function reveal(array $answers, array $question): array
    {
        return [
            'kind' => 'options',
            'correct_option_ids' => $this->scored ? $this->correctIds($question) : [], // la encuesta no tiene correcta
            'distribution' => $this->distribution($answers, $question),
        ];
    }

    /** @return list<array{option_id: int, count: int}> votos por opción (0 incluidos) */
    public function distribution(array $answers, array $question): array
    {
        $counts = [];
        foreach ($answers as $answer) {
            foreach (array_map(intval(...), $answer['selected'] ?? []) as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        return array_map(
            fn (array $o): array => ['option_id' => $o['id'], 'count' => $counts[$o['id']] ?? 0],
            $question['options'],
        );
    }

    /** @return list<int> ids de las opciones correctas, ordenados */
    private function correctIds(array $question): array
    {
        $ids = array_values(array_map(
            fn (array $o): int => $o['id'],
            array_filter($question['options'], fn (array $o): bool => $o['is_correct']),
        ));
        sort($ids);

        return $ids;
    }
}
