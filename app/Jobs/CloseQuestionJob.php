<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Game\CloseQuestion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cierre por TIMEOUT de la pregunta (time_limit + 500 ms de margen).
 * Si "todos respondieron" ya la cerró antes, este job es un no-op
 * (la idempotencia vive en CloseQuestion).
 */
class CloseQuestionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $pin,
        public readonly string $questionUuid,
    ) {}

    public function handle(CloseQuestion $closer): void
    {
        $closer->handle($this->pin, $this->questionUuid);
    }
}
