<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Part requests over any PSR-18 client, one at a time: each request is finished before `send`
 * returns. This is the transport for a caller's own client and for a PHP without ext-curl.
 */
final class SyncTransport implements PartTransport
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    #[\Override]
    public function send(#[\SensitiveParameter] RequestInterface $request, float $timeout): PromiseInterface
    {
        try {
            $response = $this->client instanceof GuzzleClientInterface
                ? $this->client->send($request, ['timeout' => $timeout, 'allow_redirects' => false, 'http_errors' => false])
                : $this->client->sendRequest($request);
        } catch (\Throwable $failure) {
            // The promise template types are invariant, so a RejectedPromise<never, PartFailure> does not pass as the
            // declared PromiseInterface<ResponseInterface, Throwable>.
            return Create::rejectionFor(PartFailure::of($failure)); // @phpstan-ignore return.type
        }

        /** @var PromiseInterface<ResponseInterface, \Throwable> $fulfilled */
        $fulfilled = Create::promiseFor($response);

        return $fulfilled;
    }

    #[\Override]
    public function tick(): void
    {
        Utils::queue()->run();
    }
}
