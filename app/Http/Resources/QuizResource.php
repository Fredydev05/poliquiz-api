<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Quiz;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Contrato de salida de un quiz. Con questions.options cargadas incluye
 * el contenido completo (lo que consume el editor).
 *
 * @mixin Quiz
 */
class QuizResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'visibility' => $this->visibility,
            'cover_url' => $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null,
            'questions_count' => $this->whenCounted('questions'),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'is_mine' => $this->user_id === $request->user()?->id,

            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(fn ($q) => [
                'kind' => $q->kind->value,
                'title' => $q->title,
                'time_limit' => $q->time_limit,
                'points_mode' => $q->points_mode->value,
                'image_path' => $q->image_path,
                'image_url' => $q->image_path ? Storage::disk('public')->url($q->image_path) : null,
                'options' => $q->options->map(fn ($o) => [
                    'text' => $o->text,
                    'is_correct' => $o->is_correct,
                ]),
            ])),
        ];
    }
}
