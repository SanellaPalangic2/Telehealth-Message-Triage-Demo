<?php

use App\Services\Eval\TriageEvaluator;
use App\Services\Rules\RulesEngine;
use Database\Seeders\PatientMessageSeeder;

function evalCases(): array
{
    return require __DIR__.'/../../database/eval/triage_cases.php';
}

test('the eval set has 30 well-formed cases with unique ids', function () {
    $cases = evalCases();

    expect($cases)->toHaveCount(30)
        ->and(array_unique(array_column($cases, 'id')))->toHaveCount(30);

    foreach ($cases as $case) {
        expect(array_keys($case))->toEqualCanonicalizing(['id', 'red_flag', 'category', 'urgency', 'body'])
            ->and($case['urgency'])->toBeIn(['low', 'medium', 'high']);

        if ($case['red_flag']) {
            expect($case['category'])->toBeNull()->and($case['urgency'])->toBe('high');
        } else {
            expect($case['category'])->toBeIn(['refill', 'side_effect', 'appointment', 'billing', 'other']);
        }
    }
});

test('the eval set covers every category and has red flags', function () {
    $cases = collect(evalCases());

    expect($cases->where('red_flag', true)->count())->toBeGreaterThanOrEqual(4)
        ->and($cases->pluck('category')->filter()->unique()->sort()->values()->all())
        ->toBe(['appointment', 'billing', 'other', 'refill', 'side_effect']);
});

test('the eval set does not reuse seed messages', function () {
    $seedBodies = array_column(PatientMessageSeeder::data(), 'body');

    foreach (evalCases() as $case) {
        expect($seedBodies)->not->toContain($case['body']);
    }
});

test('the rules catch every red flag in the eval set', function () {
    $engine = new RulesEngine;

    foreach (collect(evalCases())->where('red_flag', true) as $case) {
        expect($engine->evaluate($case['body'])->isRedFlag())->toBeTrue("Missed red flag {$case['id']}");
    }
});

test('the case set id changes when the cases change', function () {
    $cases = evalCases();
    $changed = $cases;
    $changed[0]['urgency'] = 'low';

    expect(TriageEvaluator::caseSetId('x/triage_cases.php', $cases))
        ->toStartWith('triage_cases@')
        ->not->toBe(TriageEvaluator::caseSetId('x/triage_cases.php', $changed));
});
