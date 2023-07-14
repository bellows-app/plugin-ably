<?php

use Bellows\Plugins\Ably;
use Bellows\PluginSdk\Facades\Project;
use Illuminate\Support\Facades\Http;

it('can choose an app from the list', function () {
    Http::fake([
        'me' => Http::response([
            'account' => [
                'id' => '123',
            ],
        ]),
        'accounts/123/apps' => Http::response([
            [
                'id'   => '456',
                'name' => Project::appName(),
            ],
        ]),
        'apps/456/keys' => Http::response([
            [
                'name' => 'Subscribe only',
                'key'  => 'subscribe_key',
            ],
        ]),
    ]);

    $result = $this->plugin(Ably::class)
        ->expectsQuestion('Which app do you want to use?', Project::appName())
        ->expectsQuestion('Which key do you want to use?', 'Subscribe only')
        ->deploy();

    expect($result->getEnvironmentVariables())->toBe([
        'BROADCAST_DRIVER' => 'ably',
        'ABLY_KEY'         => 'subscribe_key',
    ]);
});

it('can create a new app', function () {
    Http::fake([
        'me' => Http::response([
            'account' => [
                'id' => '123',
            ],
        ]),
        'accounts/123/apps' => Http::response([
            'id'   => '789',
            'name' => 'Test App',
        ]),
        'apps/789/keys' => Http::response([
            [
                'name' => 'Test Key',
                'key'  => 'test-key',
            ],
        ]),
    ]);

    $result = $this->plugin(Ably::class)
        ->expectsConfirmation('Create new app?', 'yes')
        ->expectsQuestion('App name', 'Test App')
        ->expectsQuestion('Which key do you want to use?', 'Test Key')
        ->deploy();

    expect($result->getEnvironmentVariables())->toEqual([
        'BROADCAST_DRIVER' => 'ably',
        'ABLY_KEY'         => 'test-key',
    ]);
});
