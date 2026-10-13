<?php

namespace App\Services;

/**
 * Removes personal data from patient text BEFORE it can reach the AI.
 *
 * Deterministic, regex-based, and deliberately biased toward over-redacting:
 * replacing a harmless word with [NAME] costs a little accuracy, while leaking
 * a real name costs privacy.
 *
 * Order matters: emails first (they contain name-like text), then phones,
 * dates, IDs, and finally names.
 */
class RedactionService
{
    /** One capitalized name word: Maria, McDonald, O'Brien, Jean-Luc, José. */
    private const NAME_WORD = "(?:\p{Lu}['’]\p{Lu}\p{Ll}+|\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+)?(?:['’-]\p{Lu}?\p{Ll}+)?)";

    /** One to three name words on the same line, with an optional middle initial. */
    private const FULL_NAME = '(?!(?:Dr|Mr|Mrs|Ms|Mx)\b)(?<name>'.self::NAME_WORD.'(?:[ \t]+(?:\p{Lu}\.[ \t]+)?'.self::NAME_WORD.'){0,2})';

    private const RELATIONS = 'husband|wife|spouse|partner|son|daughter|child|kid|father|mother|dad|mom|mum|'
        .'brother|sister|grandmother|grandfather|grandma|grandpa|aunt|uncle|friend|caregiver|'
        .'doctor|provider|nurse|therapist';

    private const MONTHS = 'Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|'
        .'Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?';

    public function redact(string $text): RedactionResult
    {
        $counts = [];

        $text = $this->replace($text, $counts, 'EMAIL', [
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
        ]);

        $text = $this->replace($text, $counts, 'PHONE', [
            // (201) 555-0142, 312-555-0187, 415.555.0163, +1 646 555 0129
            '/(?<![\w-])(?:\+?1[\s.-]?)?(?:\(\d{3}\)[\s.-]?|\d{3}[\s.-])\d{3}[\s.-]\d{4}(?![\w-])/',
        ]);

        $text = $this->replace($text, $counts, 'DATE', [
            // 03/14/1985, 5/8/61, 3-14-1985
            '/\b\d{1,2}[\/.-]\d{1,2}[\/.-](?:\d{4}|\d{2})\b/',
            // 1952-07-09
            '/\b\d{4}-\d{1,2}-\d{1,2}\b/',
            // March 3, 1990 / Mar. 3rd 1990
            '/\b(?:'.self::MONTHS.')\.?[ \t]+\d{1,2}(?:st|nd|rd|th)?,?[ \t]+\d{4}\b/i',
            // 3 March 1990
            '/\b\d{1,2}(?:st|nd|rd|th)?[ \t]+(?:'.self::MONTHS.')\.?,?[ \t]+\d{4}\b/i',
        ]);

        // A date right after a birth-date label is a DOB.
        $text = preg_replace_callback(
            '/(\b(?:DOB|D\.O\.B\.?|date of birth|birth ?date|birthday|born(?: on)?)\b[\s:,-]*(?:is[ \t]+)?)\[DATE\]/i',
            function (array $m) use (&$counts) {
                $counts['DATE']--;
                $counts['DOB'] = ($counts['DOB'] ?? 0) + 1;

                return $m[1].'[DOB]';
            },
            $text,
        );

        $text = $this->replace($text, $counts, 'ID', [
            // Labeled IDs in any format: "member ID is X", "Policy number X", "Member #X", "MRN X".
            // The value must contain a digit so "patient portal" is left alone.
            '/(?<label>\b(?:member|patient|policy|account|subscriber|insurance|group|mrn|chart)\b(?:[ \t]*(?:id|number|no\.?|#))?[ \t]*(?:is[ \t]+|:[ \t]*|#[ \t]*)?)(?=[A-Z0-9-]*\d)[A-Z0-9][A-Z0-9-]{3,}/i',
            // Unlabeled ID-shaped tokens: TD-4829-1173, A55102938.
            '/\b[A-Z]{1,4}-?\d{4,}(?:-\d{2,})*\b/',
            // Long digit runs (also catches unformatted phone numbers).
            '/\b\d{6,}\b/',
        ]);

        return $this->redactNames($text, $counts);
    }

    /**
     * Run each pattern, replacing matches with [$type]. If a pattern has a
     * "label" group, the label is kept and only the value is replaced.
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $patterns
     */
    private function replace(string $text, array &$counts, string $type, array $patterns): string
    {
        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function (array $m) use (&$counts, $type) {
                $counts[$type] = ($counts[$type] ?? 0) + 1;

                return ($m['label'] ?? '')."[{$type}]";
            }, $text);
        }

        return $text;
    }

    /**
     * Names can't be matched by shape alone, so look for them in contexts
     * where a name is expected ("my name is", "this is", "my daughter",
     * sign-offs, titles). Every name found is then removed everywhere in the
     * text, including each part on its own ("Maria" later in the message).
     *
     * @param  array<string, int>  $counts
     */
    private function redactNames(string $text, array &$counts): RedactionResult
    {
        $name = self::FULL_NAME;
        $cues = [
            // Titles: Dr. Lee, Mrs Alvarez
            '/\b(?i:dr|mr|mrs|ms|miss|mx)\.?[ \t]+'.$name.'/u',
            // Self-introductions: my name is X, this is X, I'm X, name: X
            '/\b(?i:my name is|my name\'s|this is|i am|i\'m|i’m|name:|patient:|patient name:)[ \t]+'.$name.'/u',
            // Third person: his/her/their name is X, on behalf of X
            '/\b(?i:his name is|her name is|their name is|on behalf of)[ \t]+'.$name.'/u',
            // Relations: my daughter Sofia, my father, Robert Okafor
            '/\b(?i:my)[ \t]+(?i:'.self::RELATIONS.'),?[ \t]+'.$name.'/u',
            // Sign-offs: "Thanks,\nJames Whitfield"
            '/\b(?i:thanks|thank you|regards|best|sincerely|cheers|best wishes|take care)[,!.]?[ \t]*\R+[ \t]*'.$name.'/u',
            // "- James" at the end of a message
            '/(?:^|\R)[ \t]*[-–—][ \t]*'.$name.'[ \t]*$/u',
        ];

        $found = [];
        foreach ($cues as $cue) {
            preg_match_all($cue, $text, $matches);
            array_push($found, ...$matches['name']);
        }

        // Replace full names first, then each word of each name on its own.
        $parts = [];
        foreach (array_unique($found) as $fullName) {
            $parts[] = $fullName;
            foreach (preg_split('/[ \t]+/', $fullName) as $word) {
                if (mb_strlen(rtrim($word, '.')) > 1) {
                    $parts[] = $word;
                }
            }
        }
        usort($parts, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        foreach (array_unique($parts) as $part) {
            $text = preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote($part, '/').'(?![\p{L}\p{N}])/u',
                '[NAME]',
                $text,
                -1,
                $replaced,
            );
            if ($replaced > 0) {
                $counts['NAME'] = ($counts['NAME'] ?? 0) + $replaced;
            }
        }

        return new RedactionResult($text, array_filter($counts));
    }
}
