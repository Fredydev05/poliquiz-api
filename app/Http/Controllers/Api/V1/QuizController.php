<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Quiz\DuplicateQuiz;
use App\Actions\Quiz\SaveQuizContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quiz\UpsertQuizRequest;
use App\Http\Resources\QuizResource;
use App\Models\Quiz;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class QuizController extends Controller
{
    /** Lista paginada: mis quizzes o los públicos (?filter=mine|public). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filter = $request->query('filter', 'mine');

        $quizzes = Quiz::query()
            ->withCount('questions')
            ->when($filter === 'mine', fn ($q) => $q->where('user_id', $request->user()->id))
            ->when($filter === 'public', fn ($q) => $q->where('visibility', 'public'))
            ->latest('updated_at')
            ->paginate(24);

        return QuizResource::collection($quizzes);
    }

    public function store(UpsertQuizRequest $request, SaveQuizContent $action): JsonResponse
    {
        $quiz = $request->user()->quizzes()->create(['title' => $request->validated('title')]);
        $quiz = $action->handle($quiz, $request->validated());

        return QuizResource::make($quiz)->response()->setStatusCode(201);
    }

    public function show(Request $request, Quiz $quiz): QuizResource
    {
        $this->authorize('view', $quiz);

        return QuizResource::make($quiz->load('questions.options')->loadCount('questions'));
    }

    /** Guardado atómico completo (lo usa el autoguardado del editor). */
    public function update(UpsertQuizRequest $request, Quiz $quiz, SaveQuizContent $action): QuizResource
    {
        $this->authorize('update', $quiz);

        return QuizResource::make($action->handle($quiz, $request->validated()));
    }

    public function destroy(Request $request, Quiz $quiz): JsonResponse
    {
        $this->authorize('delete', $quiz);
        $quiz->delete();

        return response()->json(null, 204);
    }

    public function duplicate(Request $request, Quiz $quiz, DuplicateQuiz $action): JsonResponse
    {
        $this->authorize('duplicate', $quiz);
        $copy = $action->handle($quiz, $request->user());

        return QuizResource::make($copy)->response()->setStatusCode(201);
    }
}
