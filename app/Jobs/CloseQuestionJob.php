<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Game\CloseQuestion;
use App\Services\Game\GameRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cierre por TIMEOUT de la pregunta (time_limit + 500 ms de margen).
 * Si "todos respondieron" ya la cerró antes, este job es un no-op
 * (la idempotencia vive en CloseQuestion).
 *
 * También es la red de seguridad: si un cierre anterior quedó a medias
 * (p. ej. falló el broadcast), este job lo retoma; si encuentra un cierre
 * en curso, se re-encola unos segundos y vuelve a mirar.
 */
class CloseQuestionJob implements ShouldQueue
{
    use Queueable;
    use WaitsForServerTime;

    /**
     * Sin tope de intentos ni fecha límite: un job atrasado (cola caída,
     * deploy) igual tiene que correr, o la sala quedaría trabada. Los
     * re-encolados por horario no cuentan; los errores sí: a la 4ª
     * excepción el job falla.
     */
    public int $tries = 0;

    public int $maxExceptions = 4;

    /** Espera entre reintentos tras una excepción (segundos). */
    public array $backoff = [2, 4, 8, 15];

    public function __construct(
        public readonly string $pin,
        public readonly string $questionUuid,
    ) {}

    public function handle(CloseQuestion $closer, GameRepository $repo): void
    {
        // Nunca cerrar antes de que termine el tiempo + la gracia de red: la
        // cola puede entregarnos el job hasta ~1 s antes de lo pedido.
        $state = $repo->getState($this->pin);
        if ($state !== null && $state['state'] === 'question' && ($state['question_uuid'] ?? '') === $this->questionUuid) {
            $deadline = (int) $state['ends_at'] + (int) config('poliquiz.answer_grace_ms');
            if (! $this->waitUntil($deadline)) {
                return; // re-encolado: falta demasiado
            }
        }

        if ($closer->handle($this->pin, $this->questionUuid, resume: true) === CloseQuestion::BUSY) {
            $this->release(3);
        }
    }
}
