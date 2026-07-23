<?php

declare(strict_types=1);

namespace App\Actions\Quiz;

use App\Models\Quiz;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Copia profunda de un quiz (preguntas + opciones) para el usuario dado. */
class DuplicateQuiz
{
    public function handle(Quiz $quiz, User $owner): Quiz
    {
        return DB::transaction(function () use ($quiz, $owner): Quiz {
            $copy = $owner->quizzes()->create([
                'title' => mb_substr($quiz->title.' (copia)', 0, 120),
                'description' => $quiz->description,
                'visibility' => 'private', // las copias nacen privadas
                'cover_path' => $quiz->cover_path,
            ]);

            foreach ($quiz->questions()->with('options')->get() as $question) {
                $newQuestion = $copy->questions()->create(
                    $question->only(['position', 'kind', 'title', 'time_limit', 'points_mode', 'image_path'])
                );
                $newQuestion->options()->createMany(
                    $question->options->map(
                        fn ($o) => $o->only(['position', 'text', 'is_correct'])
                    )->all()
                );
            }

            return $copy->load('questions.options');
        });
    }
}
