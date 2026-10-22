<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Psr7\Request;
use Hushscript\Client;
use Hushscript\Config;
use Hushscript\Credentials;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Redact;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\SdkFrames;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\TestCase;

final class RedactionTest extends TestCase
{
    private const TAIL = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

    /** @return list<string> every representation PHP can print or encode for a value */
    private static function dumps(mixed $value): array
    {
        if ($value instanceof \Throwable) {
            SdkFrames::only($value);
        }
        ob_start();
        var_dump($value);
        $dump = (string) ob_get_clean();

        return [
            print_r($value, true),
            var_export($value, true),
            $dump,
            (string) json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR),
        ];
    }

    private static function assertClean(string $text, string $where): void
    {
        self::assertStringNotContainsString(Tokens::TEST, $text, $where);
        self::assertStringNotContainsString(self::TAIL, $text, $where);
        self::assertStringNotContainsString('0123456789abcdef0123456789abcdef', $text, $where);
        self::assertStringNotContainsString('Bearer hsr', str_replace(Redact::MARKER, '', $text), $where);
    }

    /** @param list<string> $texts */
    private static function assertAllClean(array $texts, string $where): void
    {
        foreach ($texts as $index => $text) {
            self::assertClean($text, "{$where} representation {$index}");
        }
    }

    public function testNoRepresentationOfTheClientShowsTheToken(): void
    {
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:4010']);

        self::assertAllClean(self::dumps($client), 'client');
        self::assertAllClean(self::dumps($client->api()), 'api');
        self::assertAllClean([(string) json_encode($client), print_r($client, true)], 'client json');
    }

    public function testDebugInfoIsRedacted(): void
    {
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:4010']);
        $info = $client->__debugInfo();

        self::assertSame(Redact::MARKER, $info['token']);
        self::assertSame('http://127.0.0.1:4010', $info['baseUrl']);
    }

    public function testNoRepresentationOfTheConfigShowsTheToken(): void
    {
        $config = Config::resolve(Tokens::TEST, ['base_url' => 'http://127.0.0.1:4010'], []);

        self::assertAllClean(self::dumps($config), 'config');
        self::assertAllClean(self::dumps($config->credentials), 'credentials');
        self::assertSame(Redact::MARKER, $config->__debugInfo()['token']);
        self::assertSame(Redact::MARKER, $config->credentials->__debugInfo()['token']);
    }

    public function testSerializingAnythingThatCouldHoldTheTokenThrows(): void
    {
        $client = new Client(Tokens::TEST, ['base_url' => 'http://127.0.0.1:4010']);
        $config = Config::resolve(Tokens::TEST, [], []);

        foreach ([$client, $client->api(), $config, $config->credentials] as $object) {
            try {
                serialize($object);
                self::fail('serialize() must throw for '.$object::class);
            } catch (\LogicException $error) {
                self::assertClean($error->getMessage(), 'serialize');
            }
        }
    }

    public function testUnserializingIsRefused(): void
    {
        foreach ([Client::class, Config::class, Credentials::class, \Hushscript\Api::class] as $class) {
            try {
                unserialize('O:'.strlen($class).':"'.$class.'":0:{}');
                self::fail('unserialize() must throw for '.$class);
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCredentialsCannotBeCloned(): void
    {
        $this->expectException(\LogicException::class);
        clone new Credentials(Tokens::TEST);
    }

    public function testAnEchoedTokenNeverReachesAnErrorOrItsTrace(): void
    {
        $echo = ['error' => 'unauthorized', 'code' => 2000, 'details' => ['token' => Tokens::TEST, 'tail' => self::TAIL, 'list' => [Tokens::TEST]]];
        $rig = new Rig(new FakeHttp([FakeHttp::json(401, $echo)]));

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertAllClean(self::dumps($error), 'error');
            self::assertAllClean([$error->getMessage(), (string) $error->hint, $error->getTraceAsString(), (string) $error], 'error text');
            self::assertAllClean(self::dumps($error->details), 'details');
            self::assertAllClean(self::dumps($error->getTrace()), 'trace');
        }
    }

    public function testAFailedClaimNeverShowsItsSecretInAnyRepresentationOfTheError(): void
    {
        $secret = 'cs_9f8e7d6c5b4a39281706f5e4d3c2b1a0';
        $lost = new NetworkException('cURL error 56: recv failure', new Request('POST', Rig::BASE.'/v1/agent/accounts/acc_1/claim'));
        $rig = new Rig(new FakeHttp([$lost]));

        try {
            $rig->api->request('POST', '/v1/agent/accounts/acc_1/claim', body: ['claim_secret' => $secret]);
            self::fail('expected an OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            $texts = [...self::dumps($error), ...self::dumps($error->getTrace()), $error->getTraceAsString(), (string) $error];
            try {
                $texts[] = serialize($error);
            } catch (\Exception) {
                // a trace that cannot be serialized leaks nothing either
            }
            foreach ($texts as $text) {
                self::assertStringNotContainsString($secret, $text);
            }
        }
    }

    public function testAnOldTokenIsRedactedAfterARotation(): void
    {
        $echo = ['error' => 'unauthorized', 'code' => 2000, 'details' => ['token' => Tokens::TEST]];
        $rig = new Rig(new FakeHttp([FakeHttp::json(401, $echo)]));
        $rig->credentials->replace(Tokens::ROTATED);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertClean(print_r(SdkFrames::only($error), true), 'error');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function echoedForms(): iterable
    {
        $secret = self::TAIL;
        yield 'bare secret segment' => ['the server said '.$secret.' is bad'];
        yield 'secret in other case' => ['the server said '.strtoupper($secret).' is bad'];
        yield 'secret in mixed case' => ['x'.ucfirst($secret).'y'];
        yield 'percent-encoded full token' => [rawurlencode(Tokens::TEST)];
        yield 'percent-encoded in lower case' => [strtolower(rawurlencode(Tokens::TEST))];
        yield 'full token in upper case' => [strtoupper(Tokens::TEST)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('echoedForms')]
    public function testAnEchoedSecretIsRedactedInEveryFormTheApiMightReturnIt(string $echo): void
    {
        $credentials = new Credentials(Tokens::TEST);
        $scrubbed = $credentials->scrub($echo);

        self::assertStringNotContainsStringIgnoringCase(self::TAIL, $scrubbed);
        self::assertStringNotContainsStringIgnoringCase('0123456789abcdef0123456789abcdef', $scrubbed);
        self::assertStringNotContainsStringIgnoringCase(rawurlencode(Tokens::TEST), $scrubbed);
        self::assertStringContainsString(Redact::MARKER, $scrubbed);
    }

    public function testSecretTailRedactionStartsAtSixteenCharacters(): void
    {
        foreach ([15 => 'abcdefghijklmno', 16 => 'abcdefghijklmnop'] as $length => $tail) {
            $credentials = new Credentials('hsr1:eu:pat.prefix.'.$tail);
            self::assertSame($length < 16 ? 'echo '.$tail : 'echo '.Redact::MARKER, $credentials->scrub('echo '.$tail));
        }
    }

    public function testTheSecretSegmentOfAnEarlierTokenIsRedactedToo(): void
    {
        $credentials = new Credentials(Tokens::TEST);
        $credentials->replace(Tokens::ROTATED);

        $scrubbed = $credentials->scrub('old '.strtoupper(self::TAIL).' new '.str_repeat('b', 64));

        self::assertStringNotContainsStringIgnoringCase(self::TAIL, $scrubbed);
        self::assertStringNotContainsString(str_repeat('b', 64), $scrubbed);
    }

    public function testAnApiErrorThatEchoesOnlyTheSecretSegmentNeverShowsIt(): void
    {
        $echo = ['error' => 'unauthorized', 'code' => 2000, 'details' => ['why' => 'bad '.strtoupper(self::TAIL)], 'message' => rawurlencode(Tokens::TEST)];
        $rig = new Rig(new FakeHttp([FakeHttp::json(401, $echo)]));

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected an AuthenticationError');
        } catch (AuthenticationError $error) {
            self::assertAllClean(self::dumps($error), 'error');
            self::assertStringNotContainsStringIgnoringCase(self::TAIL, print_r($error, true));
        }
    }

    public function testATransportFailureWithTheTokenInItsMessageAndRequestIsSanitized(): void
    {
        $echoing = static function (\Psr\Http\Message\RequestInterface $request): never {
            throw new ConnectException('boom '.Tokens::TEST.' '.$request->getHeaderLine('Authorization'), $request->withHeader('Authorization', 'Bearer '.Tokens::TEST));
        };
        $http = new FakeHttp([$echoing, $echoing, $echoing, $echoing]);
        $rig = new Rig($http);

        try {
            $rig->api->request('GET', '/v1/account');
            self::fail('expected a NetworkError');
        } catch (NetworkError $error) {
            self::assertSame(['Bearer '.Tokens::TEST], $http->authorizations === [] ? [] : [$http->authorizations[0]]);
            self::assertAllClean(self::dumps($error), 'network error');
            self::assertAllClean([$error->getMessage(), (string) $error], 'network error text');
            $link = $error->getPrevious();
            self::assertNotNull($link);
            while ($link !== null) {
                self::assertAllClean(self::dumps($link), 'cause');
                self::assertNotInstanceOf(ConnectException::class, $link);
                $link = $link->getPrevious();
            }
        }
    }

    public function testTheTokenParameterIsMarkedSensitiveInTraces(): void
    {
        $thrower = static function (): never {
            new Client('not-a-token');
            throw new \LogicException('unreachable');
        };

        try {
            $thrower();
        } catch (HushscriptException $error) {
            $args = [];
            foreach ($error->getTrace() as $frame) {
                $args[] = $frame['args'] ?? [];
            }
            self::assertStringNotContainsString('not-a-token', print_r($args, true));
            self::assertStringNotContainsString('not-a-token', (string) $error);
        }
    }

    public function testASignedUrlQueryIsRedactedInAnyText(): void
    {
        $cases = [
            'PUT https://r2.example.com/bucket/obj?X-Amz-Signature=abcdef&X-Amz-Date=1 failed' => 'PUT https://r2.example.com/bucket/obj?[redacted] failed',
            'HTTPS://R2.example.com/Bucket/obj?X-Amz-Signature=abcdef' => 'HTTPS://R2.example.com/Bucket/obj?[redacted]',
            'for "http://127.0.0.1:9000/b/1?sig=abc#part" (reset)' => 'for "http://127.0.0.1:9000/b/1?[redacted]#part" (reset)',
            'https://r2.example.com/a?x=1 and https://r2.example.com/b?y=2' => 'https://r2.example.com/a?[redacted] and https://r2.example.com/b?[redacted]',
            'https://r2.example.com/bucket/obj without a query' => 'https://r2.example.com/bucket/obj without a query',
            'a question? no URL here' => 'a question? no URL here',
        ];
        foreach ($cases as $text => $expected) {
            self::assertSame($expected, Redact::text($text), $text);
            self::assertSame($expected, (new Credentials(Tokens::TEST))->scrub($text), $text);
        }
    }

    public function testATokenShapedStringIsRedactedEvenWhenItIsNotTheClientsOwn(): void
    {
        self::assertSame('x hsr…[redacted] y', Redact::text('x '.Tokens::ROTATED.' y'));
    }

    public function testRedactValueWalksNestedArraysAndKeys(): void
    {
        $value = [Tokens::TEST => [Tokens::TEST, 5, null], 'n' => 1.5];

        self::assertSame([Redact::MARKER => [Redact::MARKER, 5, null], 'n' => 1.5], Redact::value($value));
    }

    public function testNoLogLineCarriesTheTokenOrAQueryString(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['ok' => true])]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/transcripts', ['cursor' => Tokens::TEST, 'page_size' => 5]);

        self::assertCount(1, $rig->logLines);
        self::assertClean($rig->logLines[0], 'log');
        self::assertStringNotContainsString('?', $rig->logLines[0]);
        self::assertStringNotContainsString('cursor', $rig->logLines[0]);
        self::assertMatchesRegularExpression('~^hushscript GET /v1/transcripts 200 attempt=1 \d+ms$~', $rig->logLines[0]);
    }

    public function testAnApiRequestObjectNeverHoldsTheAuthorizationHeader(): void
    {
        $http = new FakeHttp([FakeHttp::json(500, []), FakeHttp::json(200, [])]);
        $rig = new Rig($http);

        $rig->api->request('GET', '/v1/account');

        // The header is added per attempt on a copy; only the copy reaches the client.
        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::TEST], $http->authorizations);
        self::assertInstanceOf(Request::class, $http->requests[0]);
    }
}
