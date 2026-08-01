<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Game\JoinGame;
use App\Actions\Game\SubmitAnswer;
use App\Http\Controllers\Controller;
use App\Services\Game\GameRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Acciones del JUGADOR invitado (join es público; el resto usa guard 'player'). */
class PlayerGameController extends Controller
{
    public function __construct(private readonly GameRepository $repo) {}

    public function join(Request $request, string $pin, JoinGame $action): JsonResponse
    {
        $data = $request->validate(['nickname' => ['required', 'string', 'max:40']]);

        return response()->json($action->handle($pin, $data['nickname']), 201);
    }

    /** Registra la respuesta del jugador a la pregunta en juego. */
    public function answer(Request $request, string $pin, SubmitAnswer $action): JsonResponse
    {
        $player = $request->user('player');
        abort_if($player->pin !== $pin, 401);

        $data = $request->validate([
            'question_index' => ['required', 'integer', 'min:0'],
            'question_uuid' => ['required', 'uuid'],
            'selected' => ['required', 'array', 'min:1', 'max:4'],
            'selected.*' => ['integer', 'min:0'],
        ]);

        $action->handle($player, $pin, $data['question_index'], $data['question_uuid'], $data['selected']);

        // Los puntos NO se revelan hasta QuestionEnded (anti-spoiler).
        return response()->json(['received' => true]);
    }

    /** Estado propio del jugador: reconexión y feedback post-pregunta. */
    public function me(Request $request, string $pin): JsonResponse
    {
        $player = $request->user('player'); // guard explícito: nunca el docente

        if ($player->pin !== $pin) {
            abort(401);
        }

        $state = $this->repo->getState($pin);
        if ($state === null) {
            return response()->json(['message' => 'La partida terminó.', 'code' => 'GAME_ENDED'], 410);
        }

        $data = $this->repo->player($pin, $player->uuid);
        $index = (int) $state['current_index'];

        // ¿Ya respondió la pregunta vigente? (para no repetir controles tras un refresh)
        $answered = $index >= 0
            && ($this->repo->answers($pin, $index)[$player->uuid] ?? null) !== null;

        // Si reconecta EN plena pregunta, mandamos el enunciado y opciones
        // SIN las correctas (anti-trampa), para re-pintar el control de respuesta.
        $question = null;
        if ($state['state'] === 'question' && $index >= 0) {
            $q = $this->repo->snapshot($pin)['questions'][$index] ?? null;
            if ($q !== null) {
                $question = [
                    'kind' => $q['kind'],
                    'title' => $q['title'],
                    'image_url' => $q['image_url'] ?? null,
                    'time_limit' => $q['time_limit'],
                    'points_mode' => $q['points_mode'],
                    'options' => array_map(fn ($o) => ['id' => $o['id'], 'text' => $o['text']], $q['options']),
                ];
            }
        }

        return response()->json([
            'state' => $state['state'],
            'state_version' => (int) $state['state_version'],
            'locked' => ($state['locked'] ?? '0') === '1',
            'quiz_title' => $state['quiz_title'] ?? '',
            'current_index' => $index,
            'questions_total' => (int) ($state['questions_total'] ?? 0),
            'question' => $question,
            'question_uuid' => $state['question_uuid'] ?? null,
            'ends_at_ms' => isset($state['ends_at']) ? (int) $state['ends_at'] : null,
            'server_now_ms' => (int) (microtime(true) * 1000),
            'nickname' => $player->nickname,
            'player_uuid' => $player->uuid,
            'answered' => $answered,
            'score' => $data['score'] ?? 0,
            'streak' => $data['streak'] ?? 0,
            'rank' => $this->repo->rank($pin, $player->uuid),
            // Resultado de la última pregunta cerrada (feedback del celular).
            'last_result' => isset($data['last_correct']) ? [
                'is_correct' => (bool) $data['last_correct'],
                'points' => (int) ($data['last_gain'] ?? 0),
                'streak' => (int) ($data['streak'] ?? 0),
            ] : null,
        ]);
    }
}
