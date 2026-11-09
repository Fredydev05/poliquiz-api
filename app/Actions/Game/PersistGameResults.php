<?php

declare(strict_types=1);

namespace App\Actions\Game;

use App\Models\GamePlayer;
use App\Services\Game\GameRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Vuelca los resultados de la partida de Redis a Postgres ANTES de destruir
 * la sala (plan §6). Idempotente: si ya hay filas para el juego, no repite.
 *
 * Ranking final: puntaje desc, desempate por menor tiempo total de respuesta
 * acumulado (decisión del plan §10).
 */
class PersistGameResults
{
    public function __construct(private readonly GameRepository $repo) {}

    public function handle(string $pin): void
    {
        $state = $this->repo->getState($pin);
        if ($state === null) {
            return;
        }

        $gameId = $state['game_id'];
        if (GamePlayer::where('game_id', $gameId)->exists()) {
            return; // ya persistido
        }

        $players = $this->repo->players($pin);
        if ($players === []) {
            return; // nadie jugó: nada que guardar
        }

        $total = (int) $state['questions_total'];

        // Una lectura por pregunta (evita N+1 sobre Redis).
        $answersByQuestion = [];
        for ($i = 0; $i < $total; $i++) {
            $answersByQuestion[$i] = $this->repo->answers($pin, $i);
        }

        // Resumen por jugador + detalle de sus respuestas.
        $rows = [];
        foreach ($players as $uuid => $p) {
            $answers = [];
            $correct = 0;
            $totalMs = 0;

            for ($i = 0; $i < $total; $i++) {
                $a = $answersByQuestion[$i][$uuid] ?? null;
                if ($a === null) {
                    continue;
                }
                $answers[] = [
                    'question_index' => $i,
                    // La forma depende del tipo: ids elegidos, el orden del
                    // rompecabezas o [texto] en "escribe la respuesta".
                    'selected' => $a['selected'] ?? $a['order'] ?? (isset($a['text']) ? [$a['text']] : []),
                    'response_ms' => $a['response_ms'],
                    'is_correct' => $a['is_correct'] ?? false,
                    'points' => $a['points'] ?? 0,
                    'streak_after' => $a['streak_after'] ?? 0,
                ];
                $correct += ($a['is_correct'] ?? false) ? 1 : 0;
                $totalMs += $a['response_ms'];
            }

            $rows[] = [
                'uuid' => $uuid,
                'nickname' => $p['nickname'],
                'score' => (int) $p['score'],
                'correct' => $correct,
                'total_ms' => $totalMs,
                'answers' => $answers,
            ];
        }

        // Puntaje desc; a igualdad, gana quien acumuló menos tiempo.
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['total_ms'] <=> $b['total_ms']);

        try {
            $this->store($rows, $gameId);
        } catch (UniqueConstraintViolationException) {
            // Otro proceso persistió esta misma partida entre el exists() y
            // acá: la transacción se revirtió entera y sus filas son válidas.
        }
    }

    private function store(array $rows, string|int $gameId): void
    {
        DB::transaction(function () use ($rows, $gameId): void {
            foreach ($rows as $rank => $row) {
                $player = GamePlayer::create([
                    'game_id' => $gameId,
                    'player_uuid' => $row['uuid'],
                    'nickname' => $row['nickname'],
                    'final_score' => $row['score'],
                    'final_rank' => $rank + 1,
                    'answers_correct' => $row['correct'],
                ]);

                if ($row['answers'] !== []) {
                    $player->answers()->createMany($row['answers']);
                }
            }
        });
    }
}
