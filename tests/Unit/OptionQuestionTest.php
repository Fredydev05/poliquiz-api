<?php

declare(strict_types=1);

use App\Exceptions\GameException;
use App\Services\Game\QuestionTypes\OptionQuestion;

/** Pregunta de ejemplo (formato snapshot): opción 1 correcta. */
function optionQuestion(): array
{
    return [
        'kind' => 'quiz',
        'title' => '¿2+2?',
        'time_limit' => 20,
        'points_mode' => 'standard',
        'options' => [
            ['id' => 0, 'text' => '3', 'is_correct' => false],
            ['id' => 1, 'text' => '4', 'is_correct' => true],
            ['id' => 2, 'text' => '5', 'is_correct' => false],
        ],
    ];
}

it('normalizes valid selected ids', function () {
    $answer = (new OptionQuestion)->normalizeAnswer(['selected' => [1, 1]], optionQuestion());
    expect($answer)->toBe(['selected' => [1]]); // deduplica
});

it('rejects options that do not belong to the question', function () {
    (new OptionQuestion)->normalizeAnswer(['selected' => [9]], optionQuestion());
})->throws(GameException::class);

it('rejects an empty selection', function () {
    (new OptionQuestion)->normalizeAnswer(['selected' => []], optionQuestion());
})->throws(GameException::class);

it('grades an exact match as fully correct', function () {
    $g = (new OptionQuestion)->grade(['selected' => [1]], optionQuestion());
    expect($g)->toBe(['ratio' => 1.0, 'correct' => true]);
});

it('grades a wrong option as incorrect', function () {
    $g = (new OptionQuestion)->grade(['selected' => [0]], optionQuestion());
    expect($g)->toBe(['ratio' => 0.0, 'correct' => false]);
});

it('never marks a poll (unscored) as correct', function () {
    $g = (new OptionQuestion(scored: false))->grade(['selected' => [1]], optionQuestion());
    expect($g)->toBe(['ratio' => 0.0, 'correct' => false]);
});

it('strips is_correct from the player view', function () {
    $view = (new OptionQuestion)->playerView(optionQuestion());
    expect($view['options'])->toBe([
        ['id' => 0, 'text' => '3'],
        ['id' => 1, 'text' => '4'],
        ['id' => 2, 'text' => '5'],
    ]);
});

it('counts the distribution per option including zeros', function () {
    $answers = [
        'a' => ['selected' => [1]],
        'b' => ['selected' => [1]],
        'c' => ['selected' => [0]],
    ];
    $dist = (new OptionQuestion)->distribution($answers, optionQuestion());
    expect($dist)->toBe([
        ['option_id' => 0, 'count' => 1],
        ['option_id' => 1, 'count' => 2],
        ['option_id' => 2, 'count' => 0],
    ]);
});
