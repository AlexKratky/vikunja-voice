<?php

it('renders the recorder page', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('Vikunja Voice')
        ->assertSee('id="record"', escape: false)
        ->assertSee('manifest.json');
});

it('tells crawlers not to index the app', function () {
    $this->get('/')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive">', escape: false);
});
