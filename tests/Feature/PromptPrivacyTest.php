<?php

use App\Services\TriagePrompt;
use Database\Seeders\PatientMessageSeeder;

/**
 * Break a PII string into the pieces that would still identify someone if
 * they leaked on their own: each name/number part ("Delgado", "1985", "0142")
 * and the bare digits of phone/ID numbers ("2015550142").
 *
 * @return list<string>
 */
function piiFragments(string $pii): array
{
    $fragments = [$pii];

    foreach (preg_split('/[^\p{L}\p{N}]+/u', $pii, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        if (mb_strlen($part) >= 4 && ! in_array(strtolower($part), ['example', 'march'], true)) {
            $fragments[] = $part;
        }
    }

    $digits = preg_replace('/\D/', '', $pii);
    if (strlen($digits) >= 7) {
        $fragments[] = $digits;
    }

    return array_values(array_unique($fragments));
}

test('no seeded PII reaches the AI prompt', function (array $message) {
    $payload = app(TriagePrompt::class)->build($message['body']);

    $outbound = mb_strtolower($payload->outboundText());
    $outboundDigits = preg_replace('/\D/', '', $payload->redaction->text);

    foreach ($message['pii'] as $pii) {
        foreach (piiFragments($pii) as $fragment) {
            $leaked = ctype_digit($fragment) && strlen($fragment) >= 7
                ? str_contains($outboundDigits, $fragment)
                : str_contains($outbound, mb_strtolower($fragment));

            expect($leaked)->toBeFalse("PII fragment '{$fragment}' (from '{$pii}') reached the prompt");
        }
    }
})->with(fn () => collect(PatientMessageSeeder::data())
    ->filter(fn ($m) => $m['pii'] !== [])
    ->mapWithKeys(fn ($m, $i) => ["seed #{$i} ({$m['kind']})" => [$m]])
    ->all());

test('messages with PII get one placeholder per planted value', function (array $message) {
    $payload = app(TriagePrompt::class)->build($message['body']);

    expect($payload->redaction->total())->toBeGreaterThanOrEqual(count($message['pii']));
})->with(fn () => collect(PatientMessageSeeder::data())
    ->filter(fn ($m) => $m['pii'] !== [])
    ->mapWithKeys(fn ($m, $i) => ["seed #{$i} ({$m['kind']})" => [$m]])
    ->all());

test('messages without PII reach the prompt unchanged', function (array $message) {
    $payload = app(TriagePrompt::class)->build($message['body']);

    expect($payload->redaction->text)->toBe($message['body'])
        ->and($payload->redaction->counts)->toBe([]);
})->with(fn () => collect(PatientMessageSeeder::data())
    ->filter(fn ($m) => $m['pii'] === [])
    ->mapWithKeys(fn ($m, $i) => ["seed #{$i} ({$m['kind']})" => [$m]])
    ->all());

test('the prompt wraps only the redacted text', function () {
    $payload = app(TriagePrompt::class)->build(
        'Hi, this is Maria Delgado, DOB 03/14/1985. Call (201) 555-0142.'
    );

    expect($payload->messages)->toHaveCount(1)
        ->and($payload->messages[0]['content'])->toContain(
            "<patient_message>\nHi, this is [NAME], DOB [DOB]. Call [PHONE].\n</patient_message>"
        );
});
