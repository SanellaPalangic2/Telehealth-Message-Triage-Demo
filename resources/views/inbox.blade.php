<x-layouts.app title="Inbox" :refresh="$queuedCount > 0 ? 10 : null">
    <h1>Inbox</h1>
    <p class="muted">
        New messages are triaged automatically in the background. Red-flag messages skip the AI and go
        straight to a clinician; everything else gets an AI suggestion that a person must approve, edit, or reject.
    </p>

    @if ($newCount > 0)
        <form method="POST" action="{{ route('triage.pending') }}" class="card" style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
            @csrf
            <button class="btn primary" type="submit">Triage all pending ({{ $newCount }})</button>
            <span class="small muted">
                The demo's seeded messages aren't triaged automatically, to save API calls. This queues them for the background worker.
            </span>
        </form>
    @endif

    @if ($queuedCount > 0)
        <div class="banner purple small" role="status">
            {{ $queuedCount }} {{ str('message')->plural($queuedCount) }} queued for triage. This page refreshes every 10 seconds.
        </div>
    @endif

    <nav class="tabs" aria-label="Filter messages">
        @foreach ($tabs as $key => [$label])
            <a href="{{ route('inbox', ['tab' => $key]) }}" @class(['active' => $tab === $key])>
                {{ $label }} <span class="muted">({{ $tabCounts[$key] }})</span>
            </a>
        @endforeach
    </nav>

    <div class="card" style="padding: 0; overflow-x: auto;">
        @if ($messages->isEmpty())
            <p class="muted" style="padding: 18px; margin: 0;">No messages here.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Urgency</th>
                        <th class="hide-sm">Category</th>
                        <th>Message (redacted)</th>
                        <th class="hide-sm">Received</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($messages as $message)
                        @php($analysis = $message->latestAiAnalysis)
                        <tr>
                            <td><x-status-badge :status="$message->status" /></td>
                            <td>
                                @if ($message->status === \App\Enums\MessageStatus::UrgentReview)
                                    <span class="badge red">Red flag</span>
                                @else
                                    <x-urgency-badge :urgency="$analysis?->urgency" />
                                @endif
                                @if ($analysis?->requires_review && $message->status === \App\Enums\MessageStatus::PendingReview)
                                    <div class="small" style="color: var(--amber); margin-top: 4px;">Needs a closer look</div>
                                @endif
                            </td>
                            <td class="hide-sm">
                                {{ $analysis ? \App\Enums\Category::from($analysis->category)->label() : '—' }}
                            </td>
                            <td class="preview">
                                <a href="{{ route('messages.show', $message) }}">{{ $previews[$message->id] }}</a>
                            </td>
                            <td class="hide-sm small muted" title="{{ $message->received_at->toDayDateTimeString() }}">
                                {{ $message->received_at->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</x-layouts.app>
