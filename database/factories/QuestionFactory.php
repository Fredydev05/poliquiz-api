<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Question;
use App\Models\Quiz;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Question> */
class QuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quiz_id' => Quiz::factory(),
            'position' => 0,
            'kind' => 'quiz',
            'title' => fake()->sentence().'?',
            'time_limit' => 20,
            'points_mode' => 'standard',
        ];
    }

    /** Pregunta quiz completa: 4 opciones, la primera correcta. */
    public function configure(): static
    {
        return $this->afterCreating(function (Question $question): void {
            if ($question->options()->count() > 0) {
                return;
            }
            foreach (range(0, 3) as $i) {
                $question->options()->create([
                    'position' => $i,
                    'text' => fake()->words(2, true),
                    'is_correct' => $i === 0,
                ]);
            }
        });
    }
}
