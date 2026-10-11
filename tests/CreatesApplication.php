<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require __DIR__ . '/../bootstrap/app.php';

        $this->guardAgainstCachedConfig($app);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Refuse to run against a cached configuration.
     *
     * phpunit.xml pins DB_CONNECTION to an in-memory SQLite database and
     * MAIL_MAILER to the array transport, and calls the database "the backstop
     * so a test can never reach the developer's own MySQL data". Both are
     * environment variables - and a cached config does not consult the
     * environment at all. So with bootstrap/cache/config.php present (left by
     * `artisan optimize` or `config:cache`, which is easy to run without
     * thinking about the test suite) those two settings are silently discarded
     * and the suite runs against the real database and a live mail transport.
     *
     * Nothing about the failure announces itself: the tests still pass, against
     * the wrong data. So it fails here instead, with the one command that fixes
     * it.
     */
    private function guardAgainstCachedConfig(Application $app): void
    {
        if (!file_exists($app->getCachedConfigPath())) {
            return;
        }

        throw new RuntimeException(
            'The test suite cannot run with a cached configuration: bootstrap/cache/config.php '
            . 'overrides phpunit.xml, which is what keeps tests off your real database and '
            . 'mail server. Run `php artisan config:clear` and try again.'
        );
    }
}
