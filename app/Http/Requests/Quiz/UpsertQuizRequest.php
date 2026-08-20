<?php

declare(strict_types=1);

namespace App\Http\Requests\Quiz;

use App\Enums\PointsMode;
use App\Enums\QuestionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Payload completo del quiz (quiz + questions[] + options[]).
 * Se usa tanto para crear como para el guardado atómico del editor.
 */
class UpsertQuizRequest extends FormRequest
{
    /** Tiempos permitidos por pregunta (contrato del plan §4). */
    private const TIME_LIMITS = [5, 10, 20, 30, 60, 90, 120, 240];

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'visibility' => ['sometimes', Rule::in(['private', 'public'])],
            'cover_path' => ['nullable', 'string', 'max:255'],

            'questions' => ['present', 'array', 'max:100'],
            'questions.*.kind' => ['required', Rule::enum(QuestionKind::class)],
            'questions.*.title' => ['required', 'string', 'max:250'],
            // time_limit y options son nullable acá porque la diapositiva no los
            // tiene; la obligatoriedad por tipo se valida en after().
            'questions.*.time_limit' => ['nullable', 'integer', Rule::in(self::TIME_LIMITS)],
            'questions.*.points_mode' => ['sometimes', Rule::enum(PointsMode::class)],
            'questions.*.image_path' => ['nullable', 'string', 'max:255'],
            'questions.*.body' => ['nullable', 'string', 'max:2000'], // cuerpo de la diapositiva

            'questions.*.options' => ['nullable', 'array', 'max:4'],
            'questions.*.options.*.text' => ['required', 'string', 'max:120'],
            'questions.*.options.*.is_correct' => ['required', 'boolean'],
        ];
    }

    /**
     * Reglas que dependen del TIPO de pregunta (cantidad de opciones y de
     * correctas): se validan después de las reglas planas.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach ($this->input('questions', []) as $i => $question) {
                    $kind = QuestionKind::tryFrom($question['kind'] ?? '');
                    if ($kind === null) {
                        continue; // ya falló Rule::enum
                    }

                    // Diapositiva: no lleva opciones ni tiempo, pero necesita cuerpo.
                    if ($kind === QuestionKind::Slide) {
                        if (trim((string) ($question['body'] ?? '')) === '') {
                            $validator->errors()->add("questions.$i.body", 'La diapositiva necesita un texto.');
                        }

                        continue;
                    }

                    // Preguntas interactivas: el tiempo es obligatorio.
                    if (! in_array($question['time_limit'] ?? null, self::TIME_LIMITS, true)) {
                        $validator->errors()->add("questions.$i.time_limit", 'El tiempo por pregunta es obligatorio.');
                    }

                    $options = $question['options'] ?? [];
                    [$minOpt, $maxOpt] = $kind->optionRange();
                    if (count($options) < $minOpt || count($options) > $maxOpt) {
                        $validator->errors()->add(
                            "questions.$i.options",
                            "El tipo {$kind->value} requiere entre $minOpt y $maxOpt opciones."
                        );
                    }

                    $correct = count(array_filter($options, fn ($o) => (bool) ($o['is_correct'] ?? false)));
                    [$minOk, $maxOk] = $kind->correctRange();
                    if ($correct < $minOk || $correct > $maxOk) {
                        $validator->errors()->add(
                            "questions.$i.options",
                            "El tipo {$kind->value} requiere entre $minOk y $maxOk respuestas correctas (hay $correct)."
                        );
                    }
                }
            },
        ];
    }
}
