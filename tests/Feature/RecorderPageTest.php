<?php

it('renders the recorder page', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Vikunja Voice')
        ->assertSee('id="record"', escape: false)
        ->assertSee('manifest.json');
});

it('renders the English UI by default', function () {
    $this->get('/')
        ->assertSee('<html lang="en">', escape: false)
        ->assertSee('Ready')
        ->assertSee('Send');
});

it('renders the Czech UI when the input locale is cs', function () {
    config()->set('voice.locale', 'cs');

    $this->get('/')
        ->assertSee('<html lang="cs">', escape: false)
        ->assertSee('Připraveno')
        ->assertSee('Odeslat');
});

it('exposes translated strings to the recorder script', function () {
    $this->get('/')
        ->assertSee('window.VV', escape: false)
        ->assertSee('Sending');

    config()->set('voice.locale', 'cs');

    $this->get('/')->assertSee('Znovu');
});

it('tells crawlers not to index the app', function () {
    $this->get('/')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', escape: false);
});
