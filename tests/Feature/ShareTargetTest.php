<?php

it('bounces a share-target POST back to the app when no service worker handled it', function () {
    $this->post('/share-target')
        ->assertStatus(303)
        ->assertRedirect('/?share-target=unavailable');
});

it('advertises a share target in the manifest that matches the fallback route', function () {
    $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);

    expect($manifest)->toHaveKey('share_target')
        ->and($manifest['share_target']['action'])->toBe('/share-target')
        ->and($manifest['share_target']['method'])->toBe('POST')
        ->and($manifest['share_target']['enctype'])->toBe('multipart/form-data')
        ->and($manifest['share_target']['params']['files'][0]['name'])->toBe('audio')
        ->and($manifest['share_target']['params']['files'][0]['accept'])->toContain('audio/*');
});
