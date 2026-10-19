<?php

use App\Http\Navigation;
use App\Models\User;

/**
 * Every link the menu shows the user, a group's entries among them.
 *
 * @return list<string>
 */
function navigationHrefs(?User $user): array
{
    return collect(Navigation::for($user))
        ->flatMap(fn (array $entry): array => $entry['items'] ?? [$entry])
        ->pluck('href')
        ->all();
}

test('the menu opens with the dashboard', function () {
    expect(Navigation::for(User::factory()->create())[0])->toBe([
        'title' => 'common.dashboard',
        'href' => route('dashboard', absolute: false),
        'icon' => 'layout-grid',
    ]);
});

test('the menu shows a page to a user its policy lets in', function () {
    expect(navigationHrefs(auditLogReader()))->toContain(route('audit-entries.index', absolute: false));
});

test('the menu leaves out a page its policy refuses the user', function () {
    $outsider = auditLogOutsider();

    if ($outsider === null) {
        $this->markTestSkipped('AuditEntryPolicy refuses no signed-in user: auditLogOutsider() is null');
    }

    expect(navigationHrefs($outsider))->not->toContain(route('audit-entries.index', absolute: false));
});
