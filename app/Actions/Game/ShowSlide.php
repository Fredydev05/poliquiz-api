<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Events\Game\SlideShown;
use App\Services\Game\GameRepository;
use App\Services\Game\GameStateMachine;

/**
 * Proyecta la diapositiva del índice $index (desde lobby, leaderboard u otra
 * slide). No agenda jobs ni timer: se queda en estado `slide` hasta que el
 * host toca "Siguiente" (AdvanceGame).
 */
class ShowSlide
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly GameStateMachine $machine,
    ) {}

    public function handle(string $pin, GameState $from, int $index): void
    {
        $version = $this->machine->transition($pin, $from, GameState::Slide, [
            'current_index' => (string) $index,
            'question_uuid' => '', // una slide no tiene pregunta en juego
        ]);

        $slide = $this->repo->snapshot($pin)['questions'][$index];
        $total = (int) $this->repo->getState($pin)['questions_total'];

        SlideShown::dispatch(
            $pin,
            $version,
            $index,
            $total,
            $slide['title'],
            $slide['body'] ?? null,
            $slide['image_url'] ?? null,
        );
    }
}
