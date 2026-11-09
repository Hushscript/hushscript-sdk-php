<?php

declare(strict_types=1);

namespace Hushscript\Exception;

use Hushscript\Hint;
use Hushscript\Redact;

/**
 * The base of every error the SDK raises (spec 9.1, 9.2). The message, hint and details are
 * redacted before they are stored; nothing here ever holds the token.
 */
class HushscriptException extends \RuntimeException
{
    /** @var string */
    public const DEFAULT_CODE = 'hushscript_error';

    protected const AUTO_HINT = true;

    public readonly string $errorCode;

    public readonly ?int $numericCode;

    public readonly ?int $status;

    public readonly ?string $hint;

    /** @var array<string, mixed> */
    public readonly array $details;

    public readonly ?int $retryAfter;

    /** Set on every error transcribe() raises once its create was sent: passing it back replays the same job (spec 7.3). */
    public ?string $idempotencyKey = null;

    public ?string $resumeToken = null;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        ?string $errorCode = null,
        ?int $numericCode = null,
        ?int $status = null,
        ?string $hint = null,
        array $details = [],
        ?int $retryAfter = null,
        ?string $scope = null,
        ?\Throwable $previous = null,
    ) {
        $this->errorCode = $errorCode ?? static::DEFAULT_CODE;
        $this->numericCode = $numericCode;
        $this->status = $status;
        $this->details = $details;
        $this->retryAfter = $retryAfter;
        if ($hint === null && static::AUTO_HINT === true) {
            $hint = Hint::render($this->errorCode, $details, $retryAfter, $scope);
        }
        $this->hint = $hint === null || $hint === '' ? null : Redact::text($hint);
        parent::__construct(Redact::text($message), $numericCode ?? 0, $previous);
    }
}
