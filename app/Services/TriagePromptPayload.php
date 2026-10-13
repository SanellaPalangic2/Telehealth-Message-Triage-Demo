<?php

namespace App\Services;

final readonly class TriagePromptPayload
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function __construct(
        public RedactionResult $redaction,
        public string $system,
        public array $messages,
    ) {}

    /**
     * Everything that will leave the server, as one string. Used by tests to
     * prove no personal data is present.
     */
    public function outboundText(): string
    {
        return $this->system."\n".json_encode($this->messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
