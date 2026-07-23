<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quiz;
use App\Models\User;

class QuizPolicy
{
    /** Ver: el dueño siempre; ajenos solo si es público. */
    public function view(User $user, Quiz $quiz): bool
    {
        return $quiz->user_id === $user->id || $quiz->visibility === 'public';
    }

    public function update(User $user, Quiz $quiz): bool
    {
        return $quiz->user_id === $user->id;
    }

    public function delete(User $user, Quiz $quiz): bool
    {
        return $quiz->user_id === $user->id;
    }

    /** Duplicar: cualquier quiz que pueda ver (propio o público). */
    public function duplicate(User $user, Quiz $quiz): bool
    {
        return $this->view($user, $quiz);
    }
}
