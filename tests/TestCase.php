<?php

namespace Tests;

use App\Domain\Settings;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Settings are cached in a static; the database is rolled back between tests but the cache is not.
        Settings::flush();
        parent::setUp();
    }
}
