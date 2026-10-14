<?php

use Database\Seeders\PatientMessageSeeder;

test('there are exactly 38 seed messages', function () {
    expect(PatientMessageSeeder::data())->toHaveCount(38);
});

test('the seed set covers every message kind', function () {
    $counts = collect(PatientMessageSeeder::data())->countBy('kind');

    expect($counts->keys()->sort()->values()->all())
        ->toBe(['appointment', 'billing', 'other', 'red_flag', 'refill', 'side_effect'])
        ->and($counts['red_flag'])->toBe(5);
});

test('every planted PII string actually appears in its message', function () {
    // Guards the redaction tests: if a PII string has a typo here, those
    // tests would pass trivially without proving anything.
    foreach (PatientMessageSeeder::data() as $i => $message) {
        foreach ($message['pii'] as $pii) {
            expect(str_contains($message['body'], $pii))->toBeTrue("Seed message #{$i} is missing PII '{$pii}'");
        }
    }
});

test('a good share of messages contain PII', function () {
    $withPii = collect(PatientMessageSeeder::data())->filter(fn ($m) => $m['pii'] !== []);

    expect($withPii->count())->toBeGreaterThanOrEqual(10);
});

test('seed data only uses reserved fictional phone numbers and email domains', function () {
    foreach (PatientMessageSeeder::data() as $message) {
        foreach ($message['pii'] as $pii) {
            if (str_contains($pii, '@')) {
                expect($pii)->toMatch('/@example\.(com|org|net)$/');
            }

            if (preg_match('/\d{3}\D{0,2}\d{3}\D\d{4}/', $pii)) {
                expect($pii)->toMatch('/555\D01\d{2}$/');
            }
        }
    }
});
