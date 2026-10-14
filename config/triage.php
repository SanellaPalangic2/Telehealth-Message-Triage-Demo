<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public demo rate limits
    |--------------------------------------------------------------------------
    |
    | "Run triage" from the web UI calls the paid AI API, so it is limited per
    | visitor (by IP) and globally per day. The global cap is the real cost
    | ceiling: IP limits alone can be dodged. The CLI commands (triage:process,
    | triage:eval) are for the owner and are not limited.
    |
    */

    'rate_limits' => [
        'ai_per_minute_per_ip' => (int) env('AI_TRIAGE_PER_MINUTE', 3),
        'ai_per_day_per_ip' => (int) env('AI_TRIAGE_PER_DAY_PER_IP', 20),
        'ai_per_day_global' => (int) env('AI_TRIAGE_PER_DAY', 150),
        'reviews_per_minute_per_ip' => (int) env('REVIEWS_PER_MINUTE', 30),
    ],

];
