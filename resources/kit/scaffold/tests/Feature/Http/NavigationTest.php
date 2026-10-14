<?php

use App\Http\Navigation;
use App\Models\User;

test('the menu opens with the dashboard', function () {
    expect(Navigation::for(User::factory()->create())[0])->toBe([
        'title' => 'common.dashboard',
        'href' => route('dashboard', absolute: false),
        'icon' => 'layout-grid',
    ]);
});

test('the menu shows a page to a user its policy lets in', function () {
    expect(array_column(Navigation::for(auditLogReader()), 'href'))->toContain(route('audit-entries.index', absolute: false));
});

test('the menu leaves out a page its policy refuses the user', function () {
    expect(array_column(Navigation::for(auditLogOutsider()), 'href'))->not->toContain(route('audit-entries.index', absolute: false));
});
