<?php

namespace App\Http\Controllers\Settings;

use App\Models\User;

final class StarterKitWrites
{
    public function deletesAModel(User $user): void
    {
        $user->delete();
    }
}
