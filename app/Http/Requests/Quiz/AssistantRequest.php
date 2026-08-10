<?php

declare(strict_types=1);

namespace App\Http\Requests\Quiz;

use App\Models\Quiz;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el pedido al asistente de IA. La autorización reusa la QuizPolicy:
 * solo el dueño del quiz puede pedirle sugerencias/generación.
 */
final class AssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $quiz = $this->route('quiz');

        return $quiz instanceof Quiz && $this->user()->can('update', $quiz);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Tope de largo: acota tokens/costo y reduce superficie de abuso.
            'message' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }
}
