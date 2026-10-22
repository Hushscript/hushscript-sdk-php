<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Concurrent part requests over one cURL multi handle that the upload loop steps itself, so it can
 * look at the clock and the heartbeat between steps (Guzzle's own wait would block until every
 * transfer finished). Needs ext-curl.
 */
final class GuzzleMultiTransport implements PartTransport
{
    private const SELECT_TIMEOUT = 0.05;

    private readonly CurlMultiHandler $multi;

    private readonly Client $client;

    public function __construct(float $connectTimeout)
    {
        $this->multi = new CurlMultiHandler(['select_timeout' => self::SELECT_TIMEOUT]);
        $this->client = new Client([
            'handler' => HandlerStack::create($this->multi),
            'connect_timeout' => $connectTimeout,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
    }

    public static function available(): bool
    {
        return function_exists('curl_multi_init');
    }

    #[\Override]
    public function send(#[\SensitiveParameter] RequestInterface $request, float $timeout): PromiseInterface
    {
        // Guzzle's handlers reject with a Throwable only; its generic type says mixed.
        /** @var PromiseInterface<ResponseInterface, \Throwable> $sent */
        $sent = $this->client->sendAsync($request, ['timeout' => $timeout]);

        return $sent
            ->then(null, static function (#[\SensitiveParameter] \Throwable $failure): never {
                throw PartFailure::of($failure);
            });
    }

    #[\Override]
    public function tick(): void
    {
        $this->multi->tick();
        Utils::queue()->run();
    }
}
