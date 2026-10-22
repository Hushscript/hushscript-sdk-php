<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NotFoundError;
use Hushscript\Exception\UploadError;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeIdleClock;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Transcribe\Heartbeat;
use Hushscript\Transcribe\PartTransport;
use Hushscript\Transcribe\SyncTransport;
use Hushscript\Transcript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** How the parts of one job go: the heartbeat while waiting, the part-urls failures, the 403 refresh (spec 8.4, 8.5). */
final class UploadRulesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $options
     */
    private static function go(PipelineHarness $harness, array $options = []): Transcript
    {
        return $harness->pipeline->transcribe($harness->path(), $options);
    }

    private static function failure(PipelineHarness $harness, string $class = HushscriptException::class): HushscriptException
    {
        try {
            self::go($harness);
        } catch (HushscriptException $error) {
            self::assertInstanceOf($class, $error); // @phpstan-ignore argument.type

            return $error;
        }
        self::fail('expected '.$class);
    }

    private static function partNumber(string $url): int
    {
        preg_match('~/(\d+)(?:\?|$)~', $url, $match);

        return (int) ($match[1] ?? 0);
    }

    private static function page(PipelineHarness $harness, int $from, int $to, ?int $next = null): Response
    {
        $urls = [];
        for ($n = $from; $n <= $to; $n++) {
            $urls[] = ['n' => $n, 'url' => PipelineHarness::url($n, '&fresh=1')];
        }

        return PipelineHarness::json(200, ['part_size_bytes' => $harness->partSize, 'total_parts' => $harness->totalParts, 'part_urls' => $urls, 'next_part_number' => $next]);
    }

    /** The create answers with a plan that lists no URLs yet: the first part has to fetch a page. */
    private static function planWithoutUrls(PipelineHarness $harness): void
    {
        $body = $harness->createBody();
        self::assertIsArray($body['upload']);
        $body['upload']['part_urls'] = [];
        $body['upload']['next_part_number'] = 1;
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));
    }

    /**
     * @param  list<float>  $beats
     */
    private static function longestGap(array $beats, float $end): float
    {
        $gap = 0.0;
        $last = 0.0;
        foreach ([...$beats, $end] as $time) {
            $gap = max($gap, $time - $last);
            $last = $time;
        }

        return $gap;
    }

    public function testAClientThatBlocksPerPartStillGetsAHeartbeatBetweenEveryPart(): void
    {
        $clock = new FakeClock();
        $client = new class ($clock) implements ClientInterface {
            public function __construct(private readonly FakeClock $clock)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->clock->time += 50.0;

                return PipelineHarness::stored();
            }
        };
        $harness = new PipelineHarness(clock: $clock, totalParts: 6, transport: new SyncTransport($client));
        $beats = [];
        $completedAt = null;
        $harness->api->on('POST', '/v1/uploads/[^/]+/heartbeat', static function () use ($clock, &$beats): Response {
            $beats[] = $clock->time;

            return PipelineHarness::json(200, ['ok' => true]);
        });
        $harness->api->on('POST', '/v1/uploads/[^/]+/complete', static function () use ($clock, &$completedAt): Response {
            $completedAt = $clock->time;

            return PipelineHarness::json(200, ['ok' => true, 'state' => 'queued']);
        });

        self::go($harness, ['concurrency' => 4]);

        self::assertNotNull($completedAt);
        self::assertGreaterThanOrEqual(300.0, $completedAt, 'six parts of 50 s');
        self::assertLessThanOrEqual(120.0, self::longestGap($beats, $completedAt), 'a ping at least every 120 s although each request blocks');
    }

    public function testARetryAfterDuringTheUploadDoesNotStopTheHeartbeat(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 20, script: static fn (array $request): array => [PipelineHarness::stored('"e'.self::partNumber($request['url']).'"'), 25.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', [
            new Response(429, ['Retry-After' => '60', 'Content-Type' => 'application/json'], '{"error":"rate_limited","code":1100}'),
            self::page($harness, 17, 20),
        ]);
        $beats = [];
        $completedAt = null;
        $harness->api->on('POST', '/v1/uploads/[^/]+/heartbeat', static function () use ($clock, &$beats): Response {
            $beats[] = $clock->time;

            return PipelineHarness::json(200, ['ok' => true]);
        });
        $harness->api->on('POST', '/v1/uploads/[^/]+/complete', static function () use ($clock, &$completedAt): Response {
            $completedAt = $clock->time;

            return PipelineHarness::json(200, ['ok' => true, 'state' => 'queued']);
        });
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));

        self::go($harness);

        self::assertCount(2, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'the 429 was retried after its Retry-After');
        self::assertGreaterThanOrEqual(60.0, array_sum($clock->sleeps), 'the wait was a minute, taken in slices');
        self::assertNotNull($completedAt);
        self::assertLessThanOrEqual(120.0, self::longestGap($beats, $completedAt));
    }

    public function testPingsAnsweredWithARetryAfterNeverStartAnotherPingFromInsideTheirOwnWait(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 20, script: static fn (array $request): array => [PipelineHarness::stored('"e'.self::partNumber($request['url']).'"'), 25.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 17, 20));
        $depth = 0;
        $hits = 0;
        $harness->api->on('POST', '/v1/uploads/[^/]+/heartbeat', static function () use (&$depth, &$hits): Response {
            $hits++;
            $beats = count(array_filter(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), static fn (array $frame): bool => ($frame['class'] ?? '') === Heartbeat::class && $frame['function'] === 'beat'));
            $depth = max($depth, $beats);

            return new Response(429, ['Retry-After' => '60', 'Content-Type' => 'application/json'], '{"error":"rate_limited","code":5000}');
        });

        self::go($harness);

        self::assertSame(1, $depth, 'a ping that waits for its own retry is never interrupted by another ping');
        self::assertLessThanOrEqual(24, $hits, 'bounded: four attempts per ping and a ping per 120 s at the most');
        self::assertCount(1, $harness->api->to('POST', '/v1/uploads/[^/]+/complete'), 'the upload goes on and completes');
    }

    public function testAfterOneLongBlockThePingsKeepTheIntervalInsteadOfOnePerPart(): void
    {
        $clock = new FakeClock();
        $client = new class ($clock) implements ClientInterface {
            private int $calls = 0;

            public function __construct(private readonly FakeClock $clock)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->clock->time += $this->calls++ === 0 ? 250.0 : 1.0;

                return PipelineHarness::stored();
            }
        };
        $harness = new PipelineHarness(clock: $clock, totalParts: 12, transport: new SyncTransport($client));

        self::go($harness, ['concurrency' => 1]);

        self::assertLessThanOrEqual(3, count($harness->api->to('POST', '/v1/uploads/[^/]+/heartbeat')), 'the long first part is followed by one ping, not one per fast part');
    }

    public function testADirectPutThatIsForbiddenAfterASuccessGetsOneFreshPageNotTwo(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 1 ? PipelineHarness::stored() : new Response(403), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 1, 3));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('parts_exhausted', $error->errorCode);
        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
    }

    public function testADirectPutThatIsForbiddenBeforeAnySuccessGetsOneFreshPageThenTheApi(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [str_contains($request['url'], 'r2.example') ? new Response(403) : PipelineHarness::stored(), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 1, 3));

        self::go($harness);

        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], array_values(array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api'))));
    }

    public function testAPartUrlsFailureBeforeAnyDirectSuccessSendsTheUploadThroughTheApi(): void
    {
        $harness = new PipelineHarness();
        self::planWithoutUrls($harness);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', new Response(500));

        self::go($harness);

        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], $harness->parts->lines());
        self::assertSame(0, $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody()->getSize(), 'a proxied upload completes without a parts body');
    }

    public function testAPartUrlsFailureAfterADirectSuccessIsPartsExhaustedWithTheFailureAsItsCause(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', new Response(500));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('parts_exhausted', $error->errorCode);
        self::assertSame('Part 17 could not be uploaded after its retries and a fresh URL.', $error->getMessage());
        self::assertInstanceOf(ApiError::class, $error->getPrevious());
        self::assertSame(500, $error->getPrevious()->status);
    }

    public function testATerminalPartUrlsRefusalAfterADirectSuccessSurfacesAsItself(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(403, ['error' => 'forbidden', 'code' => 1003]));

        $error = self::failure($harness, ApiError::class);

        self::assertSame(403, $error->status);
        self::assertNotInstanceOf(UploadError::class, $error);
    }

    /** @return iterable<string, array{Response}> */
    public static function pagesWithoutUrls(): iterable
    {
        yield 'a null page' => [new Response(200, ['Content-Type' => 'application/json'], 'null')];
        yield 'a page whose part_urls is null' => [PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => null, 'next_part_number' => null])];
        yield 'an empty page' => [PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [], 'next_part_number' => null])];
        yield 'a 404 page' => [PipelineHarness::json(404, ['error' => 'not_found', 'code' => 4000])];
    }

    #[DataProvider('pagesWithoutUrls')]
    public function testAPageWithoutUrlsMeansTheApiTakesTheParts(Response $page): void
    {
        $harness = new PipelineHarness();
        self::planWithoutUrls($harness);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', $page);

        self::go($harness);

        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], $harness->parts->lines());
        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'the answer is not asked for again');
    }

    public function testACreateWhosePartUrlsIsNullMeansTheApiTakesTheParts(): void
    {
        $harness = new PipelineHarness();
        $body = $harness->createBody();
        self::assertIsArray($body['upload']);
        $body['upload']['part_urls'] = null;
        $body['upload']['next_part_number'] = null;
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));

        self::go($harness);

        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], $harness->parts->lines());
        self::assertSame([], $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
    }

    public function testANullPageAfterADirectSuccessIsPartUrlMissing(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', new Response(200, ['Content-Type' => 'application/json'], 'null'));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('part_url_missing', $error->errorCode);
        self::assertSame('The API gave no upload URL for part 17.', $error->getMessage());
    }

    public function testA404NextPageAfterADirectSuccessIsPartUrlMissingLikeANullPage(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(404, ['error' => 'not_found', 'code' => 4000]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('part_url_missing', $error->errorCode);
        self::assertSame('The API gave no upload URL for part 17.', $error->getMessage());
        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'the 404 is not retried');
        self::assertSame([], array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api')));
    }

    /** @return iterable<string, array{string}> */
    public static function unusableUrls(): iterable
    {
        yield 'a port out of range' => ['https://r2.example.com:99999999/hushscript/job_1/1?X-Amz-Signature=SECRET'];
        yield 'no host' => ['/hushscript/job_1/1?X-Amz-Signature=SECRET'];
        yield 'not http' => ['ftp://r2.example.com/hushscript/job_1/1?X-Amz-Signature=SECRET'];
    }

    #[DataProvider('unusableUrls')]
    public function testAnUnusableSignedUrlIsBadResponseWithTheKeyAndTokenAndWithoutTheUrl(string $url): void
    {
        $harness = new PipelineHarness();
        $body = $harness->createBody();
        self::assertIsArray($body['upload']);
        $body['upload']['part_urls'] = [['n' => 1, 'url' => $url]];
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));

        $error = self::failure($harness, ApiError::class);

        self::assertSame('bad_response', $error->errorCode);
        self::assertNotNull($error->idempotencyKey);
        self::assertNotNull($error->resumeToken);
        $surface = $error->getMessage();
        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            $surface .= $link->getMessage().$link->getTraceAsString();
            foreach ($link->getTrace() as $frame) {
                foreach (str_contains($frame['file'] ?? '', dirname(__DIR__, 2).'/src/') ? ($frame['args'] ?? []) : [] as $argument) {
                    $surface .= $argument instanceof \Closure ? '' : print_r($argument, true);
                }
            }
        }
        self::assertStringNotContainsString('X-Amz-Signature', $surface);
        self::assertStringNotContainsString('SECRET', $surface);
        self::assertSame([], $harness->parts->sent);
    }

    public function testABadCreateAnswerThatCarriesPartUrlsLeavesNoSignedUrlInTheTrace(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        try {
            $harness = new PipelineHarness();
            $body = $harness->createBody();
            self::assertIsArray($body['upload']);
            $body['upload']['part_urls'] = [['n' => 1, 'url' => 'https://r2.example.com/hushscript/job_1/1?X-Amz-Signature=SECRET'], ['n' => 'two', 'url' => 'https://r2.example.com/hushscript/job_1/2?X-Amz-Signature=SECRET']];
            $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));

            $error = self::failure($harness, ApiError::class);
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }

        self::assertSame('bad_response', $error->errorCode);
        $surface = '';
        for ($link = \Hushscript\Tests\Support\SdkFrames::only($error); $link !== null; $link = $link->getPrevious()) {
            $surface .= print_r($link->getTrace(), true);
        }
        self::assertStringContainsString('Created', $surface, 'the trace has frames to inspect');
        self::assertStringNotContainsString('X-Amz-Signature', $surface);
        self::assertStringNotContainsString('SECRET', $surface);
    }

    public function testADirectSuccessWithoutAnEtagThatArrivesAfterTheSwitchToTheApiIsIgnored(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static function (array $request): array {
            if (str_contains($request['url'], 'r2.example')) {
                return self::partNumber($request['url']) === 1 ? [new Response(500), 0.0] : [new Response(200), 1000.0];
            }

            return [PipelineHarness::stored(), 0.0];
        });
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::go($harness);

        self::assertContains('PUT api:2', $harness->parts->lines(), 'part 2 is sent through the API instead of failing on the missing ETag');
        self::assertSame(0, $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody()->getSize());
    }

    public function testADirectSuccessWithoutAnEtagBeforeAnySwitchIsEtagMissing(): void
    {
        $harness = new PipelineHarness(script: static fn (): array => [new Response(200), 0.0]);

        self::assertSame('etag_missing', self::failure($harness, UploadError::class)->errorCode);
    }

    /** URLs for every part of the plan but `$missing`, with `$next` as the cursor of the pages still to come. */
    private static function planMissing(PipelineHarness $harness, int $missing, ?int $next): void
    {
        $body = $harness->createBody();
        self::assertIsArray($body['upload']);
        $urls = [];
        for ($n = 1; $n <= min(16, $harness->totalParts); $n++) {
            if ($n !== $missing) {
                $urls[] = ['n' => $n, 'url' => PipelineHarness::url($n)];
            }
        }
        $body['upload']['part_urls'] = $urls;
        $body['upload']['next_part_number'] = $next;
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));
    }

    /**
     * A part-urls endpoint that answers `start=2` with the URL of part 2 and a cursor of 3, and any
     * other start with a page up to the last part.
     */
    private static function servePages(PipelineHarness $harness): void
    {
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', static function (RequestInterface $request) use ($harness): Response {
            parse_str($request->getUri()->getQuery(), $query);
            $start = (int) $query['start'];

            return $start === 2 ? self::page($harness, 2, 2, 3) : self::page($harness, $start, $harness->totalParts);
        });
    }

    /** @return list<string> */
    private static function pageStarts(PipelineHarness $harness): array
    {
        return array_map(static fn (RequestInterface $request): string => $request->getUri()->getQuery(), $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
    }

    /** @return list<string> */
    private static function partLogs(PipelineHarness $harness): array
    {
        return array_values(array_filter($harness->logLines, static fn (string $line): bool => str_starts_with($line, 'hushscript PUT ')));
    }

    public function testASizeExceededPartUrlsAnswerEndsTheUploadInsteadOfFallingBack(): void
    {
        $harness = new PipelineHarness();
        self::planWithoutUrls($harness);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(422, ['error' => 'size_exceeded', 'code' => 1024]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('size_exceeded', $error->errorCode);
        self::assertSame(422, $error->status);
        self::assertSame([], $harness->parts->sent, 'no part goes through the API instead');
        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
    }

    public function testASizeExceededRefreshAfterADirectSuccessEndsTheUploadAsItself(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 2 ? new Response(403) : PipelineHarness::stored(), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(422, ['error' => 'size_exceeded', 'code' => 1024]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('size_exceeded', $error->errorCode);
        self::assertSame(['PUT r2:1', 'PUT r2:2', 'PUT r2:3'], $harness->parts->lines());
    }

    public function testAnUploadErrorOtherThanSizeExceededFromPartUrlsBeforeAnyDirectSuccessStillFallsBack(): void
    {
        $harness = new PipelineHarness();
        self::planWithoutUrls($harness);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(422, ['error' => 'upload_incomplete', 'code' => 1041]));

        self::go($harness);

        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], $harness->parts->lines());
    }

    /** @return iterable<string, array{Response|\Throwable, ?int}> */
    public static function partUrlsFailures(): iterable
    {
        yield 'a 400 is the answer itself' => [PipelineHarness::json(400, ['error' => 'invalid_request', 'code' => 1000]), 400];
        yield 'a 429 after its retries is parts_exhausted' => [PipelineHarness::json(429, ['error' => 'rate_limited', 'code' => 1100]), null];
        yield 'a 304 is parts_exhausted' => [new Response(304), null];
        yield 'a network failure is parts_exhausted' => [new ConnectException('refused', new Request('GET', PipelineHarness::BASE)), null];
    }

    /** @param ?int $terminal the status of the error that surfaces as itself, or null for parts_exhausted */
    #[DataProvider('partUrlsFailures')]
    public function testAPartUrlsFailureAfterADirectSuccessIsTerminalOnlyForA4xxOtherThan429(Response|\Throwable $answer, ?int $terminal): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', $answer);

        $error = self::failure($harness);

        if ($terminal !== null) {
            self::assertNotInstanceOf(UploadError::class, $error);
            self::assertSame($terminal, $error->status);
        } else {
            self::assertInstanceOf(UploadError::class, $error);
            self::assertSame('parts_exhausted', $error->errorCode);
        }
    }

    public function testATerminalRefusalOfTheRefreshAfterADirectSuccessSurfacesAsItself(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 2 ? new Response(500) : PipelineHarness::stored(), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(403, ['error' => 'forbidden', 'code' => 1003]));

        $error = self::failure($harness, ApiError::class);

        self::assertNotInstanceOf(UploadError::class, $error);
        self::assertSame(403, $error->status);
        self::assertCount(4, array_filter($harness->parts->lines(), static fn (string $line): bool => $line === 'PUT r2:2'), 'the old URL is not tried again after the refusal');
    }

    public function testA404RefreshAfterADirectSuccessIsPartUrlMissingLikeANullPage(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 2 ? new Response(403) : PipelineHarness::stored(), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(404, ['error' => 'not_found', 'code' => 4000]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('part_url_missing', $error->errorCode);
        self::assertSame('The API gave no upload URL for part 2.', $error->getMessage());
        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'the 404 is not retried');
        self::assertSame([], array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api')));
    }

    public function testA404RefreshBeforeAnyDirectSuccessSendsTheUploadThroughTheApi(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [str_contains($request['url'], 'r2.example') ? new Response(403) : PipelineHarness::stored(), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(404, ['error' => 'not_found', 'code' => 4000]));

        self::go($harness);

        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'the 404 is not retried');
        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], array_values(array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api'))));
    }

    public function testA404FromAProxiedPartKeepsItsMeaning(): void
    {
        $harness = new PipelineHarness(script: static fn (): array => [new Response(404, [], '{"error":"not_found","code":4000}'), 0.0]);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));

        $error = self::failure($harness, NotFoundError::class);

        self::assertSame(404, $error->status);
        self::assertCount(1, $harness->parts->sent, 'a 404 is not retried');
    }

    public function testAPartThatStillFailsAfterAFreshUrlNamesItselfAndCarriesItsLastAnswer(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 2 ? new Response(500) : PipelineHarness::stored(), 0.0]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 2, 2));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('parts_exhausted', $error->errorCode);
        self::assertSame('Part 2 could not be uploaded after its retries and a fresh URL.', $error->getMessage());
        $previous = $error->getPrevious();
        self::assertInstanceOf(ApiError::class, $previous);
        self::assertSame('The direct upload of part 2 was answered with 500.', $previous->getMessage());
        self::assertSame('http_500', $previous->errorCode);
        self::assertSame(500, $previous->status);
    }

    public function testAMissingEtagNamesThePart(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 2 ? new Response(200) : PipelineHarness::stored(), 0.0]);

        $error = self::failure($harness, UploadError::class);

        self::assertSame('etag_missing', $error->errorCode);
        self::assertSame('Storage accepted part 2 but sent no ETag.', $error->getMessage());
    }

    public function testAPageWithoutThePartsUrlNamesThePart(): void
    {
        $harness = new PipelineHarness();
        self::planMissing($harness, 2, null);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 3, 3));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('part_url_missing', $error->errorCode);
        self::assertSame('The API gave no upload URL for part 2.', $error->getMessage());
    }

    public function testAPartMissingFromAPlanWithoutACursorIsFetchedOnItsOwn(): void
    {
        $harness = new PipelineHarness();
        self::planMissing($harness, 2, null);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', self::page($harness, 2, 2));

        self::go($harness);

        self::assertSame(['start=2'], self::pageStarts($harness));
        self::assertSame(['PUT r2:1', 'PUT r2:2', 'PUT r2:3'], $harness->parts->lines());
    }

    public function testAPartMissingBelowTheCursorIsFetchedOnItsOwnAndLeavesTheCursorWhereItWas(): void
    {
        $harness = new PipelineHarness(totalParts: 20);
        self::planMissing($harness, 2, 17);
        self::servePages($harness);

        self::go($harness);

        self::assertSame(['start=2', 'start=17'], self::pageStarts($harness));
        self::assertCount(20, $harness->parts->sent);
    }

    public function testAPageWhoseCursorDoesNotMoveForwardEndsTheOrderedPaging(): void
    {
        $harness = new PipelineHarness(totalParts: 40);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', static function (RequestInterface $request) use ($harness): Response {
            parse_str($request->getUri()->getQuery(), $query);
            $start = (int) $query['start'];

            return $start === 17 ? self::page($harness, 17, 32, 17) : self::page($harness, $start, 40);
        });

        self::go($harness);

        self::assertSame(['start=17', 'start=33'], self::pageStarts($harness), 'part 33 is fetched on its own instead of asking for page 17 again');
        self::assertCount(40, $harness->parts->sent);
    }

    public function testARefreshedPageLeavesTheCursorOfThePagesStillToCome(): void
    {
        $harness = new PipelineHarness(totalParts: 20, script: static function (array $request): array {
            $expired = self::partNumber($request['url']) === 2 && ! str_contains($request['url'], 'fresh');

            return [$expired ? new Response(403) : PipelineHarness::stored(), 0.0];
        });
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody()));
        self::servePages($harness);

        self::go($harness);

        self::assertSame(['start=2', 'start=17'], self::pageStarts($harness));
        self::assertCount(21, $harness->parts->sent);
    }

    /** @return iterable<string, array{int}> */
    public static function notSuccess(): iterable
    {
        yield '199' => [199];
        yield '300' => [300];
    }

    #[DataProvider('notSuccess')]
    public function testOnlyA2xxFromStorageStoresAPart(int $status): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [str_contains($request['url'], 'r2.example') ? new Response($status, ['ETag' => '"e"']) : PipelineHarness::stored(), 0.0]);

        self::go($harness);

        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], array_values(array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api'))));
    }

    /** @return iterable<string, array{bool}> */
    public static function partWays(): iterable
    {
        yield 'direct' => [false];
        yield 'through the API' => [true];
    }

    #[DataProvider('partWays')]
    public function testEveryPartRequestCarriesTheSdkUserAgent(bool $proxied): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: $proxied)));

        self::go($harness);

        $agent = $harness->api->requests[0]->getHeaderLine('User-Agent');
        self::assertMatchesRegularExpression('~^hushscript-php/\d+\.\d+\.\d+\S* php/\d+\.\d+\.\d+\S*$~', $agent);
        self::assertNotSame([], $harness->parts->sent);
        foreach ($harness->parts->sent as $part) {
            self::assertSame([$agent], $part['headers']['User-Agent']);
        }
    }

    public function testAPlainHttpSignedUrlIsUsable(): void
    {
        $harness = new PipelineHarness();
        $body = $harness->createBody();
        self::assertIsArray($body['upload']);
        $body['upload']['part_urls'] = array_map(static fn (int $n): array => ['n' => $n, 'url' => str_replace('https://', 'http://', PipelineHarness::url($n))], range(1, 3));
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $body));

        self::go($harness);

        self::assertSame(['PUT r2:1', 'PUT r2:2', 'PUT r2:3'], $harness->parts->lines());
        self::assertStringStartsWith('http://r2.example.com/', $harness->parts->sent[0]['url']);
    }

    public function testARetryAfterIsSleptFromNowNotFromTheEpoch(): void
    {
        $clock = new FakeClock();
        $clock->time = 1000.0;
        $harness = new PipelineHarness(clock: $clock, totalParts: 1, script: static fn (array $request, int $index): array => [$index === 0 ? new Response(429, ['Retry-After' => '3']) : PipelineHarness::stored(), 0.0]);

        self::go($harness);

        self::assertContains(3.0, $clock->sleeps);
        self::assertCount(2, $harness->parts->sent);
    }

    public function testARetryDueAtOnceIsNotSleptFor(): void
    {
        $clock = new FakeClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 1, script: static fn (array $request, int $index): array => [$index === 0 ? new Response(429, ['Retry-After' => '0']) : PipelineHarness::stored(), 0.0]);

        self::go($harness);

        self::assertNotContains(0.0, $clock->sleeps);
        self::assertCount(2, $harness->parts->sent);
    }

    public function testTheIdleClockIsToldHowManyPartsAreInFlight(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static fn (): array => [PipelineHarness::stored(), 10.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::go($harness);

        self::assertSame(3, $clock->idleInFlight[0] ?? null);
    }

    public function testAPartRejectedWithAnErrorThatIsNotATransportFailureIsUnexpected(): void
    {
        $transport = new class () implements PartTransport {
            public int $sent = 0;

            #[\Override]
            public function send(RequestInterface $request, float $timeout): PromiseInterface
            {
                $this->sent++;

                // Guzzle's promise templates are invariant: a RejectedPromise<never, RuntimeException> is not a PromiseInterface<ResponseInterface, Throwable>.
                return Create::rejectionFor(new \RuntimeException('boom')); // @phpstan-ignore return.type
            }

            #[\Override]
            public function tick(): void
            {
                Utils::queue()->run();
            }
        };
        $harness = new PipelineHarness(totalParts: 1, transport: $transport);

        $error = self::failure($harness);

        self::assertSame('unexpected_error', $error->errorCode);
        self::assertSame('The call failed with an error that is not a network failure; the request may have been sent. RuntimeException: boom', $error->getMessage());
        self::assertSame(1, $transport->sent);
    }

    public function testAPartStillInFlightDoesNotBringThePingForward(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 2, script: static fn (array $request, int $index): array => [PipelineHarness::stored(), $index === 0 ? 119.5 : 10.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $beats = [];
        $harness->api->on('POST', '/v1/uploads/[^/]+/heartbeat', static function () use ($clock, &$beats): Response {
            $beats[] = $clock->time;

            return PipelineHarness::json(200, ['ok' => true]);
        });

        self::go($harness, ['concurrency' => 1]);

        self::assertSame(120.0, $beats[0] ?? null, 'no ping before the interval: the first part was still in flight when the second started');
    }

    public function testEveryPartAttemptIsLoggedWithItsPathStatusNumberDurationAndRetry(): void
    {
        $clock = new FakeIdleClock();
        $clock->time = 1000.0;
        $harness = new PipelineHarness(clock: $clock, totalParts: 1, script: static fn (array $request, int $index): array => $index === 0 ? [new Response(503), 1.5] : [PipelineHarness::stored(), 2.25]);
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::go($harness);

        self::assertSame(['hushscript PUT r2:part/1 503 attempt=1 1500ms retry_in=0.5s', 'hushscript PUT r2:part/1 200 attempt=2 2250ms'], self::partLogs($harness));
    }

    public function testAProxiedPartAttemptIsLoggedWithItsApiPath(): void
    {
        $harness = new PipelineHarness(totalParts: 1);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));

        self::go($harness);

        self::assertSame(['hushscript PUT /v1/uploads/job_1/parts/1 200 attempt=1 0ms'], self::partLogs($harness));
    }
}
