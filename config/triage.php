<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public demo limits
    |--------------------------------------------------------------------------
    |
    | Triage runs in a queued job and calls the paid AI API.
    |
    | - ai_per_day_global is the real cost ceiling: a daily budget of AI calls
    |   made by queued triage jobs, counted per call (AiBudget). When it's used
    |   up, red-flag rules still run and the AI step fails with a clear error.
    | - The per-IP and bulk limits throttle the buttons that queue triage.
    |   IP limits alone can be dodged, which is why the global budget exists.
    | - The CLI (triage:run without --queue, triage:eval) is for the owner and
    |   is not limited.
    |
    */

    'rate_limits' => [
        'ai_per_minute_per_ip' => (int) env('AI_TRIAGE_PER_MINUTE', 3),
        'ai_per_day_per_ip' => (int) env('AI_TRIAGE_PER_DAY_PER_IP', 20),
        'ai_per_day_global' => (int) env('AI_TRIAGE_PER_DAY', 150),
        'bulk_per_hour_per_ip' => (int) env('TRIAGE_BULK_PER_HOUR', 1),
        'bulk_per_day_global' => (int) env('TRIAGE_BULK_PER_DAY', 5),
        'reviews_per_minute_per_ip' => (int) env('REVIEWS_PER_MINUTE', 30),
    ],

];
