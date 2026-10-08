<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('every page shares what the kit reads on the client', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', app()->getLocale())
            ->where('timezone', config('app.timezone'))
            ->where('currency', config('app.currency'))
            ->has('translations')
        );
});

test('the translations carry the kit\'s keys', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('translations', fn ($translations): bool => $translations['common.save'] === __('common.save')));
});
