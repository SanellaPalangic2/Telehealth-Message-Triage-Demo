@php
    use App\Enums\Category;
    use App\Enums\MessageStatus;
    $status = $message->status;
@endphp

<x-layouts.app :title="'Message #'.$message->id" :refresh="$status === MessageStatus::Queued ? 5 : null">
    <p class="small"><a href="{{ route('inbox') }}">&larr; Back to inbox</a></p>

    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 16px;">
        <h1 style="margin: 0;">Message #{{ $message->id }}</h1>
        <x-status-badge :status="$status" />
        <span class="muted small">Received {{ $message->received_at->toDayDateTimeString() }}</span>
    </div>

    @if ($errors->any())
        <div class="flash error" role="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="grid">
        <div>
            <section class="card">
                <h2>Patient message <span class="muted small">(redacted)</span></h2>
                <p class="message-text">{{ $redactedText }}</p>
                <p class="small muted" style="margin-bottom: 0;">
                    Personal details are replaced with placeholders like [NAME] and [PHONE] before any text reaches the AI.
                </p>
            </section>

            {{-- Not triaged yet --}}
            @if ($status === MessageStatus::New)
                <section class="card">
                    <h2>Not triaged yet</h2>
                    <p class="muted">
                        New messages are triaged automatically in the background. The demo's seeded messages are not,
                        to save API calls, so queue this one yourself. Triage runs the red-flag rules first; only if none
                        fire is the redacted text sent to the AI.
                    </p>
                    <form method="POST" action="{{ route('messages.triage', $message) }}">
                        @csrf
                        <button class="btn primary" type="submit">Queue triage</button>
                    </form>
                </section>
            @endif

            {{-- Prompt-injection warnings --}}
            @if ($suspectedInjectionLog)
                <div class="banner red" role="alert">
                    <strong>Suspected prompt injection.</strong>
                    The AI's output was rejected because it {{ implode('; ', $suspectedInjectionLog->details['reasons']) }}.
                    No suggestion was created.
                </div>
            @elseif ($injectionLog)
                <div class="banner red" role="alert">
                    <strong>This message contains text that looks like instructions to the AI.</strong>
                    Review any AI suggestion carefully. The message was not blocked; it was flagged for human review.
                </div>
            @endif

            {{-- Waiting for the worker --}}
            @if ($status === MessageStatus::Queued)
                <div class="banner purple" role="status">
                    <strong>Queued for triage.</strong> The background worker will pick this up shortly. This page refreshes every 5 seconds.
                    <div class="small" style="margin-top: 4px;">Stuck here? The queue worker may not be running (<code>php artisan queue:work</code>).</div>
                </div>
            @endif

            {{-- Red flag: rules sent it to a clinician; no AI --}}
            @if ($status === MessageStatus::UrgentReview)
                <div class="banner red" role="alert">
                    <strong>Urgent: clinician review.</strong> A red-flag rule fired, so this message skipped the AI entirely.
                </div>
            @endif

            {{-- AI failed: show the error, no fake output --}}
            @if ($status === MessageStatus::AiFailed)
                <div class="banner purple" role="alert">
                    <strong>AI triage failed.</strong> {{ $aiLog?->details['error'] ?? 'Unknown error.' }}
                    <div class="small" style="margin-top: 4px;">No suggestion was created. Write a reply manually below, or re-run triage.</div>
                </div>
            @endif

            {{-- Stale suggestion: made by an older prompt or model --}}
            @if ($isStale && $status === MessageStatus::PendingReview)
                <div class="banner purple" role="status">
                    <strong>This suggestion may be out of date.</strong>
                    It was made with prompt <code>{{ $analysis->prompt_version }}</code> on <code>{{ $analysis->model }}</code>,
                    which differs from the current configuration.
                </div>
            @endif

            @if ($canRerun)
                <form method="POST" action="{{ route('messages.rerun', $message) }}" style="margin-bottom: 16px;">
                    @csrf
                    <button class="btn" type="submit">Re-run triage</button>
                    <span class="small muted">Runs the rules and the AI again. The previous result stays in the history.</span>
                </form>
            @endif

            {{-- AI suggestion --}}
            @if ($analysis)
                <section class="card">
                    <h2>AI suggestion</h2>
                    @if ($analysis->requires_review && $status === MessageStatus::PendingReview)
                        <div class="banner red small">
                            <strong>Needs a closer look:</strong>
                            @if ($analysis->confidence < \App\Services\Ai\AiTriageResult::LOW_CONFIDENCE)
                                low AI confidence ({{ number_format($analysis->confidence * 100) }}%).
                            @endif
                            @if ($analysis->urgency === 'high')
                                the AI rated this as high urgency.
                            @endif
                            @if ($injectionLog)
                                the message contains a possible prompt injection.
                            @endif
                        </div>
                    @endif
                    <dl class="facts">
                        <dt>Category</dt><dd>{{ Category::from($analysis->category)->label() }}</dd>
                        <dt>Urgency</dt><dd><x-urgency-badge :urgency="$analysis->urgency" /></dd>
                        <dt>Confidence</dt><dd>{{ number_format($analysis->confidence * 100) }}%</dd>
                        <dt>Summary</dt><dd>{{ $analysis->summary }}</dd>
                    </dl>
                    <label style="margin-top: 14px;">Draft reply</label>
                    <p class="message-text">{{ $analysis->draft_reply }}</p>
                </section>
            @endif

            {{-- Review actions --}}
            @if ($status === MessageStatus::PendingReview)
                <section class="card">
                    <h2>Your decision</h2>
                    <p class="muted small">Nothing is sent or changed until you decide. Every decision is recorded in the audit log.</p>

                    <form method="POST" action="{{ route('messages.approve', $message) }}">
                        @csrf
                        <label for="approve-reviewer">Your name</label>
                        <input id="approve-reviewer" type="text" name="reviewer" value="{{ old('reviewer', $reviewer) }}" required maxlength="60">
                        <div class="actions">
                            <button class="btn primary" type="submit">Approve draft as written</button>
                        </div>
                    </form>

                    <details>
                        <summary>Edit the reply, then approve</summary>
                        <form method="POST" action="{{ route('messages.edit', $message) }}">
                            @csrf
                            <label for="edit-reviewer">Your name</label>
                            <input id="edit-reviewer" type="text" name="reviewer" value="{{ old('reviewer', $reviewer) }}" required maxlength="60">
                            <label for="final_reply">Reply</label>
                            <textarea id="final_reply" name="final_reply" required maxlength="2000">{{ old('final_reply', $analysis?->draft_reply) }}</textarea>
                            <label for="edit-note">Note <span class="muted">(optional)</span></label>
                            <input id="edit-note" type="text" name="note" value="{{ old('note') }}" maxlength="500">
                            <div class="actions"><button class="btn primary" type="submit">Approve edited reply</button></div>
                        </form>
                    </details>

                    <details>
                        <summary>Reject the suggestion</summary>
                        <form method="POST" action="{{ route('messages.reject', $message) }}">
                            @csrf
                            <label for="reject-reviewer">Your name</label>
                            <input id="reject-reviewer" type="text" name="reviewer" value="{{ old('reviewer', $reviewer) }}" required maxlength="60">
                            <label for="reject-note">Reason</label>
                            <input id="reject-note" type="text" name="note" value="{{ old('note') }}" required maxlength="500" placeholder="e.g. Wrong category; patient is asking about billing">
                            <div class="actions"><button class="btn danger" type="submit">Reject suggestion</button></div>
                        </form>
                    </details>
                </section>
            @endif

            {{-- Manual handling for messages the AI didn't (or couldn't) handle --}}
            @if (in_array($status, [MessageStatus::UrgentReview, MessageStatus::AiFailed], true))
                <section class="card">
                    <h2>{{ $status === MessageStatus::UrgentReview ? 'Record clinician reply' : 'Write reply manually' }}</h2>
                    <form method="POST" action="{{ route('messages.edit', $message) }}">
                        @csrf
                        <label for="manual-reviewer">Your name</label>
                        <input id="manual-reviewer" type="text" name="reviewer" value="{{ old('reviewer', $reviewer) }}" required maxlength="60">
                        <label for="manual-reply">Reply</label>
                        <textarea id="manual-reply" name="final_reply" required maxlength="2000">{{ old('final_reply') }}</textarea>
                        <label for="manual-note">Note <span class="muted">(optional)</span></label>
                        <input id="manual-note" type="text" name="note" value="{{ old('note') }}" maxlength="500">
                        <div class="actions"><button class="btn primary" type="submit">Save reply</button></div>
                    </form>
                </section>
            @endif

            {{-- Final decision --}}
            @if ($decision)
                <section class="card">
                    <h2>Decision</h2>
                    <dl class="facts">
                        <dt>Action</dt><dd>{{ ucfirst($decision->action->value) }}</dd>
                        <dt>Reviewer</dt><dd>{{ $decision->reviewer }}</dd>
                        <dt>When</dt><dd>{{ $decision->created_at->toDayDateTimeString() }}</dd>
                        @if ($decision->note)
                            <dt>Note</dt><dd>{{ $decision->note }}</dd>
                        @endif
                    </dl>
                    @if ($decision->final_reply)
                        <label style="margin-top: 14px;">Approved reply</label>
                        <p class="message-text">{{ $decision->final_reply }}</p>
                        <p class="small muted" style="margin-bottom: 0;">In a real system, the messaging platform would send this reply. This demo doesn't send anything.</p>
                    @endif
                </section>
            @endif
        </div>

        <div>
            {{-- Why? --}}
            <section class="card">
                <h2>Why?</h2>

                <h3 class="small" style="margin: 0 0 6px;">1. Red-flag rules <span class="badge red">RULE</span></h3>
                @if (! $ruleLog)
                    <p class="muted small">Not run yet.</p>
                @elseif ($ruleLog->action === 'rules.red_flag')
                    <ul class="small" style="margin-top: 0;">
                        @foreach ($ruleLog->details['hits'] as $hit)
                            <li><strong>{{ $hit['label'] }}</strong>: matched “{{ $hit['matched'] }}”</li>
                        @endforeach
                    </ul>
                    <p class="small muted">The AI was not called.</p>
                @else
                    <p class="small">None of the {{ count($rules) }} emergency rules fired, so the message went to the AI.</p>
                    <details class="small">
                        <summary>Rules checked</summary>
                        <ul>
                            @foreach ($rules as $label)
                                <li>{{ $label }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <h3 class="small" style="margin: 16px 0 6px;">Prompt-injection checks <span class="badge red">RULE</span></h3>
                @if ($injectionLog)
                    <ul class="small" style="margin-top: 0;">
                        @foreach ($injectionLog->details['hits'] as $hit)
                            <li><strong>{{ $hit['label'] }}</strong>: matched “{{ $hit['matched'] }}”</li>
                        @endforeach
                    </ul>
                @elseif ($ruleLog)
                    <p class="small muted">No instruction-like text found in the message.</p>
                @else
                    <p class="muted small">Not run yet.</p>
                @endif
                @if ($suspectedInjectionLog)
                    <p class="small">
                        <strong>Output check:</strong> rejected because the {{ implode('; ', $suspectedInjectionLog->details['reasons']) }}.
                    </p>
                @endif

                <h3 class="small" style="margin: 16px 0 6px;">2. AI <span class="badge purple">AI</span></h3>
                @if ($analysis)
                    <dl class="facts small">
                        <dt>Model</dt><dd>{{ $analysis->model }}</dd>
                        <dt>Prompt version</dt><dd><code>{{ $analysis->prompt_version }}</code></dd>
                        <dt>Response ID</dt><dd><code>{{ $analysis->response_id }}</code></dd>
                        <dt>Latency</dt><dd>{{ number_format($analysis->latency_ms) }} ms</dd>
                        <dt>Tokens</dt><dd>{{ number_format($analysis->input_tokens) }} in · {{ number_format($analysis->output_tokens) }} out</dd>
                        <dt>Redacted</dt>
                        <dd>
                            @forelse (($aiLog?->details['redactions'] ?? []) as $type => $count)
                                {{ $count }} × [{{ $type }}]@if (! $loop->last), @endif
                            @empty
                                nothing
                            @endforelse
                        </dd>
                    </dl>
                    <details class="small">
                        <summary>Exactly what the AI returned</summary>
                        <pre class="json">{{ json_encode($analysis->raw_output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                    </details>
                @elseif ($aiLog?->action === 'ai.failed')
                    <dl class="facts small">
                        <dt>Reason</dt><dd><code>{{ $aiLog->details['reason'] }}</code></dd>
                        <dt>Error</dt><dd>{{ $aiLog->details['error'] }}</dd>
                    </dl>
                    @if (! empty($aiLog->details['raw_output']))
                        <details class="small" open>
                            <summary>Rejected AI output</summary>
                            <pre class="json">{{ json_encode($aiLog->details['raw_output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    @endif
                @elseif ($status === MessageStatus::UrgentReview)
                    <p class="muted small">Skipped because a red flag fired.</p>
                @else
                    <p class="muted small">Not run yet.</p>
                @endif
            </section>

            {{-- Audit trail --}}
            <section class="card">
                <h2>Audit trail</h2>
                @if ($logs->isEmpty())
                    <p class="muted small">No activity yet.</p>
                @else
                    <ol class="timeline small">
                        @foreach ($logs as $log)
                            <li>
                                <span class="badge {{ ['AI' => 'purple', 'RULE' => 'red', 'HUMAN' => 'green'][$log->actor->value] }}">{{ $log->actor->value }}</span>
                                <code>{{ $log->action }}</code>
                                @if (isset($log->details['reviewer']))
                                    by {{ $log->details['reviewer'] }}
                                @endif
                                <div class="muted">{{ $log->created_at->toDayDateTimeString() }}</div>
                            </li>
                        @endforeach
                    </ol>
                    <p class="small muted" style="margin-bottom: 0;">Append-only: entries can't be edited or deleted.</p>
                @endif
            </section>
        </div>
    </div>
</x-layouts.app>
