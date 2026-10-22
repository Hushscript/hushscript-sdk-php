<?php

declare(strict_types=1);

namespace Hushscript\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
use Hushscript\Clock;
use Hushscript\Credentials;
use Hushscript\Exception\ErrorMapper;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Log;
use Hushscript\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The hardened transport (spec 7): wraps any PSR-18 client with the retry table, Retry-After,
 * upload-create pacing and per-attempt Authorization. It never follows redirects itself and sends
 * the token to the configured API origin only. Every non-retried failure leaves as an SDK error
 * with a sanitized cause; the underlying exception, which holds the request, never escapes.
 */
final class RetryMiddleware implements ClientInterface
{
    private const MAX_KEY_PEEK_BYTES = 1 << 20;

    // Cannot have written the request: DNS, connect and TLS handshake or certificate failures. Guzzle 8 raises most of
    // them as a ConnectException; the local TLS setup codes (engine, certificate, cipher, CA bundle) it leaves as a plain
    // RequestException whose message still names the cURL error.
    private const NOT_SENT_ERRNOS = [5, 6, 7, 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 83];

    // The values of cURL's CURLOPT_FRESH_CONNECT and CURLOPT_FORBID_REUSE, spelled out so the package needs no ext-curl.
    private const CURLOPT_FRESH_CONNECT = 74;

    private const CURLOPT_FORBID_REUSE = 75;

    // How far an attempt got: what a failure at that point says about whether the request went out.
    private const PREPARING = 0;

    private const SENDING = 1;

    private const READING = 2;

    private const ANSWERED = 3;

    private readonly string $baseScheme;

    private readonly string $baseHost;

    private readonly int $basePort;

    private readonly Pacing $pacing;

    /** @var \Closure(float): float */
    private readonly \Closure $jitter;

