<?php

use App\Models\PatientMessage;
use App\Services\TriagePrompt;
use App\Services\TriageQueue;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * The web app and the in-container queue worker share one SQLite file.
 * These guard the fixes for "database is locked" (a 500 on "Triage all
 * pending") and "unable to open database file" (nested savepoints spilling
 * to temp files under `php artisan serve` on Windows).
 */

test('SQLite waits for a busy database and takes the write lock up front', function () {
    expect(config('database.connections.sqlite.busy_timeout'))->toBe(5000)
        ->and(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE');
});

test('SQLite keeps temporary data in memory', function () {
    // 2 = MEMORY: savepoint undo logs never need a temp file.
    expect((int) DB::select('PRAGMA temp_store')[0]->temp_store)->toBe(2);
});

test('"Triage all pending" uses one transaction with no nested savepoints', function () {
    $this->seed(DatabaseSeeder::class);
    Queue::fake();

    $levels = [];
    PatientMessage::updating(function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    // RefreshDatabase wraps each test in a transaction, so "one transaction" is level 2 here.
    $baseline = DB::transactionLevel();
    app(TriageQueue::class)->queuePending();

    expect($levels)->toHaveCount(38)
        ->and(array_unique($levels))->toBe([$baseline + 1]);
});

test('draft replies are told not to repeat numbers from the message', function () {
    // An innocent echo ("your request about a 90-day supply", "your
    // lisinopril 10mg refill") would otherwise trip the strict output check.
    expect(TriagePrompt::SYSTEM)
        ->toContain('do not repeat any numbers from the patient\'s message')
        ->toContain('"90-day"')
        ->toContain('"10mg"');
});
