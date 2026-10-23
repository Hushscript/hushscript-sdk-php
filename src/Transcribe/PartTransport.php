<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * How part requests leave the machine. The upload loop owns concurrency, retries and time; the
 * transport only starts a request and lets transfers make progress. A request that fails rejects with
 * a PartFailure (`PartFailure::of()`), which keeps the failure's class and its message with URL queries
 * redacted, but no request, header or body: Guzzle's own exceptions hold the request, and a presigned
 * URL's query is a credential.
 */
interface PartTransport
{
    /**
     * Starts the request without waiting for it. The promise fulfils with the response whatever its
     * status, or rejects with a PartFailure.
     *
     * @return PromiseInterface<ResponseInterface, \Throwable>
     */
    public function send(#[\SensitiveParameter] RequestInterface $request, float $timeout): PromiseInterface;

    /** Lets in-flight transfers progress; waits a short moment for them when none has finished. */
    public function tick(): void;
}
