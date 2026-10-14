<x-layouts.app title="Inbox">
    <h1>Inbox</h1>
    <p class="muted">
        Red-flag messages skip the AI and go straight to a clinician. Everything else gets an AI
        suggestion that a person must approve, edit, or reject.
    </p>

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
