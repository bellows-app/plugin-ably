<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\HttpClient;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\AddApiCredentialsPrompt;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\Deployment;
use Bellows\PluginSdk\Facades\Entity;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;
use Illuminate\Http\Client\PendingRequest;

class Ably extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    protected const BROADCAST_DRIVER = 'ably';

    public function __construct(
        protected HttpClient $http,
    ) {
    }

    public function requiredComposerPackages(): array
    {
        return [
            'ably/ably-php',
        ];
    }

    public function install(): ?InstallationResult
    {
        return InstallationResult::create();
    }

    public function deploy(): ?DeploymentResult
    {
        $this->http->createJsonClient(
            'https://control.ably.net/v1/',
            fn (PendingRequest $request, array $credentials) => $request->withToken($credentials['token']),
            new AddApiCredentialsPrompt(
                url: 'https://ably.com/users/access_tokens',
                credentials: ['token'],
                displayName: 'Ably',
                requiredScopes: ['read:app', 'write:app', 'read:key'],
            ),
            fn (PendingRequest $request) => $request->get('me'),
        );

        $app = $this->getApp();

        $keys = collect($this->http->client()->get("apps/{$app['id']}/keys")->json());

        $key = Console::choiceFromCollection(
            'Which key do you want to use?',
            $keys,
            'name',
            Project::appName(),
        )['key'];

        return DeploymentResult::create()->environmentVariables([
            'BROADCAST_DRIVER' => self::BROADCAST_DRIVER,
            'ABLY_KEY'         => $key,
        ]);
    }

    public function shouldDeploy(): bool
    {
        return Deployment::site()->env()->get('BROADCAST_DRIVER') !== self::BROADCAST_DRIVER
            || !Deployment::site()->env()->has('ABLY_KEY');
    }

    public function confirmDeploy(): bool
    {
        return Deployment::confirmChangeValueTo(
            Deployment::site()->env()->get('BROADCAST_DRIVER'),
            self::BROADCAST_DRIVER,
            'Change broadcast driver to Ably'
        );
    }

    protected function getApp(): array
    {
        $me = $this->http->client()->get('me')->json();
        $accountId = $me['account']['id'];

        $apps = collect($this->http->client()->get("accounts/{$accountId}/apps")->json());

        return Entity::from($apps)
            ->selectFromExisting(
                'Which app do you want to use?',
                'name',
                Project::appName(),
                'Create new app',
            )
            ->createNew('Create new app?', fn () => $this->createNewApp($accountId))
            ->prompt();
    }

    protected function createNewApp(string $accountId)
    {
        $appName = Console::ask('App name', Project::appName());

        return $this->http->client()->post("accounts/{$accountId}/apps", [
            'name' => $appName,
        ])->json();
    }
}
