<?php

namespace App\Domain\Billing\Wallet;

interface WalletRepository
{
    public function save(WalletEntity $entity): void;

    public function update(WalletEntity $entity): void;

    public function getById(string $id): WalletEntity;
}
