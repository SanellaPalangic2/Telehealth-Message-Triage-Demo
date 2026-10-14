<?php

namespace App\Http\Controllers;

use App\Models\PatientMessage;
use App\Services\RedactionService;
use App\Services\Rules\RulesEngine;
use App\Services\TriageQueue;
use Illuminate\View\View;

class MessageController extends Controller
{
    public function show(PatientMessage $message, RedactionService $redactor, TriageQueue $queue): View
    {
        $message->load(['latestAiAnalysis', 'reviewDecisions', 'auditLogs' => fn ($q) => $q->orderBy('id')]);

        $analysis = $message->latestAiAnalysis;
        $logs = $message->auditLogs;

        return view('messages.show', [
            'message' => $message,
            'analysis' => $analysis,
            // The page only ever shows redacted text. Urgent messages never
            // reached the AI, so they're redacted here for display.
            'redactedText' => $analysis?->redacted_text ?? $redactor->redact($message->body)->text,
            'ruleLog' => $logs->first(fn ($l) => str_starts_with($l->action, 'rules.')),
            'aiLog' => $logs->last(fn ($l) => str_starts_with($l->action, 'ai.')),
            'rules' => RulesEngine::rules(),
            'decision' => $message->reviewDecisions->sortByDesc('id')->first(),
            'logs' => $logs,
            'reviewer' => session('reviewer', ''),
            'injectionLog' => $logs->last(fn ($l) => $l->action === 'injection_attempt'),
            'suspectedInjectionLog' => $logs->last(fn ($l) => $l->action === 'suspected_injection'),
            'canRerun' => $queue->canRerun($message),
            'isStale' => $analysis?->isStale() ?? false,
        ]);
    }
}
