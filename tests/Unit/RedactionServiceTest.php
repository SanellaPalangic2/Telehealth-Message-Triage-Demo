<?php

use App\Services\RedactionService;

function redact(string $text): string
{
    return (new RedactionService)->redact($text)->text;
}

describe('emails', function () {
    test('are replaced', function (string $input, string $expected) {
        expect(redact($input))->toBe($expected);
    })->with([
        ['Email me at k.okafor@example.com please', 'Email me at [EMAIL] please'],
        ['d.nguyen.test@example.org.', '[EMAIL].'],
        ['UPPER.CASE+tag@Example.NET', '[EMAIL]'],
    ]);
});

describe('phone numbers', function () {
    test('are replaced in common formats', function (string $input) {
        expect(redact("Call me at {$input} today"))->toBe('Call me at [PHONE] today');
    })->with([
        '(201) 555-0142',
        '312-555-0187',
        '415.555.0163',
        '646 555 0129',
        '+1 646-555-0129',
        '1-800-555-0100',
    ]);

    test('unformatted 10-digit numbers are still removed', function () {
        expect(redact('Reach me on 2015550142'))->not->toContain('2015550142');
    });
});

describe('dates and birth dates', function () {
    test('a date after a birth-date label becomes [DOB]', function (string $input) {
        expect(redact($input))->toEndWith('[DOB].');
    })->with([
        'DOB 03/14/1985.',
        'DOB: 5/8/1961.',
        'date of birth 1952-07-09.',
        'Date of birth is March 3, 1990.',
        'born on 3 March 1990.',
        'Birthday: Nov 22nd, 2015.',
    ]);

    test('other full dates become [DATE]', function () {
        expect(redact('I was seen on 04/02/2026 and 2026-04-09.'))
            ->toBe('I was seen on [DATE] and [DATE].');
    });

    test('a DOB in parentheses is caught', function () {
        expect(redact('my son (DOB 11/22/2015) needs a checkup'))
            ->toBe('my son (DOB [DOB]) needs a checkup');
    });
});

describe('member and ID numbers', function () {
    test('are replaced while keeping the label', function (string $input, string $expected) {
        expect(redact($input))->toBe($expected);
    })->with([
        ['My member ID is TD-4829-1173.', 'My member ID is [ID].'],
        ['Member #A55102938.', 'Member #[ID].'],
        ['Policy number HX-7730-2291.', 'Policy number [ID].'],
        ['My patient ID is MRN 00482913.', 'My patient ID is MRN [ID].'],
        ['account: 99812', 'account: [ID]'],
    ]);

    test('unlabeled ID-shaped tokens are replaced', function () {
        expect(redact('Ref TD-4829-1173 and ZX12345'))->toBe('Ref [ID] and [ID]');
    });
});

describe('names', function () {
    test('are found by context', function (string $input, string $expected) {
        expect(redact($input))->toBe($expected);
    })->with([
        'self intro' => ['Hi, my name is Thomas Brennan.', 'Hi, my name is [NAME].'],
        'this is' => ['This is Priya Raman, DOB unknown', 'This is [NAME], DOB unknown'],
        "i'm" => ["I'm Carla Ruiz and I need help", "I'm [NAME] and I need help"],
        'relation' => ['My daughter Sofia Martinez needs a visit', 'My daughter [NAME] needs a visit'],
        'relation with comma' => ['on behalf of my father, Robert Okafor (age 70)', 'on behalf of my father, [NAME] (age 70)'],
        'third person' => ['His name is Daniel Kim, he is 64', 'His name is [NAME], he is 64'],
        'title' => ['Dr. Alvarez said to message', 'Dr. [NAME] said to message'],
        'sign-off' => ["Thanks,\nJames Whitfield", "Thanks,\n[NAME]"],
        'dash sign-off' => ["Need a refill.\n- Nora", "Need a refill.\n- [NAME]"],
        'apostrophe' => ["This is Liam O'Brien", 'This is [NAME]'],
        'accented' => ['my name is José Peña', 'my name is [NAME]'],
    ]);

    test('every later mention of a found name is removed too', function () {
        expect(redact('This is Maria Delgado. Maria here again, Delgado family.'))
            ->toBe('This is [NAME]. [NAME] here again, [NAME] family.');
    });

    test('do not swallow the next sentence', function () {
        expect(redact('My name is Ana Lopez. Can you help?'))
            ->toBe('My name is [NAME]. Can you help?');
    });
});

describe('text without personal data', function () {
    test('is left unchanged', function (string $input) {
        expect(redact($input))->toBe($input);
    })->with([
        'Requesting a 90-day supply of atorvastatin instead of the 30-day if possible.',
        'I run out of sertraline 50mg tomorrow. Can the doctor renew it?',
        "I've had crushing chest pain for the last 30 minutes.",
        'Why did I get a bill for my chest X-ray from last month?',
        'Is this normal? This is my second message.',
        'The patient portal link is broken.',
        'My husband\'s face is drooping on one side.',
    ]);
});

test('redaction is idempotent', function () {
    $once = redact('This is Maria Delgado, DOB 03/14/1985, call (201) 555-0142, member ID TD-4829-1173, m.d@example.com');

    expect(redact($once))->toBe($once);
});

test('counts what was removed without storing values', function () {
    $result = (new RedactionService)->redact(
        'This is Angela Brooks, phone 646-555-0129, email angela.b@example.net, DOB 1/2/1980.'
    );

    expect($result->counts)->toBe(['EMAIL' => 1, 'PHONE' => 1, 'DOB' => 1, 'NAME' => 1])
        ->and($result->total())->toBe(4);
});
