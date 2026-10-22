<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\InsufficientBalanceError;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Exception\PermissionDeniedError;
use Hushscript\Exception\RateLimitError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class RetryMiddlewareTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private static function connectFailure(int $errno = 7): ConnectException
    {
        return new ConnectException('cURL error '.$errno.': connection setup failed', new Request('GET', Rig::BASE.'/v1/account'));
    }

    private static function connectTimeout(): ConnectTimeoutException
    {
        return new ConnectTimeoutException('cURL error 28: connection timed out', new Request('GET', Rig::BASE.'/v1/account'));
    }

    private static function networkTimeout(): NetworkTimeoutException
    {
        return new NetworkTimeoutException('cURL error 28: operation timed out', new Request('GET', Rig::BASE.'/v1/account'));
    }

    private static function networkFailure(int $errno): NetworkException
    {
        return new NetworkException('cURL error '.$errno.': no answer', new Request('GET', Rig::BASE.'/v1/account'));
    }

    private static function lostResponse(): NetworkException
    {
        return new NetworkException('cURL error 56: recv failure', new Request('GET', Rig::BASE.'/v1/account'));
    }

    /** @param array<string, mixed>|string $body */
    private static function reply(int $status, array|string $body = [], string $slug = '', ?string $retryAfter = null): Response
    {
        $headers = $retryAfter === null ? [] : ['Retry-After' => $retryAfter];

        return FakeHttp::json($status, $slug === '' ? $body : ['error' => $slug, 'code' => 1], $headers);
    }

    private static function ok(): Response
    {
        return FakeHttp::json(200, ['ok' => true]);
    }

    public function testASuccessfulGetIsSentOnceWithAuthAndUserAgent(): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http);

        self::assertSame(['ok' => true], $rig->api->request('GET', '/v1/account'));

        self::assertCount(1, $http->requests);
        self::assertSame('Bearer '.Tokens::TEST, $http->requests[0]->getHeaderLine('Authorization'));
        self::assertMatchesRegularExpression('~^hushscript-php/\d+\.\d+\.\d+ php/\d+\.\d+\.\d+~', $http->requests[0]->getHeaderLine('User-Agent'));
        self::assertSame([], $rig->clock->sleeps);
    }

    /** @return iterable<string, array{int}> */
    public static function retriedStatuses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('retriedStatuses')]
    public function testAGetIsRetriedOnTransientStatusesWithFullJitterBackoff(int $status): void
    {
        $http = new FakeHttp([self::reply($status), self::reply($status), self::reply($status), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        self::assertCount(4, $http->requests);
        self::assertSame([0.5, 1.0, 2.0], $rig->clock->sleeps);
    }

    public function testBackoffCeilingGrowsToEightSeconds(): void
    {
        $http = new FakeHttp(array_fill(0, 8, self::reply(503)));
        $rig = new Rig($http, maxRetries: 7);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0, 8.0], $rig->clock->sleeps);
        }
    }

    public function testTheRetryBudgetIsMaxRetriesAndTheLastResponseBecomesTheError(): void
    {
        $http = new FakeHttp(array_fill(0, 4, self::reply(500, slug: 'internal_error')));
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('internal_error', $error->errorCode);
            self::assertSame(500, $error->status);
        }
        self::assertCount(4, $http->requests);
    }

    public function testMaxRetriesZeroSendsOnce(): void
    {
        $http = new FakeHttp([self::reply(503)]);
        $rig = new Rig($http, maxRetries: 0);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function neverRetriedStatuses(): iterable
    {
        foreach ([400, 401, 402, 403, 404, 409, 422] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('neverRetriedStatuses')]
    public function testClientErrorsAreNeverRetried(int $status): void
    {
        $http = new FakeHttp([self::reply($status)]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetryAfterOverridesBackoff(): void
    {
        $http = new FakeHttp([self::reply(429, retryAfter: '7'), self::reply(503, retryAfter: '60'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        self::assertSame([7.0, 60.0], $rig->clock->sleeps);
    }

    /** @return iterable<string, array{string, int}> */
    public static function hugeRetryAfter(): iterable
    {
        yield '10 digits' => ['4000000000', 4_000_000_000];
        yield '400 digits' => [str_repeat('9', 400), 9007199254740991];
    }

    #[DataProvider('hugeRetryAfter')]
    public function testARetryAfterOfAnyLengthIsOverSixtyAndIsNotRetried(string $header, int $reported): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'rate_limited', retryAfter: $header), self::ok()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a RateLimitError');
        } catch (RateLimitError $error) {
            self::assertSame($reported, $error->retryAfter);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testRetryAfterOver60On429RaisesRateLimitErrorAtOnce(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'rate_limited', retryAfter: '61')]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a RateLimitError');
        } catch (RateLimitError $error) {
            self::assertSame(61, $error->retryAfter);
            self::assertSame(429, $error->status);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testRetryAfterOver60OnAnotherStatusRaisesThatStatusClassNotRateLimit(): void
    {
        $http = new FakeHttp([self::reply(503, slug: 'service_unavailable', retryAfter: '120')]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame(120, $error->retryAfter);
            self::assertSame(503, $error->status);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testNetworkErrorsAreRetriedOnGetAndEndAsNetworkError(): void
    {
        $http = new FakeHttp([self::lostResponse(), self::lostResponse(), self::lostResponse(), self::lostResponse()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a NetworkError');
        } catch (NetworkError $error) {
            self::assertSame('network_error', $error->errorCode);
            self::assertNull($error->status);
            self::assertStringContainsString('GET /v1/account', $error->getMessage());
        }
        self::assertCount(4, $http->requests);
    }

    /**
     * Factories, not exceptions: a provided value stays in the test case, and with trace arguments kept every SDK
     * error's trace reaches the test runner and through it every test case's data.
     *
     * @return iterable<string, array{\Closure(): \Throwable}>
     */
    public static function unexpectedFailures(): iterable
    {
        yield 'type error' => [static fn (): \Throwable => new \TypeError('boom '.Tokens::TEST)];
        yield 'runtime error' => [static fn (): \Throwable => new \RuntimeException('boom '.Tokens::TEST)];
        yield 'base PSR client error' => [static fn (): \Throwable => new class ('boom '.Tokens::TEST) extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {}];
    }

    /** @param \Closure(): \Throwable $make */
    #[DataProvider('unexpectedFailures')]
    public function testANonTransportThrowableFromTheClientIsUnexpectedAndNeverRetried(\Closure $make): void
    {
        $failure = $make();
        foreach ([0, 3] as $maxRetries) {
            foreach ([['GET', '/v1/account'], ['POST', '/v1/org/members'], ['POST', '/v1/billing/purchase'], ['POST', '/v1/uploads']] as [$method, $path]) {
                $http = new FakeHttp([$failure, self::ok()]);
                $rig = new Rig($http, maxRetries: $maxRetries);

                try {
                    $rig->api->request($method, $path, body: ['idempotency_key' => 'key_1']);
                    self::fail('expected unexpected_error');
                } catch (HushscriptException $error) {
                    $summary = $failure::class.': boom hsr…[redacted]';
                    self::assertUnexpectedFailure($error, 'The call failed with an error that is not a network failure; the request may have been sent. '.$summary, $summary);
                    self::assertFalse(\Hushscript\Http\Unsent::has($error));
                }
                self::assertSame(1, $http->count());
                self::assertSame([], $rig->clock->sleeps);
            }
        }
    }

    /** @param \Closure(): \Throwable $make */
    #[DataProvider('unexpectedFailures')]
    public function testANonTransportThrowableFromTheClientOnANeverRouteIsAnUnknownOutcome(\Closure $make): void
    {
        $failure = $make();
        foreach (self::neverRoutes() as [$path, $hint]) {
            $http = new FakeHttp([$failure, self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', $path, body: []);
                self::fail('expected outcome_unknown');
            } catch (HushscriptException $error) {
                self::assertUnknownOutcome($error, 'No response arrived for POST '.$path.'. ', $failure::class.': boom hsr…[redacted]', $hint);
            }
            self::assertSame(1, $http->count());
            self::assertSame([], $rig->clock->sleeps);
        }
    }

    private static function assertUnexpectedFailure(HushscriptException $error, string $message, string $summary): void
    {
        self::assertSame(HushscriptException::class, $error::class);
        self::assertSame('unexpected_error', $error->errorCode);
        self::assertNull($error->status);
        self::assertNull($error->numericCode);
        self::assertSame($message, $error->getMessage());
        $cause = $error->getPrevious();
        self::assertNotNull($cause);
        self::assertSame(\RuntimeException::class, $cause::class);
        self::assertSame($summary, $cause->getMessage());
        self::assertNull($cause->getPrevious());
        self::assertStringNotContainsString(Tokens::TEST, print_r(\Hushscript\Tests\Support\SdkFrames::only($error), true));
    }

    private static function assertUnknownOutcome(HushscriptException $error, string $lead, string $summary, ?string $hint): void
    {
        self::assertSame(OutcomeUnknownError::class, $error::class);
        self::assertSame('outcome_unknown', $error->errorCode);
        self::assertNull($error->status);
        self::assertNull($error->numericCode);
        self::assertSame($lead.$summary, $error->getMessage());
        self::assertSame($hint, $error->hint);
        $cause = $error->getPrevious();
        self::assertNotNull($cause);
        self::assertSame(\RuntimeException::class, $cause::class);
        self::assertSame($summary, $cause->getMessage());
        self::assertNull($cause->getPrevious());
        self::assertStringNotContainsString(Tokens::TEST, print_r(\Hushscript\Tests\Support\SdkFrames::only($error), true));
    }

    /** A 200 whose body breaks off after a few bytes: what a connection reset mid-body looks like through PSR-7. */
    private static function brokenBody(): Response
    {
        return new Response(200, [], Utils::streamFor((static function (): \Generator {
            yield 'partial';
            throw new \RuntimeException('connection reset '.Tokens::TEST);
        })()));
    }

    public function testAResponseBodyThatFailsMidReadIsAFailedAttempt(): void
    {
        $http = new FakeHttp([self::brokenBody(), self::ok()]);
        $rig = new Rig($http);

        self::assertSame(['ok' => true], $rig->api->request('GET', '/v1/account'));
        self::assertCount(2, $http->requests);
        self::assertSame([0.5], $rig->clock->sleeps);
    }

    public function testABodyThatFailsMidReadOnEveryAttemptEndsAsANetworkError(): void
    {
        foreach ([0 => 1, 3 => 4] as $maxRetries => $attempts) {
            $http = new FakeHttp(array_map(static fn (): Response => self::brokenBody(), range(1, $attempts)));
            $rig = new Rig($http, maxRetries: $maxRetries);

            try {
                $rig->api->request('GET', '/v1/account');
                self::fail('expected network_error');
            } catch (HushscriptException $error) {
                $summary = 'RuntimeException: connection reset hsr…[redacted]';
                self::assertSame(NetworkError::class, $error::class);
                self::assertSame('network_error', $error->errorCode);
                self::assertSame('The answer to GET /v1/account could not be read. '.$summary, $error->getMessage());
                $cause = $error->getPrevious();
                self::assertNotNull($cause);
                self::assertSame(\RuntimeException::class, $cause::class);
                self::assertSame($summary, $cause->getMessage());
                self::assertStringNotContainsString(Tokens::TEST, print_r(\Hushscript\Tests\Support\SdkFrames::only($error), true));
            }
            self::assertSame($attempts, $http->count());
        }
    }

    #[DataProvider('neverRoutes')]
    public function testABodyThatFailsMidReadOnANeverRouteIsAnUnknownOutcomeSentOnce(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::brokenBody(), self::ok()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('POST', $path, body: []);
            self::fail('expected outcome_unknown');
        } catch (HushscriptException $error) {
            self::assertUnknownOutcome($error, 'The answer to POST '.$path.' could not be read. ', 'RuntimeException: connection reset hsr…[redacted]', $hint);
        }
        self::assertSame(1, $http->count());
        self::assertSame([], $rig->clock->sleeps);
    }

    private function brokenStatus(): \Psr\Http\Message\ResponseInterface
    {
        $broken = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $broken->method('getBody')->willReturn(Utils::streamFor('{}'));
        $broken->method('withBody')->willReturnSelf();
        $broken->method('getStatusCode')->willThrowException(new \RuntimeException('status '.Tokens::TEST));

        return $broken;
    }

    /** A response whose body accessor throws: an answer arrived, so the request was sent. */
    private function brokenBodyAccessor(): \Psr\Http\Message\ResponseInterface
    {
        $broken = $this->createMock(\Psr\Http\Message\ResponseInterface::class);
        $broken->method('getBody')->willThrowException(new \RuntimeException('status '.Tokens::TEST));

        return $broken;
    }

    public function testAResponseMetadataRuntimeFailureIsUnexpectedAndNeverRetried(): void
    {
        foreach ([$this->brokenStatus(...), $this->brokenBodyAccessor(...)] as $broken) {
            $http = new FakeHttp([$broken(), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('GET', '/v1/account');
                self::fail('expected unexpected_error');
            } catch (HushscriptException $error) {
                $summary = 'RuntimeException: status hsr…[redacted]';
                self::assertUnexpectedFailure($error, 'The SDK failed after the request was sent. '.$summary, $summary);
            }
            self::assertSame(1, $http->count());
            self::assertSame([], $rig->clock->sleeps);
        }
    }

    #[DataProvider('neverRoutes')]
    public function testAResponseMetadataFailureOnANeverRouteIsAnUnknownOutcome(string $path, ?string $hint): void
    {
        foreach ([$this->brokenStatus(...), $this->brokenBodyAccessor(...)] as $broken) {
            $http = new FakeHttp([$broken(), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', $path, body: []);
                self::fail('expected outcome_unknown');
            } catch (HushscriptException $error) {
                self::assertUnknownOutcome($error, 'The answer to POST '.$path.' could not be read. ', 'RuntimeException: status hsr…[redacted]', $hint);
            }
            self::assertSame(1, $http->count());
        }
    }

    public function testAFailureBeforeTheRequestIsSentIsUnexpectedAndSaysSoOnEveryRoute(): void
    {
        foreach (['/v1/account', '/v1/agent/accounts/sg_1/claim', '/v1/agent/credentials/rotate', '/v1/agent/accounts'] as $path) {
            $body = $this->createMock(\Psr\Http\Message\StreamInterface::class);
            $body->method('isSeekable')->willReturn(true);
            $body->method('rewind')->willThrowException(new \RuntimeException('rewind '.Tokens::TEST));
            $http = new FakeHttp([self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->transport->sendRequest(new Request('POST', Rig::BASE.$path, [], $body));
                self::fail('expected unexpected_error');
            } catch (HushscriptException $error) {
                $summary = 'RuntimeException: rewind hsr…[redacted]';
                self::assertUnexpectedFailure($error, 'The SDK failed before the request was sent. '.$summary, $summary);
                self::assertTrue(\Hushscript\Http\Unsent::has($error));
            }
            self::assertSame(0, $http->count());
        }
    }

    public function testAnUnsentMarkOutlivesLaterMarks(): void
    {
        $first = \Hushscript\Http\Unsent::mark(new HushscriptException('first', 'unexpected_error'));
        $second = \Hushscript\Http\Unsent::mark(new HushscriptException('second', 'unexpected_error'));

        self::assertTrue(\Hushscript\Http\Unsent::has($first));
        self::assertTrue(\Hushscript\Http\Unsent::has($second));
        self::assertFalse(\Hushscript\Http\Unsent::has(new HushscriptException('never marked', 'unexpected_error')));
    }

    /** @return iterable<string, array{\Closure(): \Throwable}> factories, as for unexpectedFailures() */
    public static function transportFailures(): iterable
    {
        yield 'PSR network' => [static fn (): \Throwable => new ConnectException('boom '.Tokens::TEST, new Request('GET', Rig::BASE.'/v1/account'))];
        yield 'PSR request' => [static fn (): \Throwable => new RequestException('boom '.Tokens::TEST, new Request('GET', Rig::BASE.'/v1/account'))];
    }

    /** @param \Closure(): \Throwable $make */
    #[DataProvider('transportFailures')]
    public function testPsrTransportFailuresKeepRetriesAndTypedRedactedTerminalMessages(\Closure $make): void
    {
        $failure = $make();
        $http = new FakeHttp([$failure, self::ok()]);
        $rig = new Rig($http);
        self::assertSame(['ok' => true], $rig->api->request('GET', '/v1/account'));
        self::assertSame(2, $http->count());
        self::assertSame([0.5], $rig->clock->sleeps);

        foreach ([0 => 1, 3 => 4] as $maxRetries => $attempts) {
            $http = new FakeHttp(array_fill(0, $attempts, $failure));
            $rig = new Rig($http, maxRetries: $maxRetries);
            try {
                $rig->api->request('GET', '/v1/account');
                self::fail('expected network_error');
            } catch (HushscriptException $error) {
                $summary = $failure::class.': boom hsr…[redacted]';
                self::assertSame(NetworkError::class, $error::class);
                self::assertSame('network_error', $error->errorCode);
                self::assertNull($error->status);
                self::assertNull($error->numericCode);
                // A ConnectException proves the connection was never made; a bare RequestException proves nothing (R100).
                $lead = $failure instanceof ConnectException ? 'Could not reach the Hushscript API: GET /v1/account; it failed before the request was sent. ' : 'Could not reach the Hushscript API: GET /v1/account. ';
                self::assertSame($lead.$summary, $error->getMessage());
                self::assertSame($failure instanceof ConnectException, \Hushscript\Http\Unsent::has($error));
                $cause = $error->getPrevious();
                self::assertNotNull($cause);
                self::assertSame(\RuntimeException::class, $cause::class);
                self::assertSame($summary, $cause->getMessage());
                self::assertNull($cause->getPrevious());
                self::assertStringNotContainsString(Tokens::TEST, print_r(\Hushscript\Tests\Support\SdkFrames::only($error), true));
            }
            self::assertSame($attempts, $http->count());
        }
    }

    // Purchase (spec 7.2): network error, 502, 503; at most one retry, same body.

    public function testPurchaseRetriesOnce502WithTheSameKey(): void
    {
        $http = new FakeHttp([self::reply(502, 'bad gateway'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);

        self::assertCount(2, $http->requests);
        self::assertSame((string) $http->requests[0]->getBody(), (string) $http->requests[1]->getBody());
        self::assertStringContainsString(self::KEY, (string) $http->requests[1]->getBody());
    }

    public function testPurchaseIsCappedAtOneRetryWhateverMaxRetriesSays(): void
    {
        $http = new FakeHttp([self::reply(503), self::reply(503), self::ok()]);
        $rig = new Rig($http, maxRetries: 10);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testPurchaseIsNeverRetriedOnA4xxOr500(): void
    {
        foreach ([[403, 'pat_purchase_requires_app'], [500, 'internal_error'], [429, 'rate_limited'], [504, 'timeout']] as [$status, $slug]) {
            $http = new FakeHttp([self::reply($status, slug: $slug), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);
                self::fail('expected an error');
            } catch (HushscriptException $error) {
                self::assertSame($slug, $error->errorCode);
            }
            self::assertCount(1, $http->requests, "status {$status}");
        }
    }

    public function testPurchaseRetriesOnceOnANetworkError(): void
    {
        $http = new FakeHttp([self::lostResponse(), self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(NetworkError::class);
        try {
            $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '300min', 'idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testSetupSessionFollowsThePurchaseRule(): void
    {
        $http = new FakeHttp([self::reply(502), self::reply(502), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', '/v1/billing/setup-session');
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    public function testPurchaseIsClassifiedFromTheRealPathWhateverTheQueryOrSlash(): void
    {
        $http = new FakeHttp([self::reply(502), self::reply(502), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', '/v1/billing/purchase/', ['x' => '1'], ['pack' => '45min']);
        } finally {
            self::assertCount(2, $http->requests);
        }
    }

    // Claim, rotate and signup: never replayed; a lost response is an unknown outcome.

    /** @return iterable<string, array{string, string|null}> */
    public static function neverRoutes(): iterable
    {
        yield 'claim' => ['/v1/agent/accounts/sg_1/claim', 'The claim may have succeeded. Retrying will fail with agent_claim_invalid. If you have no token, contact support with the signup id.'];
        yield 'rotate' => ['/v1/agent/credentials/rotate', 'The old token may already be revoked. Try a request with it; if it fails, the new token was lost and the account needs support.'];
        yield 'signup' => ['/v1/agent/accounts', null];
    }

    #[DataProvider('neverRoutes')]
    public function testALostResponseIsAnOutcomeUnknownErrorWithItsHintAndIsSentOnce(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        try {
            $rig->api->request('POST', $path, body: []);
            self::fail('expected an OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertNull($error->status);
            self::assertNull($error->numericCode);
            self::assertSame('No response arrived for POST '.$path.'. GuzzleHttp\\Exception\\NetworkException: cURL error 56: recv failure', $error->getMessage());
            $cause = $error->getPrevious();
            self::assertNotNull($cause);
            self::assertSame(\RuntimeException::class, $cause::class);
            self::assertSame('GuzzleHttp\\Exception\\NetworkException: cURL error 56: recv failure', $cause->getMessage());
            self::assertNull($cause->getPrevious());
            self::assertSame($hint, $error->hint);
            self::assertSame('outcome_unknown', $error->errorCode);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    #[DataProvider('neverRoutes')]
    public function testATimeoutAfterTheBodyWasWrittenIsAnUnknownOutcome(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::networkTimeout(), self::ok()]);
        $rig = new Rig($http);
        try {
            $rig->api->request('POST', $path, body: []);
            self::fail('expected OutcomeUnknownError');
        } catch (HushscriptException $error) {
            self::assertSame(OutcomeUnknownError::class, $error::class);
            self::assertSame('outcome_unknown', $error->errorCode);
            self::assertNull($error->status);
            self::assertSame($hint, $error->hint);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    #[DataProvider('neverRoutes')]
    public function testAFailureBeforeTheRequestWasSentIsAPlainNetworkErrorAndStillNotReplayed(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::connectFailure(7), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(NetworkError::class);
        try {
            $rig->api->request('POST', $path, body: []);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    #[DataProvider('neverRoutes')]
    public function testAServerErrorOnANeverRouteIsNotRetried(string $path, ?string $hint): void
    {
        $http = new FakeHttp([self::reply(503), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(ApiError::class);
        try {
            $rig->api->request('POST', $path, body: []);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    // Mutations that are not listed: retried only when the request provably never left.

    #[DataProvider('mutationRoutes')]
    public function testAnUnlistedMutationIsRetriedOnlyWhenNotSent(string $method, string $path): void
    {
        $http = new FakeHttp([self::connectFailure(6), self::connectFailure(7), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request($method, $path, body: ['x' => 1]);

        self::assertCount(3, $http->requests);
    }

    #[DataProvider('mutationRoutes')]
    public function testAnUnlistedMutationIsNotReplayedOnceSent(string $method, string $path): void
    {
        foreach ([self::lostResponse()] as $failure) {
            $http = new FakeHttp([$failure, self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request($method, $path, body: ['x' => 1]);
                self::fail('expected a NetworkError');
            } catch (NetworkError) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    #[DataProvider('mutationRoutes')]
    public function testAnUnlistedMutationIsNotRetriedOnAnyStatus(string $method, string $path): void
    {
        foreach ([500, 502, 503, 429] as $status) {
            $http = new FakeHttp([self::reply($status), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request($method, $path, body: ['x' => 1]);
                self::fail('expected an ApiError');
            } catch (ApiError) {
                self::assertCount(1, $http->requests, "status {$status}");
            }
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function mutationRoutes(): iterable
    {
        yield 'org member add' => ['POST', '/v1/org/members'];
        yield 'org delete' => ['DELETE', '/v1/org/members/m1'];
        yield 'default card' => ['PUT', '/v1/billing/cards/default'];
        yield 'patch' => ['PATCH', '/v1/org/settings'];
    }

    public function testNotSentWithoutAConnectErrnoMeansSent(): void
    {
        // A timeout once the transfer began, an empty reply (cURL error 52), a lost answer and a failure that names no setup error: all after the write.
        foreach ([self::networkTimeout(), self::networkFailure(52), self::lostResponse(), new RequestException('cURL error 61: bad content encoding', new Request('POST', Rig::BASE.'/v1/org/members'))] as $failure) {
            $http = new FakeHttp([$failure, self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', '/v1/org/members', body: ['x' => 1]);
                self::fail('expected a NetworkError');
            } catch (NetworkError) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    /** @return iterable<string, array{int, class-string<TransferException>}> the class Guzzle 8 raises for each setup error */
    public static function setupErrnos(): iterable
    {
        yield 'proxy dns' => [5, ConnectException::class];
        yield 'dns' => [6, ConnectException::class];
        yield 'connect' => [7, ConnectException::class];
        yield 'tls' => [35, ConnectException::class];
        yield 'peer cert' => [51, ConnectException::class];
        yield 'cert problem' => [58, RequestException::class];
        yield 'cipher' => [59, RequestException::class];
        yield 'cacert' => [60, ConnectException::class];
        yield 'cacert file' => [77, RequestException::class];
        yield 'issuer' => [83, ConnectException::class];
    }

    /** @param class-string<TransferException> $class */
    #[DataProvider('setupErrnos')]
    public function testConnectionSetupErrnosCountAsNotSent(int $errno, string $class): void
    {
        $failure = new $class('cURL error '.$errno.': setup', new Request('POST', Rig::BASE.'/v1/org/members'));
        $http = new FakeHttp([$failure, self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/org/members', body: ['x' => 1]);

        self::assertCount(2, $http->requests);
    }

    public function testATimeoutBeforeTheTransferBeganCountsAsNotSent(): void
    {
        $http = new FakeHttp([self::connectTimeout(), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/org/members', body: ['x' => 1]);

        self::assertCount(2, $http->requests);
    }

    // A response body that cannot be rewound still reaches the caller

    public function testASuccessBodyOnANonSeekableStreamReachesTheCaller(): void
    {
        $http = new FakeHttp([new Response(200, [], new NoSeekStream(Utils::streamFor('{"ok":true}')))]);
        $rig = new Rig($http);

        self::assertSame(['ok' => true], $rig->api->request('GET', '/v1/account'));
    }

    public function testAnErrorBodyOnANonSeekableStreamKeepsItsCodeAndHint(): void
    {
        $body = json_encode(['error' => 'insufficient_balance', 'code' => 3001, 'needed_seconds' => 1250, 'balance_seconds' => 600], JSON_THROW_ON_ERROR);
        $http = new FakeHttp([new Response(402, [], new NoSeekStream(Utils::streamFor($body)))]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an error');
        } catch (HushscriptException $error) {
            self::assertSame('insufficient_balance', $error->errorCode);
            self::assertSame(3001, $error->numericCode);
            self::assertSame(InsufficientBalanceError::class, $error::class);
            self::assertSame(402, $error->status);
            self::assertSame('Not enough minutes: 21 needed, 10 available. Buy a pack with the SDK\'s buy method or in the app.', $error->hint);
        }
    }

    public function testACreate429SlugIsReadFromANonSeekableStream(): void
    {
        $limited = new Response(429, [], new NoSeekStream(Utils::streamFor('{"error":"too_many_active_jobs","code":1}')));
        $http = new FakeHttp([$limited, self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['size_bytes' => 1, 'idempotency_key' => self::KEY]);

        self::assertSame([30.0], $rig->clock->sleeps, 'the active-jobs schedule waits 30 s; the rate-limit schedule would wait 15 s');
    }

    // Upload create (spec 7.2, 7.5)

    public function testAKeyedUploadCreateIsRetriedLikeAnyCreate(): void
    {
        $http = new FakeHttp([self::reply(500), self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['size_bytes' => 1, 'idempotency_key' => self::KEY]);

        self::assertCount(3, $http->requests);
    }

    public function testAnUnkeyedUploadCreateIsAnyOtherMutation(): void
    {
        foreach ([['size_bytes' => 1], ['size_bytes' => 1, 'idempotency_key' => ''], ['size_bytes' => 1, 'idempotency_key' => 5]] as $body) {
            $http = new FakeHttp([self::reply(500), self::ok()]);
            $rig = new Rig($http);

            try {
                $rig->api->request('POST', '/v1/uploads', body: $body);
                self::fail('expected an ApiError');
            } catch (ApiError) {
                self::assertCount(1, $http->requests);
            }
            $lost = new FakeHttp([self::lostResponse(), self::ok()]);
            try {
                (new Rig($lost))->api->request('POST', '/v1/uploads', body: $body);
                self::fail('expected a NetworkError');
            } catch (NetworkError) {
                self::assertCount(1, $lost->requests);
            }
        }
    }

    public function testACreateWithRateLimitedAndNoRetryAfterBacksOff15Then30Then60(): void
    {
        $http = new FakeHttp(array_fill(0, 4, self::reply(429, slug: 'rate_limited')));
        $rig = new Rig($http);

        $this->expectException(RateLimitError::class);
        try {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(4, $http->requests);
            self::assertSame([15.0, 30.0, 60.0], $rig->clock->sleeps);
        }
    }

    public function testACreateRateLimitWithRetryAfterWaitsThatLong(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'rate_limited', retryAfter: '5'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([5.0], $rig->clock->sleeps);
    }

    public function testTheRateLimitScheduleCountsOnlyRateLimitResponses(): void
    {
        // Two 500s used two retries; the first 429 still gets the first entry (15 s), not the third.
        $http = new FakeHttp([self::reply(500), self::reply(500), self::reply(429, slug: 'rate_limited'), self::ok()]);
        $rig = new Rig($http, maxRetries: 10);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([0.5, 1.0, 15.0], $rig->clock->sleeps);
    }

    public function testTooManyActiveJobsWaits30SecondsForTenTriesThenRaises(): void
    {
        $http = new FakeHttp(array_fill(0, 12, self::reply(429, slug: 'too_many_active_jobs')));
        $rig = new Rig($http, maxRetries: 3);

        try {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
            self::fail('expected a RateLimitError');
        } catch (RateLimitError $error) {
            self::assertSame('too_many_active_jobs', $error->errorCode);
        }
        self::assertCount(10, $http->requests);
        self::assertSame(array_fill(0, 9, 30.0), $rig->clock->sleeps);
    }

    public function testTooManyActiveJobsIsDisabledByMaxRetriesZeroOnly(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'too_many_active_jobs'), self::ok()]);
        $rig = new Rig($http, maxRetries: 0);

        $this->expectException(RateLimitError::class);
        try {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testTooManyActiveJobsSucceedsAfterAWait(): void
    {
        $http = new FakeHttp([self::reply(429, slug: 'too_many_active_jobs'), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([30.0], $rig->clock->sleeps);
    }

    public function testCreatesArePacedFourPerMinutePerAttempt(): void
    {
        $http = new FakeHttp([self::ok(), self::ok(), self::ok(), self::ok(), self::ok()]);
        $rig = new Rig($http);

        for ($i = 0; $i < 4; $i++) {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        }
        self::assertSame([], $rig->clock->sleeps);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame([60.0], $rig->clock->sleeps);
    }

    public function testARetriedCreateTakesAnotherPacingToken(): void
    {
        $http = new FakeHttp([self::reply(500), self::ok(), self::reply(500), self::ok(), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]); // tokens 1, 2
        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]); // tokens 3, 4
        self::assertSame([0.5, 0.5], $rig->clock->sleeps);

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]); // token 5 waits

        self::assertCount(3, $rig->clock->slept());
        self::assertEqualsWithDelta(59.0, $rig->clock->slept()[2], 0.001);
    }

    public function testUnkeyedCreatesAndOtherPathsAreHandledByPathNotByKey(): void
    {
        $http = new FakeHttp(array_fill(0, 7, self::ok()));
        $rig = new Rig($http);

        for ($i = 0; $i < 5; $i++) {
            $rig->api->request('POST', '/v1/uploads', body: ['size_bytes' => 1]);
        }
        self::assertCount(1, $rig->clock->sleeps, 'an unkeyed create is still a create and still paced');

        $before = count($rig->clock->slept());
        $rig->api->request('GET', '/v1/uploads');
        $rig->api->request('POST', '/v1/uploads/j1/heartbeat', body: []);
        self::assertCount($before, $rig->clock->slept(), 'only POST /v1/uploads is paced');
    }

    // Complete, parts, heartbeat

    public function testCompleteAndHeartbeatAndPartsAreRetriedOnServerErrors(): void
    {
        foreach ([['POST', '/v1/uploads/j1/complete', 500], ['POST', '/v1/uploads/j1/heartbeat', 500], ['POST', '/v1/uploads/j1/heartbeat', 503], ['PUT', '/v1/uploads/j1/parts/2', 500], ['POST', '/v1/uploads/j1/complete', 429]] as [$method, $path, $status]) {
            $http = new FakeHttp([self::reply($status), self::ok()]);
            $rig = new Rig($http);

            $rig->api->request($method, $path, body: []);

            self::assertCount(2, $http->requests, "{$method} {$path}");
        }
    }

    public function testHeartbeatIsNotRetriedOn501(): void
    {
        $http = new FakeHttp([self::reply(501), self::ok()]);
        $rig = new Rig($http);
        try {
            $rig->api->request('POST', '/v1/uploads/j1/heartbeat', body: []);
            self::fail('expected ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame('http_501', $error->errorCode);
            self::assertSame(501, $error->status);
        }
        self::assertCount(1, $http->requests);
        self::assertSame([], $rig->clock->sleeps);
    }

    public function testCompleteRetriesAnyFiveHundred(): void
    {
        $http = new FakeHttp([self::reply(501), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/uploads/j1/complete', body: []);

        self::assertCount(2, $http->requests);
    }

    // Authorization

    public function testAuthorizationIsReadPerAttemptAfterTheSleep(): void
    {
        $http = new FakeHttp([self::reply(503), self::ok()]);
        $rig = new Rig($http);
        $rig->clock->onSleep = function () use ($rig): void {
            $rig->credentials->replace(Tokens::ROTATED);
        };

        $rig->api->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::ROTATED], $http->authorizations);
    }

    public function testAuthorizationIsReadAfterThePacingSleepToo(): void
    {
        $http = new FakeHttp(array_fill(0, 5, self::ok()));
        $rig = new Rig($http);
        for ($i = 0; $i < 4; $i++) {
            $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);
        }
        $rig->clock->onSleep = function () use ($rig): void {
            $rig->credentials->replace(Tokens::ROTATED);
        };

        $rig->api->request('POST', '/v1/uploads', body: ['idempotency_key' => self::KEY]);

        self::assertSame('Bearer '.Tokens::ROTATED, $http->authorizations[4]);
    }

    /** @return iterable<string, array{string}> */
    public static function foreignOrigins(): iterable
    {
        yield 'other host' => ['http://evil.example:4010/v1/account'];
        yield 'other port' => ['http://127.0.0.1:4011/v1/account'];
        yield 'other scheme' => ['https://127.0.0.1:4010/v1/account'];
        yield 'userinfo trick' => ['http://127.0.0.1.evil.example/v1/account'];
    }

    #[DataProvider('foreignOrigins')]
    public function testAuthorizationGoesOnlyToTheConfiguredOrigin(string $url): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http);

        $rig->transport->sendRequest(new Request('GET', $url, ['Authorization' => 'Bearer caller-supplied']));

        self::assertSame([''], $http->authorizations);
    }

    public function testAForeignRequestMatchesNoApiRoute(): void
    {
        // A signed URL on another host that looks like the claim path is still a plain mutation there.
        $http = new FakeHttp([self::lostResponse()]);
        $rig = new Rig($http);

        $this->expectException(NetworkError::class);
        $rig->transport->sendRequest(new Request('POST', 'http://other.example/v1/agent/credentials/rotate'));
    }

    public function testARedirectIsReturnedNotFollowedAndBecomesAnApiError(): void
    {
        $http = new FakeHttp([new Response(302, ['Location' => 'https://evil.example/'])]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an ApiError');
        } catch (ApiError $error) {
            self::assertSame('http_302', $error->errorCode);
            self::assertSame(302, $error->status);
        }
        self::assertCount(1, $http->requests);
    }

    public function testAnAuthenticationErrorIsNotRetried(): void
    {
        $http = new FakeHttp([self::reply(401, slug: 'unauthorized'), self::ok()]);
        $rig = new Rig($http);

        $this->expectException(AuthenticationError::class);
        try {
            $rig->api->request('GET', '/v1/account');
        } finally {
            self::assertCount(1, $http->requests);
        }
    }

    public function testPermissionErrorsCarryTheScopeHint(): void
    {
        $http = new FakeHttp([self::reply(403, slug: 'pat_scope_missing')]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a PermissionDeniedError');
        } catch (PermissionDeniedError $error) {
            self::assertStringContainsString('"account:read" permission', (string) $error->hint);
        }
    }

    // Bodies

    public function testABodyThatCannotBeRewoundIsNeverRetried(): void
    {
        $http = new FakeHttp([self::reply(503), self::ok()]);
        $rig = new Rig($http);
        $stream = Utils::streamFor((static function (): \Generator {
            yield '{"x":1}';
        })());
        $request = (new Request('POST', Rig::BASE.'/v1/billing/purchase'))->withBody($stream);

        $response = $rig->transport->sendRequest($request);

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $http->requests);
    }

    public function testEachAttemptSendsTheWholeBody(): void
    {
        $bodies = [];
        $capture = static function (RequestInterface $request) use (&$bodies): Response {
            $bodies[] = $request->getBody()->getContents();

            return count($bodies) === 1 ? self::reply(503) : self::ok();
        };
        $http = new FakeHttp([$capture, $capture]);
        $rig = new Rig($http);

        $rig->api->request('POST', '/v1/billing/purchase', body: ['pack' => '45min', 'idempotency_key' => self::KEY]);

        self::assertSame($bodies[0], $bodies[1]);
        self::assertNotSame('', $bodies[1]);
    }

    // Logging

    public function testOneLogLinePerAttemptWithTheRetryDelay(): void
    {
        $http = new FakeHttp([self::reply(503), self::lostResponse(), self::ok()]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        self::assertSame(
            [
                'hushscript GET /v1/account 503 attempt=1 0ms retry_in=0.5s',
                'hushscript GET /v1/account error attempt=2 0ms retry_in=1s',
                'hushscript GET /v1/account 200 attempt=3 0ms',
            ],
            $rig->logLines,
        );
    }

    public function testAPutToASignedStorageUrlIsAPartUploadAndIsRetried(): void
    {
        $http = new FakeHttp([self::reply(503), self::reply(200)]);
        $rig = new Rig($http);

        $response = $rig->transport->sendRequest(new Request('PUT', 'https://r2.example/bucket/obj?X-Amz-Signature=secret', [], 'bytes'));

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(2, $http->requests);
        self::assertSame(['', ''], $http->authorizations, 'storage never sees the token');
    }

    public function testAPutToASignedStorageUrlIsRetriedAfterALostConnection(): void
    {
        $http = new FakeHttp([self::lostResponse(), self::reply(200)]);
        $rig = new Rig($http);

        $rig->transport->sendRequest(new Request('PUT', 'https://r2.example/bucket/obj?X-Amz-Signature=secret', [], 'bytes'));

        self::assertCount(2, $http->requests);
    }

    public function testAnotherMethodOnAForeignHostIsStillAnUnclassifiedMutation(): void
    {
        $http = new FakeHttp([self::reply(503)]);
        $rig = new Rig($http);

        $response = $rig->transport->sendRequest(new Request('POST', 'https://r2.example/bucket/obj', [], 'bytes'));

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $http->requests);
    }

    public function testForeignRequestsAreLoggedWithoutTheirUrl(): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http);

        $rig->transport->sendRequest(new Request('PUT', 'https://r2.example/bucket/obj?X-Amz-Signature=secret'));

        self::assertSame(['hushscript PUT external 200 attempt=1 0ms'], $rig->logLines);
    }

    public function testNothingIsLoggedWhenLoggingIsOff(): void
    {
        $http = new FakeHttp([self::ok()]);
        $rig = new Rig($http, logLevel: 'off');

        $rig->api->request('GET', '/v1/account');

        self::assertSame([], $rig->logLines);
    }

    public function testTheDefaultJitterSamplesTheWholeRangeInsideTheCeiling(): void
    {
        $samples = [];
        for ($index = 0; $index < 128; $index++) {
            $clock = new FakeClock();
            $http = new FakeHttp([self::reply(503), self::ok()]);
            $credentials = new \Hushscript\Credentials(Tokens::TEST);
            $transport = new \Hushscript\Http\RetryMiddleware($http, $credentials, Rig::BASE, $clock, new \Hushscript\Log(), 3);
            $transport->sendRequest(new Request('GET', Rig::BASE.'/v1/account'));
            self::assertCount(1, $clock->sleeps);
            self::assertCount(2, $http->requests);
            $samples[] = $clock->sleeps[0];
        }
        self::assertNotEmpty($samples);
        self::assertGreaterThanOrEqual(0.0, min($samples));
        self::assertLessThanOrEqual(0.5, max($samples));
        self::assertLessThan(0.125, min($samples));
        self::assertGreaterThan(0.375, max($samples));
        self::assertGreaterThan(1, count(array_unique($samples)));
    }

    public function testANonTransportFailureIsLoggedWithItsAttemptAndDuration(): void
    {
        $clock = new FakeClock();
        $clock->time = 1000.0;
        $http = new FakeHttp([self::reply(503), static function () use ($clock): Response {
            $clock->time += 1.5;

            throw new \LogicException('a bug in the client');
        }]);
        $rig = new Rig($http, $clock);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected unexpected_error');
        } catch (HushscriptException $error) {
            self::assertSame('unexpected_error', $error->errorCode);
        }

        self::assertSame(
            [
                'hushscript GET /v1/account 503 attempt=1 0ms retry_in=0.5s',
                'hushscript GET /v1/account error attempt=2 1500ms',
            ],
            $rig->logLines,
        );
    }

    /** A JSON body of exactly `$size` bytes carrying an idempotency key. */
    private static function keyedBody(int $size): string
    {
        $empty = json_encode(['idempotency_key' => self::KEY, 'pad' => ''], JSON_THROW_ON_ERROR);

        return json_encode(['idempotency_key' => self::KEY, 'pad' => str_repeat('x', $size - strlen($empty))], JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string, array{\Closure(): \Psr\Http\Message\StreamInterface, int}> a create body and how many attempts a 503 gets */
    public static function keyPeekBodies(): iterable
    {
        yield 'exactly 1 MiB' => [static fn (): \Psr\Http\Message\StreamInterface => Utils::streamFor(self::keyedBody(1 << 20)), 2];
        yield 'one byte over 1 MiB' => [static fn (): \Psr\Http\Message\StreamInterface => Utils::streamFor(self::keyedBody((1 << 20) + 1)), 1];
        yield 'already read to the end' => [static function (): \Psr\Http\Message\StreamInterface {
            $stream = Utils::streamFor(self::keyedBody(100));
            $stream->getContents();

            return $stream;
        }, 2];
        yield 'seekable with an unknown size' => [static fn (): \Psr\Http\Message\StreamInterface => new class (Utils::streamFor(self::keyedBody(100))) implements \Psr\Http\Message\StreamInterface {
            use \GuzzleHttp\Psr7\StreamDecoratorTrait;

            public function __construct(private readonly \Psr\Http\Message\StreamInterface $stream) // @phpstan-ignore property.onlyWritten (StreamDecoratorTrait reads it)
            {
            }

            public function getSize(): ?int
            {
                return null;
            }
        }, 1];
    }

    /** @param \Closure(): \Psr\Http\Message\StreamInterface $body */
    #[DataProvider('keyPeekBodies')]
    public function testACreateIsKeyedOnlyWhenItsWholeBodyCanBeReadUpTo1MiB(\Closure $body, int $attempts): void
    {
        $http = new FakeHttp([self::reply(503), self::ok()]);
        $rig = new Rig($http);

        $response = $rig->transport->sendRequest(new Request('POST', Rig::BASE.'/v1/uploads', ['Content-Type' => 'application/json'], $body()));

        self::assertSame($attempts, $http->count());
        self::assertSame($attempts === 2 ? 200 : 503, $response->getStatusCode());
    }

    /** @return iterable<string, array{string, string, string}> base URL, request URL, the Authorization sent */
    public static function defaultPorts(): iterable
    {
        yield 'https base with :443, request without a port' => ['https://api.example:443', 'https://api.example/v1/account', 'Bearer '.Tokens::TEST];
        yield 'http base with :80, request without a port' => ['http://api.example:80', 'http://api.example/v1/account', 'Bearer '.Tokens::TEST];
        yield 'https base, request on :80' => ['https://api.example', 'https://api.example:80/v1/account', ''];
        yield 'http base, request on :443' => ['http://api.example', 'http://api.example:443/v1/account', ''];
    }

    #[DataProvider('defaultPorts')]
    public function testAMissingPortIsTheSchemesDefault(string $base, string $url, string $authorization): void
    {
        $http = new FakeHttp([self::ok()]);
        $transport = new \Hushscript\Http\RetryMiddleware($http, new \Hushscript\Credentials(Tokens::TEST), $base, new FakeClock(), new \Hushscript\Log(), 0);

        $transport->sendRequest(new Request('GET', $url));

        self::assertSame([$authorization], $http->authorizations);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function failuresThatProveNothing(): iterable
    {
        $request = new Request('POST', Rig::BASE.'/v1/agent/accounts/sg_1/claim');
        yield 'a foreign exception with no cURL error' => [new class ('reset', $request) extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function __construct(string $message, private readonly \Psr\Http\Message\RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                return $this->request;
            }
        }];
        yield 'a foreign exception that only names a setup error' => [new class ('cURL error 7: refused', $request) extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function __construct(string $message, private readonly \Psr\Http\Message\RequestInterface $request)
            {
                parent::__construct($message);
            }

            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                return $this->request;
            }
        }];
        yield 'a Guzzle failure that names no setup error' => [new RequestException('cURL error 56: recv failure', $request)];
    }

    #[DataProvider('failuresThatProveNothing')]
    public function testAFailureThatDoesNotProveNonDeliveryCountsAsSent(\Throwable $failure): void
    {
        $http = new FakeHttp([$failure]);
        $rig = new Rig($http);

        try {
            $rig->api->request('POST', '/v1/agent/accounts/sg_1/claim', body: ['claim_secret' => 'x']);
            self::fail('expected an OutcomeUnknownError');
        } catch (HushscriptException $error) {
            self::assertSame(OutcomeUnknownError::class, $error::class);
            self::assertFalse(\Hushscript\Http\Unsent::has($error));
        }
        self::assertSame(1, $http->count());
    }
}
