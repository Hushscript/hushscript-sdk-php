<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use GuzzleHttp\Promise\PromiseInterface;
use Hushscript\Secret;
use Psr\Http\Message\ResponseInterface;

/**
 * One part on its way: the bytes, which way it is going, how many times it has been tried and what
 * its request in flight answered. The upload loop moves it; nothing here talks to the network.
 *
 * @internal
 */
final class PartRun
{
    /** @var PromiseInterface<ResponseInterface, \Throwable>|null */
    public ?PromiseInterface $promise = null;

    /** The answer of the request in flight: a response, a failure, or null while it is still out. */
    public ResponseInterface|PartFailure|null $answer = null;

    public bool $proxied = false;

    public ?Secret $url = null;

    /** Retries of the current way and URL, counted for the retry table. */
    public int $attempt = 0;

    /** Fresh URL pages this part has been given (spec 8.4): at most one. */
    public int $refreshes = 0;

    public ?float $notBefore = null;

    public float $startedAt = 0.0;

    public bool $done = false;

    public function __construct(
        public readonly int $n,
        #[\SensitiveParameter] public readonly string $data,
    ) {
    }

    public function length(): int
    {
        return strlen($this->data);
    }

    /** @return array<string, int|bool> a dump shows the part's size, not its bytes */
    public function __debugInfo(): array
    {
        return ['n' => $this->n, 'length' => $this->length(), 'proxied' => $this->proxied, 'attempt' => $this->attempt, 'done' => $this->done];
    }
}
