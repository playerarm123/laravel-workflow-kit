<?php

namespace App\Domain\Billing\Wallet;

use App\Domain\Shared\AggregateRoot;
use Override;

final class WalletEntity extends AggregateRoot
{
    private function __construct(
        private string $id,
        // define attribute here
    ) {}

    #[Override]
    public static function entityName(): string
    {
        return 'Wallet';
    }

    /**
     * ================================================
     * Building Section
     * ================================================
     */
    public static function create(
        string $id,
    ): self {
        return new self(
            id: $id,
        );
    }

    public static function reconstitute(
        string $id,
    ): self {
        return new self(
            id: $id,
        );
    }

    /**
     * ================================================
     * Behavior Section
     * ================================================
     */

    /**
     * ================================================
     * Getter Section
     * ================================================
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * ================================================
     * Asserting Section
     * ================================================
     */
}
