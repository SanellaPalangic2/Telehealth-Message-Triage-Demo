<?php

namespace App\Http\Controllers;

use App\Enums\MessageStatus;
use App\Models\PatientMessage;
use App\Services\RedactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InboxController extends Controller
{
    /** Inbox tabs: key => [label, statuses]. */
    public const TABS = [
        'open' => ['Needs attention', [MessageStatus::UrgentReview, MessageStatus::AiFailed, MessageStatus::PendingReview, MessageStatus::Queued, MessageStatus::New]],
        'urgent' => ['Urgent', [MessageStatus::UrgentReview]],
        'pending' => ['Pending review', [MessageStatus::PendingReview]],
        'failed' => ['AI failed', [MessageStatus::AiFailed]],
        'new' => ['Not triaged', [MessageStatus::New, MessageStatus::Queued]],
        'done' => ['Done', [MessageStatus::Approved, MessageStatus::Rejected]],
    ];

    public function __invoke(Request $request, RedactionService $redactor): View
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'open';

        $messages = PatientMessage::query()
            ->with('latestAiAnalysis')
            ->withExists(['auditLogs as injection_flagged' => fn ($q) => $q->whereIn('action', ['injection_attempt', 'suspected_injection'])])
            ->whereIn('status', self::TABS[$tab][1])
            // Urgent first, then failures, then pending, then the rest; newest first within each.
            ->orderByRaw("case status when 'urgent_review' then 0 when 'ai_failed' then 1 when 'pending_review' then 2 when 'queued' then 3 when 'new' then 4 else 5 end")
            ->orderByDesc('received_at')
            ->get();

        $counts = PatientMessage::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $tabCounts = collect(self::TABS)->map(
            fn ($t) => collect($t[1])->sum(fn (MessageStatus $s) => $counts[$s->value] ?? 0)
        );

        // Previews are always redacted, whether or not the AI saw the message.
        $previews = $messages->mapWithKeys(fn (PatientMessage $m) => [
            $m->id => Str::limit($m->latestAiAnalysis?->redacted_text ?? $redactor->redact($m->body)->text, 110),
        ]);

        return view('inbox', [
            'messages' => $messages,
            'previews' => $previews,
            'tab' => $tab,
            'tabs' => self::TABS,
            'tabCounts' => $tabCounts,
            'newCount' => $counts[MessageStatus::New->value] ?? 0,
            'queuedCount' => $counts[MessageStatus::Queued->value] ?? 0,
        ]);
    }
}
