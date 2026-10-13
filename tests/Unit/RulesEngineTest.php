<?php

use App\Services\Rules\RulesEngine;
use Database\Seeders\PatientMessageSeeder;

function ruleIds(string $text): array
{
    return (new RulesEngine)->evaluate($text)->ruleIds();
}

$seeds = fn (callable $filter) => fn () => collect(PatientMessageSeeder::data())
    ->filter($filter)
    ->mapWithKeys(fn ($m, $i) => ["seed #{$i} ({$m['kind']})" => [$m]])
    ->all();

test('every seeded red flag fires exactly its expected rules', function (array $message) {
    expect(ruleIds($message['body']))->toBe($message['rules']);
})->with($seeds(fn ($m) => $m['kind'] === 'red_flag'));

test('no seeded routine message is flagged', function (array $message) {
    expect(ruleIds($message['body']))->toBe([]);
})->with($seeds(fn ($m) => $m['kind'] !== 'red_flag'));

test('each rule catches other common phrasings', function (string $text, string $rule) {
    expect(ruleIds($text))->toContain($rule);
})->with([
    ['My chest feels tight and heavy', 'chest_pain'],
    ['sharp pain in my chest when I walk', 'chest_pain'],
    ['I think I am having a heart attack', 'chest_pain'],
    ["I can't breathe", 'breathing'],
    ['he is short of breath walking to the car', 'breathing'],
    ['it is hard to breathe lying down', 'breathing'],
    ['her lips look blue', 'breathing'],
    ['my tongue is swollen after the shot', 'anaphylaxis'],
    ['I think this is anaphylaxis', 'anaphylaxis'],
    ['her smile is drooping on the left', 'stroke'],
    ['sudden weakness on one side of his body', 'stroke'],
    ['I might be having a stroke', 'stroke'],
    ['I have been having suicidal thoughts', 'self_harm'],
    ['I want to kill myself', 'self_harm'],
    ["I just don't want to live anymore", 'self_harm'],
    ['I keep cutting myself', 'self_harm'],
    ['I took too many of my sleeping pills', 'overdose'],
    ['my son may have swallowed a whole bottle of vitamins', 'overdose'],
    ['the bleeding will not stop after the procedure', 'bleeding'],
    ['I am coughing up blood', 'bleeding'],
    ['my mother fainted in the kitchen', 'unresponsive'],
    ['he had a seizure this morning', 'unresponsive'],
    ["I can't wake him up", 'unresponsive'],
    ['CRUSHING CHEST PAIN', 'chest_pain'],
]);

test('near misses are not flagged', function (string $text) {
    expect(ruleIds($text))->toBe([]);
})->with([
    'Why did I get a bill for my chest X-ray?',
    'I need more solution for my nebulizer breathing treatments.',
    'My ankles are a little swollen at night.',
    'I took all my pills this morning like you said.',
    'Can I get a refill on my blood pressure medication?',
    'Do I need to fast before my blood work?',
]);

test('negated or unrelated mentions are deliberately still flagged', function (string $text, string $rule) {
    // Over-flagging is intentional: a clinician, not a regex, decides that
    // "no chest pain" or "my dad's stroke last year" is fine.
    expect(ruleIds($text))->toContain($rule);
})->with([
    ['No chest pain, just a cough', 'chest_pain'],
    ['My dad had a stroke years ago, is that relevant?', 'stroke'],
]);

test('a hit records the rule, its label, and the matched phrase', function () {
    $hit = (new RulesEngine)->evaluate('crushing chest pain since lunch')->hits[0];

    expect($hit->toArray())->toBe([
        'rule' => 'chest_pain',
        'label' => 'Chest pain or pressure',
        'matched' => 'chest pain',
    ]);
});
