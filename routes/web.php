<?php

use App\Http\Controllers\InboxController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inbox');

Route::get('/inbox', InboxController::class)->name('inbox');

Route::get('/messages/{message}', [MessageController::class, 'show'])->name('messages.show');
// Calls the paid AI API: rate-limited per visitor and globally (see AppServiceProvider).
Route::post('/messages/{message}/triage', [TriageController::class, 'store'])
    ->middleware('throttle:ai-triage')
    ->name('messages.triage');

Route::controller(ReviewController::class)->prefix('/messages/{message}')->name('messages.')->middleware('throttle:reviews')->group(function () {
    Route::post('/approve', 'approve')->name('approve');
    Route::post('/edit', 'edit')->name('edit');
    Route::post('/reject', 'reject')->name('reject');
});
