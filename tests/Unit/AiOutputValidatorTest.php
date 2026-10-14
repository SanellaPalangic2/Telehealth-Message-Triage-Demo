<?php

use App\Services\Ai\AiOutputValidator;
use App\Services\Ai\AiTriageException;

function validateOutput(mixed $input): array
{
    return (new AiOutputValidator)->validate($input);
}

function validationErrors(mixed $input): array
{
    try {
        validateOutput($input);
    } catch (AiTriageException $e) {
        expect($e->reason)->toBe(AiTriageException::INVALID_OUTPUT);

        return $e->details['errors'];
    }

    throw new RuntimeException('Expected validation to fail, but it passed.');
}

test('a valid output passes and is normalized', function () {
    $output = validateOutput(triageInput(['confidence' => 1, 'summary' => '  Needs a refill.  ']));

    expect($output['confidence'])->toBe(1.0)
        ->and($output['summary'])->toBe('Needs a refill.');
});

test('each category and urgency value is accepted', function (string $field, string $value) {
    expect(validateOutput(triageInput([$field => $value]))[$field])->toBe($value);
})->with([
    ['category', 'refill'], ['category', 'side_effect'], ['category', 'appointment'],
    ['category', 'billing'], ['category', 'other'],
    ['urgency', 'low'], ['urgency', 'medium'], ['urgency', 'high'],
]);

test('structurally invalid output is rejected', function (mixed $input, string $expectedError) {
    expect(implode(' ', validationErrors($input)))->toContain($expectedError);
})->with([
    'not an object' => ['just text', 'not an object'],
    'unknown category' => [triageInput(['category' => 'emergency']), 'category'],
    'unknown urgency' => [triageInput(['urgency' => 'critical']), 'urgency'],
    'confidence above 1' => [triageInput(['confidence' => 1.5]), 'confidence'],
    'confidence below 0' => [triageInput(['confidence' => -0.1]), 'confidence'],
    'confidence as string' => [triageInput(['confidence' => '0.9']), 'JSON number'],
    'missing field' => [array_diff_key(triageInput(), ['summary' => 1]), 'summary'],
    'extra field' => [triageInput(['diagnosis' => 'flu']), 'Unexpected fields: diagnosis'],
    'empty reply' => [triageInput(['draft_reply' => '']), 'draft reply'],
    'three-sentence summary' => [triageInput(['summary' => 'One. Two. Three.']), 'longer than 2 sentences'],
]);

test('a two-sentence summary is fine', function () {
    expect(validateOutput(triageInput(['summary' => 'Patient needs a refill. They have 3 pills left.'])))
        ->toHaveKey('summary');
});

test('draft replies that break the reply policy are rejected', function (string $reply, string $reason) {
    expect(implode(' ', validationErrors(triageInput(['draft_reply' => $reply]))))->toContain($reason);
})->with([
    ['Your visit will cost $45.', 'price'],
    ['The fee is 30 dollars.', 'price'],
    ['Your copay is about the same as before.', 'price'],
    ['We will call you on Thursday.', 'date'],
    ['We can see you tomorrow.', 'date'],
    ['Your appointment is on 10/14.', 'date'],
    ['We will reply within 24 hours.', 'date'],
    ['Expect a call at 3pm.', 'date'],
    ['We guarantee your refill will be approved.', 'guarantee'],
    ['I promise someone will help.', 'guarantee'],
    ['You should stop taking the medication.', 'medical advice'],
    ['Try to double your dose.', 'dosage'],
    ['Please take 20 mg instead.', 'dosage'],
    ['That is common and nothing serious.', 'medical advice'],
    ["Don't worry, it's normal with this medicine.", 'medical advice'],
    ['Hi [NAME], thanks for writing.', 'placeholder'],
]);

test('every violation is reported, not just the first', function () {
    $errors = validationErrors(triageInput([
        'category' => 'nope',
        'draft_reply' => 'We guarantee it will cost $10 on Monday.',
    ]));

    expect(count($errors))->toBeGreaterThanOrEqual(4);
});

test('the rejected raw output is kept for the audit trail', function () {
    try {
        validateOutput(triageInput(['category' => 'nope']));
    } catch (AiTriageException $e) {
        expect($e->details['raw_output']['category'])->toBe('nope');
    }
});
