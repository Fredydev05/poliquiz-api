<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Enums\GameState;
use App\Models\Game;
use App\Models\Quiz;
use App\Models\User;
use App\Services\Game\GameRepository;
use App\Services\Game\ServerClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * El docente "abre la sala": congela el quiz en un snapshot, crea la fila
 * permanente en Postgres, reserva un PIN único y arma el estado en Redis.
 */
class CreateGame
{
    public function __construct(private readonly GameRepository $repo) {}

    /** @return array{game: Game, pin: string} */
    public function handle(User $host, Quiz $quiz): array
    {
        $snapshot = $this->snapshot($quiz);

        $game = Game::create([
            'user_id' => $host->id,
            'quiz_id' => $quiz->id,
            'quiz_snapshot' => $snapshot,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $pin = $this->assignPin($game);

        $this->repo->initGame($pin, [
            'state' => GameState::Lobby->value,
            'state_version' => 1,
            'game_id' => $game->id,
            'host_id' => (string) $host->id,
            'quiz_title' => $snapshot['title'],
            'quiz_snapshot' => json_encode($snapshot), // hot path: se lee por pregunta
            'questions_total' => (string) count($snapshot['questions']),
            'current_index' => '-1',
            'locked' => '0',
            'last_activity' => (string) ServerClock::nowMs(),
        ]);

        return ['game' => $game, 'pin' => $pin];
    }

    /**
     * Reserva un PIN libre en Redis y lo graba en la fila. Redis puede no
     * conocer un PIN que Postgres todavía tiene en una partida 'active' (sala
     * expirada o Redis reiniciado antes de que pase el barrido): el índice
     * único lo rechaza, liberamos esa reserva y probamos con otro.
     */
    private function assignPin(Game $game): string
    {
        for ($intento = 1; ; $intento++) {
            $pin = $this->repo->reservePin($game->id);

            try {
                // Transacción propia = savepoint: el error no envenena una
                // transacción externa (Postgres la abortaría entera).
                DB::transaction(fn () => $game->update(['pin' => $pin]));

                return $pin;
            } catch (UniqueConstraintViolationException $e) {
                $this->repo->releasePin($pin, $game->id);
                if ($intento >= 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Snapshot congelado del quiz: es lo ÚNICO que se juega (editar el quiz
     * después no afecta la partida). Incluye las correctas: solo lo lee el
     * servidor y la pantalla del host.
     */
    private function snapshot(Quiz $quiz): array
    {
        return [
            'title' => $quiz->title,
            'questions' => $quiz->questions()->with('options')->get()->map(fn ($q) => [
                'kind' => $q->kind->value,
                'title' => $q->title,
                'body' => $q->body, // solo lo usa la diapositiva
                'time_limit' => $q->time_limit,
                'points_mode' => $q->points_mode->value,
                'image_url' => $q->image_path ? Storage::disk('media')->url($q->image_path) : null,
                'options' => $q->options->map(fn ($o, $i) => [
                    'id' => $i,
                    'text' => $o->text,
                    'is_correct' => $o->is_correct,
                ])->all(),
            ])->all(),
        ];
    }
}
