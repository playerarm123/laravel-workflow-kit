<?php

test('the appearance cookie reaches the root view', function (string $appearance) {
    $this->withUnencryptedCookie('appearance', $appearance)
        ->get(route('home'))
        ->assertOk()
        ->assertViewHas('appearance', $appearance);
})->with(['light', 'dark', 'system']);

test('a visitor without the cookie gets the system appearance', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertViewHas('appearance', 'system');
});

test('a dark appearance marks the html element before the scripts load', function () {
    $this->withUnencryptedCookie('appearance', 'dark')
        ->get(route('home'))
        ->assertOk()
        ->assertSee('<html lang="en" class="dark">', escape: false);
});
