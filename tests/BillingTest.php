<?php

declare(strict_types=1);

namespace Hushscript\Tests;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Psr7\Request;
use Hushscript\Client;
use Hushscript\Exception\ApiError;
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\NetworkError;
use Hushscript\Exception\PaymentMethodRequiredError;
use Hushscript\Exception\PermissionDeniedError;
use Hushscript\Exception\PreflightError;
use Hushscript\Exception\ValidationError;
use Hushscript\Resource\Billing;
use Hushscript\Tests\Support\FakeClock;
use Hushscript\Tests\Support\FakeHttp;
use Hushscript\Tests\Support\Rig;
use Hushscript\Tests\Support\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** Packs, cards and buy() (spec 6.5, 7.2, 7.3). */
final class BillingTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function card(string $id, bool $default = false, bool $eligible = true): array
    {
        return ['id' => $id, 'type' => 'card', 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'is_default' => $default, 'fast_checkout_eligible' => $eligible];
    }

    /** @param list<array<string, mixed>> $methods */
    private static function cards(?string $default, array $methods): \GuzzleHttp\Psr7\Response
    {
        return FakeHttp::json(200, ['default_id' => $default, 'methods' => $methods]);
    }

    private static function bought(int $seconds = 18000): \GuzzleHttp\Psr7\Response
    {
        return FakeHttp::json(200, ['status' => 'ok', 'balance_seconds' => $seconds]);
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

    /** @return list<RequestInterface> */
    private static function to(FakeHttp $http, string $path): array
    {
        return array_values(array_filter($http->requests, static fn (RequestInterface $request): bool => $request->getUri()->getPath() === $path));
    }

    private static function billing(FakeHttp $http, int $maxRetries = 3): Billing
    {
        return new Billing((new Rig($http, maxRetries: $maxRetries))->exchange);
    }

    public function testPacksCarryThePriceInTheAccountsCurrencyAndFlagLockedPacks(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['packs' => [
            ['id' => '45min', 'amount' => 599, 'currency' => 'USD', 'seconds' => 2700, 'referral_bonus_seconds' => 600],
            ['id' => '900min', 'amount' => 1_500_000, 'currency' => 'IDR', 'seconds' => 54_000, 'referral_bonus_seconds' => 0, 'available' => false, 'x_new' => 1],
            ['id' => '300min', 'amount' => 3499, 'currency' => 'EUR', 'seconds' => 18_000, 'referral_bonus_seconds' => 0, 'available' => null],
        ]])]);

        $packs = self::billing($http)->packs();

        self::assertCount(3, $packs);
        self::assertSame('45min', $packs[0]->id);
        self::assertSame(2700, $packs[0]->seconds);
        self::assertSame(45, $packs[0]->minutes);
        self::assertSame('$5.99', $packs[0]->price->formatted);
        self::assertSame(599, $packs[0]->price->amount);
        self::assertSame(10, $packs[0]->bonusMinutes);
        self::assertTrue($packs[0]->available, 'absent means available (human accounts)');
        self::assertFalse($packs[1]->available);
        self::assertSame("IDR\u{A0}15,000.00", $packs[1]->price->formatted, 'Stripe digits, not ICU');
        self::assertTrue($packs[2]->available, 'null means available');
        self::assertSame('GET', $http->requests[0]->getMethod());
        self::assertSame('/v1/billing/packs', $http->requests[0]->getUri()->getPath());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableBodies(): iterable
    {
        yield 'packs not a list' => [['packs' => 'x']];
        yield 'pack without price' => [['packs' => [['id' => '45min', 'currency' => 'USD', 'seconds' => 1, 'referral_bonus_seconds' => 0]]]];
        yield 'negative seconds' => [['packs' => [['id' => '45min', 'amount' => 1, 'currency' => 'USD', 'seconds' => -1, 'referral_bonus_seconds' => 0]]]];
        yield 'available is a string' => [['packs' => [['id' => '45min', 'amount' => 1, 'currency' => 'USD', 'seconds' => 1, 'referral_bonus_seconds' => 0, 'available' => 'yes']]]];
        yield 'float amount' => [['packs' => [['id' => '45min', 'amount' => 5.99, 'currency' => 'USD', 'seconds' => 1, 'referral_bonus_seconds' => 0]]]];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('unusableBodies')]
    public function testAnUnusablePackAnswerIsBadResponseWithTheActualStatus(array $body): void
    {
        $billing = self::billing(new FakeHttp([FakeHttp::json(200, $body)]));

        try {
            $billing->packs();
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
        }
    }

    public function testCardsListTheDefaultAndEveryMethod(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['default_id' => 'pm_1', 'methods' => [
            self::card('pm_1', true) + ['email' => 'a@example.com'],
            self::card('pm_2', false, false),
        ]])]);

        $cards = self::billing($http)->cards();

        self::assertSame('pm_1', $cards->defaultId);
        self::assertCount(2, $cards->cards);
        self::assertSame('visa', $cards->cards[0]->brand);
        self::assertSame('4242', $cards->cards[0]->last4);
        self::assertSame(12, $cards->cards[0]->expMonth);
        self::assertSame(2030, $cards->cards[0]->expYear);
        self::assertSame('a@example.com', $cards->cards[0]->email);
        self::assertNull($cards->cards[1]->email);
        self::assertTrue($cards->cards[0]->isDefault);
        self::assertFalse($cards->cards[1]->fastCheckoutEligible);
    }

    public function testNoDefaultCardIsNull(): void
    {
        $cards = self::billing(new FakeHttp([self::cards(null, [])]))->cards();

        self::assertNull($cards->defaultId);
        self::assertSame([], $cards->cards);
    }

    /** @return iterable<string, array{string}> */
    public static function cardIds(): iterable
    {
        yield 'plain' => ['pm_2'];
        yield 'reserved and Unicode' => ['pm %?#é'];
    }

    #[DataProvider('cardIds')]
    public function testSettingTheDefaultCardPostsItsId(string $cardId): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['ok' => true])]);

        self::billing($http)->setDefaultCard($cardId);

        self::assertSame('POST', $http->requests[0]->getMethod());
        self::assertSame('/v1/billing/cards/default', $http->requests[0]->getUri()->getPath());
        self::assertSame(['payment_method_id' => $cardId], self::sent($http->requests[0]));
    }

    /** @return iterable<string, array{string}> */
    public static function badCardIds(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'invalid UTF-8' => ["\xff"];
    }

    #[DataProvider('badCardIds')]
    public function testAnUnusableCardIdIsRefusedBeforeAnyRequest(string $cardId): void
    {
        $http = new FakeHttp();

        try {
            self::billing($http)->setDefaultCard($cardId);
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame(0, $http->count());
    }

    public function testTheAddCardUrlIsTheHostedPageAndTheRequestCarriesAKey(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['checkout_url' => 'https://checkout.example.com/setup/cs_1'])]);

        $url = self::billing($http)->addCardUrl();

        self::assertSame('https://checkout.example.com/setup/cs_1', $url);
        self::assertSame('/v1/billing/setup-session', $http->requests[0]->getUri()->getPath());
        $key = self::sent($http->requests[0])['idempotency_key'] ?? null;
        self::assertIsString($key);
        self::assertSame(36, strlen($key), 'a UUID v4');
    }

    public function testTheAddCardUrlsOneRetryAfterA502UsesTheSameKey(): void
    {
        $http = new FakeHttp([FakeHttp::json(502, 'bad gateway'), FakeHttp::json(200, ['checkout_url' => 'https://checkout.example.com/setup/cs_1'])]);

        self::billing($http)->addCardUrl();

        self::assertCount(2, $http->requests);
        self::assertSame(self::sent($http->requests[0])['idempotency_key'], self::sent($http->requests[1])['idempotency_key']);
    }

    public function testAnEmptyAddCardUrlIsBadResponse(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['checkout_url' => ''])]);
        try {
            self::billing($http)->addCardUrl();
            self::fail('expected ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
            self::assertStringContainsString('cannot read', $error->getMessage());
        }
        self::assertSame(1, $http->count());
    }

    public function testBuyReadsTheCardsThenChargesTheDefaultOne(): void
    {
        $http = new FakeHttp([self::cards('pm_default', [self::card('pm_other'), self::card('pm_default', true)]), self::bought(18_000)]);

        $purchase = self::billing($http)->buy('300min');

        self::assertSame(18_000, $purchase->seconds);
        self::assertSame(300, $purchase->minutes);
        self::assertSame(['/v1/billing/cards', '/v1/billing/purchase'], array_map(static fn (RequestInterface $request): string => $request->getUri()->getPath(), $http->requests));
        $body = self::sent($http->requests[1]);
        self::assertSame('300min', $body['pack']);
        self::assertSame('pm_default', $body['quick_payment_method_id']);
        self::assertFalse($body['policy_ack'], 'no acknowledgement unless the caller gives it');
        self::assertIsString($body['idempotency_key']);
        self::assertSame(36, strlen($body['idempotency_key']));
    }

    public function testBuyMakesNoRequestAfterTheCharge(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), self::bought(-90)]);

        $purchase = self::billing($http)->buy('300min');

        self::assertSame(2, $http->count(), 'cards and purchase only: nothing may throw once the money has moved');
        self::assertSame(-90, $purchase->seconds);
        self::assertSame(-1, $purchase->minutes, 'toward zero');
    }

    public function testTheNoRefundAcknowledgementIsSentOnlyWhenGiven(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), self::bought(), self::cards('pm_1', [self::card('pm_1', true)]), self::bought()]);
        $billing = self::billing($http);

        $billing->buy('45min');
        $billing->buy('45min', true);

        self::assertFalse(self::sent($http->requests[1])['policy_ack']);
        self::assertTrue(self::sent($http->requests[3])['policy_ack']);
    }

    public function testACallersKeyIsUsedAsGiven(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), self::bought()]);

        self::billing($http)->buy('45min', false, 'my_key-1');

        self::assertSame('my_key-1', self::sent($http->requests[1])['idempotency_key']);
    }

    /** @return iterable<string, array{string}> */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'dot' => ['a.b'];
        yield 'slash' => ['a/b'];
        yield 'non ascii' => ["r\u{e9}sum\u{e9}"];
        yield 'newline at the end' => ["abc\n"];
        yield '129 characters' => [str_repeat('a', 129)];
    }

    /** @return iterable<string, array{bool, string}> */
    public static function badPurchaseKeys(): iterable
    {
        foreach (self::badKeys() as $name => [$key]) {
            yield 'billing '.$name => [false, $key];
            yield 'client '.$name => [true, $key];
        }
    }

    #[DataProvider('badPurchaseKeys')]
    public function testAMalformedKeyIsRefusedBeforeAnyRequest(bool $clientEntry, string $key): void
    {
        $http = new FakeHttp();
        $buyer = $clientEntry
            ? new Client(Tokens::TEST, ['base_url' => Rig::BASE, 'http_client' => $http, 'clock' => new FakeClock()])
            : self::billing($http);
        try {
            $buyer->buy('45min', false, $key);
            self::fail('expected invalid_option');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->idempotencyKey);
        }
        self::assertSame(0, $http->count());
    }

    public function testAKeyOf128CharactersIsAccepted(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), self::bought()]);

        self::billing($http)->buy('45min', false, str_repeat('Az_-', 32));

        self::assertSame(2, $http->count());
    }

    /** @return iterable<string, array{bool}> */
    public static function purchaseEntryPoints(): iterable
    {
        yield 'billing' => [false];
        yield 'client' => [true];
    }

    #[DataProvider('purchaseEntryPoints')]
    public function testAnEmptyPackIsRefusedBeforeAnyRequest(bool $clientEntry): void
    {
        $http = new FakeHttp();
        $buyer = $clientEntry
            ? new Client(Tokens::TEST, ['base_url' => Rig::BASE, 'http_client' => $http, 'clock' => new FakeClock()])
            : self::billing($http);
        try {
            $buyer->buy('');
            self::fail('expected PreflightError');
        } catch (PreflightError $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertNull($error->status);
        }
        self::assertSame(0, $http->count());
    }

    public function testWithNoDefaultTheOnlyChargeableCardIsUsed(): void
    {
        $http = new FakeHttp([self::cards(null, [self::card('pm_expired', false, false), self::card('pm_ok')]), self::bought()]);

        self::billing($http)->buy('45min');

        self::assertSame('pm_ok', self::sent($http->requests[1])['quick_payment_method_id']);
    }

    public function testNoCardAtAllIsNoDefaultCardAndNothingIsSent(): void
    {
        $http = new FakeHttp([self::cards(null, [])]);

        try {
            self::billing($http)->buy('300min');
            self::fail('expected PaymentMethodRequiredError');
        } catch (PaymentMethodRequiredError $error) {
            self::assertSame('no_default_card', $error->errorCode);
            self::assertStringContainsString('add-card', (string) $error->hint);
            self::assertNull($error->idempotencyKey, 'nothing was sent, so there is no key to carry');
        }
        self::assertSame([], self::to($http, '/v1/billing/purchase'));
    }

    public function testCardsThatCannotBeChargedAreNoDefaultCard(): void
    {
        $http = new FakeHttp([self::cards(null, [self::card('pm_a', false, false)])]);

        $this->expectException(PaymentMethodRequiredError::class);
        self::billing($http)->buy('300min');
    }

    public function testSeveralChargeableCardsAndNoDefaultIsDefaultCardRequired(): void
    {
        $http = new FakeHttp([self::cards(null, [self::card('pm_a'), self::card('pm_b')])]);

        try {
            self::billing($http)->buy('300min');
            self::fail('expected PaymentMethodRequiredError');
        } catch (PaymentMethodRequiredError $error) {
            self::assertSame('default_card_required', $error->errorCode);
            self::assertStringContainsString('more than one saved card', (string) $error->hint);
        }
        self::assertSame(1, $http->count());
    }

    public function testABadCardListStopsBeforeThePurchase(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['default_id' => 'pm_1'])]);
        try {
            self::billing($http)->buy('300min');
            self::fail('expected ApiError');
        } catch (ApiError $error) {
            self::assertSame(ApiError::class, $error::class);
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
        }
        self::assertSame(1, $http->count());
        self::assertSame([], self::to($http, '/v1/billing/purchase'));
    }

    public function testA502IsRetriedOnceWithTheSameKeyAndTheSecondAnswerWins(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), FakeHttp::json(502, 'bad gateway'), self::bought()]);

        $purchase = self::billing($http)->buy('300min');

        self::assertSame(18_000, $purchase->seconds);
        $purchases = self::to($http, '/v1/billing/purchase');
        self::assertCount(2, $purchases);
        self::assertSame(self::sent($purchases[0])['idempotency_key'], self::sent($purchases[1])['idempotency_key']);
    }

    public function testAPurchaseIsNeverSentMoreThanTwiceAndTheKeyRidesOnTheError(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), FakeHttp::json(503), FakeHttp::json(503), self::bought()]);

        try {
            self::billing($http, maxRetries: 5)->buy('300min');
            self::fail('expected ApiError');
        } catch (ApiError $error) {
            self::assertSame(503, $error->status);
            $purchases = self::to($http, '/v1/billing/purchase');
            self::assertCount(2, $purchases, 'at most one retry');
            self::assertSame(self::sent($purchases[0])['idempotency_key'], $error->idempotencyKey);
        }
    }

    public function testAnErrorAnswerCarriesTheKeyAndIsNotRetried(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), FakeHttp::json(403, ['error' => 'pat_purchase_requires_app', 'code' => 4021])]);

        try {
            self::billing($http)->buy('300min');
            self::fail('expected PermissionDeniedError');
        } catch (PermissionDeniedError $error) {
            self::assertSame('pat_purchase_requires_app', $error->errorCode);
            self::assertSame(4021, $error->numericCode);
            self::assertSame(403, $error->status);
            self::assertNotNull($error->idempotencyKey);
            self::assertSame(self::sent(self::to($http, '/v1/billing/purchase')[0])['idempotency_key'], $error->idempotencyKey);
        }
        self::assertCount(1, self::to($http, '/v1/billing/purchase'));
    }

    public function testACallersKeyComesBackOnTheError(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), FakeHttp::json(400, ['error' => 'bad_request', 'code' => 1])]);

        try {
            self::billing($http)->buy('300min', false, 'retry_me');
            self::fail('expected an error');
        } catch (HushscriptException $error) {
            self::assertSame(ValidationError::class, $error::class);
            self::assertSame('bad_request', $error->errorCode);
            self::assertSame(400, $error->status);
            self::assertSame('retry_me', $error->idempotencyKey);
            self::assertSame(self::sent($http->requests[1])['idempotency_key'], $error->idempotencyKey);
        }
    }

    public function testALostResponseAfterTheRetryIsANetworkErrorCarryingTheKey(): void
    {
        $lost = static fn (): NetworkException => new NetworkException('cURL error 56', new Request('POST', Rig::BASE.'/v1/billing/purchase'));
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), $lost(), $lost()]);

        try {
            self::billing($http)->buy('300min');
            self::fail('expected NetworkError');
        } catch (HushscriptException $error) {
            self::assertSame(NetworkError::class, $error::class);
            self::assertSame('network_error', $error->errorCode);
            self::assertNull($error->status);
            self::assertNotNull($error->idempotencyKey);
            self::assertSame(self::sent($http->requests[1])['idempotency_key'], $error->idempotencyKey);
            self::assertSame(self::sent($http->requests[2])['idempotency_key'], $error->idempotencyKey);
            self::assertCount(2, self::to($http, '/v1/billing/purchase'));
        }
    }

    private static function refused(): ConnectException
    {
        return new ConnectException('cURL error 7', new Request('POST', Rig::BASE.'/v1/billing/purchase'));
    }

    // R103: a purchase that provably never left charged nothing, so it carries no key and says so.
    public function testAPurchaseThatNeverLeftCarriesNoKeyAndSaysSo(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), self::refused(), self::refused()]);

        try {
            self::billing($http)->buy('300min', false, 'k1');
            self::fail('expected NetworkError');
        } catch (HushscriptException $error) {
            self::assertSame(NetworkError::class, $error::class);
            self::assertSame('network_error', $error->errorCode);
            self::assertNull($error->status);
            self::assertSame('Could not reach the Hushscript API: POST /v1/billing/purchase; it failed before the request was sent. GuzzleHttp\\Exception\\ConnectException: cURL error 7', $error->getMessage());
            self::assertNull($error->idempotencyKey);
        }
        self::assertCount(2, self::to($http, '/v1/billing/purchase'));
    }

    public function testAPurchaseWhoseFirstAttemptMayHaveLeftKeepsTheKeyWhateverTheLastFailure(): void
    {
        $lost = new NetworkException('cURL error 56', new Request('POST', Rig::BASE.'/v1/billing/purchase'));
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), $lost, self::refused()]);

        try {
            self::billing($http)->buy('300min', false, 'k1');
            self::fail('expected NetworkError');
        } catch (HushscriptException $error) {
            self::assertSame(NetworkError::class, $error::class);
            self::assertSame('Could not reach the Hushscript API: POST /v1/billing/purchase. GuzzleHttp\\Exception\\ConnectException: cURL error 7', $error->getMessage());
            self::assertSame('k1', $error->idempotencyKey);
        }
        self::assertCount(2, self::to($http, '/v1/billing/purchase'));
    }

    public function testAPurchaseBodyThatCannotBeEncodedCarriesNoKeyAndSendsNothing(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)])]);

        try {
            self::billing($http)->buy("300min\xff", false, 'k1');
            self::fail('expected PreflightError');
        } catch (HushscriptException $error) {
            self::assertSame(PreflightError::class, $error::class);
            self::assertSame('invalid_option', $error->errorCode);
            self::assertSame('The request body cannot be encoded as JSON, so it failed before the request was sent.', $error->getMessage());
            self::assertNull($error->idempotencyKey);
        }
        self::assertCount(0, self::to($http, '/v1/billing/purchase'));
    }

    /** @return iterable<string, array{\GuzzleHttp\Psr7\Response}> */
    public static function unusablePurchaseAnswers(): iterable
    {
        yield 'status not ok' => [FakeHttp::json(200, ['status' => 'pending', 'balance_seconds' => 5])];
        yield 'no status' => [FakeHttp::json(200, ['balance_seconds' => 5])];
        yield 'balance is a string' => [FakeHttp::json(200, ['status' => 'ok', 'balance_seconds' => '5'])];
        yield 'balance is a float' => [FakeHttp::json(200, ['status' => 'ok', 'balance_seconds' => 5.5])];
        yield 'balance beyond int64' => [FakeHttp::json(200, '{"status":"ok","balance_seconds":99999999999999999999}')];
        yield 'empty body' => [FakeHttp::json(200, '')];
        yield 'not json' => [FakeHttp::json(200, 'ok')];
        yield 'a list' => [FakeHttp::json(200, '[1]')];
    }

    #[DataProvider('unusablePurchaseAnswers')]
    public function testAnUnusableSuccessIsBadResponseWithTheKeyAndNeverARetry(\GuzzleHttp\Psr7\Response $answer): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), $answer]);

        try {
            self::billing($http)->buy('300min');
            self::fail('expected bad_response');
        } catch (ApiError $error) {
            self::assertSame('bad_response', $error->errorCode);
            self::assertSame(200, $error->status);
            self::assertSame(ApiError::class, $error::class);
            self::assertNotNull($error->idempotencyKey, 'the charge may have happened: the key must come back');
            self::assertSame(self::sent($http->requests[1])['idempotency_key'], $error->idempotencyKey);
        }
        self::assertCount(1, self::to($http, '/v1/billing/purchase'));
        self::assertSame(2, $http->count());
    }

    /** @param \Closure(): \Throwable $make */
    #[DataProviderExternal(RetryMiddlewareTest::class, 'unexpectedFailures')]
    public function testNonTransportHttpFailuresAreUnexpectedAndKeepThePurchaseKey(\Closure $make): void
    {
        $failure = $make();
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), $failure, self::bought()]);

        try {
            self::billing($http)->buy('300min', false, 'purchase_key');
            self::fail('expected unexpected_error');
        } catch (HushscriptException $error) {
            $summary = $failure::class.': boom hsr…[redacted]';
            self::assertSame(HushscriptException::class, $error::class);
            self::assertSame('unexpected_error', $error->errorCode);
            self::assertNull($error->status);
            self::assertNull($error->numericCode);
            self::assertSame('The call failed with an error that is not a network failure; the request may have been sent. '.$summary, $error->getMessage());
            self::assertSame('purchase_key', $error->idempotencyKey);
            self::assertSame(self::sent($http->requests[1])['idempotency_key'], $error->idempotencyKey);
            $cause = $error->getPrevious();
            self::assertNotNull($cause);
            self::assertSame(\RuntimeException::class, $cause::class);
            self::assertSame($summary, $cause->getMessage());
            self::assertNull($cause->getPrevious());
            self::assertStringNotContainsString(Tokens::TEST, print_r(\Hushscript\Tests\Support\SdkFrames::only($error), true));
        }
        self::assertCount(1, self::to($http, '/v1/billing/purchase'));
        self::assertSame(2, $http->count());
    }

    public function testAPurchaseAnswerThatBreaksOffMidBodyIsRetriedOnceAndKeepsTheKey(): void
    {
        $broken = static fn (): \GuzzleHttp\Psr7\Response => new \GuzzleHttp\Psr7\Response(200, [], \GuzzleHttp\Psr7\Utils::streamFor((static function (): \Generator {
            yield '{"bal';
            throw new \RuntimeException('connection reset');
        })()));
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), $broken(), $broken(), self::bought()]);

        try {
            self::billing($http)->buy('300min', false, 'purchase_key');
            self::fail('expected network_error');
        } catch (HushscriptException $error) {
            self::assertSame(NetworkError::class, $error::class);
            self::assertSame('network_error', $error->errorCode);
            self::assertSame('The answer to POST /v1/billing/purchase could not be read. RuntimeException: connection reset', $error->getMessage());
            self::assertSame('purchase_key', $error->idempotencyKey);
        }
        $sent = self::to($http, '/v1/billing/purchase');
        self::assertCount(2, $sent);
        self::assertSame(['purchase_key', 'purchase_key'], array_map(static fn (RequestInterface $request): mixed => self::sent($request)['idempotency_key'], $sent));
    }

    public function testTheClientBuyIsTheBillingBuy(): void
    {
        $http = new FakeHttp([self::cards('pm_1', [self::card('pm_1', true)]), self::bought(2700)]);
        $client = new Client(Tokens::TEST, ['base_url' => Rig::BASE, 'http_client' => $http, 'clock' => new FakeClock()]);

        $purchase = $client->buy('45min', true, 'k_1');

        self::assertSame(45, $purchase->minutes);
        self::assertTrue(self::sent($http->requests[1])['policy_ack']);
        self::assertSame('k_1', self::sent($http->requests[1])['idempotency_key']);
        self::assertSame($client->billing(), $client->billing());
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function lists(): iterable
    {
        yield 'transactions' => ['transactions', '/v1/billing/transactions', 'transactions', ['id' => 7, 'seconds' => 18000, 'amount_cents' => 3499, 'amount_refunded_cents' => 0, 'currency' => 'usd', 'status' => 'succeeded', 'created_at' => 1760000000, 'receipt_number' => 'R-1', 'initiated_via' => 'sdk']];
        yield 'ledger' => ['ledger', '/v1/billing/ledger', 'entries', ['id' => 9, 'delta_seconds' => -60, 'kind' => 'settle', 'job_id' => 'j1', 'note' => null, 'created_at' => 1760000000]];
        yield 'holds' => ['holds', '/v1/billing/holds', 'holds', ['id' => 'h1', 'kind' => 'transcription', 'seconds' => 120, 'created_at' => 1760000000]];
    }

    /** @param array<string, mixed> $row */
    #[DataProvider('lists')]
    public function testTheHistoryListsFollowCursors(string $method, string $path, string $key, array $row): void
    {
        $http = new FakeHttp([
            FakeHttp::json(200, [$key => [$row, $row], 'next_cursor' => 'c2']),
            FakeHttp::json(200, [$key => [$row], 'next_cursor' => null]),
        ]);

        $items = iterator_to_array(match ($method) {
            'transactions' => self::billing($http)->transactions(),
            'ledger' => self::billing($http)->ledger(),
            default => self::billing($http)->holds(),
        }, false);

        self::assertCount(3, $items);
        self::assertSame($path, $http->requests[0]->getUri()->getPath());
        self::assertSame('', $http->requests[0]->getUri()->getQuery());
        self::assertSame('cursor=c2', $http->requests[1]->getUri()->getQuery());
    }

    public function testTransactionsAndLedgerFields(): void
    {
        $http = new FakeHttp([
            FakeHttp::json(200, ['transactions' => [['id' => 7, 'seconds' => 18000, 'amount_cents' => 3499, 'amount_refunded_cents' => 100, 'currency' => 'usd', 'status' => 'succeeded', 'created_at' => 1760000000, 'payment_method_brand' => 'visa', 'receipt_number' => 'R-1']], 'next_cursor' => null]),
            FakeHttp::json(200, ['entries' => [['id' => 9, 'delta_seconds' => -60, 'kind' => 'settle', 'job_id' => 'j1', 'created_at' => 1760000000]], 'next_cursor' => null]),
            FakeHttp::json(200, ['holds' => [['id' => 'h1', 'kind' => 'brand_new_kind', 'seconds' => 120, 'created_at' => 1760000000]], 'next_cursor' => null]),
        ]);
        $billing = self::billing($http);

        $transaction = iterator_to_array($billing->transactions(), false)[0];
        $entry = iterator_to_array($billing->ledger(), false)[0];
        $hold = iterator_to_array($billing->holds(), false)[0];

        self::assertSame(7, $transaction->id);
        self::assertSame(3499, $transaction->amountCents);
        self::assertSame(100, $transaction->amountRefundedCents);
        self::assertSame('visa', $transaction->paymentMethodBrand);
        self::assertNull($transaction->paymentMethodLast4);
        self::assertSame('R-1', $transaction->receiptNumber);
        self::assertSame('2025-10-09T08:53:20+00:00', $transaction->createdAt->format('c'));
        self::assertSame(-60, $entry->deltaSeconds);
        self::assertSame('j1', $entry->jobId);
        self::assertNull($entry->note);
        self::assertSame('brand_new_kind', $hold->kind, 'an unknown kind passes through');
        self::assertSame(120, $hold->seconds);
    }

    public function testABadHistoryRowIsBadResponse(): void
    {
        $http = new FakeHttp([FakeHttp::json(200, ['holds' => [['id' => 'h1', 'kind' => 'x', 'seconds' => -1, 'created_at' => 1]], 'next_cursor' => null])]);

        $this->expectException(ApiError::class);
        iterator_to_array(self::billing($http)->holds(), false);
    }
}
