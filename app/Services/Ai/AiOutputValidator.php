<?php

namespace App\Services\Ai;

use App\Enums\Category;
use App\Enums\Urgency;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Server-side check of the model's tool input. Never trust the model's
 * output just because the API returned it: anything that fails here is
 * rejected, not repaired.
 */
class AiOutputValidator
{
    private const FIELDS = ['category', 'urgency', 'summary', 'draft_reply', 'confidence'];

    /**
     * Phrases a draft reply must never contain, by reason. Deliberately
     * strict: a rejected draft just means a human writes the reply.
     */
    private const REPLY_POLICY = [
        'mentions a price' => [
            '/\$\s?\d/',
            '/\b\d+(?:\.\d+)?\s?(?:dollars|usd)\b/i',
            '/\b(?:copay|co-pay|deductible) (?:of|is)\b/i',
        ],
        'mentions a date or time commitment' => [
            '/\b\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?\b/',
            '/\b(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|tomorrow|tonight)\b/i',
            '/\b(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.? \d{1,2}\b/i',
            '/\bwithin \d+|\bwithin (?:an?|one|two|three|24|48) (?:hour|day|business day|week)s?\b/i',
            '/\b(?:by|before) (?:the )?end of (?:the )?(?:day|week)\b/i',
            '/\b\d{1,2}(?::\d{2})?\s?(?:am|pm)\b/i',
        ],
        'makes a guarantee' => [
            '/\bguarantee\w*\b/i',
            '/\bpromise\w*\b/i',
            '/\b(?:will definitely|100%|certainly will|rest assured)\b/i',
        ],
        'gives medical advice' => [
            '/\byou should (?:take|stop|start|increase|decrease|double|skip|reduce|continue)\b/i',
            '/\b(?:stop|start|keep|continue) taking\b/i',
            '/\b(?:increase|decrease|double|reduce|lower|raise|change) (?:your|the) (?:dose|dosage)\b/i',
            '/\b\d+\s?(?:mg|mcg|ml)\b/i',
            '/\b(?:is|it\'s|that\'s|this is) (?:normal|safe|nothing to worry about|not serious|common)\b/i',
        ],
        'contains a redaction placeholder' => [
            '/\[(?:NAME|PHONE|EMAIL|DOB|DATE|ID)\]/',
        ],
    ];

    /**
     * @param  mixed  $input  the tool_use block's input
     * @return array{category: string, urgency: string, summary: string, draft_reply: string, confidence: float}
     *
     * @throws AiTriageException
     */
    public function validate(mixed $input): array
    {
        if (! is_array($input)) {
            $this->reject(['The tool input is not an object.'], $input);
        }

        $errors = [];

        if ($extra = array_diff(array_keys($input), self::FIELDS)) {
            $errors[] = 'Unexpected fields: '.implode(', ', $extra).'.';
        }

        $validator = Validator::make($input, [
            'category' => ['required', 'string', Rule::enum(Category::class)],
            'urgency' => ['required', 'string', Rule::enum(Urgency::class)],
            'summary' => ['required', 'string', 'max:400'],
            'draft_reply' => ['required', 'string', 'max:1200'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ]);
        array_push($errors, ...$validator->errors()->all());

        if (isset($input['confidence']) && ! is_int($input['confidence']) && ! is_float($input['confidence'])) {
            $errors[] = 'The confidence must be a JSON number, not a string.';
        }

        if (is_string($input['summary'] ?? null) && $this->sentenceCount($input['summary']) > 2) {
            $errors[] = 'The summary is longer than 2 sentences.';
        }

        if (is_string($input['draft_reply'] ?? null)) {
            foreach (self::REPLY_POLICY as $reason => $patterns) {
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $input['draft_reply'])) {
                        $errors[] = "The draft reply {$reason}.";
                        break;
                    }
                }
            }
        }

        if ($errors !== []) {
            $this->reject($errors, $input);
        }

        return [
            'category' => $input['category'],
            'urgency' => $input['urgency'],
            'summary' => trim($input['summary']),
            'draft_reply' => trim($input['draft_reply']),
            'confidence' => (float) $input['confidence'],
        ];
    }

    private function sentenceCount(string $text): int
    {
        return count(preg_split('/(?<=[.!?])\s+(?=\S)/', trim($text), -1, PREG_SPLIT_NO_EMPTY));
    }

    /**
     * @param  list<string>  $errors
     */
    private function reject(array $errors, mixed $input): never
    {
        throw new AiTriageException(
            AiTriageException::INVALID_OUTPUT,
            'The AI returned output that failed validation: '.implode(' ', $errors),
            ['errors' => $errors, 'raw_output' => $input],
        );
    }
}
