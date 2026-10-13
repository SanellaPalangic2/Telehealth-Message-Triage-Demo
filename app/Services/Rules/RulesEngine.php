<?php

namespace App\Services\Rules;

/**
 * Deterministic emergency detection. No AI involved.
 *
 * Any hit sends the message straight to a clinician and the AI never sees it.
 * The rules are tuned for recall over precision: a false alarm costs a
 * clinician a few seconds, a missed emergency could cost a life. In
 * particular there is NO negation handling ("no chest pain" still fires) —
 * a human makes that call, not a regex.
 */
class RulesEngine
{
    /**
     * rule id => [label, patterns]. Patterns run case-insensitively on the
     * original message text (it never leaves the server).
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const RULES = [
        'chest_pain' => ['Chest pain or pressure', [
            '/\bchest\b[\w\s,\'’-]{0,20}?\b(?:pain|pains|pressure|tight\w*|heav\w*|squeez\w*|crush\w*|hurt\w*|ache|aching)\b/',
            '/\b(?:pain|pressure|tightness|heaviness|crushing)\b[\w\s,\'’-]{0,20}?\bchest\b/',
            '/\bheart attack\b/',
        ]],
        'breathing' => ['Trouble breathing', [
            '/\b(?:trouble|difficulty|hard|struggling|struggle|problems?)\s+(?:to\s+)?breath(?:e|ing)?\b/',
            '/\b(?:can\'?t|can’t|cannot|can not|unable to|couldn\'?t)\s+(?:catch\s+(?:my|his|her)\s+)?breath(?:e)?\b/',
            '/\bshort(?:ness)?\s+of\s+breath\b/',
            '/\b(?:gasping|choking)\b/',
            '/\b(?:stopped|not|isn\'?t)\s+breathing\b/',
            '/\blips?\b[\w\s]{0,15}\b(?:blue|purple|gr[ae]y)\b/',
        ]],
        'anaphylaxis' => ['Possible severe allergic reaction', [
            '/\b(?:throat|tongue|lips?|face|mouth)\b[\w\s\'’-]{0,25}?\b(?:clos\w*|swell\w*|swollen|tight\w*)\b/',
            '/\banaphyla\w*\b/',
        ]],
        'stroke' => ['Possible stroke', [
            '/\b(?:face|mouth|smile)\b[\w\s\'’-]{0,20}?\bdroop\w*\b/',
            '/\bslurr\w*\b/',
            '/\b(?:numb\w*|weak\w*|paraly\w*|can\'?t move)\b[\w\s\'’-]{0,30}?\bone side\b/',
            '/\bone side\b[\w\s\'’-]{0,30}?\b(?:numb\w*|weak\w*|paraly\w*|droop\w*)\b/',
            '/\bstroke\b/',
        ]],
        'self_harm' => ['Suicide or self-harm risk', [
            '/\bsuicid\w*\b/',
            '/\bkill(?:ing)?\s+(?:myself|me)\b/',
            '/\b(?:end|ending|take|taking)\s+(?:my|his|her|their)\s+(?:own\s+)?life\b/',
            '/\bend(?:ing)?\s+it\s+all\b/',
            '/\b(?:don\'?t|do not|no longer)\s+want\s+to\s+(?:live|be alive|be here|wake up)\b/',
            '/\b(?:hurt|hurting|harm|harming|cut|cutting)\s+myself\b/',
            '/\bself[\s-]?harm\w*\b/',
        ]],
        'overdose' => ['Possible overdose or poisoning', [
            '/\boverdos\w*\b/',
            // Not "took all my pills": that's how people describe a normal day.
            '/\b(?:took|taken|swallowed)\s+(?:too many|way too many|a whole bottle|the whole bottle|an entire bottle)\b/',
            '/\bpoison\w*\b/',
        ]],
        'bleeding' => ['Severe bleeding', [
            '/\b(?:heavy|severe|uncontrolled|a lot of|lots of)\s+bleeding\b/',
            '/\bbleeding\b[\w\s\'’-]{0,15}?\b(?:won\'?t|will not|doesn\'?t|does not|can\'?t)\s+stop\b/',
            '/\b(?:vomiting|throwing up|coughing up|coughing)\s+blood\b/',
        ]],
        'unresponsive' => ['Fainting, seizure, or unresponsive', [
            '/\b(?:passed out|fainted|unconscious|unresponsive|seizures?|seizing|convuls\w*)\b/',
            '/\b(?:won\'?t|will not|can\'?t)\s+wake\s+(?:him|her|them)?\s*up\b/',
        ]],
    ];

    public function evaluate(string $text): RulesResult
    {
        $hits = [];

        foreach (self::RULES as $rule => [$label, $patterns]) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern.'iu', $text, $match)) {
                    $hits[] = new RuleHit($rule, $label, $match[0]);

                    continue 2; // one hit per rule is enough
                }
            }
        }

        return new RulesResult($hits);
    }

    /**
     * @return array<string, string> rule id => label
     */
    public static function rules(): array
    {
        return array_map(fn (array $rule) => $rule[0], self::RULES);
    }
}
