<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Game\LaunchQuestion;
use App\Services\Game\GameRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispara la pregunta cuando termina el reveal de get_ready.
 * Idempotente: LaunchQuestion::start() verifica question_uuid y estado.
 */
class StartQuestionJob implements ShouldQueue
{
    use Queueable;
    use WaitsForServerTime;

    /**
     * Sin tope de intentos ni fecha límite: un job atrasado (cola caída,
     * deploy) igual tiene que correr, o la sala quedaría trabada. Los
     * re-encolados por horario no cuentan; los errores sí: a la 3ª
     * excepción el job falla.
     */
    public int $tries = 0;

    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $pin,
        public readonly string $questionUuid,
    ) {}

    public function handle(LaunchQuestion $launcher, GameRepository $repo): void
    {
        // El reveal dura lo configurado aunque la cola entregue el job antes.
        $state = $repo->getState($this->pin);
        if ($state !== null && ($state['question_uuid'] ?? '') === $this->questionUuid && isset($state['ready_until'])) {
            if (! $this->waitUntil((int) $state['ready_until'])) {
                return;
            }
        }

        $launcher->start($this->pin, $this->questionUuid);
    }
}
