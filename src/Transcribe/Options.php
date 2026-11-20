<?php

declare(strict_types=1);

namespace Hushscript\Transcribe;

use Hushscript\Exception\PreflightError;

/**
 * The options of `transcribe()` (spec 6.1), snake_case, checked before any request.
 */
final class Options
{
    public const MAX_CONCURRENCY = 8;

    public const DEFAULT_CONCURRENCY = 4;

    public const DEFAULT_WAIT_TIMEOUT = 3600.0;

    private const KEY_MIN = 16;

    private const KEY_MAX = 128;

    private const MAX_KEYTERMS = 1000;

    private const MAX_KEYTERM_WORDS = 6;

    private const MAX_KEYTERM_CHARS = 128;

    private const MAX_PROMPT_CHARS = 2000;

    /** The fixed text the API wraps around a prompt and its key terms into one provider prompt. */
    private const COMPILED_PROMPT_OVERHEAD = 203;

    /** Unicode White_Space, the set Rust's `char::is_whitespace` uses; PHP's `trim()` and `\s` cover less. */
    private const WHITESPACE = '\x{9}-\x{D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

    private const MAX_MATTER_CODE = 64;

    /** Mirrors the backend's MEDICAL_LANGUAGES, compared exactly. */
    private const MEDICAL_LANGUAGES = ['en', 'en_au', 'en_uk', 'en_us', 'es', 'fr', 'de', 'de_ch'];

    private const TRANSCRIBE = [
        'title', 'language', 'speakers', 'translate_to', 'keyterms', 'prompt', 'medical', 'vet', 'keep_disfluencies',
        'multichannel', 'auto_insights', 'matter_code', 'duration_seconds', 'size', 'idempotency_key', 'concurrency', 'wait_timeout',
        'on_progress', 'on_resume_token',
    ];

    private const RESUME = ['concurrency', 'wait_timeout', 'on_progress'];

    private const WAIT = ['wait_timeout', 'on_progress'];

    public readonly Callbacks $callbacks;

    /**
     * @param  list<string>  $translateTo
     * @param  list<string>  $keyterms
     * @param  (\Closure(array<string, mixed>): void)|null  $onProgress
     * @param  (\Closure(string): void)|null  $onResumeToken
     */
    private function __construct(
        public readonly ?string $title,
        public readonly ?string $language,
        public readonly bool $speakers,
        public readonly array $translateTo,
        public readonly array $keyterms,
        public readonly ?string $prompt,
        public readonly bool $medical,
        public readonly bool $vet,
        public readonly bool $keepDisfluencies,
        public readonly bool $multichannel,
        public readonly ?bool $autoInsights,
        public readonly ?string $matterCode,
        public readonly float|int|null $durationSeconds,
        public readonly ?int $size,
        #[\SensitiveParameter] public readonly ?string $idempotencyKey,
        public readonly int $concurrency,
        public readonly float $waitTimeout,
        public readonly ?\Closure $onProgress,
        public readonly ?\Closure $onResumeToken,
    ) {
        $this->callbacks = new Callbacks();
    }

