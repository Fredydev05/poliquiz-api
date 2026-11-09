<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Game\ServerClock;

/**
 * La cola de Redis guarda el `delay` en SEGUNDOS ENTEROS (trunca): un job
 * pedido para "dentro de 10,5 s" puede ejecutarse a los 10 s. Para los jobs
 * del game loop, donde importan los milisegundos (el cierre de una pregunta,
 * el fin del reveal), este helper garantiza que nunca actúen ANTES de tiempo.
 *
 * No duerme en el worker (bloquearía los jobs de las otras salas): si llegó
 * temprano se re-encola. Por eso los jobs que lo usan limitan sus reintentos
 * por excepciones (maxExceptions), no por cantidad de intentos.
 */
trait WaitsForServerTime
{
    /** Diferencias menores a esto se esperan en el lugar (no vale re-encolar). */
    private int $inlineWaitMs = 100;

    /** true si ya es la hora; si no, re-encola el job y devuelve false. */
    protected function waitUntil(int $targetMs): bool
    {
        $remaining = $targetMs - ServerClock::nowMs();
        if ($remaining <= 0) {
            return true;
        }
        if ($remaining <= $this->inlineWaitMs) {
            usleep($remaining * 1000);

            return true;
        }

        $this->release(max(1, (int) ceil($remaining / 1000)));

        return false;
    }
}
