<?php

declare(strict_types=1);

namespace Hushscript\Tests\Transcribe;

use Hushscript\Exception\PreflightError;
use Hushscript\Transcribe\Options;
use Hushscript\Transcribe\Preflight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalid(): iterable
    {
        yield 'unknown key' => [['durationSeconds' => 30]];
        yield 'concurrency zero' => [['concurrency' => 0]];
        yield 'concurrency negative' => [['concurrency' => -1]];
        yield 'concurrency nine' => [['concurrency' => 9]];
        yield 'concurrency float' => [['concurrency' => 1.5]];
        yield 'concurrency whole float' => [['concurrency' => 2.0]];
        yield 'concurrency string' => [['concurrency' => '2']];
        yield 'concurrency string four' => [['concurrency' => '4']];
        yield 'wait timeout zero' => [['wait_timeout' => 0]];
        yield 'wait timeout negative' => [['wait_timeout' => -1]];
        yield 'wait timeout NaN' => [['wait_timeout' => NAN]];
        yield 'wait timeout infinite' => [['wait_timeout' => INF]];
        yield 'duration negative' => [['duration_seconds' => -1]];
        yield 'key with a trailing newline' => [['idempotency_key' => "0123456789abcdef\n"]];
        yield 'key 15 characters' => [['idempotency_key' => str_repeat('a', 15)]];
        yield 'duration zero' => [['duration_seconds' => 0]];
        yield 'duration NaN' => [['duration_seconds' => NAN]];
        yield 'duration infinite' => [['duration_seconds' => INF]];
        yield 'duration string' => [['duration_seconds' => '30']];
        yield 'key too short' => [['idempotency_key' => 'short']];
        yield 'key with a space' => [['idempotency_key' => '0123456789abcdef 0123']];
        yield 'key too long' => [['idempotency_key' => str_repeat('a', 129)]];
        yield 'key with a control character' => [['idempotency_key' => "0123456789abcdef\x01"]];
        yield 'medical without a language' => [['medical' => true]];
        yield 'medical in the wrong language' => [['medical' => true, 'language' => 'ja']];
        yield 'vet without a language' => [['vet' => true]];
        yield 'vet in a language not listed' => [['vet' => true, 'language' => 'ja']];
        yield 'medical and vet together' => [['medical' => true, 'vet' => true, 'language' => 'en']];
        yield 'too many keyterms' => [['keyterms' => self::uniqueTerms(1001)]];
        yield 'a keyterm of 129 characters' => [['keyterms' => [str_repeat('a', 129)]]];
        yield 'a prompt of 2001 characters' => [['prompt' => str_repeat('a', 2001)]];
        yield 'a keyterm of seven words' => [['keyterms' => ['one two three four five six seven']]];
        yield 'keyterms not a list' => [['keyterms' => ['a' => 'b']]];
        yield 'matter code too long' => [['matter_code' => str_repeat('m', 65)]];
        yield 'title not a string' => [['title' => 5]];
        yield 'speakers not a bool' => [['speakers' => 'yes']];
        yield 'auto insights not a bool' => [['auto_insights' => 1]];
        yield 'resume token not callable' => [['on_resume_token' => 'no_such_function_here']];
        yield 'progress not callable' => [['on_progress' => 'no_such_function_here']];
        yield 'translate_to with a number' => [['translate_to' => ['es', 3]]];
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('invalid')]
    public function testAnInvalidOptionIsRefusedBeforeAnyRequest(array $options): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        try {
            $harness->pipeline->transcribe($harness->path(), $options);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame([], $harness->api->requests);
        self::assertSame([], $harness->parts->sent);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidMessages(): iterable
    {
        yield 'unknown' => [['durationSeconds' => 30], 'Unknown option "durationSeconds".'];
        yield 'concurrency' => [['concurrency' => 9], 'concurrency must be a whole number from 1 to 8.'];
        yield 'timeout' => [['wait_timeout' => 0], 'wait_timeout must be a positive number of seconds.'];
        yield 'duration' => [['duration_seconds' => 0], 'duration_seconds must be a positive number of seconds.'];
        yield 'size' => [['size' => 0], 'size must be a positive whole number of bytes.'];
        yield 'short key' => [['idempotency_key' => 'short'], 'idempotency_key must be 16 to 128 visible ASCII characters, no spaces.'];
        yield 'medical' => [['medical' => true], 'medical needs language to be one of en, en_au, en_uk, en_us, es, fr, de, de_ch.'];
        yield 'vet' => [['vet' => true], 'vet needs language to be one of en, en_au, en_uk, en_us, es, fr, de, de_ch.'];
        yield 'medical and vet' => [['medical' => true, 'vet' => true, 'language' => 'en'], 'medical and vet cannot be used together.'];
        yield 'term count' => [['keyterms' => self::uniqueTerms(1001)], 'keyterms takes up to 1000 unique terms.'];
        yield 'term words' => [['keyterms' => ['fine', 'one two three four five six seven']], 'keyterms[1] has more than 6 words.'];
        yield 'term length' => [['keyterms' => ['fine', str_repeat('a', 129)]], 'keyterms[1] is longer than 128 characters.'];
        yield 'prompt length' => [['prompt' => str_repeat('a', 2001)], 'prompt is longer than 2000 characters.'];
        yield 'term total words' => [['keyterms' => array_map(static fn (int $i): string => 't'.$i.' b c d e f', range(0, 166))], 'keyterms takes up to 1000 words in total (1002).'];
        yield 'matter' => [['matter_code' => str_repeat('m', 65)], 'matter_code must be at most 64 characters.'];
        yield 'auto insights' => [['auto_insights' => 1], 'auto_insights must be true, false or null.'];
        foreach (['title', 'language', 'prompt', 'matter_code', 'idempotency_key'] as $name) {
            yield $name.' type' => [[$name => 5], $name.' must be a string.'];
        }
        foreach (['speakers', 'medical', 'vet', 'keep_disfluencies', 'multichannel'] as $name) {
            yield $name.' type' => [[$name => 'yes'], $name.' must be true or false.'];
        }
        foreach (['keyterms', 'translate_to'] as $name) {
            yield $name.' list' => [[$name => 'term'], $name.' must be a list of strings.'];
            yield $name.' item' => [[$name => [5]], $name.' must be a list of strings.'];
        }
        foreach (['on_progress', 'on_resume_token'] as $name) {
            yield $name.' type' => [[$name => 'no_such_function_here'], $name.' must be callable.'];
        }
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('invalidMessages')]
    public function testInvalidOptionsExplainTheProblemWithoutSendingARequest(array $raw, string $message): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        try {
            $harness->pipeline->transcribe($harness->path(), $raw);
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertSame($message, $error->getMessage());
            self::assertNull($error->status);
        }
        self::assertSame([], $harness->api->requests);
        self::assertSame([], $harness->parts->sent);
    }

    public function testTheDefaults(): void
    {
        $options = Options::forTranscribe([]);

        self::assertSame(4, $options->concurrency);
        self::assertSame(3600.0, $options->waitTimeout);
        self::assertTrue($options->speakers);
        self::assertFalse($options->medical);
        self::assertFalse($options->multichannel);
        self::assertNull($options->autoInsights);
        self::assertNull($options->title);
        self::assertNull($options->language);
        self::assertNull($options->prompt);
        self::assertNull($options->matterCode);
        self::assertNull($options->durationSeconds);
        self::assertNull($options->size);
        self::assertNull($options->idempotencyKey);
        self::assertNull($options->onProgress);
        self::assertNull($options->onResumeToken);
        self::assertSame([], $options->translateTo);
        self::assertSame([], $options->keyterms);
        self::assertSame([], $options->transcriptionOptions());
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $options->newKey());
        self::assertNotSame($options->newKey(), $options->newKey());
    }

    public function testACallerKeyIsKept(): void
    {
        self::assertSame('0123456789abcdef', Options::forTranscribe(['idempotency_key' => '0123456789abcdef'])->newKey());
    }

    public function testUuidVersionAndVariantBitsAcrossGeneratedKeys(): void
    {
        for ($index = 0; $index < 64; $index++) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Options::uuid4());
        }
    }

    public function testGeneratedKeysKeepEveryRandomBitOutsideTheVersionAndVariant(): void
    {
        $keys = [];
        $versionLow = [];
        $variantLow = [];
        for ($index = 0; $index < 200; $index++) {
            $key = Options::uuid4();
            $keys[$key] = true;
            // Character 15 is the low nibble of the version byte, character 20 the low nibble of the variant byte.
            $versionLow[hexdec($key[15]) % 2] = true;
            $variantLow[hexdec($key[20]) % 2] = true;
        }

        self::assertCount(200, $keys);
        self::assertCount(2, $versionLow, 'the low bit of the version nibble is random');
        self::assertCount(2, $variantLow, 'the low bit of the variant nibble is random');
    }

    public function testAKeytermThatIsNotValidUtf8IsNotCountedAsWords(): void
    {
        self::assertSame(["\xff\xfe"], Options::forTranscribe(['keyterms' => ["\xff\xfe"]])->keyterms);
    }

    public function testFractionalTimeoutAndDurationArePreserved(): void
    {
        $options = Options::forTranscribe(['wait_timeout' => 0.25, 'duration_seconds' => 15.5, 'size' => 1]);

        self::assertSame(0.25, $options->waitTimeout);
        self::assertSame(15.5, $options->durationSeconds);
        self::assertSame(1, $options->size);
        self::assertSame(0.25, Options::forResume(['wait_timeout' => 0.25])->waitTimeout);
        self::assertSame(0.25, Options::forWait(['wait_timeout' => 0.25])->waitTimeout);
    }

    public function testUnicodeMatterCodeLimitCountsCharacters(): void
    {
        $code = str_repeat('界', 64);
        self::assertSame($code, Options::forTranscribe(['matter_code' => $code])->matterCode);
        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('matter_code must be at most 64 characters.');

        Options::forTranscribe(['matter_code' => $code.'界']);
    }

    public function testResumeTokenCallbackReceivesTheExactToken(): void
    {
        $seen = [];
        $options = Options::forTranscribe(['on_resume_token' => static function (string $token) use (&$seen): void {
            $seen[] = $token;
        }]);
        $options->resumeToken('resume-token');
        Options::forTranscribe([])->resumeToken('ignored');

        self::assertSame(['resume-token'], $seen);
        self::assertNull(Options::forTranscribe([])->progressSink());
    }

    public function testProgressSinkForwardsPollingEvents(): void
    {
        $seen = [];
        $options = Options::forWait(['on_progress' => static function (array $event) use (&$seen): void {
            $seen[] = $event;
        }]);
        $sink = $options->progressSink();
        self::assertNotNull($sink);
        $sink(['stage' => 'waiting', 'status' => 'processing']);

        self::assertSame([['stage' => 'waiting', 'status' => 'processing']], $seen);
    }

    public function testMedicalModeAcceptsEachSupportedLanguage(): void
    {
        foreach (['en', 'en_au', 'en_uk', 'en_us', 'es', 'fr', 'de', 'de_ch'] as $language) {
            self::assertSame(
                ['language_code' => $language, 'medical_mode' => true],
                Options::forTranscribe(['language' => $language, 'medical' => true])->transcriptionOptions(),
            );
        }
    }

    public function testVetModeAcceptsEachSupportedLanguage(): void
    {
        foreach (['en', 'en_au', 'en_uk', 'en_us', 'es', 'fr', 'de', 'de_ch'] as $language) {
            self::assertSame(
                ['language_code' => $language, 'vet_mode' => true],
                Options::forTranscribe(['language' => $language, 'vet' => true])->transcriptionOptions(),
            );
        }
    }

    public function testMedicalAndVetCompareTheLanguageAsTheApiNormalizesIt(): void
    {
        self::assertSame(
            ['language_code' => ' EN ', 'medical_mode' => true],
            Options::forTranscribe(['language' => ' EN ', 'medical' => true])->transcriptionOptions(),
        );
        self::assertSame(
            ['language_code' => 'De_CH', 'vet_mode' => true],
            Options::forTranscribe(['language' => 'De_CH', 'vet' => true])->transcriptionOptions(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function stt37Languages(): iterable
    {
        foreach (self::stt37() as $language) {
            yield $language => [$language];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function universal2Languages(): iterable
    {
        foreach ([
            'am', 'as', 'az', 'ba', 'be', 'bg', 'bn', 'bo', 'br', 'bs', 'cs', 'cy', 'el', 'eu', 'fo', 'gu', 'ha', 'haw',
            'hr', 'ht', 'hu', 'hy', 'id', 'is', 'jw', 'ka', 'kk', 'km', 'kn', 'la', 'lb', 'ln', 'lo', 'lt', 'lv', 'mg',
            'mi', 'mk', 'ml', 'mn', 'ms', 'mt', 'my', 'ne', 'oc', 'pa', 'pl', 'ps', 'sa', 'sd', 'si', 'sk', 'sl', 'sn',
            'so', 'sq', 'sr', 'su', 'sw', 'ta', 'te', 'tg', 'th', 'tk', 'tl', 'tt', 'uk', 'uz', 'yi', 'yo',
        ] as $language) {
            yield $language => [$language];
        }
    }

    /** @return list<string> */
    private static function stt37(): array
    {
        return [
            'af', 'ar', 'ca', 'da', 'de', 'de_ch', 'en', 'en_au', 'en_uk', 'en_us', 'es', 'et', 'fa', 'fi', 'fr', 'gl',
            'he', 'hi', 'it', 'ja', 'ko', 'mr', 'nl', 'nn', 'no', 'pt', 'ro', 'ru', 'sv', 'tr', 'ur', 'vi', 'zh',
        ];
    }

    /** @param array<string, mixed> $raw */
    private static function contextRefusal(array $raw): PreflightError
    {
        try {
            Options::forTranscribe($raw);
        } catch (PreflightError $error) {
            return $error;
        }
        self::fail('expected invalid_option');
    }

    private static function contextMessage(string $option, string $language): string
    {
        return $option.' does not work for language "'.$language.'"; it needs language to be one of '.implode(', ', self::stt37()).'.';
    }

    #[DataProvider('universal2Languages')]
    public function testKeytermsAreRefusedInEachUniversal2Language(string $language): void
    {
        $error = self::contextRefusal(['keyterms' => ['Hushscript'], 'language' => $language]);

        self::assertSame('invalid_option', $error->errorCode);
        self::assertNull($error->status);
        self::assertSame(self::contextMessage('keyterms', $language), $error->getMessage());
    }

    #[DataProvider('universal2Languages')]
    public function testPromptIsRefusedInEachUniversal2Language(string $language): void
    {
        $error = self::contextRefusal(['prompt' => 'Names.', 'language' => $language]);

        self::assertSame('invalid_option', $error->errorCode);
        self::assertNull($error->status);
        self::assertSame(self::contextMessage('prompt', $language), $error->getMessage());
    }

    #[DataProvider('stt37Languages')]
    public function testKeytermsAndPromptAreAcceptedInEachStt37Language(string $language): void
    {
        self::assertSame(
            ['language_code' => $language, 'keyterms_prompt' => ['Hushscript'], 'prompt' => 'Names.'],
            Options::forTranscribe(['keyterms' => ['Hushscript'], 'prompt' => 'Names.', 'language' => $language])->transcriptionOptions(),
        );
    }

    public function testTheKeytermMessageNamesTheNormalizedLanguageWhenBothAreSet(): void
    {
        $error = self::contextRefusal(['keyterms' => ['x'], 'prompt' => 'Names.', 'language' => ' PL ']);

        self::assertSame(self::contextMessage('keyterms', 'pl'), $error->getMessage());
    }

    public function testAPromptIsRefusedNextToBlankKeyterms(): void
    {
        $error = self::contextRefusal(['keyterms' => [' ', ''], 'prompt' => 'Names.', 'language' => 'th']);

        self::assertSame(self::contextMessage('prompt', 'th'), $error->getMessage());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function contextWithoutRefusal(): iterable
    {
        yield 'blank key terms and a blank prompt' => [['keyterms' => [' ', ''], 'prompt' => '  ']];
        yield 'an empty key term list' => [['keyterms' => []]];
        yield 'no context, with keep_disfluencies' => [['keep_disfluencies' => true]];
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('contextWithoutRefusal')]
    public function testBlankContextIsNotRefusedInAUniversal2Language(array $raw): void
    {
        self::assertSame('pl', Options::forTranscribe($raw + ['language' => 'pl'])->language);
    }

    public function testKeytermsAndPromptAreAcceptedUnderAutoDetect(): void
    {
        self::assertSame(
            ['keyterms_prompt' => ['Hushscript'], 'prompt' => 'Names.'],
            Options::forTranscribe(['keyterms' => ['Hushscript'], 'prompt' => 'Names.'])->transcriptionOptions(),
        );
    }

    public function testAnUnlistedLanguageIsLeftToTheServer(): void
    {
        self::assertSame(
            ['language_code' => 'xx', 'keyterms_prompt' => ['Hushscript']],
            Options::forTranscribe(['keyterms' => ['Hushscript'], 'language' => 'xx'])->transcriptionOptions(),
        );
    }

    public function testKeytermWordsAreSeparatedByUnicodeWhitespace(): void
    {
        $six = "one\ttwo\nthree\u{2003}four five six";
        self::assertSame([$six], Options::forTranscribe(['keyterms' => [$six]])->keyterms);
        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('keyterms[0] has more than 6 words.');

        Options::forTranscribe(['keyterms' => [$six.' seven']]);
    }

    /** @return iterable<string, array{string}> */
    public static function whitespaceCharacters(): iterable
    {
        foreach ([0x9, 0xA, 0xB, 0xC, 0xD, 0x20, 0x85, 0xA0, 0x1680, 0x2000, 0x2005, 0x200A, 0x2028, 0x2029, 0x202F, 0x205F, 0x3000] as $codePoint) {
            yield sprintf('U+%04X', $codePoint) => [mb_chr($codePoint, 'UTF-8')];
        }
    }

    #[DataProvider('whitespaceCharacters')]
    public function testUnicodeWhitespaceIsTrimmedAndSplitsWords(string $space): void
    {
        $options = Options::forTranscribe(['keyterms' => [$space.'two'.$space.'words'.$space, $space.$space], 'prompt' => $space.'Context'.$space]);

        self::assertSame(['two'.$space.'words'], $options->keyterms);
        self::assertSame('Context', $options->prompt);
        $six = Options::forTranscribe(['keyterms' => [implode($space, ['a', 'b', 'c', 'd', 'e', 'f'])]]);
        self::assertCount(1, $six->keyterms);
        $this->expectException(PreflightError::class);
        Options::forTranscribe(['keyterms' => [implode($space, ['a', 'b', 'c', 'd', 'e', 'f', 'g'])]]);
    }

    public function testCharactersOutsideUnicodeWhiteSpaceAreNotTrimmed(): void
    {
        foreach ([0x200B, 0x180E, 0xFEFF, 0x2060] as $codePoint) {
            $term = 'x'.mb_chr($codePoint, 'UTF-8');
            self::assertSame([$term], Options::forTranscribe(['keyterms' => [$term]])->keyterms);
        }
    }

    public function testALongWhitespaceRunIsTrimmedWithoutBlowingTheRegexEngine(): void
    {
        $run = str_repeat("\u{3000}", 200000);
        $started = microtime(true);
        $options = Options::forTranscribe(['keyterms' => [$run.'term'.$run], 'prompt' => $run]);

        self::assertSame(['term'], $options->keyterms);
        self::assertNull($options->prompt);
        self::assertLessThan(5.0, microtime(true) - $started);
    }

    public function testATermIsCountedInCodePointsNotBytesOrUtf16Units(): void
    {
        $limit = self::limit('MAX_KEYTERM_CHARS');
        foreach (['a', "\u{E9}", "\u{1F600}", "\u{4E2D}"] as $character) {
            self::assertSame([str_repeat($character, $limit)], Options::forTranscribe(['keyterms' => [str_repeat($character, $limit)]])->keyterms);
        }
        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('keyterms[0] is longer than '.$limit.' characters.');

        Options::forTranscribe(['keyterms' => [str_repeat("\u{1F600}", $limit + 1)]]);
    }

    public function testATermOverTheLimitIsReportedAtItsIndex(): void
    {
        $limit = self::limit('MAX_KEYTERM_CHARS');
        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('keyterms[2] is longer than '.$limit.' characters.');

        Options::forTranscribe(['keyterms' => ['ok', '   ', ' '.str_repeat('a', $limit + 1).' ']]);
    }

    public function testTheLengthLimitAppliesAfterTrimming(): void
    {
        $term = str_repeat('a', self::limit('MAX_KEYTERM_CHARS'));

        self::assertSame([$term], Options::forTranscribe(['keyterms' => ["  \u{3000}".$term." \n"]])->keyterms);
    }

    public function testRepeatedTermsCountOnce(): void
    {
        $limit = self::limit('MAX_KEYTERMS');
        $terms = array_merge(self::uniqueTerms($limit), array_fill(0, 50, 'term0'), [' term1 ']);
        $options = Options::forTranscribe(['keyterms' => $terms]);

        self::assertSame(self::uniqueTerms($limit), $options->keyterms);
        self::assertSame(self::uniqueTerms($limit), $options->transcriptionOptions()['keyterms_prompt']);
        $this->expectException(PreflightError::class);
        Options::forTranscribe(['keyterms' => array_merge($terms, ['one more'])]);
    }

    public function testBlankTermsDoNotCountTowardTheLimit(): void
    {
        $limit = self::limit('MAX_KEYTERMS');
        $terms = array_merge(self::uniqueTerms($limit), array_fill(0, 10, " \t"));

        self::assertCount($limit, Options::forTranscribe(['keyterms' => $terms])->keyterms);
    }

    public function testThePromptIsCappedAtTheLimit(): void
    {
        $limit = self::limit('MAX_PROMPT_CHARS');
        foreach (['a', "\u{1F600}"] as $character) {
            self::assertSame(str_repeat($character, $limit), Options::forTranscribe(['prompt' => str_repeat($character, $limit)])->prompt);
        }
        self::assertSame(str_repeat('a', $limit), Options::forTranscribe(['prompt' => ' '.str_repeat('a', $limit)."\u{2003}"])->prompt);
        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('prompt is longer than '.$limit.' characters.');

        Options::forTranscribe(['prompt' => str_repeat('a', $limit + 1)]);
    }

    public function testThePromptIsCheckedAloneNotWithTheTerms(): void
    {
        $limit = self::limit('MAX_PROMPT_CHARS');
        $terms = array_map(static fn (int $i): string => str_repeat('k', 120).$i, range(0, 999));

        $options = Options::forTranscribe(['keyterms' => $terms, 'prompt' => str_repeat('a', $limit)]);

        self::assertCount(1000, $options->keyterms);
        self::assertSame(str_repeat('a', $limit), $options->prompt);
        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('prompt is longer than '.$limit.' characters.');

        Options::forTranscribe(['keyterms' => ['x'], 'prompt' => str_repeat('a', $limit + 1)]);
    }

    public function testTheTermsAreCappedInWordsInTotal(): void
    {
        $limit = self::limit('MAX_KEYTERM_TOTAL_WORDS');
        $fit = array_merge(array_map(static fn (int $i): string => 'a'.$i.' b c d e f', range(0, 165)), ['x y z h']);
        $words = array_sum(array_map(static fn (string $term): int => substr_count($term, ' ') + 1, $fit));
        self::assertSame($limit, $words);

        self::assertSame($fit, Options::forTranscribe(['keyterms' => $fit])->keyterms);
        try {
            Options::forTranscribe(['keyterms' => array_merge($fit, ['one'])]);
            self::fail('The terms are one word over.');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
            self::assertSame('keyterms takes up to '.$limit.' words in total ('.($limit + 1).').', $error->getMessage());
        }
    }

    public function testTheWordTotalCountsTrimmedDistinctTermsByUnicodeWhitespace(): void
    {
        $limit = self::limit('MAX_KEYTERM_TOTAL_WORDS');
        $terms = array_map(static fn (int $i): string => 'a'.$i." b\u{3000}c \u{A0}d\u{2003}e f", range(0, 165));
        $terms[] = ' x y z h ';
        $repeats = array_merge($terms, $terms, ['', " \t"]);

        self::assertCount(167, Options::forTranscribe(['keyterms' => $repeats])->keyterms);
        $this->expectException(PreflightError::class);
        $this->expectExceptionMessage('in total ('.($limit + 1).')');

        Options::forTranscribe(['keyterms' => array_merge($repeats, ['one'])]);
    }

    public function testBlankTermsAndAPromptAddNothingToTheWordTotal(): void
    {
        $terms = array_merge(array_map(static fn (int $i): string => 't'.$i, range(0, 999)), [' ', "\t"]);

        self::assertCount(1000, Options::forTranscribe(['keyterms' => $terms, 'prompt' => str_repeat('p ', 900)])->keyterms);
    }

    public function testEmptyAndBlankOptionsAreNotSent(): void
    {
        foreach ([
            [],
            ['keyterms' => []],
            ['keyterms' => ['', "  \u{3000}"]],
            ['prompt' => ''],
            ['prompt' => " \n\u{2003}"],
            ['prompt' => null],
        ] as $raw) {
            $options = Options::forTranscribe($raw);
            self::assertSame([], $options->transcriptionOptions());
            self::assertNull($options->prompt);
            self::assertSame([], $options->keyterms);
        }
    }

    public function testNormalizedTermsAndPromptReachTheRequestBody(): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        $harness->pipeline->transcribe($harness->path(), ['keyterms' => [' Hushscript ', '', "\u{3000}", 'Hushscript', 'two  words'], 'prompt' => "\n A meeting \u{2003}"]);
        $body = json_decode((string) $harness->api->to('POST', '/v1/uploads')[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame(['keyterms_prompt' => ['Hushscript', 'two  words'], 'prompt' => 'A meeting'], $body['transcription_options']);
    }

    public function testBlankOptionsAreAbsentFromTheRequestBody(): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        $harness->pipeline->transcribe($harness->path(), ['keyterms' => [' '], 'prompt' => '  ']);
        $body = json_decode((string) $harness->api->to('POST', '/v1/uploads')[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('transcription_options', $body);
    }

    /** @return list<string> */
    private static function uniqueTerms(int $count): array
    {
        return array_map(static fn (int $i): string => 'term'.$i, range(0, $count - 1));
    }

    private static function limit(string $name): int
    {
        $value = (new \ReflectionClassConstant(Options::class, $name))->getValue();
        self::assertIsInt($value);

        return $value;
    }

    public function testTheOptionsBecomeTheApiBody(): void
    {
        $options = Options::forTranscribe([
            'title' => 'Board call',
            'language' => 'de',
            'speakers' => false,
            'keyterms' => ['Hushscript', 'two words'],
            'prompt' => 'A meeting',
            'medical' => true,
            'keep_disfluencies' => true,
            'multichannel' => true,
            'auto_insights' => false,
            'matter_code' => 'M-1',
        ]);

        self::assertSame(
            [
                'language_code' => 'de',
                'speaker_detection' => false,
                'keyterms_prompt' => ['Hushscript', 'two words'],
                'prompt' => 'A meeting',
                'medical_mode' => true,
                'keep_disfluencies' => true,
                'multichannel' => true,
                'auto_generate_insights' => false,
            ],
            $options->transcriptionOptions(),
        );
        self::assertSame(
            ['size_bytes' => 10, 'duration_seconds' => 21, 'idempotency_key' => 'k', 'title' => 'Board call', 'matter_code' => 'M-1', 'transcription_options' => $options->transcriptionOptions()],
            Preflight::createBody(10, 21, $options, 'k'),
        );
    }

    public function testTranslationDoesNotChangeSpeakerDetection(): void
    {
        $off = Options::forTranscribe(['speakers' => false, 'translate_to' => ['es', 'fr']])->transcriptionOptions();
        $on = Options::forTranscribe(['translate_to' => ['es', 'fr']])->transcriptionOptions();

        self::assertSame(['speaker_detection' => false, 'translation_targets' => ['es', 'fr']], $off);
        self::assertSame(['translation_targets' => ['es', 'fr']], $on);
    }

    public function testFifteenDistinctTranslationTargetsAreAccepted(): void
    {
        $targets = ['es', 'fr', 'de', 'it', 'pt', 'nl', 'af', 'sq', 'am', 'ar', 'hy', 'as', 'az', 'ba', 'eu'];

        self::assertSame($targets, Options::forTranscribe(['translate_to' => $targets])->translateTo);
        $repeated = [...$targets, ' ES ', 'Fr', ' '];
        self::assertCount(18, Options::forTranscribe(['translate_to' => $repeated])->translateTo);
    }

    public function testSixteenDistinctTranslationTargetsAreRefused(): void
    {
        $targets = ['es', 'fr', 'de', 'it', 'pt', 'nl', 'af', 'sq', 'am', 'ar', 'hy', 'as', 'az', 'ba', 'eu', 'be'];

        try {
            Options::forTranscribe(['translate_to' => $targets]);
            self::fail('expected a PreflightError');
        } catch (PreflightError $error) {
            self::assertSame('invalid_option', $error->errorCode);
            self::assertSame('translate_to takes up to 15 distinct languages.', $error->getMessage());
        }
    }

    public function testResumeAndWaitTakeTheirOwnOptionsOnly(): void
    {
        self::assertSame(2, Options::forResume(['concurrency' => 2])->concurrency);
        self::assertSame(9.0, Options::forWait(['wait_timeout' => 9])->waitTimeout);
        foreach ([fn () => Options::forResume(['title' => 'x']), fn () => Options::forWait(['concurrency' => 2])] as $call) {
            try {
                $call();
                self::fail('expected a PreflightError');
            } catch (PreflightError $error) {
                self::assertSame(PreflightError::class, $error::class);
                self::assertSame('invalid_option', $error->errorCode);
                self::assertNull($error->status);
            }
        }
    }

    public function testProgressReachesTheCallback(): void
    {
        $seen = [];
        $options = Options::forTranscribe(['on_progress' => static function (array $progress) use (&$seen): void {
            $seen[] = $progress;
        }]);

        $options->progress(['stage' => 'uploading']);
        Options::forTranscribe([])->progress(['stage' => 'ignored']);

        self::assertSame([['stage' => 'uploading']], $seen);
    }

    public function testADumpOfTheOptionsShowsNoCallback(): void
    {
        $secret = 'captured-resume-token-value';
        $options = Options::forTranscribe([
            'on_progress' => static function (array $event) use ($secret): void {
                $held = [$event, $secret];
                unset($held);
            },
            'on_resume_token' => static function (string $token) use ($secret): void {
                $held = [$token, $secret];
                unset($held);
            },
        ]);

        $dump = print_r($options, true);
        ob_start();
        var_dump($options);
        $dump .= (string) ob_get_clean();

        self::assertStringNotContainsString('Closure', $dump);
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringContainsString('…[callback]', $dump);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function acceptedBoundaries(): iterable
    {
        yield 'concurrency one' => [['concurrency' => 1]];
        yield 'concurrency eight' => [['concurrency' => 8]];
        yield '1000 keyterms' => [['keyterms' => self::uniqueTerms(1000)]];
        yield '128 character keyterm' => [['keyterms' => [str_repeat('a', 128)]]];
        yield 'six words' => [['keyterms' => ['one two three four five six']]];
        yield '64 character matter code' => [['matter_code' => str_repeat('m', 64)]];
        yield '128 character key' => [['idempotency_key' => str_repeat('k', 128)]];
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('acceptedBoundaries')]
    public function testAcceptedOptionBoundariesReachCreateUnchanged(array $raw): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        $harness->pipeline->transcribe($harness->path(), $raw);
        $create = $harness->api->to('POST', '/v1/uploads');
        self::assertCount(1, $create);
        $body = json_decode((string) $create[0]->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $options = Options::forTranscribe($raw);
        self::assertSame($raw['concurrency'] ?? 4, $options->concurrency);
        self::assertSame($raw['keyterms'] ?? [], $options->keyterms);
        self::assertSame($raw['matter_code'] ?? null, $options->matterCode);
        if (isset($raw['idempotency_key'])) {
            self::assertSame($raw['idempotency_key'], $body['idempotency_key']);
        }
        if (isset($raw['keyterms'])) {
            self::assertIsArray($body['transcription_options']);
            self::assertSame($raw['keyterms'], $body['transcription_options']['keyterms_prompt']);
        }
        if (isset($raw['matter_code'])) {
            self::assertSame($raw['matter_code'], $body['matter_code']);
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidOtherOptions(): iterable
    {
        foreach (['resume', 'wait'] as $operation) {
            yield $operation.' unknown' => [$operation, ['unknown' => 1]];
        }
        foreach ([0, -1, 9, 1.5, '2'] as $index => $concurrency) {
            yield 'resume concurrency '.$index => ['resume', ['concurrency' => $concurrency]];
        }
    }

    /** @param array<string, mixed> $raw */
    #[DataProvider('invalidOtherOptions')]
    public function testResumeAndWaitRejectInvalidOptionsWithoutRequests(string $operation, array $raw): void
    {
        $harness = new \Hushscript\Tests\Support\PipelineHarness();
        try {
            if ($operation === 'resume') {
                $token = \Hushscript\Transcribe\Resume::state(\Hushscript\Tests\Support\PipelineHarness::JOB, 'key-1', $harness->size, 20)->encode();
                $harness->pipeline->resume($token, null, $raw);
            } else {
                $harness->pipeline->wait('job_1', $raw);
            }
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame([], $harness->api->requests);
        self::assertSame([], $harness->parts->sent);
    }
}
