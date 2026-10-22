<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use GuzzleHttp\Psr7\Response;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\DailyLimitError;
use Hushscript\Exception\FileLimitError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\InsufficientBalanceError;
use Hushscript\Exception\JobFailedError;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\RateLimitError;
use Hushscript\Exception\UnsupportedMediaError;
use Hushscript\Exception\UploadError;
use Hushscript\Exception\WaitTimeoutError;
use Hushscript\Tests\Support\FakeIdleClock;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Transcribe\PartFailure;
use Hushscript\Transcribe\Resume;
use Hushscript\Transcript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class PipelineTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private static \ArrayObject $handedOut;

    /** @var list<string> */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $path) {
            @unlink($path);
        }
    }

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

    /**
     * Everything an observer of an error chain can read: messages, trace text, and every string
     * reachable from a trace argument, objects and their private properties included. A value kept
     * outside the object (a Secret, Credentials) is not reachable, which is the point.
     */
    private static function surface(\Throwable $error): string
    {
        $out = '';
        $seen = [];
        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            // The frames of the SDK; the ones below them are the test runner's own arguments.
            $frames = array_filter($link->getTrace(), static fn (array $frame): bool => str_contains($frame['file'] ?? '', dirname(__DIR__, 2).'/src/'));
            $out .= $link->getMessage()."\n".$link->getTraceAsString()."\n".self::reachable($frames, 0, $seen);
            // What a log handler or a debugger would print: the SDK frames dumped, each argument exported and serialized.
            $out .= self::quietly(static fn (): string => print_r($frames, true));
            foreach ($frames as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    $out .= self::quietly(static fn (): string => var_export($argument, true));
                    $out .= self::quietly(static fn (): string => serialize($argument));
                }
            }
        }

        return $out;
    }

    /** @param \Closure(): string $render  a dump that may warn about cycles or refuse to serialize a closure or a Secret */
    private static function quietly(\Closure $render): string
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $render();
        } catch (\Throwable) {
            return '';
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<int, true> $seen */
    private static function reachable(mixed $value, int $depth, array &$seen): string
    {
        if (is_string($value)) {
            return $value."\n";
        }
        if ($depth > 7) {
            return '';
        }
        $out = '';
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $out .= (is_string($key) ? $key."\n" : '').self::reachable($item, $depth + 1, $seen);
            }

            return $out;
        }
        if (! is_object($value) || isset($seen[spl_object_id($value)])) {
            return '';
        }
        $seen[spl_object_id($value)] = true;
        if ($value instanceof \Closure) {
            return self::reachable((new \ReflectionFunction($value))->getStaticVariables(), $depth + 1, $seen);
        }
        foreach ((new \ReflectionObject($value))->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($value)) {
                continue;
            }
            $out .= self::reachable($property->getValue($value), $depth + 1, $seen);
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $body
     * @param  array<string, mixed>  $changes
     * @return array<mixed>
     */
    private static function upload(array $body, array $changes): array
    {
        $upload = is_array($body['upload'] ?? null) ? $body['upload'] : [];

        return [...$body, 'upload' => [...$upload, ...$changes]];
    }

    private static function fail500(): Response
    {
        return new Response(500);
    }

    private static function firstHandedOut(): ?string
    {
        return self::$handedOut->getArrayCopy()[0] ?? null;
    }

    private static function partNumber(string $url): int
    {
        preg_match('~/(\d+)(?:\?|$)~', $url, $match);

        return (int) ($match[1] ?? 0);
    }

    public function testADirectUploadPutsEveryPartWithoutAuthorizationAndCompletesWithTheSortedEtags(): void
    {
        $harness = new PipelineHarness(script: fn (array $request): array => [PipelineHarness::stored('"etag-'.self::partNumber($request['url']).'"'), 0.0]);
        $tokens = [];
        $progress = [];

        $transcript = self::go($harness, [
            'on_resume_token' => static function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            },
            'on_progress' => static function (array $event) use (&$progress): void {
                $progress[] = $event;
            },
        ]);

        self::assertSame('tr_1', $transcript->id);
        self::assertSame(
            ['GET /v1/account', 'POST /v1/uploads', 'POST /v1/uploads/job_1/heartbeat', 'POST /v1/uploads/job_1/complete', 'GET /v1/jobs/job_1', 'GET /v1/transcripts/tr_1'],
            $harness->api->lines(),
        );
        self::assertSame(['PUT r2:1', 'PUT r2:2', 'PUT r2:3'], $harness->parts->lines());
        self::assertSame(['', '', ''], array_column($harness->parts->sent, 'authorization'));
        $lengths = array_column($harness->parts->sent, 'length');
        self::assertSame($harness->size, array_sum($lengths));
        self::assertSame([$harness->partSize, $harness->partSize], array_slice($lengths, 0, 2));
        $complete = $harness->api->to('POST', '/v1/uploads/job_1/complete')[0];
        self::assertSame(
            ['parts' => [['n' => 1, 'etag' => 'etag-1'], ['n' => 2, 'etag' => 'etag-2'], ['n' => 3, 'etag' => 'etag-3']]],
            json_decode((string) $complete->getBody(), true),
        );
        self::assertCount(1, $tokens);
        $state = Resume::decode($tokens[0]);
        self::assertSame(['job_1', $harness->size, 21], [$state->jobId, $state->size, $state->durationSeconds]);
        self::assertSame(
            ['stage' => 'uploading', 'percent' => 0, 'bytes_sent' => 0, 'bytes_total' => $harness->size],
            $progress[0],
        );
        $last = null;
        foreach ($progress as $event) {
            if ($event['stage'] === 'uploading') {
                $last = $event;
            }
        }
        self::assertSame(100, $last['percent'] ?? null);
        self::assertSame($harness->size, $last['bytes_sent'] ?? null);
    }

    public function testTheCreateIsOneKeyedPostWithTheOptions(): void
    {
        $harness = new PipelineHarness();

        self::go($harness, ['title' => 'Call', 'language' => 'de', 'idempotency_key' => 'caller-key-0123456789']);

        $create = $harness->api->to('POST', '/v1/uploads');
        self::assertCount(1, $create);
        self::assertSame(
            ['size_bytes' => $harness->size, 'duration_seconds' => 21, 'idempotency_key' => 'caller-key-0123456789', 'title' => 'Call', 'transcription_options' => ['language_code' => 'de']],
            json_decode((string) $create[0]->getBody(), true),
        );
    }

    public function testAProxiedUploadSendsThePartsToTheApiWithTheTokenAndCompletesWithoutABody(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));

        self::go($harness);

        self::assertSame(['PUT api:1'], $harness->parts->lines());
        $part = $harness->parts->sent[0];
        self::assertSame('http://127.0.0.1:4010/v1/uploads/job_1/parts/1', $part['url']);
        self::assertSame('Bearer '.\Hushscript\Tests\Support\Tokens::TEST, $part['authorization']);
        self::assertSame(['application/octet-stream'], $part['headers']['Content-Type']);
        self::assertSame($harness->size, $part['length']);
        self::assertSame(0, $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody()->getSize());
    }

    public function testADirectUploadThatFailsOnItsFirstPartFallsBackToTheApiForTheRest(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [str_contains($request['url'], 'r2.example') ? self::fail500() : PipelineHarness::stored(), 0.0]);

        self::go($harness);

        $lines = $harness->parts->lines();
        self::assertSame(['PUT api:1', 'PUT api:2', 'PUT api:3'], array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'api'))));
        self::assertLessThanOrEqual(12, count(array_filter($lines, static fn (string $line): bool => str_contains($line, 'r2'))), 'each part retried at most maxRetries times');
        self::assertSame(0, $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody()->getSize());
        self::assertSame([], $harness->api->to('GET', '/v1/uploads/job_1/part-urls'), 'no URL page is fetched on the way to the fallback');
    }

    public function testAnUploadThatHasHadADirectSuccessKeepsRetryingDirectWithAFreshUrl(): void
    {
        $page = null;
        $harness = new PipelineHarness(script: static function (array $request): array {
            $part = self::partNumber($request['url']);
            if ($part === 1 && ! str_contains($request['url'], 'fresh')) {
                return [self::fail500(), 0.0];
            }

            return [PipelineHarness::stored('"e'.$part.'"'), 0.0];
        });
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', static function (RequestInterface $request) use (&$page): Response {
            $page = $request->getUri()->getQuery();

            return PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [['n' => 1, 'url' => PipelineHarness::url(1, '&fresh=1')]], 'next_part_number' => null]);
        });

        self::go($harness);

        $lines = $harness->parts->lines();
        self::assertCount(5, array_filter($lines, static fn (string $line): bool => $line === 'PUT r2:1'), '4 attempts on the old URL, 1 on the fresh one');
        self::assertSame([], array_filter($lines, static fn (string $line): bool => str_contains($line, 'api')));
        self::assertSame('start=1', $page);
        $body = json_decode((string) $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['parts']);
        self::assertSame([1, 2, 3], array_column($body['parts'], 'n'));
    }

    public function testAfterTheRetriesAndAFreshUrlAPartThatStillFailsIsPartsExhausted(): void
    {
        $harness = new PipelineHarness(script: static function (array $request): array {
            return [self::partNumber($request['url']) === 1 ? self::fail500() : PipelineHarness::stored(), 0.0];
        });
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [['n' => 1, 'url' => PipelineHarness::url(1, '&fresh=1')]], 'next_part_number' => null]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('parts_exhausted', $error->errorCode);
        self::assertCount(8, array_filter($harness->parts->lines(), static fn (string $line): bool => $line === 'PUT r2:1'), '4 attempts, a fresh URL, 4 more attempts');
        $previous = $error->getPrevious();
        self::assertInstanceOf(ApiError::class, $previous, 'the last direct failure is the cause');
        self::assertSame(500, $previous->status);
        // The fakes keep the requests they saw; those are the test's own record, not the SDK's.
        $harness->parts->sent = [];
        $harness->api->requests = [];
        self::assertStringNotContainsString('X-Amz-Signature', self::surface($error));
    }

    public function testAPartThatFailsInTransitCarriesTheTransportFailureAsTheCauseWithoutTheUrl(): void
    {
        $harness = new PipelineHarness(script: static fn (array $request): array => [
            self::partNumber($request['url']) === 1 ? PipelineHarness::stored() : PartFailure::of(new \GuzzleHttp\Exception\ConnectException('cURL error 28 for '.$request['url'], new \GuzzleHttp\Psr7\Request('PUT', $request['url']))),
            0.0,
        ]);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [['n' => 2, 'url' => PipelineHarness::url(2, '&fresh=1')], ['n' => 3, 'url' => PipelineHarness::url(3, '&fresh=1')]], 'next_part_number' => null]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('parts_exhausted', $error->errorCode);
        $previous = $error->getPrevious();
        self::assertInstanceOf(NetworkError::class, $previous);
        // R96: the cause names what failed, host and path included; the signed query is gone.
        $summary = 'GuzzleHttp\\Exception\\ConnectException: cURL error 28 for https://r2.example.com/hushscript/job_1/2?[redacted]';
        self::assertSame('The direct upload of part 2 failed: '.$summary, $previous->getMessage());
        self::assertSame($summary, $previous->getPrevious()?->getMessage());
        $harness->parts->sent = [];
        $harness->api->requests = [];
        self::assertStringNotContainsString('X-Amz-Signature', self::surface($error));
        self::assertStringNotContainsString('SECRET', self::surface($error));
    }

    /**
     * The mode is decided once. Part 2 is held; part 1 gives up on direct first (the whole upload goes
     * through the API), so part 2's direct success that lands later is thrown away and re-sent.
     */
    public function testADirectSuccessThatArrivesAfterTheSwitchToTheApiIsSentAgainThroughTheApi(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static function (array $request): array {
            if (str_contains($request['url'], 'r2.example')) {
                return self::partNumber($request['url']) === 1 ? [self::fail500(), 0.0] : [PipelineHarness::stored('"late"'), 1000.0];
            }

            return [PipelineHarness::stored(), 0.0];
        });
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::go($harness);

        $lines = $harness->parts->lines();
        self::assertContains('PUT api:1', $lines);
        self::assertContains('PUT api:2', $lines, 'part 2 reached storage after the switch, so the API gets it too');
        self::assertContains('PUT api:3', $lines);
        self::assertSame(0, $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody()->getSize(), 'a switched upload completes without a parts body');
    }

    /**
     * The other order: part 2's direct success lands first, so part 1, which then gives up, keeps
     * trying direct instead of switching.
     */
    public function testADirectSuccessThatArrivesBeforeAPartGivesUpKeepsTheUploadDirect(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static function (array $request): array {
            $part = self::partNumber($request['url']);
            if ($part === 1 && ! str_contains($request['url'], 'fresh')) {
                return [self::fail500(), 0.0];
            }

            return [PipelineHarness::stored('"e'.$part.'"'), $part === 1 ? 0.0 : 0.25];
        });
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [['n' => 1, 'url' => PipelineHarness::url(1, '&fresh=1')]], 'next_part_number' => null]));

        self::go($harness);

        self::assertSame([], array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api')));
        $body = json_decode((string) $harness->api->to('POST', '/v1/uploads/job_1/complete')[0]->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['parts']);
        self::assertSame([1, 2, 3], array_column($body['parts'], 'n'));
    }

    public function testAnExpiredUrlIsRefreshedOnceFromThePageAndTheUploadStaysDirect(): void
    {
        $harness = new PipelineHarness(script: static function (array $request): array {
            $expired = self::partNumber($request['url']) === 1 && ! str_contains($request['url'], 'fresh');

            return [$expired ? new Response(403) : PipelineHarness::stored(), 0.0];
        });
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(200, ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [['n' => 1, 'url' => PipelineHarness::url(1, '&fresh=1')]], 'next_part_number' => null]));

        self::go($harness);

        self::assertCount(1, $harness->api->to('GET', '/v1/uploads/job_1/part-urls'));
        self::assertCount(2, array_filter($harness->parts->lines(), static fn (string $line): bool => $line === 'PUT r2:1'));
        self::assertSame([], array_filter($harness->parts->lines(), static fn (string $line): bool => str_contains($line, 'api')));
    }

    public function testMoreThanOnePageOfUrlsIsFetchedInOrder(): void
    {
        $harness = new PipelineHarness(totalParts: 40);
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', function (RequestInterface $request) use ($harness): Response {
            parse_str($request->getUri()->getQuery(), $query);
            $start = (int) $query['start'];
            $urls = [];
            for ($n = $start; $n < $start + 16 && $n <= 40; $n++) {
                $urls[] = ['n' => $n, 'url' => PipelineHarness::url($n)];
            }

            return PipelineHarness::json(200, ['part_size_bytes' => $harness->partSize, 'total_parts' => 40, 'part_urls' => $urls, 'next_part_number' => $start + 16 <= 40 ? $start + 16 : null]);
        });

        self::go($harness);

        self::assertSame(['start=17', 'start=33'], array_map(static fn (RequestInterface $request): string => $request->getUri()->getQuery(), $harness->api->to('GET', '/v1/uploads/job_1/part-urls')));
        self::assertCount(40, $harness->parts->sent);
        self::assertLessThanOrEqual(4, $harness->parts->maxPending);
    }

    public function testAPartThatIsRateLimitedWaitsForRetryAfter(): void
    {
        $harness = new PipelineHarness(totalParts: 1, script: static fn (array $request, int $index): array => [$index === 0 ? new Response(429, ['Retry-After' => '3']) : PipelineHarness::stored(), 0.0]);

        self::go($harness);

        self::assertContains(3.0, $harness->clock->sleeps);
        self::assertCount(2, $harness->parts->sent);
    }

    public function testNoMoreThanConcurrencyPartsAreInFlight(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 7, script: static fn (): array => [PipelineHarness::stored(), 10.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::go($harness, ['concurrency' => 2]);

        self::assertSame(2, $harness->parts->maxPending);
        self::assertCount(7, $harness->parts->sent);
    }

    public function testTheHeartbeatIsNeverMoreThan120SecondsFromTheLastRequest(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 6, script: static fn (): array => [PipelineHarness::stored(), 50.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        /** @var list<float> $times */
        $times = [];
        $create = static function () use ($clock, &$times): void {
            $times[] = $clock->time;
        };
        $harness->api->on('POST', '/v1/uploads', function () use ($harness, $create): Response {
            $create();

            return PipelineHarness::json(200, $harness->createBody());
        });
        $harness->api->on('POST', '/v1/uploads/job_1/heartbeat', function () use ($create): Response {
            $create();

            return PipelineHarness::json(200, ['ok' => true]);
        });
        $harness->api->on('POST', '/v1/uploads/job_1/complete', function () use ($create): Response {
            $create();

            return PipelineHarness::json(200, ['ok' => true, 'state' => 'queued']);
        });

        self::go($harness, ['concurrency' => 1]);

        self::assertSame([0.0, 120.0, 240.0, 300.0, 300.0], $times);
        self::assertGreaterThanOrEqual(4, count($times));
        for ($index = 1; $index < count($times); $index++) {
            self::assertLessThanOrEqual(120.0, $times[$index] - $times[$index - 1], 'gap before request '.$index);
        }
    }

    public function testAHeartbeatThatSaysWrongStateEndsTheBeatAndTheUploadFinishes(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static fn (): array => [PipelineHarness::stored(), 100.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('POST', '/v1/uploads/job_1/heartbeat', PipelineHarness::json(409, ['error' => 'wrong_state', 'code' => 4001]));

        self::go($harness, ['concurrency' => 1]);

        self::assertCount(1, $harness->api->to('POST', '/v1/uploads/job_1/heartbeat'), 'one ping, then the beat stopped (and no ping before complete)');
    }

    public function testARefusedTokenOnAHeartbeatAbortsTheUploadWithAuthenticationError(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static fn (): array => [PipelineHarness::stored(), 150.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('POST', '/v1/uploads/job_1/heartbeat', PipelineHarness::json(401, ['error' => 'unauthorized', 'code' => 2000]));

        $error = self::failure($harness, AuthenticationError::class);

        self::assertNotNull($error->idempotencyKey);
        self::assertNotNull($error->resumeToken);
        self::assertSame([], $harness->api->to('POST', '/v1/uploads/job_1/complete'));
        self::assertGreaterThan(0, $harness->parts->cancelled, 'requests still in flight are cancelled');
    }

    public function testAHeartbeatThatKeepsFailingDoesNotKillTheUpload(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static fn (): array => [PipelineHarness::stored(), 150.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);
        $harness->api->on('POST', '/v1/uploads/job_1/heartbeat', PipelineHarness::json(503));

        $transcript = self::go($harness, ['concurrency' => 1]);

        self::assertSame('tr_1', $transcript->id);
    }

    public function testAPartThatCannotBeReadMidUploadIsASourceReadFailureWithTheKeyAndToken(): void
    {
        $copy = tempnam(sys_get_temp_dir(), 'hs');
        self::assertNotFalse($copy);
        $this->temp[] = $copy;
        $harness = new PipelineHarness();
        copy($harness->path(), $copy);
        $harness->api->on('POST', '/v1/uploads', function () use ($harness, $copy): Response {
            file_put_contents($copy, 'cut short');

            return PipelineHarness::json(200, $harness->createBody());
        });

        try {
            $harness->pipeline->transcribe($copy, []);
            self::fail('expected an UploadError');
        } catch (UploadError $error) {
            self::assertSame('source_read_failed', $error->errorCode);
            self::assertNotNull($error->idempotencyKey);
            self::assertNotNull($error->resumeToken);
        }
    }

    public function testAStorageAnswerWithoutAnEtagIsEtagMissing(): void
    {
        $harness = new PipelineHarness(script: static fn (): array => [new Response(200), 0.0]);

        $error = self::failure($harness, UploadError::class);

        self::assertSame('etag_missing', $error->errorCode);
        self::assertNotNull($error->resumeToken);
    }

    public function testAnUploadPlanWithNoUrlForAPartIsPartUrlMissing(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, self::upload($harness->createBody(), ['part_urls' => []])));
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', PipelineHarness::json(200, ['part_size_bytes' => $harness->partSize, 'total_parts' => 3, 'part_urls' => [['n' => 2, 'url' => PipelineHarness::url(2)]], 'next_part_number' => null]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('part_url_missing', $error->errorCode);
    }

    public function testAPlanThatDoesNotMatchTheFileIsABadPartPlan(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, self::upload($harness->createBody(), ['total_parts' => 4])));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('bad_part_plan', $error->errorCode);
        self::assertNotNull($error->idempotencyKey);
        self::assertNotNull($error->resumeToken);
    }

    /** @return iterable<string, array{\Closure(array<mixed>): array<mixed>}> */
    public static function unusableCreates(): iterable
    {
        yield 'part size zero' => [static fn (array $b): array => self::upload($b, ['part_size_bytes' => 0])];
        yield 'part size negative' => [static fn (array $b): array => self::upload($b, ['part_size_bytes' => -5])];
        yield 'part size fractional' => [static fn (array $b): array => self::upload($b, ['part_size_bytes' => 1.5])];
        yield 'part size a string' => [static fn (array $b): array => self::upload($b, ['part_size_bytes' => '8'])];
        yield 'part size above 32 MiB' => [static fn (array $b): array => self::upload($b, ['part_size_bytes' => 32 * 1024 * 1024 + 1])];
        yield 'part size PHP_INT_MAX' => [static fn (array $b): array => self::upload($b, ['part_size_bytes' => PHP_INT_MAX])];
        yield 'total parts negative' => [static fn (array $b): array => self::upload($b, ['total_parts' => -1])];
        yield 'total parts fractional' => [static fn (array $b): array => self::upload($b, ['total_parts' => 2.5])];
        yield 'total parts a string' => [static fn (array $b): array => self::upload($b, ['total_parts' => '3'])];
        yield 'total parts zero' => [static fn (array $b): array => self::upload($b, ['total_parts' => 0])];
        yield 'total parts missing' => [static fn (array $b): array => [...$b, 'upload' => ['part_size_bytes' => 5, 'part_urls' => []]]];
        yield 'part number zero' => [static fn (array $b): array => self::upload($b, ['part_urls' => [['n' => 0, 'url' => 'https://x.example/1']]])];
        yield 'part number fractional' => [static fn (array $b): array => self::upload($b, ['part_urls' => [['n' => 1.5, 'url' => 'https://x.example/1']]])];
        yield 'empty url' => [static fn (array $b): array => self::upload($b, ['part_urls' => [['n' => 1, 'url' => '']]])];
        yield 'next part negative' => [static fn (array $b): array => self::upload($b, ['next_part_number' => -1])];
        yield 'upload a list' => [static fn (array $b): array => [...$b, 'upload' => [1, 2]]];
        yield 'upload empty' => [static fn (array $b): array => [...$b, 'upload' => []]];
        yield 'no job id' => [static fn (array $b): array => ['state' => 'uploading', 'upload' => $b['upload']]];
        yield 'dot job id' => [static fn (array $b): array => [...$b, 'job_id' => '..']];
        yield 'slash job id' => [static fn (array $b): array => [...$b, 'job_id' => 'a/b']];
        yield 'backslash job id' => [static fn (array $b): array => [...$b, 'job_id' => 'a\\b']];
    }

    /**
     * @param  \Closure(array<mixed>): array<mixed>  $break
     */
    #[DataProvider('unusableCreates')]
    public function testAnUnusableCreateAnswerIsABadResponseThatCarriesTheKey(\Closure $break): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $break($harness->createBody())));

        $error = self::failure($harness, ApiError::class);

        self::assertSame('bad_response', $error->errorCode);
        self::assertSame(200, $error->status);
        self::assertNull($error->numericCode);
        self::assertNotNull($error->idempotencyKey);
        self::assertSame([], $harness->parts->sent);
    }

    public function testAPartSizeOfExactly32MiBIsUsable(): void
    {
        $plan = \Hushscript\Transcribe\Plan::parse(['part_size_bytes' => 32 * 1024 * 1024, 'total_parts' => 1, 'part_urls' => []], 200);

        self::assertSame(33_554_432, $plan->partSize);
    }

    public function testAnEmptyCreateBodyIsABadResponse(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', new Response(200));

        self::assertSame('bad_response', self::failure($harness, ApiError::class)->errorCode);
    }

    public function testADoneJobWithoutATranscriptCarriesTheKeyAndToken(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'done']));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('transcript_missing', $error->errorCode);
        self::assertNotNull($error->idempotencyKey);
        self::assertNotNull($error->resumeToken);
    }

    public function testAFailedJobRaisesJobFailedError(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'failed', 'error' => 'undecodable', 'failure_kind' => 'media_undecodable']));

        $error = self::failure($harness, JobFailedError::class);

        self::assertSame(JobFailedError::class, $error::class);
        self::assertSame('job_failed', $error->errorCode);
        self::assertNull($error->status);
        self::assertSame('job_1', $error->jobId);
        self::assertSame('media_undecodable', $error->failureKind);
        self::assertNotNull($error->resumeToken);
    }

    public function testAJobThatOutlastsTheWaitTimeoutRaisesWaitTimeoutWithTheToken(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'processing']));

        try {
            self::go($harness, ['wait_timeout' => 30]);
            self::fail('expected a WaitTimeoutError');
        } catch (WaitTimeoutError $error) {
            self::assertNotNull($error->resumeToken);
            self::assertSame(Resume::decode($error->resumeToken)->jobId, 'job_1');
        }
    }

    public function testACompleteThatReportsLostBytesIsAnUploadError(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads/job_1/complete', PipelineHarness::json(409, ['error' => 'upload_incomplete', 'code' => 1041]));

        $error = self::failure($harness, UploadError::class);

        self::assertSame('upload_incomplete', $error->errorCode);
        self::assertNotNull($error->resumeToken);
    }

    public function testAServerRefusalOfTheCreateCarriesTheKeyButNoToken(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(402, ['error' => 'insufficient_balance', 'code' => 3001, 'needed_seconds' => 21, 'balance_seconds' => 5]));

        $error = self::failure($harness, InsufficientBalanceError::class);

        self::assertNotNull($error->idempotencyKey);
        self::assertNull($error->resumeToken);
        self::assertSame(21, $error->details['needed_seconds']);
    }

    public function testACreateThatIsLostInTheNetworkIsRetriedWithTheSameKey(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads', [
            new \GuzzleHttp\Exception\NetworkException('cURL error 56: lost', new \GuzzleHttp\Psr7\Request('POST', PipelineHarness::BASE.'/v1/uploads')),
            PipelineHarness::json(200, $harness->createBody()),
        ]);

        self::go($harness);

        $keys = array_map(static function (RequestInterface $request): mixed {
            $body = json_decode((string) $request->getBody(), true);

            return is_array($body) ? $body['idempotency_key'] ?? null : null;
        }, $harness->api->to('POST', '/v1/uploads'));
        self::assertCount(2, $keys);
        self::assertSame($keys[0], $keys[1]);
    }

    public function testEveryCreateAttemptTakesAPacingToken(): void
    {
        $harness = new PipelineHarness(maxRetries: 5);
        $times = [];
        $harness->api->on('POST', '/v1/uploads', function () use ($harness, &$times): Response {
            $times[] = $harness->clock->time;

            return count($times) < 5 ? PipelineHarness::json(503) : PipelineHarness::json(200, $harness->createBody());
        });

        self::go($harness);

        self::assertCount(5, $times);
        self::assertGreaterThanOrEqual(60.0, $times[4] - $times[0], 'the fifth create waited out the 4-per-minute window');
    }

    public function testPreflightFailuresMakeNoRequest(): void
    {
        foreach ([
            ['short-10s.mp3', [], FileLimitError::class, 'duration_out_of_range'],
            ['webm-renamed.mp3', [], UnsupportedMediaError::class, 'unsupported_media'],
            ['cbr-20s.mp3', ['concurrency' => 99], PreflightError::class, 'invalid_option'],
            ['cbr-20s.mp3', ['duration_seconds' => NAN], PreflightError::class, 'invalid_option'],
            ['cbr-20s.mp3', ['duration_seconds' => 5], FileLimitError::class, 'duration_out_of_range'],
            ['cbr-20s.mp3', ['duration_seconds' => INF], PreflightError::class, 'invalid_option'],
            ['cbr-20s.mp3', ['duration_seconds' => 1e300], FileLimitError::class, 'duration_out_of_range'],
            ['cbr-20s.mp3', ['duration_seconds' => 9.3e18], FileLimitError::class, 'duration_out_of_range'],
            ['cbr-20s.mp3', ['duration_seconds' => 36000.5], FileLimitError::class, 'duration_out_of_range'],
        ] as [$file, $options, $class, $code]) {
            $harness = new PipelineHarness(file: $file);
            try {
                self::go($harness, $options);
                self::fail('expected '.$class);
            } catch (HushscriptException $error) {
                self::assertInstanceOf($class, $error);
                self::assertSame($code, $error->errorCode);
                self::assertNull($error->idempotencyKey, 'nothing was sent, so there is no key');
            }
            self::assertSame([], $harness->api->requests, $file);
            self::assertSame([], $harness->parts->sent);
        }
    }

    public function testAMissingFileIsRefusedBeforeAnyRequest(): void
    {
        $harness = new PipelineHarness();

        try {
            $harness->pipeline->transcribe('/nonexistent/file.mp3', []);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('file_unreadable', $error->errorCode);
        }
        self::assertSame([], $harness->api->requests);
    }

    public function testAnAccountWithNoBalanceStillSendsTheCreateAndSurfacesTheServers402(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/account', PipelineHarness::json(200, ['balance_seconds' => 0, 'held_seconds' => 4]));
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(402, ['error' => 'insufficient_balance', 'code' => 3001, 'needed_seconds' => 21, 'balance_seconds' => 0, 'held_seconds' => 4]));

        $error = self::failure($harness, InsufficientBalanceError::class);

        self::assertSame(402, $error->status);
        self::assertSame(['needed_seconds' => 21, 'balance_seconds' => 0, 'held_seconds' => 4], $error->details);
        self::assertSame(['GET /v1/account', 'POST /v1/uploads'], $harness->api->lines());
    }

    public function testAnUploadThatWouldPassTheDailyLimitIsRefused(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/account', PipelineHarness::json(200, ['balance_seconds' => 36000, 'limits' => ['max_daily_seconds' => 100, 'used_today_seconds' => 90]]));

        $error = self::failure($harness, DailyLimitError::class);

        self::assertSame('daily_limit', $error->errorCode);
        self::assertSame(['GET /v1/account'], $harness->api->lines());
    }

    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        yield 'every direct attempt fails' => ['exhausted'];
        yield 'storage sends no ETag' => ['etag_missing'];
        yield 'the part-urls refresh fails' => ['refresh_failed'];
        yield 'the job failed' => ['job_failed'];
    }

    #[DataProvider('failures')]
    public function testNoSignedUrlOrTokenSurfacesAnywhere(string $scenario): void
    {
        $fresh = ['part_size_bytes' => 1, 'total_parts' => 3, 'part_urls' => [['n' => 1, 'url' => PipelineHarness::url(1, '&fresh=1')], ['n' => 2, 'url' => PipelineHarness::url(2, '&fresh=1')], ['n' => 3, 'url' => PipelineHarness::url(3, '&fresh=1')]], 'next_part_number' => null];
        $harness = match ($scenario) {
            'etag_missing' => new PipelineHarness(script: static fn (): array => [new Response(200), 0.0]),
            'refresh_failed' => new PipelineHarness(script: static fn (array $request): array => [self::partNumber($request['url']) === 1 ? PipelineHarness::stored() : self::fail500(), 0.0]),
            'job_failed' => new PipelineHarness(script: static fn (): array => [PipelineHarness::stored(), 0.0]),
            default => new PipelineHarness(script: static fn (): array => [PartFailure::of(new \GuzzleHttp\Exception\ConnectException('cURL error 28 for '.PipelineHarness::url(1), new \GuzzleHttp\Psr7\Request('PUT', PipelineHarness::url(1)))), 0.0]),
        };
        $harness->api->on('GET', '/v1/uploads/job_1/part-urls', match ($scenario) {
            'refresh_failed' => static fn (RequestInterface $request): \Throwable => new \GuzzleHttp\Exception\ConnectException('cURL error 7 for '.$request->getUri(), $request),
            default => PipelineHarness::json(200, $fresh),
        });
        if ($scenario === 'job_failed') {
            $harness->api->on('GET', '/v1/jobs/[^/]+', PipelineHarness::json(200, ['id' => 'job_1', 'state' => 'failed', 'error' => 'boom', 'failure_kind' => 'x']));
        }
        // Kept in a static, not in the closure: what the caller's own callback captured is the caller's.
        self::$handedOut = new \ArrayObject();

        try {
            self::go($harness, ['on_resume_token' => static function (string $value): void {
                self::$handedOut->append($value);
            }]);
            self::fail('expected a failure');
        } catch (HushscriptException $error) {
            // The fakes keep the requests they saw; those are the test's own record, not the SDK's.
            $harness->parts->sent = [];
            $harness->api->requests = [];
            $surface = self::surface($error);
            $token = self::firstHandedOut();
            self::assertIsString($token, 'the create succeeded, so a resume token was handed out');
            foreach (['X-Amz-Signature', 'SECRET', \Hushscript\Tests\Support\Tokens::TEST, $token] as $needle) {
                $at = strpos($surface, $needle);
                self::assertFalse($at, $needle === $token ? 'the resume token' : $needle.' near: '.preg_replace('/[^\x20-\x7e]/', '.', substr($surface, max(0, (int) $at - 150), 300)));
            }
            self::assertSame($token, $error->resumeToken, 'the caller gets the token on the error itself');
        }
        $logs = implode("\n", $harness->logLines);
        self::assertStringNotContainsString('X-Amz-Signature', $logs);
        self::assertStringNotContainsString('SECRET', $logs);
        self::assertStringNotContainsString('r2.example.com', $logs);
        self::assertStringNotContainsString(\Hushscript\Tests\Support\Tokens::TEST, $logs);
    }


    public function testAFailingProxiedPartRaisesTheApiErrorItself(): void
    {
        $harness = new PipelineHarness(script: static fn (): array => [new Response(401, [], '{"error":"unauthorized","code":2000}'), 0.0]);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));

        $error = self::failure($harness, AuthenticationError::class);

        self::assertSame(401, $error->status);
        self::assertCount(1, $harness->parts->sent, 'a 401 is not retried');
    }

    public function testAProxiedPartThatKeepsFailingInTransitIsANetworkErrorAfterTheRetries(): void
    {
        $harness = new PipelineHarness(script: static fn (): array => [PartFailure::of(new \GuzzleHttp\Exception\ConnectException('connection reset '.\Hushscript\Tests\Support\Tokens::TEST, new \GuzzleHttp\Psr7\Request('PUT', PipelineHarness::BASE))), 0.0]);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));

        $error = self::failure($harness, NetworkError::class);

        self::assertCount(4, $harness->parts->sent);
        $summary = 'GuzzleHttp\\Exception\\ConnectException: connection reset hsr…[redacted]';
        self::assertSame('Could not reach the Hushscript API: PUT /v1/uploads/job_1/parts/1. '.$summary, $error->getMessage());
        $cause = $error->getPrevious();
        self::assertNotNull($cause);
        self::assertSame(\RuntimeException::class, $cause::class);
        self::assertSame($summary, $cause->getMessage());
    }

    /** @return iterable<string, array{bool}> */
    public static function partWays(): iterable
    {
        yield 'direct' => [false];
        yield 'through the API' => [true];
    }

    #[DataProvider('partWays')]
    public function testANonTransportErrorFromTheCallersClientOnAPartPutIsUnexpectedAndNotRetried(bool $proxied): void
    {
        $client = new class () implements \Psr\Http\Client\ClientInterface {
            /** @var list<string> */
            public array $paths = [];

            #[\Override]
            public function sendRequest(RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->paths[] = $request->getUri()->getPath();

                throw new \TypeError('boom '.\Hushscript\Tests\Support\Tokens::TEST.' at '.PipelineHarness::url(1));
            }
        };
        $harness = new PipelineHarness(transport: new \Hushscript\Transcribe\SyncTransport($client));
        if ($proxied) {
            $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));
        }

        $error = self::failure($harness);

        $summary = 'TypeError: boom hsr…[redacted] at https://r2.example.com/hushscript/job_1/1?[redacted]';
        self::assertSame(HushscriptException::class, $error::class);
        self::assertSame('unexpected_error', $error->errorCode);
        self::assertSame('The call failed with an error that is not a network failure; the request may have been sent. '.$summary, $error->getMessage());
        $cause = $error->getPrevious();
        self::assertNotNull($cause);
        self::assertSame(\RuntimeException::class, $cause::class);
        self::assertSame($summary, $cause->getMessage());
        self::assertNotNull($error->idempotencyKey);
        self::assertNotNull($error->resumeToken);
        // The parts already started each went out once; none was retried or sent the other way.
        self::assertSame(array_unique($client->paths), $client->paths);
        self::assertSame([], array_filter($client->paths, static fn (string $path): bool => str_starts_with($path, $proxied ? '/hushscript/' : '/v1/')));
        self::assertContains($proxied ? '/v1/uploads/job_1/parts/1' : '/hushscript/job_1/1', $client->paths);
        self::assertStringNotContainsString('SECRET', self::surface($error));
    }

    public function testARateLimitedProxiedPartWithALongRetryAfterRaisesAtOnce(): void
    {
        $harness = new PipelineHarness(script: static fn (): array => [new Response(429, ['Retry-After' => '61'], '{"error":"rate_limited","code":5000}'), 0.0]);
        $harness->api->on('POST', '/v1/uploads', PipelineHarness::json(200, $harness->createBody(proxied: true)));

        $error = self::failure($harness, RateLimitError::class);

        self::assertSame(61, $error->retryAfter);
        self::assertCount(1, $harness->parts->sent);
    }

    public function testAFatalPartCancelsTheRequestsStillInFlight(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, script: static fn (array $request): array => self::partNumber($request['url']) === 1 ? [new Response(200), 0.0] : [PipelineHarness::stored(), 500.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::failure($harness, UploadError::class);

        self::assertSame(2, $harness->parts->cancelled);
    }

    public function testTheMemoryBoundIsOnePartPerSlot(): void
    {
        $clock = new FakeIdleClock();
        $harness = new PipelineHarness(clock: $clock, totalParts: 12, script: static fn (): array => [PipelineHarness::stored(), 5.0]);
        $clock->nextEvent = $harness->parts->nextRelease(...);

        self::go($harness, ['concurrency' => 3]);

        self::assertSame(3, $harness->parts->maxPending);
    }

    public function testEveryUploadPartReachesTheTransportWithTheThreeHundredSecondTimeout(): void
    {
        $harness = new PipelineHarness();
        self::go($harness);
        self::assertCount(3, $harness->parts->sent);
        self::assertSame([300.0, 300.0, 300.0], array_column($harness->parts->sent, 'timeout'));
    }

    /** @return iterable<string, array{int, bool}> */
    public static function dailyBoundary(): iterable
    {
        yield 'exact limit' => [79, true];
        yield 'one second above' => [80, false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dailyBoundary')]
    public function testTheDailyLimitAllowsEqualityAndRefusesAnExcessBeforeCreate(int $used, bool $accepted): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/account', PipelineHarness::json(200, ['limits' => ['used_today_seconds' => $used, 'max_daily_seconds' => 100]]));
        if ($accepted) {
            self::go($harness, ['duration_seconds' => 21]);
            self::assertCount(1, $harness->api->to('POST', '/v1/uploads'));
        } else {
            try {
                self::go($harness, ['duration_seconds' => 21]);
                self::fail('expected daily_limit');
            } catch (HushscriptException $error) {
                self::assertSame(\Hushscript\Exception\DailyLimitError::class, $error::class);
                self::assertSame('daily_limit', $error->errorCode);
                self::assertNull($error->status);
                self::assertSame(['used_today_seconds' => 80, 'max_daily_seconds' => 100], $error->details);
                self::assertSame('This upload would pass your daily transcription limit (1 of 1 min used in the last 24 h).', $error->hint);
            }
            self::assertSame(['GET /v1/account'], $harness->api->lines());
            self::assertSame([], $harness->parts->sent);
        }
    }

    /** @return iterable<string, array{float|int, ?string, ?string}> */
    public static function durations(): iterable
    {
        yield 'too short' => [14.99, \Hushscript\Exception\FileLimitError::class, 'duration_out_of_range'];
        yield 'minimum' => [15, null, null];
        yield 'maximum' => [36000, null, null];
        yield 'too long' => [36000.01, \Hushscript\Exception\FileLimitError::class, 'duration_out_of_range'];
        yield 'NaN' => [NAN, \Hushscript\Exception\PreflightError::class, 'invalid_option'];
        yield 'infinity' => [INF, \Hushscript\Exception\PreflightError::class, 'invalid_option'];
        yield 'negative' => [-1, \Hushscript\Exception\PreflightError::class, 'invalid_option'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('durations')]
    public function testDurationBoundariesRunBeforeAnyRequest(float|int $seconds, ?string $class, ?string $code): void
    {
        $harness = new PipelineHarness();
        if ($class === null) {
            self::go($harness, ['duration_seconds' => $seconds]);
            $requests = $harness->api->to('POST', '/v1/uploads');
            self::assertCount(1, $requests);
            $body = json_decode((string) $requests[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame($seconds, $body['duration_seconds']);
        } else {
            try {
                self::go($harness, ['duration_seconds' => $seconds]);
                self::fail('expected duration error');
            } catch (\Hushscript\Exception\HushscriptException $error) {
                self::assertSame($class, $error::class);
                self::assertSame($code, $error->errorCode);
                self::assertNull($error->status);
            }
            self::assertSame([], $harness->api->requests);
            self::assertSame([], $harness->parts->sent);
        }
    }

    /** Three minutes of Retry-After on the poll, then a finished job: long enough for an attached heartbeat to ping. */
    private static function slowPoll(PipelineHarness $harness): void
    {
        $limited = static fn (): Response => new Response(429, ['Retry-After' => '60', 'Content-Type' => 'application/json'], '{"error":"rate_limited","code":1100}');
        $harness->api->on('GET', '/v1/jobs/job_1', [$limited(), $limited(), $limited(), PipelineHarness::json(200, PipelineHarness::jobBody())]);
    }

    public function testAnUploadRunsWithoutAWaitTicker(): void
    {
        $harness = new PipelineHarness(ticker: false);

        self::assertSame('tr_1', self::go($harness)->id);
        self::assertSame(['GET /v1/account', 'POST /v1/uploads', 'POST /v1/uploads/job_1/heartbeat', 'POST /v1/uploads/job_1/complete', 'GET /v1/jobs/job_1', 'GET /v1/transcripts/tr_1'], $harness->api->lines());
    }

    public function testTheHeartbeatNoLongerRidesOnSleepsOnceTheUploadIsDone(): void
    {
        $harness = new PipelineHarness();
        self::slowPoll($harness);

        self::go($harness);

        self::assertSame([60.0, 60.0, 60.0], $harness->clock->sleeps);
        self::assertCount(1, $harness->api->to('POST', '/v1/uploads/job_1/heartbeat'), 'only the ping before complete');
    }

    public function testTheHeartbeatNoLongerRidesOnSleepsAfterAFailedUpload(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads/job_1/complete', PipelineHarness::json(400, ['error' => 'bad_request', 'code' => 1000]));
        self::slowPoll($harness);
        try {
            self::go($harness);
            self::fail('expected the complete to fail');
        } catch (HushscriptException) {
        }

        $harness->jobsWait('job_1', []);

        self::assertSame([60.0, 60.0, 60.0], $harness->clock->sleeps);
        self::assertCount(1, $harness->api->to('POST', '/v1/uploads/job_1/heartbeat'), 'only the ping before complete');
    }

    public function testATokenRefusedOnThePingBeforeCompleteStopsTheComplete(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('POST', '/v1/uploads/job_1/heartbeat', PipelineHarness::json(401, ['error' => 'unauthorized', 'code' => 2000]));

        self::failure($harness, AuthenticationError::class);

        self::assertSame(['GET /v1/account', 'POST /v1/uploads', 'POST /v1/uploads/job_1/heartbeat'], $harness->api->lines());
    }
}
