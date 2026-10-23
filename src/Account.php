<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

final readonly class Account
{
    use RawBody;

    /**
     * @param  int  $balanceSeconds  net of holds and clawback debt; may be negative
     * @param  int  $heldSeconds  committed to in-flight jobs, and already deducted from `balanceSeconds`
     * @param  ?\DateTimeImmutable  $creditsExpireAt  always null; minutes do not expire
     * @param  string  $dataRegion  for example `us`, `eu`, `apac` or `oc`
     * @param  ?int  $purchasesRemainingInWindow  agent accounts only, else null
     */
    private function __construct(
        public int $balanceSeconds,
        public int $heldSeconds,
        public ?\DateTimeImmutable $creditsExpireAt,
        public bool $cardOnFile,
        public string $dataRegion,
        public UploadLimits $limits,
        public ?int $purchasesRemainingInWindow,
        /** @var array<mixed> */
        #[\SensitiveParameter] private array $raw,
    ) {
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $account): self
    {
        return new self(
            $account->integer('balance_seconds'),
            $account->count('held_seconds'),
            $account->nullableTimestamp('credits_expire_at'),
            $account->bool('card_on_file'),
            $account->string('data_region'),
            UploadLimits::fromApi($account->child('limits')),
            $account->nullableCount('purchases_remaining_in_window'),
            $account->raw(),
        );
    }
}
