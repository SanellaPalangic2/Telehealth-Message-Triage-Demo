<?php

namespace App\Services\Rules;

/**
 * Spots text in a patient message that looks like instructions aimed at the
 * AI ("ignore your instructions", "SYSTEM:", "reply in JSON", hidden HTML
 * comments, fake delimiter tags).
 *
 * It never blocks a message: patients can't be refused care because of odd
 * wording. A hit is logged (RULE) and forces human review of whatever the AI
 * suggests. It's one layer; the prompt delimiters, the strict schema, the
 * output check, and the status guards are the others.
 */
class InjectionDetector
{
    /** @var array<string, array{0: string, 1: list<string>}> */
    private const PATTERNS = [
        'override' => ['Tries to override the AI\'s instructions', [
            '/\b(?:ignore|disregard|forget|override)\b[\w\s,\'’]{0,20}?\b(?:instructions?|rules?|prompts?|guidelines|directions)\b/',
            '/\b(?:new|updated|real) instructions\b/',
            '/\badmin override\b/',
        ]],
        'prompt_leak' => ['Asks for the system prompt or instructions', [
            '/\b(?:system|hidden|initial|full) prompt\b/',
            '/\b(?:print|reveal|show|repeat|output|tell me)\b[\w\s]{0,15}?\b(?:your|the)\s+(?:full\s+|hidden\s+)?(?:system\s+)?(?:prompt|instructions)\b/',
        ]],
        'role_play' => ['Pretends to be the system or changes the AI\'s role', [
            '/(?:^|[\n.!?]\s*)(?:system|assistant|developer|admin)\s*:/',
            '/\byou are (?:now|no longer)\b/',
            '/\b(?:developer|admin|approval|god|jailbreak) mode\b/',
        ]],
        'output_control' => ['Tries to dictate the AI\'s output', [
            '/\b(?:reply|respond|answer|output)\s+(?:only\s+)?(?:in|with|as)\s+json\b/',
            '/\b(?:approve|approved|category|urgency|status|confidence)\s*=\s*\w+/',
            '/\b(?:mark|classify|label|flag)\s+(?:this|it|me|my message|the message)\s+(?:message\s+)?(?:as|to)\s+(?:low|medium|high|billing|refill|appointment|other|side[_ ]effect|approved|urgent|non-urgent)\b/',
            '/\bset\s+(?:the\s+)?(?:urgency|category|status|confidence)\b/',
        ]],
        'authority' => ['Claims authority over the AI', [
            '/\bI (?:authorize|order|instruct|command) you\b/',
            '/\bas the (?:doctor|admin|administrator|developer|clinic admin)\b[\w\s,]{0,20}?\bI\b/',
        ]],
        'markup' => ['Contains hidden markup or fake delimiters', [
            '/<\/?\s*patient_message/',
            '/<!--/',
            '/\[\/?(?:INST|SYS)\]/',
        ]],
    ];

    public function evaluate(string $text): RulesResult
    {
        $hits = [];

        foreach (self::PATTERNS as $rule => [$label, $patterns]) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern.'iu', $text, $match)) {
                    $hits[] = new RuleHit($rule, $label, trim($match[0]));

                    continue 2;
                }
            }
        }

        return new RulesResult($hits);
    }
}
