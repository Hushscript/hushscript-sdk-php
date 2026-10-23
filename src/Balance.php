<?php

declare(strict_types=1);

namespace Hushscript;

use Hushscript\Http\Body;

final readonly class Balance
{
    use RawBody;

    /**
     * @param  int  $seconds  spendable now, already net of holds (not "balance minus held"); may be negative (clawback debt)
     * @param  int  $minutes  `seconds / 60`, rounded toward zero
     * @param  int  $heldSeconds  committed to in-flight jobs, and already deducted from `seconds`
     * @param  ?\DateTimeImmutable  $expiresAt  always null; minutes do not expire
     * @param  ?int  $purchasesRemaining  agent accounts only, else null
     */
    private function __construct(
        public int $seconds,
        public int $minutes,
        public int $heldSeconds,
        public ?\DateTimeImmutable $expiresAt,
        public ?int $purchasesRemaining,
        /** @var array<mixed> */
        #[\SensitiveParameter] private array $raw,
    ) {
    }

    /**
     * The balance a claim reports: the pack just granted. A new account holds nothing and has no expiry in the answer.
     * `toJson()` holds the one field it was read from; the whole claim answer is the account's `toJson()`.
     *
     * @internal
     */
    public static function granted(int $seconds): self
    {
        return new self($seconds, intdiv($seconds, 60), 0, null, null, ['balance_seconds' => $seconds]);
    }

    /** @internal */
    public static function fromApi(#[\SensitiveParameter] Body $account): self
    {
        $seconds = $account->integer('balance_seconds');

        return new self(
            $seconds,
            intdiv($seconds, 60),
            $account->count('held_seconds'),
            $account->nullableTimestamp('credits_expire_at'),
            $account->nullableCount('purchases_remaining_in_window'),
            $account->raw(),
        );
    }
}
