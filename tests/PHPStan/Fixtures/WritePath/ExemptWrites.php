<?php

namespace App\Http\Controllers\WritePathFixtures;

use App\Models\User;

final class ExemptWrites
{
    public function deletesAModel(User $user): void
    {
        $user->delete();
    }
}
