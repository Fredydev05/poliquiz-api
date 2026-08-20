<?php

declare(strict_types=1);

namespace App\Services\Game\QuestionTypes;

use App\Enums\QuestionKind;

/**
 * Mapea cada QuestionKind a su estrategia. Es el ÚNICO lugar que hay que tocar
 * al sumar un tipo nuevo (junto con su clase QuestionType). El match sobre el
 * enum es exhaustivo: si se agrega un case sin estrategia, revienta en runtime
 * (falla visible, no comportamiento silencioso).
 */
final class QuestionTypeResolver
{
    private readonly QuestionType $scoredOptions;

    private readonly QuestionType $poll;

    private readonly QuestionType $short;

    private readonly QuestionType $puzzle;

    private readonly QuestionType $slide;

    public function __construct()
    {
        $this->scoredOptions = new OptionQuestion(scored: true);
        $this->poll = new OptionQuestion(scored: false); // opciones, pero sin correcta ni puntos
        $this->short = new ShortAnswerQuestion;
        $this->puzzle = new PuzzleQuestion;
        $this->slide = new SlideItem;
    }

    public function for(QuestionKind $kind): QuestionType
    {
        return match ($kind) {
            QuestionKind::Quiz,
            QuestionKind::TrueFalse,
            QuestionKind::Multi => $this->scoredOptions,
            QuestionKind::Poll => $this->poll,
            QuestionKind::Short => $this->short,
            QuestionKind::Puzzle => $this->puzzle,
            QuestionKind::Slide => $this->slide,
        };
    }
}
