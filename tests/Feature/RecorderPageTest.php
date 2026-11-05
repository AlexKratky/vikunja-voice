<?php

it('renders the recorder page', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Vikunja Voice')
        ->assertSee('id="record"', escape: false)
        ->assertSee('id="import"', escape: false)
        ->assertSee('id="import-audio"', escape: false)
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

it('renders the delete-all history button', function () {
    $this->get('/')
        ->assertSee('id="clear-history"', escape: false)
        ->assertSee('Delete all')
        ->assertSee('Delete the entire history?');

    config()->set('voice.locale', 'cs');

    $this->get('/')
        ->assertSee('Smazat vše')
        ->assertSee('Opravdu smazat celou historii?');
});

it('renders share and download buttons in the preview', function () {
    $this->get('/')
        ->assertSee('id="share-preview"', escape: false)
        ->assertSee('id="download-preview"', escape: false)
        ->assertSee('Download recording');

    config()->set('voice.locale', 'cs');

    $this->get('/')->assertSee('Stáhnout nahrávku');
});

it('renders the microphone picker hidden until inputs are known', function () {
    $this->get('/')
        ->assertSee('<div id="mic-row" class="mic-row" hidden>', escape: false)
        ->assertSee('<select id="mic-select" aria-label="Microphone">', escape: false);

    config()->set('voice.locale', 'cs');

    $this->get('/')->assertSee('aria-label="Mikrofon"', escape: false);
});

it('exposes the continue-recording strings to the recorder script', function () {
    $this->get('/')
        ->assertSee('Continue recording')
        ->assertSee('tap the mic to add more');

    config()->set('voice.locale', 'cs');

    // @json escapes non-ASCII, so the Czech string appears \u-escaped.
    $this->get('/')->assertSee('Pokra\u010dovat v nahr\u00e1v\u00e1n\u00ed', escape: false);
});

it('exposes the unsent badge string for recordings not yet sent', function () {
    $this->get('/')
        ->assertSee('not sent');

    config()->set('voice.locale', 'cs');

    // @json escapes non-ASCII, so the Czech string appears \u-escaped.
    $this->get('/')->assertSee('neodesl\u00e1no', escape: false);
});

it('exposes the silenced-microphone strings to the recorder script', function () {
    $this->get('/')
        ->assertSee('The microphone went silent')
        ->assertSee('Recording stopped');

    config()->set('voice.locale', 'cs');

    // @json escapes non-ASCII, so the Czech string appears \u-escaped.
    $this->get('/')->assertSee('Mikrofon ztichl, nahr\u00e1v\u00e1n\u00ed se zastavilo', escape: false);
});

it('tells crawlers not to index the app', function () {
    $this->get('/')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', escape: false);
});
