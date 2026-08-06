<?php

it('renders the recorder page', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('vikunja-voice')
        ->assertSee('id="record"', escape: false)
        ->assertSee('manifest.json');
});
