<?php

namespace App\Http\Controllers;

use App\Models\PatientMessage;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

class ReviewController extends Controller
{
    public function __construct(private ReviewService $reviews) {}

    public function approve(Request $request, PatientMessage $message): RedirectResponse
    {
        $data = $request->validate([
            'reviewer' => ['required', 'string', 'max:60'],
        ]);

        return $this->run($request, $message, 'Reply approved.',
            fn () => $this->reviews->approve($message, $data['reviewer']));
    }

    public function edit(Request $request, PatientMessage $message): RedirectResponse
    {
        $data = $request->validate([
            'reviewer' => ['required', 'string', 'max:60'],
            'final_reply' => ['required', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->run($request, $message, 'Edited reply approved.',
            fn () => $this->reviews->edit($message, $data['reviewer'], $data['final_reply'], $data['note'] ?? null));
    }

    public function reject(Request $request, PatientMessage $message): RedirectResponse
    {
        $data = $request->validate([
            'reviewer' => ['required', 'string', 'max:60'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        return $this->run($request, $message, 'Suggestion rejected.',
            fn () => $this->reviews->reject($message, $data['reviewer'], $data['note']));
    }

    private function run(Request $request, PatientMessage $message, string $success, callable $action): RedirectResponse
    {
        // Remember the reviewer's name for the next form.
        $request->session()->put('reviewer', $request->input('reviewer'));

        try {
            $action();
        } catch (LogicException $e) {
            return to_route('messages.show', $message)->with('error', $e->getMessage());
        }

        return to_route('messages.show', $message)->with('success', $success);
    }
}
