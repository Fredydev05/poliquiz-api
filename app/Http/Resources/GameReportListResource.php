<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila del historial de partidas del docente.
 *
 * @mixin Game
 */
class GameReportListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quiz_title' => $this->quiz_snapshot['title'] ?? 'Sin título',
            'status' => $this->status, // ended | aborted
            'players_count' => $this->players_count,
            'questions_count' => count($this->quiz_snapshot['questions'] ?? []),
            'played_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
        ];
    }
}
