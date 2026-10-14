<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Global daily cap on queued AI calls: the real cost ceiling for the public
 * demo. Counted per AI call (not per click), because one "Triage all
 * pending" click can queue many calls.
 */
class AiBudget
{
    /**
     * Use one unit of today's budget. Returns false if it's used up.
     */
    public function consume(): bool
    {
        $key = 'ai-budget:'.now()->toDateString();

        if (RateLimiter::tooManyAttempts($key, $this->dailyLimit())) {
            return false;
        }

        RateLimiter::hit($key, 2 * 86400);

        return true;
    }

    public function dailyLimit(): int
    {
        return (int) config('triage.rate_limits.ai_per_day_global');
    }
}
