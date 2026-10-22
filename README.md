# Hushscript PHP SDK

The official PHP SDK for [Hushscript](https://hushscript.com), a privacy-first, pay-as-you-go
transcription service. It uploads a file, waits for the transcript and hands it back, and it handles
the failures in between so you do not have to.

## Why Hushscript

- **No subscription.** You buy prepaid minute packs, and nothing recurs. Current prices are on the
  [pricing page](https://hushscript.com/pricing).
- **The audio you send isn't kept.** The audio sent for transcription isn't kept after processing. Stored
  transcripts are encrypted at rest, and you choose how long they stay.
- **Free speaker labels and AI insights.** Speaker labels carry no extra charge (medical mode and
  translation cost more). The first all-in AI insights run on a transcript (summaries, action items,
  quotes, chapters) is free in the Hushscript app.
- **Built for specialist recordings.** Key terms, prompts and multichannel audio in
  around 99 languages, plus a medical mode for English, Spanish, French and German.
- **An SDK you can read.** It is MIT licensed, official, sends no telemetry, and is tested against a
  [conformance suite](https://github.com/Hushscript/hushscript-sdk-conformance) shared with the other
  Hushscript SDKs.

## What you can do with it

- Transcribe a file from a path or an open stream, with progress callbacks and retries that cannot
  charge you twice.
- Set the language or let it be detected, turn speaker labels on or off, and pass `keyterms` and a
  `prompt` to steer the vocabulary.
- Use medical mode, multichannel audio, or translate into other languages (`translate_to`) in the
  same call.
- Export SRT, WebVTT and plain text that match the Hushscript app's exports byte for byte, including
  translations and the AI-cleaned body.
- Resume an interrupted job with its `resumeToken` instead of paying twice.
- Check your balance, list transcripts and jobs, and buy minute packs from code (off until you
  allow it).
- Let an agent sign up, pay at a hosted checkout and claim its own token, with no human account.
- Use it from Laravel; the README has a short service provider example.

[Website](https://hushscript.com) · [Pricing](https://hushscript.com/pricing) · [SDK docs](https://hushscript.com/sdks) · [API reference](https://hushscript.com/developers)

## Quick start

```sh
composer require hushscript/sdk
export HUSHSCRIPT_TOKEN=hsr1:...   # Account → MCP in the Hushscript app
```

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Hushscript\Client;

$hs = new Client(); // reads HUSHSCRIPT_TOKEN

$transcript = $hs->transcribe('meeting.mp3');
echo $transcript->text();
file_put_contents('meeting.srt', $transcript->toSrt());
```

Options and results use snake_case array keys for input (`'translate_to'`) and camelCase properties
and methods on results (`$balance->heldSeconds`).

## Inputs

`$file` is a path or an open stream. A stream that can seek is measured and probed like a file; one
that cannot (a pipe, `php://stdin`) is read once, in order, one part at a time, and needs the `size`
(bytes) and `duration_seconds` options, and is read from where it stands, so it must start at the first
byte of the file. A stream that can seek is read from its start whatever its position. The SDK never
closes a stream.
Bytes already in memory go in as a `php://memory` stream (a string is always a path):

```php
$memory = fopen('php://memory', 'r+b'); fwrite($memory, $bytes);
$transcript = $hs->transcribe($memory);
```

## Check my balance

```php
$balance = $hs->balance();
echo "{$balance->minutes} minutes to spend\n";
```

`$balance->minutes` is what you can spend now. Minutes held for jobs that are still running are
already taken out of it, so do not subtract `heldSeconds` again.

## List my transcripts

```php
foreach ($hs->transcripts()->list() as $summary) {
    echo $summary->id, ' ', $summary->title, "\n";
}
```

The list holds finished transcripts only. A recording that is still processing is a job, so look for
it in `$hs->jobs()->list(['active' => true])`. `$hs->jobs()->wait($jobId)` waits for one, and
`$hs->transcripts()->get($transcriptId)` returns the full `Transcript` once it is done. Lists are
generators that fetch pages as you go; `page()` returns one page with its `nextCursor`, and raises
`pagination_loop` if that equals the cursor you sent (an empty one ends the list).

A field the API adds later is kept: `toJson()` on every result (a page, the account, the balance, a
purchase, the cards, an agent signup, claim or rotation) and on every object inside one (a list item,
a job's options, the account limits) returns that object as received, with snake_case keys.
`var_dump` and `print_r` show the named fields only.

## Transcribe

```php
$transcript = $hs->transcribe('/path/to/call.mp3', [
    'language' => 'en',
    'wait_timeout' => 1800,
    'on_progress' => static fn (array $progress) => print_r($progress),
]);
```

`transcribe` checks the file (format by its first bytes, duration with getID3, never less than the
true duration), uploads it in parts, waits for the job and returns a `Transcript`. Options:
`title`, `language`, `speakers`, `translate_to`, `keyterms`, `prompt`, `medical`, `multichannel`,
`auto_insights`, `matter_code`, `duration_seconds`, `size`, `idempotency_key`, `concurrency`,
`wait_timeout`, `on_progress`, `on_resume_token`.

`keyterms` and `prompt` are trimmed first (Unicode whitespace); blank terms are dropped, repeated terms
count once, and an empty list or blank prompt is not sent. Then, before any request: at most 1000 terms,
each at most 128 characters (code points) and 6 words; a prompt of at most 2000 characters; and when both
are set, the prompt and terms compiled together at most 2000 characters (203 + prompt + each term + 2,
plus 1 between terms). A violation raises `PreflightError` (`invalid_option`) naming the limit and the
term index.

When the call fails after the upload was created, the error carries `idempotencyKey` and
`resumeToken`. A wait that runs out raises `WaitTimeoutError` with the token:

```php
try {
    $hs->transcribe($path, ['wait_timeout' => 60]);
} catch (\Hushscript\Exception\WaitTimeoutError $timeout) {
    $transcript = $hs->resume($timeout->resumeToken, $path);
}
```

`resume($token, $path)` reads the job first. A job that is past uploading needs no file; one that is
still uploading is continued with the same file (a different size is refused before any request).
`$path` may be a stream too; one that cannot seek takes its size from the token and is read from where
it stands, so it must start at the first byte of the file.

PHP has no background threads. The upload loop owns the heartbeat (a ping at least every 120 s while
parts are in flight) and runs part requests on its own cURL multi handle, so a part that stalls for
minutes does not stop the heartbeat. With your own `http_client`, parts go through it one at a time
(no `Authorization` header is added to signed part URLs) and the heartbeat runs before every part,
early when the latest part could outlast the interval. While a part request blocks, nothing else
runs, so the true bound between two pings is about 120 s plus one part's duration, which is at most
the 300 s part timeout. The SDK passes that timeout to a Guzzle client; any other PSR-18 client has
to enforce its own timeout, or one stalled part holds the whole upload and its heartbeat. The
`part_transport` option (an instance of `Hushscript\Transcribe\PartTransport`) replaces how parts are
sent; a clock that also implements `Hushscript\IdleClock` lets tests move time while requests are in
flight.

## Buy a pack

```php
foreach ($hs->billing()->packs() as $pack) {
    echo $pack->id, ' ', $pack->minutes, ' minutes ', $pack->price->formatted, "\n";
}

$purchase = $hs->buy('300min', true);
echo "{$purchase->minutes} minutes to spend now\n";
```

The packs are `45min`, `300min`, `900min`, `1800min` and `6000min`. Prices depend on your country,
so read them from `$pack->price` and never hard-code one. `$price->formatted` is built from the
currency's minor units (a price in IDR or JPY is not scaled like USD); compare `amount` and
`currency`, not `formatted`, across SDKs.

`buy($pack, $acknowledgeNoRefund = false, $idempotencyKey = null)` charges your saved default card, or
the only card that can be charged when there is no default, and returns a `Purchase`: the `seconds`
and `minutes` from the purchase response, without a follow-up request. A lost or unusable response
can still raise an error after payment; retry with the `idempotencyKey` attached to that error.
With no usable card it raises `PaymentMethodRequiredError` and sends
nothing: `no_default_card` when there is none (add one at the URL from
`$hs->billing()->addCardUrl()` or in the app), `default_card_required` when several cards could be
charged and none is the default (pick one with `$hs->billing()->setDefaultCard($cardId)`).
`$hs->billing()->cards()` lists them.

Show your buyer this notice before you call it, then pass `true` as the second argument:

> Minutes are added to your account instantly after payment. All purchases are final and
> non-refundable.

The flag records that the notice was shown. It does not gate the charge, and without it `buy()`
still goes ahead.

The history is there too: `$hs->billing()->transactions()`, `ledger()` and `holds()` are generators
over every purchase, every balance movement and every minute still reserved.

### Allow buying first

Buying from code is off until you allow it in three places. If any is missing, the call fails with
`pat_scope_missing`.

1. The token has the permission labeled "Buy minutes and manage your saved payment methods" (the
   `billing` scope), ticked when you create it.
2. The token has "Let this token buy minutes" turned on, on the same "Create a new token" form.
3. "Let connected apps buy minutes" is on for the whole account.

All of it is in the app under Account → MCP.

### When a purchase does not go through

If the saved card was declined or cannot be charged without you, the call fails with
`pat_purchase_requires_app` (code 4021, status 403). If the bank wants to confirm the payment, it
fails with `pat_purchase_requires_authentication` (code 4023, status 403). In both cases nothing was
charged: buy that pack in the app. A key that met 4021 is spent, so the next `buy()` call makes a
new one.

A purchase is retried at most once, on a network error, a 502 or a 503, with the same idempotency
key, so those retries cannot charge you twice. When `buy()` still gives up after the request was
sent (a 401, a 429, any other 403, a 5xx, a network failure after the send, or an answer the SDK
cannot use), the card may have been charged. Every such error
carries `idempotencyKey`; ask again with it and the purchase can never happen twice:

```php
use Hushscript\Exception\HushscriptException;

try {
    $purchase = $hs->buy('300min', true);
} catch (HushscriptException $error) {
    $refused = in_array($error->status, [400, 402, 404, 422], true)
        || ($error->status === 403 && in_array($error->numericCode, [4021, 4023], true));
    if ($error->idempotencyKey === null || $refused) {
        throw $error; // A proven refusal charged nothing; without a key, this error cannot be retried here.
    }
    $purchase = $hs->buy('300min', true, $error->idempotencyKey);
}
```

A key you pass yourself must be 1 to 128 characters from `A-Za-z0-9_-`. Do not reuse a key for a
different pack.

## Export subtitles

```php
file_put_contents('meeting.srt', $transcript->toSrt());
file_put_contents('meeting.vtt', $transcript->toVtt('es'));
file_put_contents('meeting.txt', $transcript->toText(timestamps: true));
```

The output is byte for byte what the Hushscript app exports. `toSrt` and `toVtt` wrap lines into
caption cues by default; pass `captions: false` for one cue per utterance.

## Agents: sign up without a human account

An agent can open an account, have it paid for at a hosted checkout page, and claim its own token.
The first two calls need no token yet, so they are static.

```php
use Hushscript\Client;

function keep(string $path, string $token): void
{
    // A private temporary file renamed into place: the file is never half a token, and an older
    // token is not truncated before the new one is safely on disk.
    $temporary = tempnam(dirname($path), '.token-'); // created with mode 0600
    file_put_contents($temporary, $token."\n");
    rename($temporary, $path);
}

$signup = Client::agentSignup([
    'email' => 'agent@example.com',
    'pack' => '45min',
    'accept_terms' => '<the policy version you accept>',
    'agent' => ['name' => 'My agent', 'platform' => 'custom'],
]);
echo "Pay {$signup->price->formatted} at {$signup->checkoutUrl}\n";

// After the checkout is paid:
$account = Client::agentClaim($signup->signupId, $signup->claimSecret);
keep('agent-token', $account->token); // before anything else can fail

$hs = new Client($account->token);
$rotated = $hs->agent()->rotateToken(); // revokes the claimed token; the client sends the new one
keep('agent-token', $rotated->token);
```

- `accept_terms` is never filled in for you; an empty one raises `ValidationError` with the code
  `accept_terms_required` before any request. A wrong version fails with `policy_version_stale` (status 422), and
  the error's `hint` names the current version.
- The claim answers `wrong_state` (a `ConflictError`) until the checkout is paid.
- New agent accounts can buy only `45min` and `300min` until earlier purchases settle.
- Keep `claimSecret` and every token: the server shows each of them once, and a rotation revokes
  the token it replaces, so lose the new one and the account is out of reach. A claim or a rotation
  whose answer is lost, or comes back without a usable token, raises `OutcomeUnknownError` and is
  never retried, because a retry could not succeed; its `hint` says what to check. When the answer
  did carry something that looked like a token, `$error->receivedToken()` returns it, so you can store
  it before anything else. It is kept out of the error's message, `var_dump`, `print_r`,
  `var_export`, serialization and traces.
- `'dry_run' => true` validates a signup without opening a checkout.
- Both static calls take an options array last (`base_url`, `timeout`, `log_level`, `clock`,
  `http_client`). They never read `HUSHSCRIPT_TOKEN` and send no `Authorization` header.

## Errors

Every error the SDK raises extends `Hushscript\Exception\HushscriptException`, so one `catch` covers
everything:

```php
use Hushscript\Exception\HushscriptException;
use Hushscript\Exception\InsufficientBalanceError;

try {
    $hs->transcribe('meeting.mp3');
} catch (InsufficientBalanceError $error) {
    echo $error->hint;
} catch (HushscriptException $error) {
    echo $error->errorCode, ' ', $error->hint ?? $error->getMessage();
}
```

An error carries `errorCode` (a slug such as `insufficient_balance`), `numericCode`, `status`,
`getMessage()`, `hint` (what to do next, or `null`), `details` and `retryAfter`. The classes:

```
HushscriptException
├─ ConfigurationError
├─ PreflightError                  no credit was held
│  ├─ UnsupportedMediaError
│  ├─ FileLimitError
│  ├─ DailyLimitError
│  └─ ResumeMismatchError
├─ ApiError                        has status
│  ├─ AuthenticationError          401
│  ├─ PermissionDeniedError        403
│  ├─ InsufficientBalanceError     402
│  ├─ NotFoundError                404
│  ├─ ConflictError                409
│  ├─ ValidationError              400, 422
│  └─ RateLimitError               429
├─ PaymentMethodRequiredError
├─ UploadError
├─ JobFailedError
├─ WaitTimeoutError                has resumeToken
├─ AbortError                      has resumeToken once a job exists
├─ NetworkError
└─ OutcomeUnknownError
```

An error raised after an upload started, or once a purchase may have been sent, carries
`idempotencyKey` (a purchase that failed before its request was sent charged nothing, carries none and
says so);
once the job exists it carries `resumeToken` too. Pass the token to `$hs->resume($token, $file)` to
carry on without a second charge. Every failure reaches you as one of these classes; one that fits
no other is `HushscriptException` with the code `unexpected_error`, whose message says whether the
request was sent when the SDK knows it ("before" or "after the request was sent") and says it may
have been sent when it does not.

A PSR-18 client that throws `NetworkExceptionInterface` or `RequestExceptionInterface`, or a response
body that breaks off while it is read, is a transport failure: retried per the table, then a
`NetworkError`. Any other exception the client throws is `unexpected_error` and is never retried,
part uploads included. On a claim, rotation or signup, which are never sent twice, any failure after
the request may have gone out is an `OutcomeUnknownError` whose hint says what to check. Every such
message names the original class and its redacted message, and the cause is a stand-in carrying the
same text. An exception thrown by your own `on_progress` or `on_resume_token` callback comes back
unchanged.

### Hints and the methods they mean

The hint text is the same in every Hushscript SDK, so it names an action, not a method. Here is
what each action is in PHP:

| The hint says | In PHP |
|---|---|
| the SDK's buy method | `$hs->buy($pack)` |
| the SDK's add-card URL method, add-card link | `$hs->billing()->addCardUrl()` |
| set a default card with the SDK's billing methods | `$hs->billing()->setDefaultCard($cardId)` |
| pass durationSeconds | the `'duration_seconds'` option of `transcribe()` |
| fetch the job | `$hs->jobs()->get($jobId)` |
| accept the terms version | the `'accept_terms'` key of `Client::agentSignup()` |
| pass the original file | the `$file` argument of `$hs->resume($token, $file)` |
| the token needs the "{scope}" permission | create a token that has it (Account → MCP) |

## What the SDK does for you

- **Checks before it spends.** It sniffs the format from the file's bytes, probes the duration,
  and refuses a file the server would refuse before any minutes are held.
- **Keeps retries from charging twice.** Every upload and purchase carries an idempotency key, and
  retries reuse it. A `resumeToken` replays the same job instead of starting a new one, and the
  key on a failed purchase does the same for `buy()`.
- **Retries what is safe.** Reads, heartbeats and part uploads retry on network errors, 429 and 5xx
  with jittered backoff, and honor `Retry-After` up to 60 seconds. A purchase retries once on a
  network error, 502 or 503. A claim, a rotation and a signup never retry.
- **Paces uploads.** The API allows four upload creates a minute. A loop over a folder waits as
  needed, and `on_progress` keeps reporting.
- **Keeps the upload alive.** It uploads parts, falls back to the proxied route when direct upload
  fails, refreshes expired part URLs and sends a heartbeat every two minutes.
- **Waits and fetches.** It polls the job until it is done, raises `JobFailedError` if it fails,
  and gives you a `resumeToken` if the wait times out. The job keeps running.
- **Redacts secrets.** The token, a received token and every signed upload URL stay out of every
  error, log line, dump and trace.

## Supported formats

MP3, M4A, MP4 and MOV (the audio track of a video is used), WAV, FLAC, and OGG with Vorbis or Opus.
Files can be up to 5 GB and from 15 seconds to 10 hours. The SDK does not convert media. For
anything else, convert it first:

```sh
ffmpeg -i input -vn -c:a libopus output.opus
```

## Configuration

| Option | Env var | Default |
|---|---|---|
| token (first argument) | `HUSHSCRIPT_TOKEN` | none |
| `base_url` | `HUSHSCRIPT_BASE_URL` | `https://api.hushscript.com` |
| `timeout` | none | 60 s |
| `max_retries` | none | 3 |
| `log_level` | `HUSHSCRIPT_LOG` | `off` (`debug` logs one metadata line per attempt to standard error) |
| `http_client` | none | a PSR-18 client; Guzzle by default |
| `clock` | none | `Hushscript\Clock`, for tests |
| `part_transport` | none | advanced: a `Hushscript\Transcribe\PartTransport` for part uploads |

An explicit option beats its environment variable. `null`, an empty string and a whitespace-only
string (for the token, `base_url` and `log_level`), and an empty or whitespace-only variable, all mean
"not set": the option, then the variable, then the default. A token still missing raises
`ConfigurationError`. The base URL must be `https://`, or `http://` to `localhost`, `127.0.0.1` or
`[::1]`, with no path.

A custom `http_client` must not follow redirects and must return 4xx and 5xx answers as responses.
The SDK still adds the retry and authorization rules around it, and sends the token only to the
configured API origin. If the client has a `withOptions()` method, the SDK calls
`withOptions(['max_redirects' => 0])` on it; any other client must be configured that way by you.

Requests that must never be replayed (account claim, credential rotation, signup, unkeyed creates,
purchases and every other mutation) have to go out over a fresh connection. libcurl otherwise replays
a request on a kept-alive connection that the server closed after the body was sent, and the API would
see it twice. With the default Guzzle client, and with any `GuzzleHttp\ClientInterface` you pass, the
SDK requests a fresh, non-reusable connection for those calls. A custom PSR-18 client that is not a
Guzzle client must itself never retry or replay a request on a reused connection, or must open a new
connection per request; the SDK cannot enforce this on a foreign client. Symfony HttpClient does not
meet this rule: it cannot open a fresh connection per non-replayable request, and libcurl can resend
a POST on a pooled connection that died. Guzzle is the supported transport for `buy`, claim, rotate,
signup and an unkeyed create.

## Laravel

Bind one client in a service provider and inject it where you need it:

```php
namespace App\Providers;

use Hushscript\Client;
use Illuminate\Support\ServiceProvider;

final class HushscriptServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static fn (): Client => new Client(
            config('services.hushscript.token'),
            ['log_level' => 'off'],
        ));
    }
}
```

```php
// config/services.php
'hushscript' => ['token' => env('HUSHSCRIPT_TOKEN')],
```

Register the provider in `bootstrap/providers.php` (or `config/app.php` before Laravel 11), then
type-hint `Hushscript\Client` in a controller or job. A transcription can take minutes, so run it
in a queued job rather than a web request, and give it a stable `idempotency_key` option (derived
from the record you are transcribing) so a retried job replays the same upload instead of starting
another.

## The low-level API

Every `/v1` operation is available as `$hs->api()->request($method, $path, $query, $body)` on the
same retries, timeouts and redaction. It returns the decoded JSON body:

```php
$account = $hs->api()->request('GET', '/v1/account');
```

## Privacy

The SDK sends no telemetry. The only client identification is the `User-Agent` header
(`hushscript-php/<version> php/<version>`). Logging is off by default; `HUSHSCRIPT_LOG=debug` (or
`'log_level' => 'debug'`) writes one line per request with the method, path, status and timing, and
never a header, body, title or transcript text. The client's token never appears in `var_dump`,
`print_r`, `var_export`, `json_encode`, exception messages or traces, and `serialize()` of the client
throws. In error messages, hints and causes, any URL keeps its scheme, host and path, but its query
string becomes `?[redacted]`, and a claim secret of 16 or more characters is redacted once a claim has
used it. Results that hand you a secret (the claim secret, a new token) show it as `[redacted]` in
`var_dump` and `print_r`, but carry it in `toJson()`, `json_encode`, `serialize` and `var_export`,
which are your own export.

## Server only

The SDK is for servers and scripts. The API does not accept browser origins, and a token shipped in
client-side code is a leaked token.

## Pricing

Personal accounts buy prepaid minute packs. The price of a pack depends on your country, and your
minutes stay valid for a year after your last transcription or purchase. A transcription uses the
minutes of the recording; medical mode and each translation target use more. Current prices are on
[hushscript.com/pricing](https://hushscript.com/pricing).

## Requirements

- PHP 8.2 or newer, with `ext-json` and `ext-mbstring`; CI tests PHP 8.2, 8.3, 8.4 and 8.5
- `guzzlehttp/guzzle` ^8 and `james-heinrich/getid3` (installed by Composer)
- `ext-curl` for concurrent part uploads (without it, or with a client of your own, parts go one at a time)

## Links

- Documentation: <https://hushscript.com/sdks>
- REST API reference: <https://hushscript.com/developers>
- Package on Packagist: <https://packagist.org/packages/hushscript/sdk>
- Source: <https://github.com/Hushscript/hushscript-sdk-php>
- Other Hushscript SDKs: [TypeScript](https://github.com/Hushscript/hushscript-sdk-typescript), [Python](https://github.com/Hushscript/hushscript-sdk-python), [Go](https://github.com/Hushscript/hushscript-sdk-go), [CLI](https://github.com/Hushscript/hushscript-cli)

## Releases

Releases come from release-please. Every push to `main` runs the `release` workflow, which opens or
updates a release PR that bumps the version in `src/Version.php` and writes `CHANGELOG.md`. Merging
that PR creates the `v<version>` tag and the GitHub release. No workflow in this repository publishes
to Packagist.

## Development

Everything runs in containers through [just](https://github.com/casey/just): `just test`,
`just lint`, `just conformance`, `just examples`, `just build`, `just ci`. These recipes accept
PHP 8.2, 8.3, 8.4 and 8.5; individual recipes default to 8.2. `just ci` runs lint, tests, conformance,
examples and build on all four versions. Use `just ci 8.4` to run the full gate
on one version. `just examples` runs every program in `examples/` against the conformance mock.

The conformance mock is built from the `hushscript-sdk-conformance` repository at the ref (a tag or a commit SHA) in
`conformance-ref`; `just ci` fails when a scenario is skipped (`CONFORMANCE_STRICT=1`). To use a
local checkout instead:

```sh
CONFORMANCE_CONTEXT=../hushscript-sdk-conformance CONFORMANCE_DIR=../hushscript-sdk-conformance just ci
```

## Security

See [SECURITY.md](SECURITY.md).

## License

MIT
