<?php

use App\Http\Controllers\InboxController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inbox');

Route::get('/inbox', InboxController::class)->name('inbox');

Route::get('/messages/{message}', [MessageController::class, 'show'])->name('messages.show');
// These queue paid AI calls: rate-limited per visitor (AppServiceProvider),
// and every queued AI call also counts against the global daily AI budget.
Route::controller(TriageController::class)->group(function () {
    Route::post('/messages/{message}/triage', 'store')->middleware('throttle:ai-triage')->name('messages.triage');
    Route::post('/messages/{message}/rerun', 'rerun')->middleware('throttle:ai-triage')->name('messages.rerun');
    Route::post('/triage/pending', 'pending')->middleware('throttle:triage-bulk')->name('triage.pending');
});

Route::controller(ReviewController::class)->prefix('/messages/{message}')->name('messages.')->middleware('throttle:reviews')->group(function () {
    Route::post('/approve', 'approve')->name('approve');
    Route::post('/edit', 'edit')->name('edit');
    Route::post('/reject', 'reject')->name('reject');
});
