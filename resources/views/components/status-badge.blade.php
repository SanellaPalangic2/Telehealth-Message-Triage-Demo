@props(['status'])

@php
    $color = match ($status) {
        \App\Enums\MessageStatus::UrgentReview => 'red',
        \App\Enums\MessageStatus::AiFailed => 'purple',
        \App\Enums\MessageStatus::PendingReview => 'amber',
        \App\Enums\MessageStatus::New, \App\Enums\MessageStatus::Queued => 'blue',
        \App\Enums\MessageStatus::Approved => 'green',
        \App\Enums\MessageStatus::Rejected => 'gray',
    };
@endphp

<span class="badge {{ $color }}">{{ $status->label() }}</span>
