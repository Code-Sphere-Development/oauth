<?php

namespace CodeSphere\OAuth\Tests;

use CodeSphere\OAuth\OAuthServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OAuthServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.name', 'TestSphere');
        $app['config']->set('codesphere.url', 'https://account.code-sphere.test');
        $app['config']->set('codesphere.client_id', 'client-123');
    }
}
