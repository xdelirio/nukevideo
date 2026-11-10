<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Sleep;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * A cached config (bootstrap/cache/config.php) shadows phpunit.xml's sqlite settings, which
     * would point RefreshDatabase at the real database and wipe it. Refuse to run instead.
     *
     * Read the cached file directly, before parent::setUp(): RefreshDatabase migrates from inside it
     * (setUpTraits), so by the time a config() call could answer, the real database is already gone.
     */
    protected function setUp(): void
    {
        $cached = __DIR__.'/../bootstrap/cache/config.php';

        if (file_exists($cached)) {
            $connection = data_get(require $cached, 'database.default');

            if ($connection !== 'sqlite') {
                throw new RuntimeException(
                    'Tests must run on sqlite, but bootstrap/cache/config.php selects "'.$connection.'". Run `php artisan config:clear`.'
                );
            }
        }

        parent::setUp();

        // Nothing under test should really wait: the S3 transfer retries back off for minutes.
        Sleep::fake();
    }
}
