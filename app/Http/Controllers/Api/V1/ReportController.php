<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\QuestionKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\GameReportListResource;
use App\Models\Game;
use App\Models\PlayerAnswer;
use App\Services\Game\TextModerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Reportes post-partida (plan §6). Solo el docente anfitrión puede verlos.
 * Toda la data sale de Postgres (game_players + player_answers) y del
 * quiz_snapshot congelado en la partida.
 */
class ReportController extends Controller
{
    /** Historial de partidas jugadas por el docente. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $games = Game::where('user_id', $request->user()->id)
            ->whereIn('status', ['ended', 'aborted'])
            ->withCount('players')
            ->latest('ended_at')
            ->paginate(20);

        return GameReportListResource::collection($games);
    }

    /** Resumen: precisión global, podio y pregunta más difícil. */
    public function show(Request $request, Game $game): JsonResponse
    {
        $this->authorizeGame($request, $game);

        $players = $game->players()->orderBy('final_rank')->get();
        $questions = $game->quiz_snapshot['questions'] ?? [];
        $answers = $this->answersByQuestion($game);

        // Solo cuentan las preguntas con respuesta correcta: una encuesta, una
        // diapositiva o una nube no pueden bajar la precisión ni ser "la más difícil".
        $puntuables = array_filter($questions, fn (array $q) => $this->isScored($q));

        $totalCorrect = $players->sum('answers_correct');
        $totalPossible = max(1, $players->count() * count($puntuables));

        // Pregunta más difícil = menor tasa de acierto (entre las respondidas).
        $hardest = null;
        $peorTasa = 2.0;
        foreach ($puntuables as $i => $q) {
            $qa = $answers[$i] ?? collect();
            if ($qa->isEmpty()) {
                continue;
            }
            $tasa = $qa->where('is_correct', true)->count() / $qa->count();
            if ($tasa < $peorTasa) {
                $peorTasa = $tasa;
                $hardest = ['index' => $i, 'title' => $q['title'], 'accuracy' => round($tasa * 100)];
            }
        }

        return response()->json([
            'game_id' => $game->id,
            'quiz_title' => $game->quiz_snapshot['title'] ?? '',
            'status' => $game->status,
            'played_at' => $game->started_at?->toIso8601String(),
            'ended_at' => $game->ended_at?->toIso8601String(),
            'players_count' => $players->count(),
            'questions_count' => count($questions),
            'accuracy' => round($totalCorrect / $totalPossible * 100),
            'podium' => $players->take(3)->map(fn ($p) => [
                'nickname' => $p->nickname,
                'score' => $p->final_score,
                'rank' => $p->final_rank,
            ])->values(),
            'hardest_question' => $hardest,
        ]);
    }

    /** Distribución por opción, % de acierto y tiempo medio por pregunta. */
    public function questions(Request $request, Game $game): JsonResponse
    {
        $this->authorizeGame($request, $game);

        $questions = $game->quiz_snapshot['questions'] ?? [];
        $answers = $this->answersByQuestion($game);

        $result = collect($questions)->map(function (array $q, int $i) use ($answers) {
            $qa = $answers[$i] ?? collect();
            $answered = $qa->count();
            // Solo en los tipos de opciones "selected" son ids elegidos; en
            // rompecabezas es un orden y en "escribe" un texto: no hay conteo.
            $shape = QuestionKind::from($q['kind'])->answerShape();
            $porOpcion = $shape === 'options';
            $scored = $this->isScored($q);

            return [
                'index' => $i,
                'title' => $q['title'],
                'kind' => $q['kind'],
                'answered' => $answered,
                'scored' => $scored,
                'correct' => $qa->where('is_correct', true)->count(),
                // Sin respuesta correcta (encuesta, nube…) no hay precisión que medir.
                'accuracy' => ! $scored ? null : ($answered ? round($qa->where('is_correct', true)->count() / $answered * 100) : 0),
                'avg_response_ms' => $answered ? (int) round($qa->avg('response_ms')) : null,
                'options' => collect($q['options'] ?? [])->map(fn (array $o) => [
                    'id' => $o['id'],
                    'text' => $o['text'],
                    'is_correct' => $o['is_correct'],
                    // Cuántos eligieron esta opción (una respuesta puede tener varias).
                    'count' => $porOpcion
                        ? $qa->filter(fn ($a) => in_array($o['id'], $a->selected, true))->count()
                        : null,
                ])->values(),
                // Lo que escribieron (escribe, nube, abierta, ideas), agrupado.
                'texts' => in_array($shape, ['text', 'ideas'], true) ? $this->groupTexts($qa) : null,
            ];
        });

        return response()->json(['questions' => $result]);
    }

    /** Detalle por jugador: puesto, puntaje, aciertos y sus respuestas. */
    public function players(Request $request, Game $game): JsonResponse
    {
        $this->authorizeGame($request, $game);

        $players = $game->players()->with('answers')->orderBy('final_rank')->get();

        return response()->json([
            'players' => $players->map(fn ($p) => [
                'nickname' => $p->nickname,
                'rank' => $p->final_rank,
                'score' => $p->final_score,
                'answers_correct' => $p->answers_correct,
                'answers' => $p->answers->sortBy('question_index')->map(fn ($a) => [
                    'question_index' => $a->question_index,
                    'is_correct' => $a->is_correct,
                    'points' => $a->points,
                    'response_ms' => $a->response_ms,
                ])->values(),
            ])->values(),
        ]);
    }

    /* ------------------------------ helpers ------------------------------ */

    private function authorizeGame(Request $request, Game $game): void
    {
        abort_unless($game->user_id === $request->user()->id, 403);
    }

    /**
     * Todas las respuestas del juego agrupadas por índice de pregunta.
     *
     * @return Collection<int, Collection<int, PlayerAnswer>>
     */
    private function isScored(array $question): bool
    {
        return QuestionKind::from($question['kind'])->isScored();
    }

    /**
     * Agrupa los textos iguales (sin importar mayúsculas ni acentos), los más
     * repetidos primero.
     *
     * @return list<array{text: string, count: int}>
     */
    private function groupTexts(Collection $answers): array
    {
        $groups = [];
        foreach ($answers as $answer) {
            foreach ($answer->selected as $text) {
                $key = TextModerator::normalize((string) $text);
                $groups[$key] ??= ['text' => (string) $text, 'count' => 0];
                $groups[$key]['count']++;
            }
        }
        $groups = array_values($groups);
        usort($groups, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return array_slice($groups, 0, 200);
    }

    private function answersByQuestion(Game $game)
    {
        return PlayerAnswer::whereIn('game_player_id', $game->players()->select('id'))
            ->get()
            ->groupBy('question_index');
    }
}
