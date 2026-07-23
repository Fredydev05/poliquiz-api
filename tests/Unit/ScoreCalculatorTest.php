<?php

declare(strict_types=1);

use App\Enums\PointsMode;
use App\Services\Scoring\ScoreCalculator;

beforeEach(function () {
    $this->calc = new ScoreCalculator;
});

it('scores instant answers near 1000 and limit answers at 500', function () {
    expect($this->calc->score(true, 0, 20000, PointsMode::Standard, 0)['points'])->toBe(1000)
        ->and($this->calc->score(true, 10000, 20000, PointsMode::Standard, 0)['points'])->toBe(750)
        ->and($this->calc->score(true, 20000, 20000, PointsMode::Standard, 0)['points'])->toBe(500);
});

it('doubles points in double mode without doubling the streak bonus', function () {
    // streakBefore=1 → esta es la 2ª correcta: bonus 100 (no se duplica).
    expect($this->calc->score(true, 0, 20000, PointsMode::Double, 1)['points'])->toBe(2100);
});

it('gives zero points in none mode but keeps the streak alive', function () {
    $r = $this->calc->score(true, 0, 20000, PointsMode::None, 2);
    expect($r['points'])->toBe(0)->and($r['streak'])->toBe(3);
});

it('builds streak bonus from the 2nd consecutive correct and caps at 500', function () {
    // streakBefore = 0..6 → bonus esperado 0,100,200,300,400,500,500
    $bonusEsperado = [0, 100, 200, 300, 400, 500, 500];
    foreach ($bonusEsperado as $before => $bonus) {
        $r = $this->calc->score(true, 0, 20000, PointsMode::Standard, $before);
        expect($r['points'])->toBe(1000 + $bonus)
            ->and($r['streak'])->toBe($before + 1);
    }
});

it('resets the streak and gives 0 points on a wrong answer', function () {
    $r = $this->calc->score(false, 1000, 20000, PointsMode::Standard, 5);
    expect($r['points'])->toBe(0)->and($r['streak'])->toBe(0);
});
