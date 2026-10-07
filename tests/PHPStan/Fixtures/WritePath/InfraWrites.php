<?php

namespace App\Infra\WritePathFixtures;

use App\Models\User;

final class InfraWrites
{
    public function deletesAModel(User $user): void
    {
        $user->delete();
    }
}
