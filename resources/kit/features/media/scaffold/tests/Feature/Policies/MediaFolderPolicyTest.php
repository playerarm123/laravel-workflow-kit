<?php

use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Each ability of MediaFolderPolicy, allowed and denied, asked the way a controller asks it.
 */
beforeEach(function () {
    $this->mediaFolder = MediaFolder::factory()->create();
});

it('allows a user who verified their email to keep the folders', function () {
    $gate = Gate::forUser(User::factory()->create());

    expect($gate->allows('create', MediaFolder::class))->toBeTrue()
        ->and($gate->allows('update', $this->mediaFolder))->toBeTrue()
        ->and($gate->allows('delete', $this->mediaFolder))->toBeTrue();
});

it('denies a user who has not', function () {
    $gate = Gate::forUser(User::factory()->unverified()->create());

    expect($gate->allows('create', MediaFolder::class))->toBeFalse()
        ->and($gate->allows('update', $this->mediaFolder))->toBeFalse()
        ->and($gate->allows('delete', $this->mediaFolder))->toBeFalse();
});
