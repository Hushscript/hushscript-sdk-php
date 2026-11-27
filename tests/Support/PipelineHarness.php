<?php

declare(strict_types=1);

namespace Hushscript\Tests\Support;

use GuzzleHttp\Psr7\Response;
use Hushscript\Credentials;
use Hushscript\Http\Exchange;
use Hushscript\Http\RetryMiddleware;
use Hushscript\Http\WaitTicker;
use Hushscript\Log;
use Hushscript\Transcribe\PartFailure;
use Hushscript\Transcribe\PartTransport;
use Hushscript\Transcribe\Pipeline;

/**
 * A pipeline over a scripted API and scripted part transport, on a fake clock, with a real media
 * file from the conformance fixtures.
 */
final class PipelineHarness
{
    public const BASE = 'http://127.0.0.1:4010';

    public const JOB = 'job_1';

    public readonly FakeApi $api;

    public readonly FakePartTransport $parts;

    public readonly Pipeline $pipeline;

    public readonly Credentials $credentials;

    /** @var list<string> */
    public array $logLines = [];

    public readonly int $size;

    public readonly int $partSize;

    /**
     * @param  \Closure(array{method: string, url: string, authorization: string, length: int, headers: array<mixed>}, int): array{Response|PartFailure, float}|null  $script
     */
    public function __construct(
        public readonly FakeClock|FakeIdleClock $clock = new FakeClock(),
        public readonly string $file = 'cbr-20s.mp3',
        public readonly int $totalParts = 3,
        ?\Closure $script = null,
        int $maxRetries = 3,
        ?PartTransport $transport = null,
        bool $ticker = true,
    ) {
        $this->size = (int) filesize($this->path());
        $this->partSize = (int) ceil($this->size / $totalParts);
        $this->api = new FakeApi();
        $this->parts = new FakePartTransport($clock, $script ?? static fn (array $request): array => [self::stored(), 0.0]);
        $this->credentials = new Credentials(Tokens::TEST);
        $ticker = $ticker ? new WaitTicker() : null;
        $retry = new RetryMiddleware(
            $this->api,
            $this->credentials,
            self::BASE,
            $clock,
            new Log('debug', function (string $line): void {
                $this->logLines[] = $line;
            }),
            $maxRetries,
            jitter: static fn (float $ceiling): float => $ceiling,
            ticker: $ticker,
        );
        $api = new Exchange($retry, $this->credentials, self::BASE);
        $this->pipeline = new Pipeline(
            $api,
            $this->credentials,
            self::BASE,
            $clock,
            new Log('debug', function (string $line): void {
                $this->logLines[] = $line;
            }),
            $maxRetries,
            fn (): PartTransport => $transport ?? $this->parts,
            static fn (float $ceiling): float => $ceiling,
            $ticker,
        );
        $this->defaults();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function jobsWait(string $id, array $options): \Hushscript\Job
    {
        return $this->pipeline->wait($id, $options);
    }

    public function path(): string
    {
        return Conformance::dir().'/fixtures/media/'.$this->file;
    }

    public static function stored(string $etag = '"etag"'): Response
    {
        return new Response(200, ['ETag' => $etag]);
    }

    /**
     * @return array<string, mixed>
     */
    public function createBody(?int $parts = null, bool $proxied = false, string $state = 'uploading'): array
    {
        $parts ??= $this->totalParts;
        $urls = [];
        for ($n = 1; $n <= min(16, $parts); $n++) {
            $urls[] = ['n' => $n, 'url' => self::url($n)];
        }

        return [
            'job_id' => self::JOB,
            'state' => $state,
            'upload' => $proxied ? null : [
                'part_size_bytes' => $this->partSize,
                'total_parts' => $parts,
                'part_urls' => $urls,
                'next_part_number' => $parts > 16 ? 17 : null,
            ],
        ];
    }

    public static function url(int $n, string $suffix = ''): string
    {
        return 'https://r2.example.com/hushscript/'.self::JOB.'/'.$n.'?X-Amz-Signature=SECRET'.$n.$suffix;
    }

    /** @param array<mixed> $body */
    public static function json(int $status, array $body = []): Response
    {
        return FakeHttp::json($status, $body);
    }

    /**
     * A job as `GET /v1/jobs/{id}` sends it, with every field of the public `Job`.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public static function jobBody(string $state = 'done', array $changes = []): array
    {
        return [...[
            'id' => self::JOB,
            'state' => $state,
            'mode' => 'standard',
            'transcript_id' => $state === 'done' ? 'tr_1' : null,
            'error' => null,
            'failure_kind' => null,
            'retry_of' => null,
            'declared_seconds' => 20,
            'verified_seconds' => null,
            'actual_seconds' => null,
            'billable_seconds' => null,
            'created_at' => 1760000000,
            'options' => ['language_code' => null, 'speaker_detection' => true, 'medical_mode' => false, 'vet_mode' => false, 'keep_disfluencies' => false, 'multichannel' => false, 'translation_targets' => [], 'keyterms' => false, 'prompt' => false, 'auto_generate_insights' => null],
        ], ...$changes];
    }

    /**
     * A transcript as `GET /v1/transcripts/{id}` sends it.
     *
     * @return array<string, mixed>
     */
    public static function transcriptBody(string $id = 'tr_1'): array
    {
        return [
            'id' => $id,
            'title' => 'Call',
            'language_detected' => 'en',
            'duration_seconds' => 20,
            'speaker_names' => ['A' => 'Dana'],
            'tags' => [],
            'created_at' => 1760000000,
            'updated_at' => 1760000100,
            'auto_delete_at' => null,
            'edited' => false,
            'body' => ['original' => ['utterances' => [['speaker' => 'A', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'hello']]], 'translations' => new \stdClass()],
        ];
    }

    /** The routes of a happy path; add routes over them, the latest wins. */
    private function defaults(): void
    {
        $this->api
            ->on('GET', '/v1/account', fn (): Response => self::json(200, ['balance_seconds' => 36000, 'held_seconds' => 0, 'limits' => ['max_daily_seconds' => 108000, 'used_today_seconds' => 0]]))
            ->on('POST', '/v1/uploads', fn (): Response => self::json(200, $this->createBody()))
            ->on('POST', '/v1/uploads/[^/]+/heartbeat', fn (): Response => self::json(200, ['ok' => true]))
            ->on('POST', '/v1/uploads/[^/]+/complete', fn (): Response => self::json(200, ['ok' => true, 'state' => 'queued']))
            ->on('PUT', '/v1/uploads/[^/]+/parts/\d+', fn (): Response => self::json(200, ['ok' => true]))
            ->on('GET', '/v1/jobs/[^/]+', fn (): Response => self::json(200, self::jobBody()))
            ->on('GET', '/v1/transcripts/tr_1', fn (): Response => self::json(200, self::transcriptBody()));
    }
}