    /**
     * @param  (\Closure(float): float)|null  $jitter  maps a ceiling to a delay in [0, ceiling]; default full jitter
     */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly Credentials $credentials,
        string $baseUrl,
        private readonly Clock $clock,
        private readonly Log $log,
        private readonly int $maxRetries,
        ?Pacing $pacing = null,
        ?\Closure $jitter = null,
        private readonly ?WaitTicker $ticker = null,
    ) {
        $parts = parse_url($baseUrl);
        $this->baseScheme = strtolower($parts['scheme'] ?? 'https');
        $this->baseHost = strtolower($parts['host'] ?? '');
        $this->basePort = $parts['port'] ?? self::defaultPort($this->baseScheme);
        $this->pacing = $pacing ?? new Pacing($clock);
        $this->jitter = $jitter ?? static fn (float $ceiling): float => $ceiling * (random_int(0, 1_000_000) / 1_000_000);
    }

    #[\Override]
    public function sendRequest(#[\SensitiveParameter] RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $onBase = strtolower($uri->getScheme()) === $this->baseScheme
            && strtolower($uri->getHost()) === $this->baseHost
            && ($uri->getPort() ?? self::defaultPort($this->baseScheme)) === $this->basePort;
        // Spec 7.4: the class comes from the method and the actual path. A request to any other
        // host matches no API route.
        $path = $onBase ? $uri->getPath() : '';
        $method = strtoupper($request->getMethod());
        $class = Routes::classify($method, $path);
        if (! $onBase && $method === 'PUT') {
            // R56: a PUT to a signed storage URL replays the same bytes for the same part, so it is a part
            // upload (retried, connection reuse allowed), never an unclassified mutation.
            $class = RetryClass::Part;
        }
        $route = Routes::unrecoverable($method, $path);
        if ($class === RetryClass::Create && ! $this->hasIdempotencyKey($request)) {
            // Only a keyed create is a safe replay; without a key it is any other mutation.
            $class = RetryClass::Mutate;
        }
        $paced = $onBase && $method === 'POST' && Routes::normalize($path) === '/v1/uploads';
        $label = $onBase ? $uri->getPath() : 'external';
        $rewindable = $request->getBody()->isSeekable();
        $maxRetries = $rewindable ? $this->maxRetries : 0;
        $attempt = 0;
        $limited = [];
        // Whether any attempt may have reached the server: until one has, a failure provably sent nothing.
        $sentAny = false;

        while (true) {
            // Pacing takes a token per attempt, not per request: a retried create is another create.
            if ($paced) {
                $wait = $this->pacing->reserve();
                if ($wait > 0) {
                    $this->pause($wait);
                }
            }
            $started = $this->clock->now();
            $response = null;
            $summary = '';
            $stage = self::PREPARING;
            try {
                $response = $this->attemptOnce($request, $onBase, $class, $stage);
                $outcome = $this->outcomeOf($response, $class);
            } catch (\Throwable $failure) {
                // Strings only: the exception holds the request, which carries the Authorization header.
                $summary = Exchange::summary($failure, $this->credentials);
                // A body that fails midway is a connection that failed midway: PSR-7 streams report it as a plain
                // RuntimeException, so the stage says what it is, not the class.
                if (! $failure instanceof NetworkExceptionInterface && ! $failure instanceof RequestExceptionInterface && $stage !== self::READING) {
                    // Not a transport failure (R86): a bug or misuse in the caller's client or the SDK, so it is neither
                    // retried nor called a network error.
                    $this->log->attempt($method, $label, null, $attempt + 1, ($this->clock->now() - $started) * 1000, null);

                    throw $this->notTransport($failure, $stage, $class, $route, "{$method} {$label}", $summary);
                }
                $response = null;
                $outcome = new Outcome(failed: true, notSent: $stage === self::SENDING && self::provablyNotSent($failure));
            }
            $sentAny = $sentAny || ! $outcome->notSent;
            $kind = RetryPolicy::createLimitKind($class, $outcome);
            $decision = RetryPolicy::decide(
                $class,
                $attempt,
                $maxRetries,
                $outcome,
                $this->jitter,
                $kind === null ? null : ($limited[$kind] ?? 0),
            );
            if ($kind !== null && $decision->retry) {
                $limited[$kind] = ($limited[$kind] ?? 0) + 1;
            }
            $this->log->attempt($method, $label, $outcome->status, $attempt + 1, ($this->clock->now() - $started) * 1000, $decision->retry ? $decision->delay : null);
            $attempt++;
            if (! $decision->retry) {
                if ($response === null) {
                    throw $this->failureError($class, $route, "{$method} {$label}", $summary, $outcome->notSent, $stage === self::READING, ! $sentAny);
                }

                return $response;
            }
            $this->pause($decision->delay);
        }
    }

    private function pause(float $seconds): void
    {
        $this->ticker === null ? $this->clock->sleep($seconds) : $this->ticker->sleep($this->clock, $seconds);
    }

    /** @param  int  $stage  how far the attempt got (PREPARING to ANSWERED), kept up to date for the caller */
    private function attemptOnce(#[\SensitiveParameter] RequestInterface $request, bool $onBase, RetryClass $class, int &$stage): ResponseInterface
    {
        $outgoing = $request->withHeader('User-Agent', 'hushscript-php/'.Version::SDK.' php/'.PHP_VERSION);
        // Read per attempt, after any sleep: a rotation applies to retries already queued (spec 7.4).
        // A call that needs no token (agent signup and claim) has none to send.
        $token = $this->credentials->token();
        $outgoing = $onBase && $token !== ''
            ? $outgoing->withHeader('Authorization', 'Bearer '.$token)
            : $outgoing->withoutHeader('Authorization');
        $body = $outgoing->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $stage = self::SENDING;
        $response = $this->transmit($outgoing, $class);
        $stage = self::ANSWERED;
        $stream = $response->getBody();
        // Read inside the attempt: a body that fails midway is a failed attempt. The bytes go back
        // into a seekable stream, so a body that cannot be rewound still reaches the caller whole.
        $stage = self::READING;
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $contents = $stream->getContents();
        $stage = self::ANSWERED;

        return $response->withBody(Utils::streamFor($contents));
    }

    /**
     * A request that must not be replayed (R54) never rides a reused connection: libcurl replays a
     * request on its own when a kept-alive connection dies after the body went out, and neither the
     * retry table nor the not-sent test can see it. A Guzzle client is told to open a fresh,
     * non-reusable connection; any other PSR-18 client has to guarantee this itself (README).
     */
    private function transmit(#[\SensitiveParameter] RequestInterface $outgoing, RetryClass $class): ResponseInterface
    {
        $replayable = match ($class) {
            RetryClass::Safe, RetryClass::Part, RetryClass::Complete, RetryClass::Create => true,
            RetryClass::Purchase, RetryClass::Never, RetryClass::Mutate => false,
        };
        if ($replayable || ! $this->inner instanceof GuzzleClientInterface) {
            return $this->inner->sendRequest($outgoing);
        }

        // The request-level 'curl' option replaces the client's own defaults wholesale, so they are carried over
        // here (a CONNECT_TO or RESOLVE rule must survive); ours win on the two reuse keys. Guzzle's stream
        // handler, used without ext-curl, ignores the option and opens a connection per request.
        $options = [
            'allow_redirects' => false,
            'http_errors' => false,
            'curl' => array_replace(self::configuredCurl($this->inner), [self::CURLOPT_FRESH_CONNECT => true, self::CURLOPT_FORBID_REUSE => true]),
        ];

        return $this->inner->send($outgoing, $options);
    }

    /**
     * @return array<mixed>
     */
    private static function configuredCurl(GuzzleClientInterface $client): array
    {
        // getConfig() is on Guzzle's Client but no longer on its interface; a client without it has no defaults to
        // carry over. The option is read once per non-replayable request.
        if (! method_exists($client, 'getConfig')) {
            return [];
        }
        $curl = $client->getConfig('curl');

        return is_array($curl) ? $curl : [];
    }

    private function outcomeOf(#[\SensitiveParameter] ResponseInterface $response, RetryClass $class): Outcome
    {
        $status = $response->getStatusCode();
        $slug = null;
        if ($class === RetryClass::Create && $status === 429) {
            $parsed = json_decode((string) $response->getBody(), true);
            $value = is_array($parsed) ? ($parsed['error'] ?? null) : null;
            $slug = is_string($value) ? $value : null;
        }

        $retryAfter = $response->getHeaderLine('Retry-After');

        return new Outcome($status, ErrorMapper::parseRetryAfter($retryAfter === '' ? null : $retryAfter), $slug);
    }

    private function failureError(RetryClass $class, ?string $route, string $where, string $summary, bool $notSent, bool $unread, bool $nothingSent): HushscriptException
    {
        $cause = new \RuntimeException($summary);
        if ($nothingSent) {
            // No attempt got as far as sending (R100, R103): nothing can have happened on the server.
            return Unsent::mark(new NetworkError("Could not reach the Hushscript API: {$where}; it failed before the request was sent. {$summary}", previous: $cause));
        }
        if ($class === RetryClass::Never && ! $notSent) {
            return new OutcomeUnknownError(
                ($unread ? "The answer to {$where} could not be read. " : "No response arrived for {$where}. ").$summary,
                'outcome_unknown',
                hint: Routes::UNKNOWN_HINTS[$route ?? ''] ?? '',
                previous: $cause,
            );
        }

        return new NetworkError(($unread ? "The answer to {$where} could not be read. " : "Could not reach the Hushscript API: {$where}. ").$summary, previous: $cause);
    }

    /**
     * A failure that is not a transport failure (R86, R100, R101). Before the request went out it is
     * `unexpected_error` saying so. Once it may have gone out, a claim, rotation or signup has an unknown outcome
     * whose hint says what to check, and any other call is `unexpected_error` saying what is known.
     *
     * @param  int  $stage  PREPARING to ANSWERED
     */
    private function notTransport(#[\SensitiveParameter] \Throwable $failure, int $stage, RetryClass $class, ?string $route, string $where, string $summary): HushscriptException
    {
        if ($stage === self::PREPARING) {
            return Exchange::wrapUnexpected($failure, $this->credentials, false);
        }
        if ($class === RetryClass::Never) {
            return new OutcomeUnknownError(
                ($stage === self::SENDING ? "No response arrived for {$where}. " : "The answer to {$where} could not be read. ").$summary,
                'outcome_unknown',
                hint: Routes::UNKNOWN_HINTS[$route ?? ''] ?? '',
                previous: new \RuntimeException($summary),
            );
        }

        return Exchange::wrapUnexpected($failure, $this->credentials, $stage === self::SENDING ? null : true);
    }

    /**
     * Spec 7.4: only connection setup (DNS, connect, TLS handshake, including their timeouts) counts as not
     * sent; any error after the body was written is "sent", whatever its code. Guzzle's built-in handlers raise
     * setup failures as a ConnectException (a ConnectTimeoutException for a timeout before the transfer began, a
     * NetworkTimeoutException for any later one). A custom client's errors are sent unless they are Guzzle's and
     * name a cURL setup error.
     */
    private static function provablyNotSent(#[\SensitiveParameter] \Throwable $failure): bool
    {
        if ($failure instanceof ConnectException) {
            return true;
        }

        return $failure instanceof TransferException
            && preg_match('/^cURL error (\d+):/', $failure->getMessage(), $match) === 1
            && in_array((int) $match[1], self::NOT_SENT_ERRNOS, true);
    }

    private function hasIdempotencyKey(#[\SensitiveParameter] RequestInterface $request): bool
    {
        $body = $request->getBody();
        $size = $body->getSize();
        if (! $body->isSeekable() || $size === null || $size > self::MAX_KEY_PEEK_BYTES) {
            return false;
        }
        $body->rewind();
        $raw = $body->getContents();
        $parsed = json_decode($raw, true);
        $key = is_array($parsed) ? ($parsed['idempotency_key'] ?? null) : null;

        return is_string($key) && $key !== '';
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }
}
