<?php

namespace App\Http\Controllers\WritePathFixtures;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;

final class EntryPointWrites
{
    public function deletesAModel(User $user): void
    {
        $user->delete();
    }

    public function updatesThroughTheQueryBuilder(): void
    {
        User::query()->where('name', 'x')->update(['name' => 'y']);
    }

    public function insertsThroughTheDbFacade(): void
    {
        DB::table('users')->insert(['name' => 'x']);
    }

    public function runsARawStatement(): void
    {
        DB::statement('select 1');
    }

    public function createsStatically(): void
    {
        User::create(['name' => 'x']);
    }

    public function deletesAFileNotARow(Filesystem $storage): void
    {
        $storage->delete('a.txt');
    }

    public function onlyReads(): void
    {
        User::query()->where('name', 'x')->get();
    }
}
