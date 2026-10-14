<?php

namespace App\Services\Ai;

use App\Services\RedactionResult;

/**
 * A validated AI suggestion plus the metadata of the call that produced it.
 */
final readonly class AiTriageResult
{
    public const LOW_CONFIDENCE = 0.6;

    /**
     * @param  array{category: string, urgency: string, summary: string, draft_reply: string, confidence: float}  $output
     * @param  array<string, mixed>  $rawOutput  the tool input exactly as the model returned it
     */
    public function __construct(
        public array $output,
        public array $rawOutput,
        public RedactionResult $redaction,
        public string $model,
        public string $promptVersion,
        public string $responseId,
        public int $latencyMs,
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    /**
     * Every suggestion is reviewed by a human before anything happens. This
     * flag marks the ones that need a closer look: low confidence, or the AI
     * itself thinks it's urgent.
     */
    public function requiresReview(): bool
    {
        return $this->output['confidence'] < self::LOW_CONFIDENCE
            || $this->output['urgency'] === 'high';
    }
}
