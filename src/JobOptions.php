<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

/**
 * What a job was submitted with, from the API's content-free summary: it says whether keyterms and a
 * prompt were set, never what they were.
 */
final readonly class JobOptions
{
    use RawBody;

    /**
     * @param  ?string  $languageCode  the forced language, or null when it was auto-detected
     * @param  list<string>  $translationTargets
     * @param  ?bool  $autoGenerateInsights  the upload's insights override, or null when it used the account default
     */
    private function __construct(
        public ?string $languageCode,
        public bool $speakerDetection,
        public bool $medicalMode,
        public bool $vetMode,
        public bool $keepDisfluencies,
        public bool $multichannel,
        public array $translationTargets,
        public bool $keyterms,
        public bool $prompt,
        public ?bool $autoGenerateInsights,
        /** @var array<mixed> */
        #[\SensitiveParameter] private array $raw,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $options): self
    {
        return new self(
            $options->nullableString('language_code'),
            $options->bool('speaker_detection'),
            $options->bool('medical_mode'),
            $options->bool('vet_mode'),
            $options->bool('keep_disfluencies'),
            $options->bool('multichannel'),
            $options->strings('translation_targets'),
            $options->bool('keyterms'),
            $options->bool('prompt'),
            $options->nullableBool('auto_generate_insights'),
            $options->raw(),
        );
    }
}
