<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * Any failure to get a valid AI suggestion. The message is safe to show to
 * staff: it never contains the API key or unredacted patient text.
 */
class AiTriageException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const HTTP_ERROR = 'http_error';

    public const CONNECTION_ERROR = 'connection_error';

    public const REFUSED = 'refused';

    public const TRUNCATED = 'truncated';

    public const NO_TOOL_CALL = 'no_tool_call';

    public const INVALID_OUTPUT = 'invalid_output';

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
