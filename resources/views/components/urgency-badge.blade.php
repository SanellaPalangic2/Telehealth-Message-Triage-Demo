@props(['urgency'])

@if ($urgency)
    <span class="badge {{ ['high' => 'red', 'medium' => 'amber', 'low' => 'gray'][$urgency] ?? 'gray' }}">{{ ucfirst($urgency) }}</span>
@else
    <span class="muted">—</span>
@endif
