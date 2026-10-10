<?php

namespace Tests;

use App\Contracts\IdentityAuthority;
use App\Models\CentralIdentity;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeIdentityAuthority;

abstract class TestCase extends BaseTestCase
{
    public function actingAs($user, $guard = null)
    {
        parent::actingAs($user, $guard);
        $identity = CentralIdentity::where('user_id', $user->id)->first();
        $this->withSession(['central.identity_epoch' => (int) ($identity?->session_epoch ?? 0)]);

        return $this;
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.$connection.database");
        if (! (($connection === 'sqlite' && $database === ':memory:') || ($connection === 'pgsql' && $database === 'central_test'))) {
            throw new \RuntimeException('Test database guard refused: only SQLite memory or central_test are permitted.');
        }
        $app['config']->set('central.agents_enabled', false);
        $app['config']->set('central.exclusive_enabled', false);
        $app['config']->set('central.internal_only', false);
        $app->instance(IdentityAuthority::class, new FakeIdentityAuthority);
        Http::preventStrayRequests();

        return $app;
    }
}
