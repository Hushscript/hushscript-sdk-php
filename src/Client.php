<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\RetryPolicy;
use Hushscript\Http\Stack;
use Hushscript\Resource\Account as AccountResource;
use Hushscript\Resource\Agent;
use Hushscript\Resource\Billing;
use Hushscript\Resource\Jobs;
use Hushscript\Resource\Transcripts;
use Hushscript\Transcribe\GuzzleMultiTransport;
use Hushscript\Transcribe\PartTransport;
use Hushscript\Transcribe\Pipeline;
use Hushscript\Transcribe\SyncTransport;

/**
 * Entry point of the SDK. The token never appears in any representation of this object.
 */
final class Client
{
    private readonly Config $config;

    private readonly Api $api;

    private readonly Pipeline $pipeline;

    private readonly Jobs $jobs;

    private readonly Transcripts $transcripts;

    private readonly AccountResource $accountResource;

    private readonly Billing $billing;

    private readonly Agent $agent;

    /**
     * @param  array<string, mixed>  $options  base_url, timeout, max_retries, log_level, clock, http_client (a PSR-18 client), part_transport (advanced: how part uploads are sent)
     *
     * @throws \Hushscript\Exception\ConfigurationError
     */
    public function __construct(#[\SensitiveParameter] ?string $token = null, #[\SensitiveParameter] array $options = [])
    {
        $this->config = Config::resolve($token, $options);
        $stack = Stack::build($this->config);
        $exchange = $stack->exchange;
        $inner = $stack->inner;
        $this->api = new Api($exchange);
        $config = $this->config;
        $this->pipeline = new Pipeline(
            $exchange,
            $config->credentials,
            $config->baseUrl,
            $config->clock,
            $stack->log,
            $config->maxRetries,
            static fn (): PartTransport => $config->partTransport
                ?? ($config->httpClient === null && GuzzleMultiTransport::available() ? new GuzzleMultiTransport($config->timeout) : new SyncTransport($inner)),
            RetryPolicy::fullJitter(...),
            $stack->ticker,
        );
        $this->jobs = new Jobs($exchange, $this->pipeline);
        $this->transcripts = new Transcripts($exchange);
        $this->accountResource = new AccountResource($exchange);
        $this->billing = new Billing($exchange);
        $this->agent = new Agent($exchange, $config->credentials);
    }

    public function api(): Api
    {
        return $this->api;
    }

    /**
     * Uploads a file, waits for the transcript and returns it (spec 8). Part uploads run concurrently
     * on the default HTTP client; with a client of your own they go one at a time.
     *
     * @param  string|resource  $file  a path to an MP3, M4A/MP4/MOV, WAV, FLAC or Ogg file, or an open stream of one
     *                                 (a `php://memory` or `php://temp` stream holds bytes). A stream that can seek is
     *                                 measured and probed; one that cannot needs `size` and `duration_seconds`. The SDK
     *                                 reads one that can seek from its start, and one that cannot from where it stands
     *                                 (that must be the first byte of the file). It never closes a stream.
     * @param  array<string, mixed>  $options  title, language, speakers, translate_to, keyterms, prompt, medical,
     *                                         vet, keep_disfluencies, multichannel, auto_insights, matter_code, duration_seconds, size,
     *                                         idempotency_key, concurrency, wait_timeout, on_progress, on_resume_token.
     *                                         `keyterms` and `prompt` are trimmed first (blank ones are dropped and
     *                                         an empty option is not sent), then checked before any request: at most
     *                                         1000 unique terms of 128 characters and 6 words each, at most 1000 words
     *                                         in total across the terms, and a prompt of at most 2000 characters on its
     *                                         own (the two are sent separately). The server also checks a token budget
     *                                         for both, which the SDK does not estimate. Languages the universal-2
     *                                         model serves refuse both with `invalid_option`.
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function transcribe(mixed $file, array $options = []): Transcript
    {
        return $this->pipeline->transcribe($file, $options);
    }

    /**
     * Picks a transcription up from the resume token of an earlier call (spec 7.3). The file is needed
     * only while the job is still uploading. The options may also come second: `resume($token, $options)`.
     * A stream that cannot seek is read from where it stands, as the job's whole file (its size is the token's).
     *
     * @param  array<string, mixed>|string|resource|null  $file  a path, a stream, or the options when no file is given
     * @param  array<string, mixed>  $options  concurrency, wait_timeout, on_progress
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function resume(#[\SensitiveParameter] string $token, mixed $file = null, array $options = []): Transcript
    {
        if (is_array($file)) {
            return $this->pipeline->resume($token, null, $file);
        }

        return $this->pipeline->resume($token, $file, $options);
    }

    public function jobs(): Jobs
    {
        return $this->jobs;
    }

    public function transcripts(): Transcripts
    {
        return $this->transcripts;
    }

    /**
     * What can be spent now (spec 6.3): `seconds` is already net of holds, not "balance minus held".
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function balance(): Balance
    {
        return $this->accountResource->balance();
    }

    /** @throws \Hushscript\Exception\HushscriptException */
    public function account(): Account
    {
        return $this->accountResource->account();
    }

    public function billing(): Billing
    {
        return $this->billing;
    }

    /** The calls an agent account makes with its own token: `rotateToken()`. */
    public function agent(): Agent
    {
        return $this->agent;
    }

    /**
     * Buys a minute pack with the saved card; it is `billing()->buy()`. `$acknowledgeNoRefund` records that the
     * no-refund notice was shown to the buyer. Every error raised after the purchase request was sent carries
     * `idempotencyKey`: pass it back as `$idempotencyKey` to ask again with no chance of a second charge.
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public function buy(string $pack, bool $acknowledgeNoRefund = false, #[\SensitiveParameter] ?string $idempotencyKey = null): Purchase
    {
        return $this->billing->buy($pack, $acknowledgeNoRefund, $idempotencyKey);
    }

    /**
     * Opens an agent account. It needs no token (HUSHSCRIPT_TOKEN is not read or sent): the agent then has the
     * checkout paid at `checkoutUrl` and claims the account with `agentClaim()`. `accept_terms`, the policy version
     * being accepted, is never defaulted. The request is never retried; a lost answer is an `OutcomeUnknownError`.
     *
     * @param  array<string, mixed>  $request  email, pack, accept_terms, agent (name, platform, contact_url), data_region, dry_run
     * @param  array<string, mixed>  $options  base_url, timeout, log_level, clock, http_client
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public static function agentSignup(#[\SensitiveParameter] array $request, array $options = []): AgentSignup
    {
        $body = Agent::signupBody($request);

        return Agent::signup(Stack::build(Config::tokenless($options))->exchange, $body);
    }

    /**
     * Claims a paid agent signup and returns the account's token, which the server shows once: write it down before
     * doing anything else. It answers `wrong_state` (a `ConflictError`) until the checkout is paid, and is never
     * retried: a lost answer, or one without a usable token, is an `OutcomeUnknownError` whose hint says what to
     * check and whose `receivedToken()` holds any token the answer carried.
     *
     * @param  array<string, mixed>  $options  base_url, timeout, log_level, clock, http_client
     *
     * @throws \Hushscript\Exception\HushscriptException
     */
    public static function agentClaim(string $signupId, #[\SensitiveParameter] string $claimSecret, array $options = []): AgentAccount
    {
        $path = Agent::claimPath($signupId);
        $config = Config::tokenless($options);

        return Agent::claim(Stack::build($config)->exchange, $config->credentials, $path, $claimSecret);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->config->baseUrl, 'token' => Redact::MARKER];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Client cannot be serialized.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Client cannot be unserialized.');
    }
}
