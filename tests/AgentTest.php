<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Hushscript\Client;
use Hushscript\Credentials;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\AuthenticationError;
use Hushscript\Exception\ConfigurationError;
use Hushscript\Exception\ConflictError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\OutcomeUnknownError;
use Hushscript\Exception\PermissionDeniedError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\ValidationError;
use Hushscript\Http\Exchange;
use Hushscript\Resource\Agent;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\SdkFrames;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Agent signup, claim and rotation (spec 6.6, 7.4). */
final class AgentTest extends TestCase
{
    private const CLAIM_HINT = 'The claim may have succeeded. Retrying will fail with agent_claim_invalid. If you have no token, contact support with the signup id.';

    private const ROTATE_HINT = 'The old token may already be revoked. Try a request with it; if it fails, the new token was lost and the account needs support.';

    /** @return array<string, mixed> */
    private static function options(FakeHttp $http): array
    {
        return ['base_url' => Rig::BASE, 'http_client' => $http, 'clock' => new FakeClock()];
    }

    /** @return array<string, mixed> */
    private static function request(): array
    {
        return ['email' => 'agent@example.com', 'pack' => '45min', 'accept_terms' => '2026-10-01', 'agent' => ['name' => 'Example agent', 'platform' => 'custom']];
    }

    /** @return array<string, mixed> */
    private static function signedUp(): array
    {
        return ['signup_id' => 'sg_1', 'claim_secret' => 'cs_0123456789abcdef0123', 'checkout_url' => 'https://checkout.example.com/pay/sg_1', 'pack' => '45min', 'amount' => 599, 'currency' => 'USD', 'expires_at' => 1760001800];
    }

    /** @return array<string, mixed> */
    private static function claimed(string $token = Tokens::ROTATED): array
    {
        return ['user_id' => 'usr_1', 'pat' => $token, 'scopes' => ['account:read', 'billing'], 'balance_seconds' => 2700];
    }

    private static function client(FakeHttp $http, string $token = Tokens::TEST): Client
    {
        return new Client($token, self::options($http));
    }

