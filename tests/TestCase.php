<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case.
 *
 * Boots the real application from bootstrap/app.php, so tests exercise the same
 * container, config and service bindings the app runs with.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
