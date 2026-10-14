<?php

namespace App\Http\Controllers;

use App\Models\PatientMessage;
use App\Services\TriageQueue;
use Illuminate\Http\RedirectResponse;
use LogicException;

/**
 * Person-initiated triage. Everything goes through the queue: the request
 * returns immediately and the background worker does the AI call.
 */
class TriageController extends Controller
{
    public function __construct(private TriageQueue $queue) {}

    /** Queue triage for one not-yet-triaged message. */
    public function store(PatientMessage $message): RedirectResponse
    {
        return $this->queueOne($message, false, 'Queued for triage. The background worker will pick it up shortly.');
    }

    /** Re-run triage for a failed or stale result. */
    public function rerun(PatientMessage $message): RedirectResponse
    {
        return $this->queueOne($message, true, 'Triage re-run queued.');
    }

    /** Queue every message that hasn't been triaged yet. */
    public function pending(): RedirectResponse
    {
        $count = $this->queue->queuePending();

        return to_route('inbox')->with(...$count === 0
            ? ['error', 'There are no untriaged messages.']
            : ['success', "Queued {$count} ".str('message')->plural($count).' for triage.']);
    }

    private function queueOne(PatientMessage $message, bool $rerun, string $success): RedirectResponse
    {
        try {
            $this->queue->queue($message, 'ui', $rerun);
        } catch (LogicException $e) {
            return to_route('messages.show', $message)->with('error', $e->getMessage());
        }

        return to_route('messages.show', $message)->with('success', $success);
    }
}
