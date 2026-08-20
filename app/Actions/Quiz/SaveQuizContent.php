<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Models\Quiz;
use Illuminate\Support\Facades\DB;

/**
 * Guardado ATÓMICO del contenido completo de un quiz.
 * El editor autoguarda mandando todo el payload; aquí se reemplazan las
 * preguntas dentro de una transacción: o entra todo, o no entra nada.
 */
class SaveQuizContent
{
    /**
     * @param  array{title:string, description?:?string, visibility?:string,
     *               cover_path?:?string, questions:array<int,array>}  $data
     */
    public function handle(Quiz $quiz, array $data): Quiz
    {
        return DB::transaction(function () use ($quiz, $data): Quiz {
            $quiz->fill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'visibility' => $data['visibility'] ?? $quiz->visibility ?? 'private',
                'cover_path' => $data['cover_path'] ?? null,
            ])->save();

            // Reemplazo total: más simple y robusto que diffear posiciones,
            // y el volumen (≤100 preguntas) lo permite de sobra.
            $quiz->questions()->delete();

            foreach ($data['questions'] as $index => $q) {
                $question = $quiz->questions()->create([
                    'position' => $index,
                    'kind' => $q['kind'],
                    'title' => $q['title'],
                    'body' => $q['body'] ?? null,            // solo la diapositiva
                    'time_limit' => $q['time_limit'] ?? null, // la diapositiva no tiene
                    'points_mode' => $q['points_mode'] ?? 'standard',
                    'image_path' => $q['image_path'] ?? null,
                ]);

                // La diapositiva no tiene opciones.
                $question->options()->createMany(
                    collect($q['options'] ?? [])->values()->map(fn (array $o, int $pos) => [
                        'position' => $pos,
                        'text' => $o['text'],
                        'is_correct' => (bool) $o['is_correct'],
                    ])->all()
                );
            }

            return $quiz->refresh()->load('questions.options');
        });
    }
}
