<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Game\LaunchQuestion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispara la pregunta cuando termina el countdown de get_ready (3 s).
 * Idempotente: LaunchQuestion::start() verifica question_uuid y estado.
 */
class StartQuestionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $pin,
        public readonly string $questionUuid,
    ) {}

    public function handle(LaunchQuestion $launcher): void
    {
        $launcher->start($this->pin, $this->questionUuid);
    }
}
