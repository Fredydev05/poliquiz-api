<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Game\AdvanceGame;
use App\Actions\Game\CreateGame;
use App\Actions\Game\EndGame;
use App\Actions\Game\KickPlayer;
use App\Events\Game\GameLocked;
use App\Exceptions\GameException;
use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Services\Game\GameRepository;
use App\Services\Game\ServerClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Acciones del DOCENTE anfitrión sobre su partida. */
class GameController extends Controller
{
    public function __construct(private readonly GameRepository $repo) {}

    /** Abre la sala para un quiz propio (o público) con preguntas. */
    public function store(Request $request, CreateGame $action): JsonResponse
    {
        $data = $request->validate(['quiz_id' => ['required', 'integer', 'exists:quizzes,id']]);

        $quiz = Quiz::findOrFail($data['quiz_id']);
        $this->authorize('view', $quiz);

        abort_if(
            $quiz->questions()->count() === 0,
            422,
            'El cuestionario no tiene preguntas.'
        );

        ['game' => $game, 'pin' => $pin] = $action->handle($request->user(), $quiz);

        return response()->json(['game_id' => $game->id, 'pin' => $pin], 201);
    }

    /** Estado completo para rehidratar la pantalla del host (F5-proof). */
    public function state(Request $request, string $pin): JsonResponse
    {
        $state = $this->requireHostedGame($request, $pin);
        $index = (int) $state['current_index'];
        $players = $this->repo->players($pin);

        // Si hay una pregunta/diapositiva en curso o revelada, va COMPLETA (el
        // host sí puede ver las correctas y el cuerpo de la slide: es su proyección).
        $question = null;
        if ($index >= 0 && in_array($state['state'], ['get_ready', 'question', 'results', 'slide'], true)) {
            $question = $this->repo->snapshot($pin)['questions'][$index] ?? null;
        }

        return response()->json([
            'pin' => $pin,
            'state' => $state['state'],
            'state_version' => (int) $state['state_version'],
            'locked' => ($state['locked'] ?? '0') === '1',
            'quiz_title' => $state['quiz_title'] ?? '',
            'questions_total' => (int) ($state['questions_total'] ?? 0),
            'current_index' => $index,
            'question_uuid' => $state['question_uuid'] ?? null,
            'question' => $question,
            'ends_at_ms' => isset($state['ends_at']) ? (int) $state['ends_at'] : null,
            'server_now_ms' => ServerClock::nowMs(),
            // Exacto: la expulsión borra la respuesta del expulsado.
            'answered_count' => $index >= 0 ? $this->repo->answersCount($pin, $index) : 0,
            'top' => $this->repo->top($pin, 5),
            'players' => collect($players)
                ->map(fn (array $p, string $uuid) => [
                    'uuid' => $uuid,
                    'nickname' => $p['nickname'],
                    'score' => $p['score'],
                    'connected' => $p['connected'],
                ])->values(),
        ]);
    }

    /** start y next comparten la misma acción: avanzar la máquina. */
    public function start(Request $request, string $pin, AdvanceGame $action): JsonResponse
    {
        $this->requireHostedGame($request, $pin);

        return response()->json($action->handle($pin));
    }

    public function next(Request $request, string $pin, AdvanceGame $action): JsonResponse
    {
        $this->requireHostedGame($request, $pin);

        return response()->json($action->handle($pin));
    }

    /** Aborta la partida (X del host). */
    public function end(Request $request, string $pin, EndGame $action): JsonResponse
    {
        $this->requireHostedGame($request, $pin);
        $action->handle($pin);

        return response()->json(null, 204);
    }

    public function lock(Request $request, string $pin): JsonResponse
    {
        return $this->setLocked($request, $pin, true);
    }

    public function unlock(Request $request, string $pin): JsonResponse
    {
        return $this->setLocked($request, $pin, false);
    }

    public function kick(Request $request, string $pin, string $playerUuid, KickPlayer $action): JsonResponse
    {
        $this->requireHostedGame($request, $pin);
        $action->handle($pin, $playerUuid);

        return response()->json(null, 204);
    }

    /* ----------------------------- helpers ----------------------------- */

    private function setLocked(Request $request, string $pin, bool $locked): JsonResponse
    {
        $this->requireHostedGame($request, $pin);
        $this->repo->updateState($pin, ['locked' => $locked ? '1' : '0']);
        GameLocked::dispatch($pin, $locked);

        return response()->json(['locked' => $locked]);
    }

    /** La sala debe existir y pertenecer al docente autenticado. */
    private function requireHostedGame(Request $request, string $pin): array
    {
        $state = $this->repo->getState($pin) ?? throw GameException::pinNotFound();

        if ((int) $state['host_id'] !== $request->user()->id) {
            throw GameException::forbidden();
        }

        return $state;
    }
}
