<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase
{
    use CreatesApplication, RefreshDatabase;

    /**
     * Boot the testing helpers.
     *
     * Passport requires real RSA keys on disk to sign personal access tokens,
     * and a "personal access" oauth_clients row to issue them via
     * HasApiTokens::createToken() (see Laravel\Passport\Bridge\PersonalAccessGrant).
     * Both are recreated here rather than relying on DatabaseSeeder, which pulls
     * in the full app seeder chain and is far too heavy for a fast test suite.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePassportKeysExist();
        $this->ensurePassportPersonalAccessClientExists();
    }

    private function ensurePassportKeysExist(): void
    {
        $privateKey = storage_path('oauth-private.key');
        $publicKey = storage_path('oauth-public.key');

        if (! File::exists($privateKey) || ! File::exists($publicKey)) {
            Artisan::call('passport:keys', ['--force' => false]);
        }
    }

    private function ensurePassportPersonalAccessClientExists(): void
    {
        $hasPersonalClient = DB::table('oauth_clients')
            ->where('revoked', false)
            ->where('grant_types', 'like', '%"personal_access"%')
            ->exists();

        if (! $hasPersonalClient) {
            Artisan::call('passport:client', [
                '--personal' => true,
                '--provider' => 'users',
                '--name' => 'Testing Personal Access Client',
            ]);
        }
    }
}
