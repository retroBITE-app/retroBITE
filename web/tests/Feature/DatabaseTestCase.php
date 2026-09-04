<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Bootstrap;
use App\Support\SettingsOverrides;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a real (temporary) SQLite database with the schema
 * applied. The file is rebuilt once per class and cleared between tests.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $booted = false;

    public static function setUpBeforeClass(): void
    {
        if (self::$booted) {
            return;
        }

        @unlink((string) config('settings.db_path'));

        require_once dirname(__DIR__, 2) . '/bootstrap/database.php';
        Bootstrap::prepare();

        self::$booted = true;
    }

    protected function setUp(): void
    {
        Capsule::table('settings')->delete();
        SettingsOverrides::invalidate();
    }

    protected function tearDown(): void
    {
        Capsule::table('settings')->delete();
        SettingsOverrides::invalidate();
    }
}
