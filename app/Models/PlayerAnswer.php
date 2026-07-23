<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['game_player_id', 'question_index', 'selected', 'response_ms', 'is_correct', 'points', 'streak_after'])]
class PlayerAnswer extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'selected' => 'array',
            'is_correct' => 'boolean',
        ];
    }

    public function gamePlayer(): BelongsTo
    {
        return $this->belongsTo(GamePlayer::class);
    }
}