    /** @param array<string, mixed> $raw */
    public static function forTranscribe(#[\SensitiveParameter] array $raw): self
    {
        return self::from($raw, self::TRANSCRIBE);
    }

    /** @param array<string, mixed> $raw */
    public static function forResume(#[\SensitiveParameter] array $raw): self
    {
        return self::from($raw, self::RESUME);
    }

    /** @param array<string, mixed> $raw */
    public static function forWait(#[\SensitiveParameter] array $raw): self
    {
        return self::from($raw, self::WAIT);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $allowed
     */
    private static function from(#[\SensitiveParameter] array $raw, array $allowed): self
    {
        foreach (array_keys($raw) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw self::invalid('Unknown option "'.$name.'".');
            }
        }
        $concurrency = $raw['concurrency'] ?? self::DEFAULT_CONCURRENCY;
        if (! is_int($concurrency) || $concurrency < 1 || $concurrency > self::MAX_CONCURRENCY) {
            throw self::invalid('concurrency must be a whole number from 1 to '.self::MAX_CONCURRENCY.'.');
        }
        $waitTimeout = self::positiveNumber($raw['wait_timeout'] ?? self::DEFAULT_WAIT_TIMEOUT);
        if ($waitTimeout === null) {
            throw self::invalid('wait_timeout must be a positive number of seconds.');
        }
        $duration = $raw['duration_seconds'] ?? null;
        if ($duration !== null && self::positiveNumber($duration) === null) {
            throw self::invalid('duration_seconds must be a positive number of seconds.');
        }
        $size = $raw['size'] ?? null;
        if ($size !== null && (! is_int($size) || $size < 1)) {
            throw self::invalid('size must be a positive whole number of bytes.');
        }
        $key = self::optionalString($raw, 'idempotency_key');
        if ($key !== null && (strlen($key) < self::KEY_MIN || strlen($key) > self::KEY_MAX || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1)) {
            throw self::invalid('idempotency_key must be '.self::KEY_MIN.' to '.self::KEY_MAX.' visible ASCII characters, no spaces.');
        }
        $language = self::optionalString($raw, 'language');
        $medical = self::flag($raw, 'medical', false);
        $vet = self::flag($raw, 'vet', false);
        if ($medical && $vet) {
            throw self::invalid('medical and vet cannot be used together.');
        }
        if ($medical && ! in_array($language, self::MEDICAL_LANGUAGES, true)) {
            throw self::invalid('medical needs language to be one of '.implode(', ', self::MEDICAL_LANGUAGES).'.');
        }
        if ($vet) {
            throw self::invalid('vet is not available for any language yet.');
        }
        $keyterms = self::keyterms(self::strings($raw, 'keyterms'));
        $prompt = self::prompt(self::optionalString($raw, 'prompt'), $keyterms);
        $matterCode = self::optionalString($raw, 'matter_code');
        if ($matterCode !== null && mb_strlen($matterCode) > self::MAX_MATTER_CODE) {
            throw self::invalid('matter_code must be at most '.self::MAX_MATTER_CODE.' characters.');
        }
        $auto = $raw['auto_insights'] ?? null;
        if ($auto !== null && ! is_bool($auto)) {
            throw self::invalid('auto_insights must be true, false or null.');
        }

        return new self(
            self::optionalString($raw, 'title'),
            $language,
            self::flag($raw, 'speakers', true),
            self::strings($raw, 'translate_to'),
            $keyterms,
            $prompt,
            $medical,
            $vet,
            self::flag($raw, 'keep_disfluencies', false),
            self::flag($raw, 'multichannel', false),
            $auto,
            $matterCode,
            is_int($duration) || is_float($duration) ? $duration : null,
            $size,
            $key,
            $concurrency,
            $waitTimeout,
            self::callback($raw, 'on_progress'),
            self::callback($raw, 'on_resume_token'),
        );
    }

    public function newKey(): string
    {
        return $this->idempotencyKey ?? self::uuid4();
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }

    /** @return array<string, mixed> */
    public function transcriptionOptions(): array
    {
        $wire = [];
        if ($this->language !== null) {
            $wire['language_code'] = $this->language;
        }
        if (! $this->speakers && $this->translateTo === []) {
            $wire['speaker_detection'] = false;
        }
        if ($this->translateTo !== []) {
            $wire['translation_targets'] = $this->translateTo;
        }
        if ($this->keyterms !== []) {
            $wire['keyterms_prompt'] = $this->keyterms;
        }
        if ($this->prompt !== null) {
            $wire['prompt'] = $this->prompt;
        }
        if ($this->medical) {
            $wire['medical_mode'] = true;
        }
        if ($this->vet) {
            $wire['vet_mode'] = true;
        }
        if ($this->keepDisfluencies) {
            $wire['keep_disfluencies'] = true;
        }
        if ($this->multichannel) {
            $wire['multichannel'] = true;
        }
        if ($this->autoInsights !== null) {
            $wire['auto_generate_insights'] = $this->autoInsights;
        }

        return $wire;
    }

    /** @param array<string, mixed> $progress */
    public function progress(array $progress): void
    {
        if ($this->onProgress !== null) {
            $this->callbacks->call($this->onProgress, $progress);
        }
    }

    /** The resume token goes to the caller before anything else happens with the job. */
    public function resumeToken(#[\SensitiveParameter] string $token): void
    {
        if ($this->onResumeToken !== null) {
            $this->callbacks->call($this->onResumeToken, $token);
        }
    }

    /**
     * The progress callback as the polling loop calls it, or null.
     *
     * @return (\Closure(array<string, mixed>): void)|null
     */
    public function progressSink(): ?\Closure
    {
        return $this->onProgress === null ? null : $this->progress(...);
    }

    /**
     * Trims, drops blanks and repeats, and checks the limits the API enforces; the indexes in the messages are the caller's.
     *
     * @param  list<string>  $terms
     * @return list<string>
     */
    private static function keyterms(array $terms): array
    {
        $unique = [];
        foreach ($terms as $index => $term) {
            $term = self::trim($term);
            if ($term === '') {
                continue;
            }
            if (mb_strlen($term, 'UTF-8') > self::MAX_KEYTERM_CHARS) {
                throw self::invalid('keyterms['.$index.'] is longer than '.self::MAX_KEYTERM_CHARS.' characters.');
            }
            $words = preg_split('/['.self::WHITESPACE.']+/u', $term, -1, PREG_SPLIT_NO_EMPTY);
            if ($words !== false && count($words) > self::MAX_KEYTERM_WORDS) {
                throw self::invalid('keyterms['.$index.'] has more than '.self::MAX_KEYTERM_WORDS.' words.');
            }
            $unique[$term] = true;
            if (count($unique) > self::MAX_KEYTERMS) {
                throw self::invalid('keyterms takes up to '.self::MAX_KEYTERMS.' unique terms.');
            }
        }

        return array_map(strval(...), array_keys($unique));
    }

    /** @param list<string> $keyterms */
    private static function prompt(?string $prompt, array $keyterms): ?string
    {
        $prompt = $prompt === null ? '' : self::trim($prompt);
        if ($prompt === '') {
            return null;
        }
        $length = mb_strlen($prompt, 'UTF-8');
        if ($length > self::MAX_PROMPT_CHARS) {
            throw self::invalid('prompt is longer than '.self::MAX_PROMPT_CHARS.' characters.');
        }
        if ($keyterms !== []) {
            $compiled = self::COMPILED_PROMPT_OVERHEAD + $length + count($keyterms) - 1;
            foreach ($keyterms as $term) {
                $compiled += mb_strlen($term, 'UTF-8') + 2;
            }
            if ($compiled > self::MAX_PROMPT_CHARS) {
                throw self::invalid('prompt and keyterms together exceed '.self::MAX_PROMPT_CHARS.' characters ('.$compiled.').');
            }
        }

        return $prompt;
    }

    /** Trims Unicode White_Space from both ends in one linear pass; a string that is not valid UTF-8 comes back as it is. */
    private static function trim(string $value): string
    {
        $class = '['.self::WHITESPACE.']';
        if (preg_match('/\A'.$class.'*+(.*[^'.self::WHITESPACE.'])?/su', $value, $match) !== 1) {
            return $value;
        }

        return $match[1] ?? '';
    }

    private static function positiveNumber(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite($value) && $value > 0 ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    private static function optionalString(#[\SensitiveParameter] array $raw, string $name): ?string
    {
        $value = $raw[$name] ?? null;
        if ($value !== null && ! is_string($value)) {
            throw self::invalid($name.' must be a string.');
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function flag(#[\SensitiveParameter] array $raw, string $name, bool $default): bool
    {
        $value = $raw[$name] ?? $default;
        if (! is_bool($value)) {
            throw self::invalid($name.' must be true or false.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     */
    private static function strings(#[\SensitiveParameter] array $raw, string $name): array
    {
        $value = $raw[$name] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            throw self::invalid($name.' must be a list of strings.');
        }
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw self::invalid($name.' must be a list of strings.');
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $raw */
    private static function callback(#[\SensitiveParameter] array $raw, string $name): ?\Closure
    {
        $value = $raw[$name] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_callable($value)) {
            throw self::invalid($name.' must be callable.');
        }

        return \Closure::fromCallable($value);
    }

    /**
     * The options without the caller's callbacks, whose captured variables could hold anything (a resume token among them).
     *
     * @return array<mixed>
     */
    public function __debugInfo(): array
    {
        $fields = get_object_vars($this);
        unset($fields['callbacks']);
        foreach (['onProgress', 'onResumeToken'] as $callback) {
            $fields[$callback] = $fields[$callback] === null ? null : '…[callback]';
        }

        return $fields;
    }

    private static function invalid(string $message): PreflightError
    {
        return new PreflightError($message, 'invalid_option');
    }
}
