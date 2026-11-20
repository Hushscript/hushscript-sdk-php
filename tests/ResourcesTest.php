<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Psr7\Response;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\PreflightError;
use Hushscript\Resource\Account;
use Hushscript\Resource\Jobs;
use Hushscript\Resource\Transcripts;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\PipelineHarness;
use Hushscript\Tests\Support\Rig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The friendly layer's reads: shapes, dates, ids, pagination (spec 6.3, 6.4). */
final class ResourcesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function row(int $n): array
    {
        return ['id' => sprintf('tr_%03d', $n), 'title' => 'T'.$n, 'language_detected' => null, 'duration_seconds' => 30, 'created_at' => 1760000000 + $n, 'auto_delete_at' => null, 'tags' => ['a']];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function page(array $rows, ?string $next): Response
    {
        return FakeHttp::json(200, ['transcripts' => $rows, 'next_cursor' => $next]);
    }

    public function testBalanceMinutesTruncateTowardZeroAndDatesAreUtc(): void
    {
        $http = new FakeHttp([
            FakeHttp::json(200, ['balance_seconds' => -90, 'held_seconds' => 5, 'credits_expire_at' => 1760000000]),
            FakeHttp::json(200, ['balance_seconds' => 119, 'held_seconds' => 0, 'credits_expire_at' => null, 'purchases_remaining_in_window' => 2, 'x_new' => 1]),
        ]);
        $account = new Account((new Rig($http))->exchange);

        $negative = $account->balance();
        $agent = $account->balance();

        self::assertSame(-90, $negative->seconds);
        self::assertSame(-1, $negative->minutes, 'toward zero, not floor');
        self::assertSame(5, $negative->heldSeconds);
        self::assertNull($negative->purchasesRemaining, 'absent means null');
        self::assertSame('2025-10-09T08:53:20+00:00', $negative->expiresAt?->format('c'));
        self::assertSame('UTC', $negative->expiresAt->getTimezone()->getName());
        self::assertSame(1, $agent->minutes);
        self::assertSame(2, $agent->purchasesRemaining);
        self::assertNull($agent->expiresAt);
    }

    public function testTheAccountCarriesItsLimits(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, [
            'balance_seconds' => 600, 'held_seconds' => 71, 'credits_expire_at' => 1760000300, 'purchases_remaining_in_window' => 2, 'card_on_file' => true, 'data_region' => 'eu',
            'limits' => ['max_daily_seconds' => 108000, 'max_active_jobs' => 3, 'active_jobs' => 1, 'used_today_seconds' => 60],
        ])]);

        $info = (new Account((new Rig($http))->exchange))->account();

        self::assertSame(600, $info->balanceSeconds);
        self::assertSame(71, $info->heldSeconds);
        self::assertSame(1760000300, $info->creditsExpireAt?->getTimestamp());
        self::assertSame('UTC', $info->creditsExpireAt->getTimezone()->getName());
        self::assertSame(3, $info->limits->maxActiveJobs);
        self::assertSame(60, $info->limits->usedTodaySeconds);
        self::assertTrue($info->cardOnFile);
        self::assertSame('eu', $info->dataRegion);
        self::assertSame(108000, $info->limits->maxDailySeconds);
        self::assertSame(1, $info->limits->activeJobs);
        self::assertSame(2, $info->purchasesRemainingInWindow);
    }

    public function testAnUnknownKeyNestedInAnAccountResponseStaysReachable(): void
    {
        $limits = ['max_daily_seconds' => 108000, 'max_active_jobs' => 3, 'active_jobs' => 1, 'used_today_seconds' => 60, 'future_limit' => ['burst' => [1, null]]];
        $http = new FakeHttp([FakeHttp::json(200, [
            'balance_seconds' => 600, 'held_seconds' => 71, 'credits_expire_at' => null, 'purchases_remaining_in_window' => 2, 'card_on_file' => true, 'data_region' => 'eu',
            'limits' => $limits,
        ])]);

        $info = (new Account((new Rig($http))->exchange))->account();

        self::assertSame($limits, $info->limits->toJson());
    }

    public function testAnUnknownKeyNestedInAJobResponseStaysReachable(): void
    {
        $options = ['language_code' => null, 'speaker_detection' => true, 'medical_mode' => false, 'vet_mode' => false, 'keep_disfluencies' => false, 'multichannel' => false, 'translation_targets' => [], 'keyterms' => false, 'prompt' => false, 'auto_generate_insights' => null, 'future_option' => ['mode' => ['fast', null]]];
        $body = PipelineHarness::jobBody(changes: ['options' => $options]);
        $http = new FakeHttp([FakeHttp::json(200, $body)]);

        $job = (new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline))->get('job_1');

        self::assertSame($options, $job->options->toJson());
        self::assertSame($body, $job->toJson());
    }

    public function testAListWalksEveryPageLazilyAndSendsTheCursor(): void
    {
        $http = new FakeHttp([self::page([self::row(1), self::row(2)], 'c2'), self::page([self::row(3)], 'c3'), self::page([self::row(4)], null)]);
        $list = (new Transcripts((new Rig($http))->exchange))->list(['page_size' => 2]);

        self::assertCount(0, $http->requests, 'nothing is sent before the first item is asked for');
        $ids = [];
        foreach ($list as $item) {
            $ids[] = $item->id;
            if ($item->id === 'tr_002') {
                self::assertCount(1, $http->requests, 'the next page is not fetched before it is needed');
            }
        }

        self::assertSame(['tr_001', 'tr_002', 'tr_003', 'tr_004'], $ids);
        self::assertSame(['limit=2', 'cursor=c2&limit=2', 'cursor=c3&limit=2'], self::queries($http));
    }

    /** @return list<string> */
    private static function queries(FakeHttp $http): array
    {
        return array_map(static fn (\Psr\Http\Message\RequestInterface $request): string => self::sorted($request->getUri()->getQuery()), $http->requests);
    }

    private static function sorted(string $query): string
    {
        $parts = explode('&', $query);
        sort($parts);

        return implode('&', $parts);
    }

    public function testAnEmptyNextCursorEndsTheWalk(): void
    {
        $http = new FakeHttp([self::page([self::row(1)], '')]);

        $items = iterator_to_array((new Transcripts((new Rig($http))->exchange))->list(), false);

        self::assertCount(1, $items);
        self::assertCount(1, $http->requests);
    }

    public function testACursorEqualToTheOneJustSentIsAPaginationLoopAfterItsItemsWereYielded(): void
    {
        $http = new FakeHttp([self::page([self::row(1)], 'same'), self::page([self::row(2)], 'same')]);
        /** @var list<string> $seen */
        $seen = [];

        try {
            foreach ((new Transcripts((new Rig($http))->exchange))->list() as $item) {
                $seen[] = $item->id;
            }
            self::fail('expected a pagination_loop');
        } catch (HushscriptException $error) {
            self::assertSame(HushscriptException::class, $error::class);
            self::assertSame('pagination_loop', $error->errorCode);
            self::assertNull($error->status);
        }

        self::assertSame(['tr_001', 'tr_002'], $seen);
        self::assertCount(2, $http->requests);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badOptions(): iterable
    {
        yield 'zero' => [['page_size' => 0]];
        yield 'negative' => [['page_size' => -1]];
        yield 'float' => [['page_size' => 1.5]];
        yield 'string' => [['page_size' => '5']];
        yield 'unknown' => [['limit' => 5]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('badOptions')]
    public function testABadPageSizeIsRefusedWhenTheListIsCalledNotWhenItIsIterated(array $options): void
    {
        $http = new FakeHttp();

        try {
            (new Transcripts((new Rig($http))->exchange))->list($options); // @phpstan-ignore argument.type
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }

        self::assertCount(0, $http->requests);
    }

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'invalid utf-8' => ["tr_\xB1"];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
    }

    #[DataProvider('badIds')]
    public function testAnUnusableIdIsRefusedBeforeAnyRequest(string $id): void
    {
        $http = new FakeHttp();
        $transcripts = new Transcripts((new Rig($http))->exchange);

        try {
            $transcripts->get($id);
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }

        self::assertCount(0, $http->requests);
    }

    public function testAnIdIsOneEncodedPathSegment(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, PipelineHarness::transcriptBody('a b?c'))]);

        $transcript = (new Transcripts((new Rig($http))->exchange))->get('a b?c');

        self::assertSame('/v1/transcripts/a%20b%3Fc', $http->requests[0]->getUri()->getPath());
        self::assertSame(20, $transcript->durationSeconds);
        self::assertSame('2025-10-09T08:53:20+00:00', $transcript->createdAt->format('c'));
        self::assertSame(['A' => 'Dana'], $transcript->speakers);
    }

    public function testADeletionRecordKeepsItsReceiptAsTheApiSentIt(): void
    {
        $receipt = ['deleted_at' => '2026-01-01T00:00:00Z', 'nested' => ['k' => 1]];
        $http = new FakeHttp([FakeHttp::json(200, ['deleted' => [
            ['id' => 'tr_1', 'title' => 'x', 'duration_seconds' => 5, 'created_at' => 1760000000, 'deleted_at' => 1760000100, 'cause' => 'future_cause', 'receipt' => $receipt],
            ['id' => 'tr_2', 'title' => 'y', 'duration_seconds' => 6, 'created_at' => 1760000000, 'deleted_at' => 1760000100, 'cause' => 'manual', 'receipt' => null],
        ], 'next_cursor' => null])]);

        $records = iterator_to_array((new Transcripts((new Rig($http))->exchange))->deleted(), false);

        self::assertSame($receipt, $records[0]->receipt);
        self::assertSame('future_cause', $records[0]->cause, 'an unknown cause passes through');
        self::assertNull($records[1]->receipt);
        self::assertSame(1760000100, $records[1]->deletedAt->getTimestamp());
    }

    public function testAnyTwoHundredSomethingWithAUsableBodySucceedsAndABadOneCarriesItsStatus(): void
    {
        $http = new FakeHttp([
            new Response(201, ['Content-Type' => 'application/json'], (string) json_encode(['balance_seconds' => 60, 'held_seconds' => 0])),
            new Response(202, ['Content-Type' => 'application/json'], '{"balance_seconds":"x","held_seconds":0}'),
        ]);
        $account = new Account((new Rig($http))->exchange);

        self::assertSame(1, $account->balance()->minutes);
        try {
            $account->balance();
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(202, $error->status);
            self::assertNull($error->numericCode);
            self::assertStringNotContainsString('"x"', $error->getMessage(), 'a value is never echoed');
        }
    }

    public function testJobsListSendsActiveAndWalksThePages(): void
    {
        $jobs = [PipelineHarness::jobBody('processing'), PipelineHarness::jobBody('queued', ['id' => 'job_2'])];
        $http = new FakeHttp([
            FakeHttp::json(200, ['jobs' => [$jobs[0]], 'next_cursor' => 'c2']),
            FakeHttp::json(200, ['jobs' => [$jobs[1]], 'next_cursor' => '']),
        ]);

        $listed = iterator_to_array((new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline))->list(['active' => true]), false);

        self::assertSame(['job_1', 'job_2'], array_map(static fn ($job): string => $job->id, $listed));
        self::assertSame('active=true', self::sorted($http->requests[0]->getUri()->getQuery()));
        self::assertSame('active=true&cursor=c2', self::sorted($http->requests[1]->getUri()->getQuery()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidJobsOptions(): iterable
    {
        yield 'unknown' => [['bogus' => true]];
        yield 'active string' => [['active' => 'yes']];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidJobsOptions')]
    public function testJobsListRefusesInvalidOptionsBeforeAnyRequest(array $options): void
    {
        $http = new FakeHttp();
        try {
            (new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline))->list($options);
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame([], $http->requests);
    }

    public function testJobsGetReturnsTheFullShapeAndPassesAnUnknownStateThrough(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, PipelineHarness::jobBody('reticulating', ['transcript_id' => 'tr_shape', 'declared_seconds' => 31, 'verified_seconds' => 32, 'actual_seconds' => 33, 'billable_seconds' => 34, 'retry_of' => 'job_original', 'error' => 'opaque failure', 'failure_kind' => 'media_unreadable', 'options' => ['language_code' => 'de', 'speaker_detection' => false, 'medical_mode' => true, 'vet_mode' => false, 'keep_disfluencies' => false, 'multichannel' => false, 'translation_targets' => ['es', 'fr'], 'keyterms' => true, 'prompt' => false, 'auto_generate_insights' => true], 'private_result_key' => 'x', 'fetch_url' => 'https://secret.example/']))]);

        $job = (new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline))->get('job_1');

        self::assertSame('job_1', $job->id);
        self::assertSame('tr_shape', $job->transcriptId);
        self::assertSame('opaque failure', $job->error);
        self::assertSame('media_unreadable', $job->failureKind);
        self::assertSame(31, $job->declaredSeconds);
        self::assertSame(32, $job->verifiedSeconds);
        self::assertSame(33, $job->actualSeconds);
        self::assertSame(34, $job->billableSeconds);
        self::assertSame('job_original', $job->retryOf);
        self::assertSame('de', $job->options->languageCode);
        self::assertFalse($job->options->speakerDetection);
        self::assertTrue($job->options->medicalMode);
        self::assertFalse($job->options->multichannel);
        self::assertSame(['es', 'fr'], $job->options->translationTargets);
        self::assertTrue($job->options->keyterms);
        self::assertFalse($job->options->prompt);
        self::assertTrue($job->options->autoGenerateInsights);
        self::assertSame('reticulating', $job->state);
        self::assertSame('standard', $job->mode);
        self::assertSame(1760000000, $job->createdAt->getTimestamp());
        self::assertStringNotContainsString('secret.example', print_r($job, true));
        self::assertStringNotContainsString('private_result', print_r($job, true));
    }

    public function testJobsGetRefusesAnUnusableIdBeforeAnyRequestAndAJobWithoutAStateIsBadResponse(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['id' => 'job_1'])]);
        $jobs = new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline);

        try {
            $jobs->get('..');
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
            self::assertCount(0, $http->requests);
        }
        try {
            $jobs->get('job_1');
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
        }
    }

    public function testJobsWaitReturnsAFailedJobInsteadOfRaising(): void
    {
        $harness = new PipelineHarness();
        $harness->api->on('GET', '/v1/jobs/job_1', PipelineHarness::json(200, PipelineHarness::jobBody('failed', ['error' => 'undecodable', 'failure_kind' => 'media_undecodable'])));

        $job = $harness->jobsWait('job_1', []);

        self::assertSame('failed', $job->state);
        self::assertSame('media_undecodable', $job->failureKind);
    }

    public function testJobsWaitReturnsADoneJob(): void
    {
        $harness = new PipelineHarness();

        $job = $harness->jobsWait('job_1', []);

        self::assertSame('done', $job->state);
        self::assertSame('tr_1', $job->transcriptId);
    }

    public function testOneTranscriptPageReturnsEverySummaryFieldAndTheNextCursor(): void
    {
        $row = ['id' => 'tr_page', 'title' => 'Quarterly review', 'language_detected' => 'fr', 'duration_seconds' => 137, 'created_at' => 1760000200, 'auto_delete_at' => 1760000800, 'tags' => ['finance', 'review']];
        $http = new FakeHttp([self::page([$row], 'next-page')]);
        $page = (new Transcripts((new Rig($http))->exchange))->page(['cursor' => 'previous-page', 'page_size' => 7]);

        self::assertCount(1, $page->items);
        self::assertSame('next-page', $page->nextCursor);
        self::assertSame(['cursor=previous-page&limit=7'], self::queries($http));
        $item = $page->items[0];
        self::assertSame('tr_page', $item->id);
        self::assertSame('Quarterly review', $item->title);
        self::assertSame('fr', $item->language);
        self::assertSame(137, $item->durationSeconds);
        self::assertSame(1760000200, $item->createdAt->getTimestamp());
        self::assertSame(1760000800, $item->autoDeleteAt?->getTimestamp());
        self::assertSame('UTC', $item->createdAt->getTimezone()->getName());
        self::assertSame(['finance', 'review'], $item->tags);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function repeatedPageCursors(): iterable
    {
        // An empty next cursor ends the list (as in the iterator), so an empty cursor sent and answered is no loop.
        foreach (['opaque' => 'same', 'spaces' => '   ', 'tab' => "\t", 'unicode' => "\u{00A0}"] as $name => $cursor) {
            yield $name.' with items' => [$cursor, true];
            yield $name.' without items' => [$cursor, false];
        }
    }

    #[DataProvider('repeatedPageCursors')]
    public function testOnePageRejectsTheNonNullCursorItWasSent(string $cursor, bool $withItems): void
    {
        $http = new FakeHttp([self::page($withItems ? [self::row(1)] : [], $cursor)]);

        try {
            (new Transcripts((new Rig($http))->exchange))->page(['cursor' => $cursor]);
            self::fail('expected pagination_loop');
        } catch (HushscriptException $error) {
            self::assertSame(HushscriptException::class, $error::class);
            self::assertSame('pagination_loop', $error->errorCode);
            self::assertNull($error->status);
            self::assertSame('The API returned the same page cursor it was sent; stopping instead of repeating it.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }

        self::assertCount(1, $http->requests);
        self::assertSame(['cursor='.rawurlencode($cursor)], self::queries($http));
    }

    /** @return iterable<string, array{?string, ?string}> */
    public static function advancingPageCursors(): iterable
    {
        yield 'initial null ends' => [null, null];
        yield 'initial empty ends' => [null, ''];
        yield 'initial advances' => [null, 'next'];
        yield 'empty sent ends with null' => ['', null];
        yield 'empty sent advances' => ['', 'next'];
        yield 'empty sent ends with empty' => ['', ''];
        yield 'opaque sent ends with null' => ['previous', null];
        yield 'opaque sent ends with empty' => ['previous', ''];
        yield 'opaque sent advances' => ['previous', 'next'];
        yield 'whitespace stays opaque' => [' ', "\t"];
    }

    #[DataProvider('advancingPageCursors')]
    public function testOnePageAcceptsAnInitialOrDifferentNextCursor(?string $cursor, ?string $next): void
    {
        $http = new FakeHttp([self::page([self::row(1)], $next)]);
        $page = (new Transcripts((new Rig($http))->exchange))->page(['cursor' => $cursor]);

        self::assertSame($next, $page->nextCursor);
        self::assertSame('tr_001', $page->items[0]->id);
        self::assertCount(1, $http->requests);
        self::assertSame([$cursor === null ? '' : 'cursor='.rawurlencode($cursor)], self::queries($http));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPageOptions(): iterable
    {
        yield 'cursor number' => [['cursor' => 5]];
        yield 'zero' => [['page_size' => 0]];
        yield 'negative' => [['page_size' => -1]];
        yield 'unknown' => [['foo' => 1]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalidPageOptions')]
    public function testInvalidPageOptionsSendNoRequest(array $options): void
    {
        $http = new FakeHttp();
        try {
            (new Transcripts((new Rig($http))->exchange))->page($options);
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame([], $http->requests);
    }

    public function testTranscriptMapsItsFieldsAndSelectsTranslationsWithAnOriginalFallback(): void
    {
        $body = PipelineHarness::transcriptBody('tr_shape');
        $body['title'] = 'Distinct title';
        $body['language_detected'] = 'en';
        $body['duration_seconds'] = 57;
        $body['auto_delete_at'] = 1760000500;
        $body['tags'] = ['first', 'second'];
        $body['edited'] = true;
        self::assertIsArray($body['body']);
        $body['body']['translations'] = [
            'es' => ['utterances' => [['speaker' => 'B', 'start_ms' => 1100, 'end_ms' => 2200, 'text' => 'hola']]],
            'cleaned' => ['utterances' => [['speaker' => 'A', 'start_ms' => 300, 'end_ms' => 900, 'text' => 'Hello cleaned']]],
            'fr' => ['utterances' => [['speaker' => '', 'start_ms' => 2300, 'end_ms' => 3400, 'text' => 'bonjour']]],
        ];
        $http = new FakeHttp([FakeHttp::json(200, $body)]);
        $transcript = (new Transcripts((new Rig($http))->exchange))->get('tr_shape');

        self::assertSame('tr_shape', $transcript->id);
        self::assertSame('Distinct title', $transcript->title);
        self::assertSame('en', $transcript->language);
        self::assertSame(57, $transcript->durationSeconds);
        self::assertSame(1760000000, $transcript->createdAt->getTimestamp());
        self::assertSame(1760000100, $transcript->updatedAt->getTimestamp());
        self::assertSame(1760000500, $transcript->autoDeleteAt?->getTimestamp());
        self::assertSame(['first', 'second'], $transcript->tags);
        self::assertTrue($transcript->edited);
        self::assertSame(['A' => 'Dana'], $transcript->speakers);
        self::assertSame(['es', 'fr'], $transcript->languages);
        self::assertNotNull($transcript->cleaned);
        self::assertSame('Hello cleaned', $transcript->cleaned[0]->text);
        self::assertSame('A', $transcript->cleaned[0]->speaker);
        self::assertSame('Dana', $transcript->cleaned[0]->speakerName);
        self::assertSame(300, $transcript->cleaned[0]->startMs);
        self::assertSame(900, $transcript->cleaned[0]->endMs);
        self::assertSame('A', $transcript->utterances[0]->speaker);
        self::assertSame('Dana', $transcript->utterances[0]->speakerName);
        self::assertSame(0, $transcript->utterances[0]->startMs);
        self::assertSame(1000, $transcript->utterances[0]->endMs);
        self::assertSame('hello', $transcript->utterances[0]->text);
        self::assertSame($transcript->cleaned, $transcript->utterancesFor('cleaned'));
        self::assertSame('hola', $transcript->utterancesFor('es')[0]->text);
        self::assertSame('B', $transcript->utterancesFor('es')[0]->speaker);
        self::assertSame('Speaker B', $transcript->utterancesFor('es')[0]->speakerName);
        self::assertSame(1100, $transcript->utterancesFor('es')[0]->startMs);
        self::assertSame(2200, $transcript->utterancesFor('es')[0]->endMs);
        self::assertSame('bonjour', $transcript->utterancesFor('fr')[0]->text);
        self::assertSame($transcript->utterances, $transcript->utterancesFor('missing'));
        self::assertSame($transcript->utterances, $transcript->utterancesFor(''));
        self::assertSame($transcript->utterances, $transcript->utterancesFor());
        self::assertSame('Dana: hello', $transcript->text());
        self::assertSame($transcript->toText(), $transcript->text());
        self::assertSame($body, $transcript->toJson());
    }

    #[DataProvider('badIds')]
    public function testJobsGetAndWaitRefuseBadIdsBeforeAnyRequest(string $id): void
    {
        foreach (['get', 'wait'] as $method) {
            $harness = new PipelineHarness();
            $http = new FakeHttp();
            $jobs = new Jobs((new Rig($http))->exchange, $harness->pipeline);
            try {
                if ($method === 'get') {
                    $jobs->get($id);
                } else {
                    $jobs->wait($id);
                }
                self::fail('expected invalid_option');
            } catch (PreflightError $error) {
                self::assertSame(PreflightError::class, $error::class);
                self::assertSame('invalid_option', $error->errorCode);
                self::assertNull($error->status);
            }
            self::assertSame([], $http->requests);
            self::assertSame([], $harness->api->requests);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function jobOptionFlags(): iterable
    {
        yield 'speakers' => ['speaker_detection', 'speakerDetection'];
        yield 'medical' => ['medical_mode', 'medicalMode'];
        yield 'vet' => ['vet_mode', 'vetMode'];
        yield 'keep disfluencies' => ['keep_disfluencies', 'keepDisfluencies'];
        yield 'multichannel' => ['multichannel', 'multichannel'];
        yield 'keyterms' => ['keyterms', 'keyterms'];
        yield 'prompt' => ['prompt', 'prompt'];
        yield 'auto insights' => ['auto_generate_insights', 'autoGenerateInsights'];
    }

    #[DataProvider('jobOptionFlags')]
    public function testEveryJobOptionFlagReadsItsOwnWireKey(string $wire, string $property): void
    {
        $flags = ['speaker_detection' => false, 'medical_mode' => false, 'vet_mode' => false, 'keep_disfluencies' => false, 'multichannel' => false, 'keyterms' => false, 'prompt' => false, 'auto_generate_insights' => false];
        $flags[$wire] = true;
        $http = new FakeHttp([FakeHttp::json(200, PipelineHarness::jobBody('queued', ['options' => [...$flags, 'language_code' => null, 'translation_targets' => []]]))]);
        $job = (new Jobs((new Rig($http))->exchange, (new PipelineHarness())->pipeline))->get('job_1');
        foreach (self::jobOptionFlags() as [$key, $name]) {
            $actual = match ($name) {
                'speakerDetection' => $job->options->speakerDetection,
                'medicalMode' => $job->options->medicalMode,
                'vetMode' => $job->options->vetMode,
                'keepDisfluencies' => $job->options->keepDisfluencies,
                'multichannel' => $job->options->multichannel,
                'keyterms' => $job->options->keyterms,
                'prompt' => $job->options->prompt,
                'autoGenerateInsights' => $job->options->autoGenerateInsights,
                default => self::fail('unknown job option '.$name),
            };
            self::assertSame($name === $property, $actual, $key);
        }
    }

}
