<?php

declare(strict_types=1);

namespace App\Services\Game\QuestionTypes;

use App\Exceptions\GameException;

/**
 * Rompecabezas: el alumno ORDENA piezas. Las opciones se guardan en el orden
 * CORRECTO (id = posición 0..n-1); al jugador le llegan barajadas y devuelve el
 * orden en que las dejó (array de ids).
 *
 * Puntaje PARCIAL: cada pieza en su lugar correcto suma; ratio = aciertos/total.
 * La racha solo sigue con el orden completo (lo decide ScoreCalculator).
 */
final class PuzzleQuestion implements QuestionType
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
        $order = array_map(intval(...), $payload['order'] ?? []);
        $ids = array_map(fn (array $o): int => $o['id'], $question['options']);

        // El orden enviado tiene que ser una PERMUTACIÓN exacta de las piezas.
        $a = $order;
        $b = $ids;
        sort($a);
        sort($b);
        if ($a !== $b) {
            throw new GameException('INVALID_ORDER', 'Orden inválido para esta pregunta.', 422);
        }

        return ['order' => $order];
    }

    public function grade(array $answer, array $question): array
    {
        $order = array_map(intval(...), $answer['order'] ?? []);
        $correct = array_map(fn (array $o): int => $o['id'], $question['options']); // orden correcto
        $total = count($correct);
        if ($total === 0) {
            return ['ratio' => 0.0, 'correct' => false];
        }

        $aciertos = 0;
        foreach ($order as $pos => $id) {
            if (($correct[$pos] ?? null) === $id) {
                $aciertos++;
            }
        }

        $ratio = $aciertos / $total;

        return ['ratio' => $ratio, 'correct' => $ratio >= 1.0];
    }

    public function playerView(array $question): array
    {
        $pieces = array_map(fn (array $o): array => ['id' => $o['id'], 'text' => $o['text']], $question['options']);

        // Barajar sin regalar el orden correcto (tope de intentos por las dudas).
        if (count($pieces) > 1) {
            for ($i = 0; $i < 10 && $this->isIdentity($pieces); $i++) {
                shuffle($pieces);
            }
        }

        return [
            'kind' => $question['kind'],
            'title' => $question['title'],
            'image_url' => $question['image_url'] ?? null,
            'time_limit' => $question['time_limit'],
            'points_mode' => $question['points_mode'],
            'options' => $pieces, // piezas barajadas; el id le dice al server cuál es cuál
        ];
    }

    public function reveal(array $answers, array $question): array
    {
        $correctOrder = [];
        foreach (array_values($question['options']) as $i => $o) {
            $correctOrder[] = ['position' => $i + 1, 'text' => $o['text']];
        }

        // Cuántos ordenaron TODO bien.
        $full = 0;
        foreach ($answers as $a) {
            if ($this->grade($a, $question)['correct']) {
                $full++;
            }
        }

        return [
            'kind' => 'order',
            'correct_order' => $correctOrder, // [{position, text}] en el orden correcto
            'fully_correct' => $full,
            'answered' => count($answers),
        ];
    }

    /** ¿Las piezas quedaron en su orden natural (id ascendente = correcto)? */
    private function isIdentity(array $pieces): bool
    {
        foreach (array_values($pieces) as $i => $p) {
            if ($p['id'] !== $i) {
                return false;
            }
        }

        return true;
    }
}
