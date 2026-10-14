<?php

namespace App\Http\Controllers;

use App\Models\PatientMessage;
use App\Services\TriageOutcome;
use App\Services\TriagePipeline;
use Illuminate\Http\RedirectResponse;
use LogicException;

class TriageController extends Controller
{
    public function store(PatientMessage $message, TriagePipeline $pipeline): RedirectResponse
    {
        try {
            $outcome = $pipeline->process($message);
        } catch (LogicException $e) {
            return to_route('messages.show', $message)->with('error', $e->getMessage());
        }

        return to_route('messages.show', $message)->with(...match ($outcome) {
            TriageOutcome::Urgent => ['error', 'Red flag detected. Sent to the urgent clinician queue. The AI was not used.'],
            TriageOutcome::AiFailed => ['error', 'AI triage failed. See the details below. This message needs manual triage.'],
            TriageOutcome::Suggested => ['success', 'AI suggestion ready for review.'],
        });
    }
}
