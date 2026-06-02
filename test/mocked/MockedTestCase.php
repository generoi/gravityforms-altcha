<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that exercise WP-glue code with WordPress functions stubbed
 * via Brain\Monkey — no WordPress, no database, runs in plain CI.
 */
abstract class MockedTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}
