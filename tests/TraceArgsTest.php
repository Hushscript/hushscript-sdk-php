<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Psr7\Request;
use Hushscript\Client;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\SdkFrames;
use Hushscript\Tests\Support\Tokens;
use Hushscript\Transcribe\SyncTransport;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * R102: with trace arguments kept (phpunit.xml), no token, claim secret or signed URL is reachable through the
 * arguments of an SDK frame in the trace of an SDK error or of any error in its getPrevious() chain. A hidden
 * argument (SensitiveParameterValue) is what PHP shows in place of the value, so the walk does not open it; the
 * test doubles that play the caller's HTTP client are the caller's, so it does not open them either.
 */
final class TraceArgsTest extends TestCase
{
    private const CLAIM_SECRET = 'cs_walk_0123456789abcdef';

    private const MAX_DEPTH = 8;

    /** Every string reachable from the arguments of the SDK frames of the error chain. */
    private static function reachableFromArgs(\Throwable $error): string
    {
        $out = '';
        $seen = [];
        $frames = 0;
        for ($link = SdkFrames::only($error); $link !== null; $link = $link->getPrevious()) {
            foreach ($link->getTrace() as $frame) {
                $frames++;
                $out .= self::reachable($frame['args'] ?? [], 0, $seen);
            }
        }
        self::assertGreaterThan(0, $frames);

        return $out;
    }

    /** @param array<int, true> $seen */
    private static function reachable(mixed $value, int $depth, array &$seen): string
    {
        if (is_string($value)) {
            return $value."\n";
        }
        if ($depth > self::MAX_DEPTH) {
            return '';
        }
        if (is_array($value)) {
            $out = '';
            foreach ($value as $key => $item) {
                $out .= (is_string($key) ? $key."\n" : '').self::reachable($item, $depth + 1, $seen);
            }

            return $out;
        }
        if (! is_object($value) || $value instanceof \SensitiveParameterValue || isset($seen[spl_object_id($value)]) || self::testDouble($value)) {
            return '';
        }
        $seen[spl_object_id($value)] = true;
        if ($value instanceof \Closure) {
            return self::reachable((new \ReflectionFunction($value))->getClosureUsedVariables(), $depth + 1, $seen);
        }
        $out = $value instanceof \Throwable ? $value->getMessage()."\n" : '';
        for ($class = new \ReflectionObject($value); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName() || ! $property->isInitialized($value)) {
                    continue;
                }
                $out .= self::reachable($property->getValue($value), $depth + 1, $seen);
            }
        }

        return $out;
    }

    /** The test's own doubles stand in for the caller's HTTP client: what they record is the caller's, not the SDK's. */
    private static function testDouble(object $value): bool
    {
        return str_starts_with($value::class, 'Hushscript\\Tests\\') || $value instanceof \PHPUnit\Framework\MockObject\MockObject;
    }

    private static function assertNoSecret(\Throwable $error, string ...$secrets): void
    {
        $text = self::reachableFromArgs($error);
        foreach ([Tokens::TEST, Tokens::ROTATED, self::CLAIM_SECRET, 'X-Amz-Signature', ...$secrets] as $secret) {
            self::assertStringNotContainsString($secret, $text);
        }
    }

    /** @return array<string, mixed> */
    private static function options(ClientInterface $http): array
    {
        return ['base_url' => Rig::BASE, 'http_client' => $http, 'clock' => new FakeClock()];
    }

    /** A connection failure whose message quotes the request, as cURL's do, with the token and a signed URL in it. */
    private static function echoing(): \Closure
    {
        return static function (RequestInterface $request): never {
            throw new NetworkException('cURL error 56: failed for '.$request->getUri().' '.$request->getHeaderLine('Authorization').' '.$request->getBody(), $request);
        };
    }

    public function testAFailedClaimLeavesNoSecretInTraceArguments(): void
    {
        $http = new FakeHttp([self::echoing()]);

        try {
            Client::agentClaim('sg_1', self::CLAIM_SECRET, self::options($http));
            self::fail('expected outcome_unknown');
        } catch (OutcomeUnknownError $error) {
            self::assertNoSecret($error);
        }
        self::assertSame(1, $http->count());
    }

    public function testAClaimAnswerThatCannotBeUsedLeavesNoSecretInTraceArguments(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'user_id' => 5])]);

        try {
            Client::agentClaim('sg_1', self::CLAIM_SECRET, self::options($http));
            self::fail('expected outcome_unknown');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(Tokens::ROTATED, $error->receivedToken());
            self::assertNoSecret($error);
        }
    }

    public function testAFailedRotationLeavesNoSecretInTraceArguments(): void
    {
        foreach ([self::echoing(), FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_1', 'scopes' => ['a'], 'expires_at' => 'soon'])] as $answer) {
            $http = new FakeHttp([$answer]);

            try {
                (new Client(Tokens::TEST, self::options($http)))->agent()->rotateToken();
                self::fail('expected outcome_unknown');
            } catch (OutcomeUnknownError $error) {
                self::assertNoSecret($error);
            }
        }
    }

    public function testAFailedSignupLeavesNoSecretInTraceArguments(): void
    {
        $request = ['email' => 'agent@example.com', 'pack' => '45min', 'accept_terms' => '2026-10-01', 'agent' => ['name' => 'Example agent', 'platform' => 'custom']];
        $unusable = ['signup_id' => 'sg_1', 'claim_secret' => self::CLAIM_SECRET, 'checkout_url' => 'https://checkout.example.com/pay/sg_1?X-Amz-Signature=CHECKOUT', 'amount' => 'free', 'currency' => 'USD', 'expires_at' => 1760001800];
        foreach ([[self::echoing(), OutcomeUnknownError::class], [FakeHttp::json(200, $unusable), ApiError::class]] as [$answer, $class]) {
            $http = new FakeHttp([$answer]);

            try {
                Client::agentSignup($request, self::options($http));
                self::fail('expected '.$class);
            } catch (HushscriptException $error) {
                self::assertSame($class, $error::class);
                self::assertNoSecret($error);
            }
        }
    }

    public function testAFailedTranscribeCreateLeavesNoSecretInTraceArguments(): void
    {
        $harness = new PipelineHarness();
        $body = $harness->createBody();
        $upload = $body['upload'];
        self::assertIsArray($upload);
        self::assertIsArray($upload['part_urls']);
        $upload['part_urls'][] = ['n' => 'two', 'url' => PipelineHarness::url(2)];
        $body['upload'] = $upload;
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));

        try {
            $harness->pipeline->transcribe($harness->path(), []);
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertNoSecret($error, 'SECRET1');
        }
    }

    public function testAFailedPartPutLeavesNoSecretInTraceArguments(): void
    {
        $storage = new class () implements ClientInterface {
            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new NetworkException('cURL error 56: reset for '.$request->getUri(), new Request('PUT', $request->getUri()));
            }
        };
        $harness = new PipelineHarness(transport: new SyncTransport($storage), maxRetries: 0);

        try {
            $harness->pipeline->transcribe($harness->path(), []);
            self::fail('expected network_error');
        } catch (NetworkError $error) {
            // The direct PUTs failed, so the part went through the API, which failed the same way.
            self::assertSame('network_error', $error->errorCode);
            self::assertNoSecret($error, 'SECRET1');
        }
    }
}