    /** @return array<string, mixed> */
    private static function sent(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getBody(), true);
        $fields = [];
        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return $fields;
    }

    private static function lost(): NetworkException
    {
        return new NetworkException('cURL error 56: recv failure', new Request('POST', Rig::BASE.'/v1/agent/credentials/rotate'));
    }

    private static function surface(\Throwable $error): string
    {
        SdkFrames::only($error);
        $parts = [];
        for ($link = $error; $link !== null; $link = $link->getPrevious()) {
            $parts[] = $link->getMessage();
            $parts[] = (string) $link;
            $parts[] = print_r($link, true);
            $parts[] = var_export($link, true);
            $parts[] = var_export($link->getTrace(), true);
            $parts[] = print_r($link->getTrace(), true);
            try {
                $parts[] = serialize($link);
            } catch (\Exception) {
                // A trace that holds a closure or a hidden argument cannot be serialized at all: nothing leaks that way.
            }
            if ($link instanceof HushscriptException) {
                $parts[] = (string) $link->hint;
                $parts[] = (string) json_encode($link->details);
            }
        }

        return implode("\n", $parts);
    }

    public function testSignupSendsTheRequestWithoutAnyToken(): void
    {
        $previous = getenv('HUSHSCRIPT_TOKEN');
        putenv('HUSHSCRIPT_TOKEN='.Tokens::TEST);
        try {
            $http = new FakeHttp([FakeHttp::json(200, self::signedUp())]);

            $signup = Client::agentSignup(self::request() + ['data_region' => 'eu', 'dry_run' => true], self::options($http));
        } finally {
            putenv($previous === false ? 'HUSHSCRIPT_TOKEN' : 'HUSHSCRIPT_TOKEN='.$previous);
        }

        self::assertSame('sg_1', $signup->signupId);
        self::assertSame('cs_0123456789abcdef0123', $signup->claimSecret);
        self::assertSame('https://checkout.example.com/pay/sg_1', $signup->checkoutUrl);
        self::assertSame('$5.99', $signup->price->formatted);
        self::assertSame('2025-10-09T09:23:20+00:00', $signup->expiresAt->format('c'));
        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/agent/accounts', $request->getUri()->getPath());
        self::assertSame('', $request->getHeaderLine('Authorization'), 'the environment token is neither read nor sent');
        self::assertSame(
            ['contact_email' => 'agent@example.com', 'pack_id' => '45min', 'accept_terms_version' => '2026-10-01', 'agent' => ['name' => 'Example agent', 'platform' => 'custom'], 'data_region' => 'eu', 'dry_run' => true],
            self::sent($request),
        );
    }

    public function testTheOptionalFieldsAreLeftOutWhenNotGiven(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, self::signedUp())]);

        Client::agentSignup(['agent' => ['name' => 'A', 'platform' => 'p', 'contact_url' => 'https://example.com/a']] + self::request(), self::options($http));

        $body = self::sent($http->requests[0]);
        self::assertArrayNotHasKey('data_region', $body);
        self::assertArrayNotHasKey('dry_run', $body);
        $agent = $body['agent'];
        self::assertIsArray($agent);
        self::assertSame('https://example.com/a', $agent['contact_url']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function missingTerms(): iterable
    {
        $base = ['email' => 'a@example.com', 'pack' => '45min', 'agent' => ['name' => 'A', 'platform' => 'p']];
        yield 'absent' => [$base];
        yield 'empty' => [$base + ['accept_terms' => '']];
        yield 'blank' => [$base + ['accept_terms' => " \t"]];
        yield 'null' => [$base + ['accept_terms' => null]];
        yield 'not a string' => [$base + ['accept_terms' => 20261001]];
    }

    /** @param array<string, mixed> $request */
    #[DataProvider('missingTerms')]
    public function testTermsAreNeverDefaultedAndNothingIsSent(array $request): void
    {
        $http = new FakeHttp();

        try {
            Client::agentSignup($request, self::options($http));
            self::fail('expected accept_terms_required');
        } catch (ValidationError $error) {
            self::assertSame('accept_terms_required', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->numericCode);
        }
        self::assertSame(0, $http->count());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badRequests(): iterable
    {
        yield 'unknown key' => [self::request() + ['acceptTerms' => 'x']];
        yield 'no email' => [['email' => ''] + self::request()];
        yield 'email is a number' => [['email' => 5] + self::request()];
        yield 'no pack' => [array_diff_key(self::request(), ['pack' => 1])];
        yield 'no agent' => [array_diff_key(self::request(), ['agent' => 1])];
        yield 'agent is a string' => [['agent' => 'me'] + self::request()];
        yield 'agent without a platform' => [['agent' => ['name' => 'A']] + self::request()];
        yield 'agent unknown key' => [['agent' => ['name' => 'A', 'platform' => 'p', 'url' => 'x']] + self::request()];
        yield 'dry run is a string' => [self::request() + ['dry_run' => 'yes']];
        yield 'region is a number' => [self::request() + ['data_region' => 3]];
    }

    /** @param array<string, mixed> $request */
    #[DataProvider('badRequests')]
    public function testAMalformedSignupIsRefusedBeforeAnyRequest(array $request): void
    {
        $http = new FakeHttp();

        try {
            Client::agentSignup($request, self::options($http));
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame(0, $http->count());
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function unknownOptions(): iterable
    {
        foreach (['signup', 'claim'] as $operation) {
            yield $operation.' retry setting' => [$operation, ['max_retries' => 0]];
            yield $operation.' unknown setting' => [$operation, ['future_option' => true]];
        }
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('unknownOptions')]
    public function testAnUnknownOptionIsAConfigurationError(string $operation, array $options): void
    {
        $http = new FakeHttp();
        try {
            $options += self::options($http);
            if ($operation === 'signup') {
                Client::agentSignup(self::request(), $options);
            } else {
                Client::agentClaim('sg_1', 'cs_secret_0123456789ab', $options);
            }
            self::fail('expected ConfigurationError');
        } catch (HushscriptException $error) {
            self::assertSame(ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame(0, $http->count());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function badBaseUrls(): iterable
    {
        foreach (ConfigTest::badBaseUrls() as $name => [$url]) {
            yield 'signup '.$name => ['signup', $url];
            yield 'claim '.$name => ['claim', $url];
        }
    }

    #[DataProvider('badBaseUrls')]
    public function testABadBaseUrlIsRefusedBeforeAnyAgentRequest(string $operation, mixed $url): void
    {
        $http = new FakeHttp();
        $options = ['base_url' => $url] + self::options($http);
        try {
            if ($operation === 'signup') {
                Client::agentSignup(self::request(), $options);
            } else {
                Client::agentClaim('sg_1', 'cs_secret_0123456789ab', $options);
            }
            self::fail('expected ConfigurationError');
        } catch (HushscriptException $error) {
            self::assertSame(ConfigurationError::class, $error::class);
            self::assertSame('config_invalid', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->hint);
        }
        self::assertSame(0, $http->count());
    }

    public function testAStaleTermsVersionIsA422AndTheHintQuotesTheCurrentOne(): void
    {
        $http = new FakeHttp([FakeHttp::json(422, ['error' => 'policy_version_stale', 'code' => 1043, 'details' => ['current_version' => '2026-10-01']])]);

        try {
            Client::agentSignup(['accept_terms' => '2025-01-01'] + self::request(), self::options($http));
            self::fail('expected an error');
        } catch (ValidationError $error) {
            self::assertSame('policy_version_stale', $error->errorCode);
            self::assertSame(422, $error->status);
            self::assertStringContainsString('2026-10-01', (string) $error->hint);
            self::assertSame(1, $http->count());
        }
    }

    public function testASignupIsNeverRetried(): void
    {
        $http = new FakeHttp([FakeHttp::json(503), FakeHttp::json(200, self::signedUp())]);
        try {
            Client::agentSignup(self::request(), self::options($http));
            self::fail('expected ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame('http_503', $error->errorCode);
            self::assertSame(503, $error->status);
        }
        self::assertSame(1, $http->count());
    }

    public function testALostSignupIsAnUnknownOutcomeWithNoHint(): void
    {
        $http = new FakeHttp([self::lost(), FakeHttp::json(200, self::signedUp())]);

        try {
            Client::agentSignup(self::request(), self::options($http));
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertNull($error->hint);
            self::assertNull($error->receivedToken());
        }
        self::assertSame(1, $http->count());
    }

    public function testAnUnusableSignupAnswerIsBadResponse(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['signup_id' => 'sg_1'])]);

        try {
            Client::agentSignup(self::request(), self::options($http));
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
        }
    }

    public function testASignupAnswerIsHiddenFromDumps(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, self::signedUp())]);

        $signup = Client::agentSignup(self::request(), self::options($http));

        ob_start();
        var_dump($signup);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString('cs_0123456789abcdef0123', print_r($signup, true).$dumped);
        self::assertSame('cs_0123456789abcdef0123', $signup->claimSecret);
    }

    public function testClaimPostsTheSecretToTheEncodedSignupPathWithoutAToken(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, self::claimed())]);

        $account = Client::agentClaim('sg 1', 'cs_secret_0123456789ab', self::options($http));

        self::assertSame(Tokens::ROTATED, $account->token);
        self::assertSame('usr_1', $account->userId);
        self::assertSame(['account:read', 'billing'], $account->scopes);
        self::assertSame(2700, $account->balance->seconds);
        self::assertSame(45, $account->balance->minutes);
        self::assertSame(0, $account->balance->heldSeconds);
        self::assertNull($account->balance->expiresAt);
        self::assertNull($account->balance->purchasesRemaining);
        $request = $http->requests[0];
        self::assertSame('/v1/agent/accounts/sg%201/claim', $request->getUri()->getPath());
        self::assertSame(['claim_secret' => 'cs_secret_0123456789ab'], self::sent($request));
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    /** @return iterable<string, array{string}> */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'slash' => ['a/b'];
        yield 'backslash' => ['a\\b'];
        yield 'invalid utf-8' => ["\xff"];
    }

    #[DataProvider('badIds')]
    public function testAnUnusableSignupIdIsRefusedBeforeAnyRequest(string $id): void
    {
        $http = new FakeHttp();

        try {
            Client::agentClaim($id, 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame(0, $http->count());
    }

    public function testTheClaimTokenIsHiddenFromDumpsOfTheAccount(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, self::claimed())]);

        $account = Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));

        ob_start();
        var_dump($account);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString(Tokens::ROTATED, print_r($account, true).$dumped);
        self::assertSame(Tokens::ROTATED, $account->token);
    }

    public function testDeliberateExportOfTheClaimedAccountCarriesTheToken(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, self::claimed())]);

        $account = Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));

        $json = json_encode($account, JSON_THROW_ON_ERROR);
        self::assertStringContainsString(Tokens::ROTATED, $json);
        self::assertSame(['token' => Tokens::ROTATED], array_intersect_key((array) json_decode($json, true, 512, JSON_THROW_ON_ERROR), ['token' => true]));
    }

    /** @return iterable<string, array{int, string, string, class-string<HushscriptException>}> */
    public static function claimErrors(): iterable
    {
        yield 'invalid' => [401, 'agent_claim_invalid', '4036', AuthenticationError::class];
        yield 'not paid yet' => [409, 'wrong_state', '4001', ConflictError::class];
    }

    /** @param class-string<HushscriptException> $class */
    #[DataProvider('claimErrors')]
    public function testClaimAnswersKeepTheirMappedError(int $status, string $slug, string $numeric, string $class): void
    {
        $http = new FakeHttp([FakeHttp::json($status, ['error' => $slug, 'code' => (int) $numeric]), FakeHttp::json(200, self::claimed())]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected '.$class);
        } catch (HushscriptException $error) {
            self::assertInstanceOf($class, $error);
            self::assertSame($slug, $error->errorCode);
            self::assertNull($error->hint, 'the wrong_state hint names a job call: not on a claim');
        }
        self::assertSame(1, $http->count(), 'never retried');
    }

    /** @return iterable<string, array{int}> */
    public static function transientStatuses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('transientStatuses')]
    public function testClaimAndRotateAreNeverRetriedOnAServerAnswer(int $status): void
    {
        $http = new FakeHttp([FakeHttp::json($status), FakeHttp::json($status), FakeHttp::json(200, self::claimed())]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected an error');
        } catch (ApiError $error) {
            self::assertSame($status, $error->status);
        }
        try {
            self::client($http)->agent()->rotateToken();
            self::fail('expected an error');
        } catch (ApiError $error) {
            self::assertSame($status, $error->status);
        }
        self::assertSame(2, $http->count());
    }

    public function testALostClaimIsAnUnknownOutcomeWithTheClaimHintAndIsSentOnce(): void
    {
        $http = new FakeHttp([self::lost(), FakeHttp::json(200, self::claimed())]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(self::CLAIM_HINT, $error->hint);
            self::assertNull($error->receivedToken());
            self::assertNotNull($error->getPrevious());
        }
        self::assertSame(1, $http->count());
    }

    public function testAnyFailureWhileUsingAClaimAnswerThatCarriedATokenIsAnUnknownOutcomeWithTheToken(): void
    {
        $rig = new Rig(new FakeHttp([FakeHttp::json(200, self::claimed())]));
        $minted = new \ReflectionMethod(Agent::class, 'minted');

        try {
            $minted->invoke(null, $rig->exchange, $rig->credentials, '/v1/agent/accounts/sg_1/claim', ['claim_secret' => 'cs_secret_0123456789ab'], \Hushscript\Http\Routes::CLAIM, static function (\Hushscript\Http\Body $answer, ?string $seen): never {
                throw new \LogicException('not a bad body '.$seen);
            });
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(Tokens::ROTATED, $error->receivedToken());
            self::assertSame(self::CLAIM_HINT, $error->hint);
            self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            self::assertStringContainsString('not a bad body', $error->getPrevious()->getMessage());
            self::assertStringNotContainsString(Tokens::ROTATED, $error->getMessage().$error->getPrevious()->getMessage());
        }
    }

    public function testAFailureBeforeTheClaimWasSentIsPlainNetworkErrorAndStillNotReplayed(): void
    {
        $refused = new ConnectException('cURL error 7', new Request('POST', Rig::BASE.'/x'));
        $http = new FakeHttp([$refused, FakeHttp::json(200, self::claimed())]);

        $this->expectException(NetworkError::class);
        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
        } finally {
            self::assertSame(1, $http->count());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function answersWithoutAToken(): iterable
    {
        yield 'empty body' => [''];
        yield 'not json' => ['claimed'];
        yield 'a list' => ['[1,2]'];
        yield 'no pat' => ['{"user_id":"usr_1","scopes":["a"],"balance_seconds":5}'];
        yield 'pat is a number' => ['{"user_id":"usr_1","pat":5,"scopes":["a"],"balance_seconds":5}'];
        yield 'pat is empty' => ['{"user_id":"usr_1","pat":"","scopes":["a"],"balance_seconds":5}'];
        yield 'pat is not a token' => ['{"user_id":"usr_1","pat":"abc","scopes":["a"],"balance_seconds":5}'];
        yield 'pat has a control character' => ['{"user_id":"usr_1","pat":"hsr1:eu:pat.aa\\u0007bb","scopes":["a"],"balance_seconds":5}'];
        yield 'pat has spaces around it' => ['{"user_id":"usr_1","pat":" '.Tokens::ROTATED.' ","scopes":["a"],"balance_seconds":5}'];
    }

    #[DataProvider('answersWithoutAToken')]
    public function testAClaimAnswerWithoutAUsableTokenIsALostResponse(string $content): void
    {
        $http = new FakeHttp([FakeHttp::json(200, $content)]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(self::CLAIM_HINT, $error->hint);
            self::assertSame('bad_response', $error->getPrevious() instanceof ApiError ? $error->getPrevious()->errorCode : null);
            self::assertSame(200, $error->getPrevious() instanceof ApiError ? $error->getPrevious()->status : null);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function claimsWithATokenButUnusable(): iterable
    {
        // The token itself is added inside the test: a datum of the provider would show up in the arguments of PHPUnit's own trace frames.
        yield 'no user id' => [['user_id' => null]];
        yield 'scopes not a list' => [['scopes' => 'a']];
        yield 'a scope that is a number' => [['scopes' => ['a', 1]]];
        yield 'balance missing' => [['balance_seconds' => null]];
        yield 'balance negative' => [['balance_seconds' => -1]];
        yield 'balance is a float' => [['balance_seconds' => 1.5]];
    }

    /** @param array<string, mixed> $spoil */
    #[DataProvider('claimsWithATokenButUnusable')]
    public function testAClaimWithATokenButAnUnusableRestKeepsTheTokenOnTheError(array $spoil): void
    {
        $answer = array_filter(
            array_replace(['pat' => Tokens::ROTATED, 'user_id' => 'u', 'scopes' => ['a'], 'balance_seconds' => 5], $spoil),
            static fn (mixed $value): bool => $value !== null,
        );
        $http = new FakeHttp([FakeHttp::json(200, $answer)]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(self::CLAIM_HINT, $error->hint);
            self::assertSame(Tokens::ROTATED, $error->receivedToken(), 'shown once: it must not be lost');
            $surface = self::surface($error);
            self::assertStringNotContainsString(Tokens::ROTATED, $surface);
            self::assertStringNotContainsString('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $surface, 'nor its secret half');
            self::assertStringNotContainsString('cs_secret_0123456789ab', $surface);
        }
    }

    public function testTheReceivedTokenOfALostAnswerIsInNoRepresentationOfTheError(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => Tokens::ROTATED])]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(Tokens::ROTATED, $error->receivedToken());
            ob_start();
            var_dump(SdkFrames::only($error));
            $dumped = (string) ob_get_clean();
            self::assertStringNotContainsString('bbbbbbbbbbbbbbbb', $dumped.self::surface($error));
        }
    }

    public function testTheClaimSecretIsRedactedFromAnEchoingError(): void
    {
        $http = new FakeHttp([FakeHttp::json(401, ['error' => 'agent_claim_invalid', 'code' => 4036, 'details' => ['echo' => 'secret was cs_secret_0123456789ab'], 'x' => 'cs_secret_0123456789ab'])]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected an error');
        } catch (AuthenticationError $error) {
            self::assertStringNotContainsString('cs_secret_0123456789ab', self::surface($error));
        }
    }

    public function testAForeignErrorQuotingTheClaimSecretAndASignedUrlShowsNeither(): void
    {
        foreach ([new \TypeError('bad cs_secret_0123456789ab at https://r2.example.com/b/1?X-Amz-Signature=SIG'), new NetworkException('bad cs_secret_0123456789ab at https://r2.example.com/b/1?X-Amz-Signature=SIG', new Request('POST', Rig::BASE.'/v1/agent/accounts/sg_1/claim'))] as $failure) {
            $http = new FakeHttp([$failure]);

            try {
                Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
                self::fail('expected OutcomeUnknownError');
            } catch (OutcomeUnknownError $error) {
                $summary = $failure::class.': bad hsr…[redacted] at https://r2.example.com/b/1?[redacted]';
                self::assertSame('No response arrived for POST /v1/agent/accounts/sg_1/claim. '.$summary, $error->getMessage());
                self::assertSame($summary, $error->getPrevious()?->getMessage());
                self::assertSame(self::CLAIM_HINT, $error->hint);
                self::assertStringNotContainsString('cs_secret_0123456789ab', self::surface($error));
                self::assertStringNotContainsString('SIG', self::surface($error));
            }
        }
    }

    public function testTheClaimedTokenIsScrubbedFromWhatTheFailureShows(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'user_id' => ['x' => Tokens::ROTATED], 'scopes' => [], 'balance_seconds' => 1])]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertStringNotContainsString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', self::surface($error));
        }
    }

    public function testRotationSwapsTheTokenInPlaceForEveryLaterRequest(): void
    {
        $http = new FakeHttp([
            FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_9', 'scopes' => ['account:read'], 'expires_at' => 1760003600]),
            FakeHttp::json(200, ['balance_seconds' => 1]),
        ]);
        $client = self::client($http);

        $rotated = $client->agent()->rotateToken();
        $client->api()->request('GET', '/v1/account');

        self::assertSame(Tokens::ROTATED, $rotated->token);
        self::assertSame('pat_9', $rotated->tokenId);
        self::assertSame(['account:read'], $rotated->scopes);
        self::assertSame('2025-10-09T09:53:20+00:00', $rotated->expiresAt?->format('c'));
        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::ROTATED], $http->authorizations);
        self::assertSame('/v1/agent/credentials/rotate', $http->requests[0]->getUri()->getPath());
    }

    public function testAnAnswerWithoutAnExpiryMeansItNeverExpires(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_9', 'scopes' => ['a']])]);

        $rotated = self::client($http)->agent()->rotateToken();

        self::assertNull($rotated->expiresAt);
    }

    public function testNullExpiryIsAlsoNeverExpires(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_9', 'scopes' => ['a'], 'expires_at' => null])]);

        self::assertNull(self::client($http)->agent()->rotateToken()->expiresAt);
    }

    public function testARetryAlreadyQueuedSendsTheRotatedToken(): void
    {
        $http = new FakeHttp([
            FakeHttp::json(503),
            FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_9', 'scopes' => ['a']]),
            FakeHttp::json(200, ['balance_seconds' => 1]),
        ]);
        $clock = new FakeClock();
        $client = new Client(Tokens::TEST, ['base_url' => Rig::BASE, 'http_client' => $http, 'clock' => $clock]);
        $clock->onSleep = static function () use ($client): void {
            $client->agent()->rotateToken();
        };

        $client->api()->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::TEST, 'Bearer '.Tokens::ROTATED], $http->authorizations);
    }

    public function testTheOldTokenIsScrubbedAfterARotationToo(): void
    {
        $http = new FakeHttp([
            FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_9', 'scopes' => ['a']]),
            FakeHttp::json(401, ['error' => 'unauthorized', 'code' => 2000, 'details' => ['echo' => Tokens::TEST.' and '.Tokens::ROTATED.' and bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']]),
        ]);
        $client = self::client($http);
        $client->agent()->rotateToken();

        try {
            $client->api()->request('GET', '/v1/account');
            self::fail('expected an error');
        } catch (AuthenticationError $error) {
            $surface = self::surface($error);
            self::assertStringNotContainsString(Tokens::TEST, $surface);
            self::assertStringNotContainsString(Tokens::ROTATED, $surface);
            self::assertStringNotContainsString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $surface, 'a bare echoed secret half (R60)');
        }
    }

    public function testALostRotationIsAnUnknownOutcomeAndTheOldTokenStays(): void
    {
        $http = new FakeHttp([self::lost(), FakeHttp::json(200, ['balance_seconds' => 1])]);
        $client = self::client($http);

        try {
            $client->agent()->rotateToken();
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(self::ROTATE_HINT, $error->hint);
            self::assertNull($error->receivedToken());
        }
        $client->api()->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::TEST], $http->authorizations);
        self::assertSame('/v1/agent/credentials/rotate', $http->requests[0]->getUri()->getPath());
        self::assertCount(2, $http->requests, 'the rotation was sent once');
    }

    public function testARotationAnswerWithoutATokenIsALostResponseAndTheOldTokenStays(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat_id' => 'pat_9', 'scopes' => ['a']]), FakeHttp::json(200, ['balance_seconds' => 1])]);
        $client = self::client($http);

        try {
            $client->agent()->rotateToken();
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(self::ROTATE_HINT, $error->hint);
            self::assertInstanceOf(ApiError::class, $error->getPrevious());
        }
        $client->api()->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::TEST], $http->authorizations);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rotationsWithAUsableTokenAndABadRest(): iterable
    {
        yield 'no pat id' => [['pat' => Tokens::ROTATED, 'scopes' => ['a']]];
        yield 'scopes missing' => [['pat' => Tokens::ROTATED, 'pat_id' => 'p']];
        yield 'a scope that is a number' => [['pat' => Tokens::ROTATED, 'pat_id' => 'p', 'scopes' => [1]]];
    }

    /** @param array<string, mixed> $answer */
    #[DataProvider('rotationsWithAUsableTokenAndABadRest')]
    public function testAnAnswerThatCarriesTheTokenSwapsItEvenWhenTheRestIsUnusable(array $answer): void
    {
        $http = new FakeHttp([FakeHttp::json(200, $answer), FakeHttp::json(200, ['balance_seconds' => 1])]);
        $client = self::client($http);

        try {
            $client->agent()->rotateToken();
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(Tokens::ROTATED, $error->receivedToken());
            self::assertSame(self::ROTATE_HINT, $error->hint);
        }
        $client->api()->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::ROTATED], $http->authorizations, 'the old token is revoked: the client sends the one it holds');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableExpiries(): iterable
    {
        yield 'a string' => ['soon'];
        yield 'a float' => [1760000000.5];
        yield 'negative' => [-1];
        yield 'beyond year 9999' => [253_402_300_800];
        yield 'beyond int64' => [99_999_999_999_999_999_999];
    }

    #[DataProvider('unreadableExpiries')]
    public function testAnExpiryThatCannotBeReadDistrustsTheWholeAnswerAndKeepsTheOldToken(mixed $expiry): void
    {
        $answer = '{"pat":"'.Tokens::ROTATED.'","pat_id":"p","scopes":["a"],"expires_at":'.json_encode($expiry).'}';
        $http = new FakeHttp([FakeHttp::json(200, $answer), FakeHttp::json(200, ['balance_seconds' => 1])]);
        $client = self::client($http);

        try {
            $client->agent()->rotateToken();
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(Tokens::ROTATED, $error->receivedToken(), 'kept on the error so nothing is lost');
        }
        $client->api()->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::TEST], $http->authorizations);
    }

    /** @return iterable<string, array{string}> */
    public static function tokensTheClientRefuses(): iterable
    {
        yield 'no hsr prefix' => ['abc.def'];
        yield 'a space' => ['hsr1:eu:pat.aa bb'];
        yield 'a control character' => ["hsr1:eu:pat.aa\x07bb"];
        yield 'a newline' => ["hsr1:eu:pat.aa\nbb"];
        yield 'non ascii' => ["hsr1:eu:pat.r\u{e9}sum\u{e9}"];
    }

    #[DataProvider('tokensTheClientRefuses')]
    public function testATokenThatFailsTheTokenCheckIsNeverSwappedIn(string $token): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => $token, 'pat_id' => 'p', 'scopes' => ['a']]), FakeHttp::json(200, ['balance_seconds' => 1])]);
        $client = self::client($http);

        try {
            $client->agent()->rotateToken();
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame($token, $error->receivedToken());
        }
        $client->api()->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::TEST], $http->authorizations);
    }

    public function testARotationRefusedByTheServerKeepsTheOldToken(): void
    {
        $http = new FakeHttp([FakeHttp::json(403, ['error' => 'pat_forbidden', 'code' => 2022]), FakeHttp::json(200, ['balance_seconds' => 1])]);
        $client = self::client($http);

        try {
            $client->agent()->rotateToken();
            self::fail('expected PermissionDeniedError');
        } catch (PermissionDeniedError) {
        }
        $client->api()->request('GET', '/v1/account');

        self::assertSame(['Bearer '.Tokens::TEST, 'Bearer '.Tokens::TEST], $http->authorizations);
    }

    public function testTheRotatedTokenIsHiddenFromDumps(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_9', 'scopes' => ['a']])]);

        $rotated = self::client($http)->agent()->rotateToken();

        self::assertStringNotContainsString(Tokens::ROTATED, print_r($rotated, true));
        self::assertSame(Tokens::ROTATED, $rotated->token);
    }

    public function testDeliberateExportOfTheRotatedTokenAndTheClaimSecretCarriesThem(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['pat' => Tokens::ROTATED, 'pat_id' => 'pat_9', 'scopes' => ['a']])]);
        $rotated = self::client($http)->agent()->rotateToken();
        $signup = Client::agentSignup(self::request(), self::options(new FakeHttp([FakeHttp::json(200, self::signedUp())])));

        self::assertStringContainsString(Tokens::ROTATED, json_encode($rotated, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('cs_0123456789abcdef0123', json_encode($signup, JSON_THROW_ON_ERROR));
    }

    private static function unreadableBody(): Response
    {
        $stream = new class () implements \Psr\Http\Message\StreamInterface {
            public function __toString(): string
            {
                throw new \RuntimeException('read failed '.Tokens::TEST);
            }

            public function close(): void
            {
            }

            public function detach(): mixed
            {
                return null;
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
            }

            public function rewind(): void
            {
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                throw new \RuntimeException('read failed '.Tokens::TEST);
            }

            public function getContents(): string
            {
                throw new \RuntimeException('read failed '.Tokens::TEST);
            }

            public function getMetadata(?string $key = null): mixed
            {
                return null;
            }
        };

        return new Response(200, [], $stream);
    }

    public function testABodyThatFailsMidReadOnAClaimIsAnUnknownOutcomeSentOnce(): void
    {
        $http = new FakeHttp([self::unreadableBody(), FakeHttp::json(200, self::claimed())]);

        try {
            Client::agentClaim('sg_1', 'cs_secret_0123456789ab', self::options($http));
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame(self::CLAIM_HINT, $error->hint);
            self::assertSame('The answer to POST /v1/agent/accounts/sg_1/claim could not be read. RuntimeException: read failed hsr…[redacted]', $error->getMessage());
            self::assertSame('RuntimeException: read failed hsr…[redacted]', $error->getPrevious()?->getMessage());
            self::assertStringNotContainsString(Tokens::TEST, self::surface($error));
        }
        self::assertSame(1, $http->count());
    }

    public function testTheSdksOwnFailureOnTheAnswerOfARouteThatCannotBeRepeatedIsAnUnknownOutcomeToo(): void
    {
        // Straight through the exchange, with no retry middleware to read the body first (R61 and spec 7.4).
        $http = new FakeHttp([self::unreadableBody(), self::unreadableBody()]);
        $credentials = new Credentials(Tokens::TEST);
        $exchange = new Exchange($http, $credentials, Rig::BASE);

        foreach ([[fn (): mixed => Agent::claim($exchange, $credentials, '/v1/agent/accounts/sg_1/claim', 'cs_secret_0123456789ab'), self::CLAIM_HINT], [fn (): mixed => (new Agent($exchange, $credentials))->rotateToken(), self::ROTATE_HINT]] as [$call, $hint]) {
            try {
                $call();
                self::fail('expected OutcomeUnknownError');
            } catch (OutcomeUnknownError $error) {
                self::assertSame($hint, $error->hint);
                self::assertSame('The answer could not be used, so its outcome is unknown. RuntimeException: read failed hsr…[redacted]', $error->getMessage());
                self::assertSame('RuntimeException: read failed hsr…[redacted]', $error->getPrevious()?->getMessage());
                self::assertStringNotContainsString(Tokens::TEST, self::surface($error));
            }
        }
    }

    public function testAFailureOfTheSdksOwnWhileReadingAMintedAnswerNamesTheOriginalType(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, self::claimed())]);
        $credentials = new Credentials(Tokens::TEST);
        $exchange = new Exchange($http, $credentials, Rig::BASE);
        $minted = new \ReflectionMethod(Agent::class, 'minted');

        try {
            $minted->invoke(null, $exchange, $credentials, '/v1/agent/accounts/sg_1/claim', null, \Hushscript\Http\Routes::CLAIM, static function (): never {
                throw new \TypeError('boom '.Tokens::TEST);
            });
            self::fail('expected OutcomeUnknownError');
        } catch (OutcomeUnknownError $error) {
            self::assertSame('The answer to the claim could not be used, so the token may be lost. TypeError: boom hsr…[redacted]', $error->getMessage());
            self::assertSame(self::CLAIM_HINT, $error->hint);
            $cause = $error->getPrevious();
            self::assertNotNull($cause);
            self::assertSame(\RuntimeException::class, $cause::class);
            self::assertSame('TypeError: boom hsr…[redacted]', $cause->getMessage());
            self::assertStringNotContainsString(Tokens::TEST, self::surface($error));
        }
    }

    public function testTheSdksOwnFailureOnAnOrdinaryAnswerIsUnexpectedError(): void
    {
        $http = new FakeHttp([self::unreadableBody()]);
        $exchange = new Exchange($http, new Credentials(Tokens::TEST), Rig::BASE);

        try {
            $exchange->request('GET', '/v1/account');
            self::fail('expected an SDK error');
        } catch (HushscriptException $error) {
            self::assertNotInstanceOf(OutcomeUnknownError::class, $error);
            self::assertSame('unexpected_error', $error->errorCode);
        }
    }
}
