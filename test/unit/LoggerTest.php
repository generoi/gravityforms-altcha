<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests;

use Genero\GravityFormsAltcha\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function test_format_without_context(): void
    {
        $this->assertSame('altcha: pass', Logger::format('altcha', 'pass'));
    }

    public function test_format_with_context(): void
    {
        $this->assertSame(
            'altcha: fail {"form":43,"reason":"invalid"}',
            Logger::format('altcha', 'fail', ['form' => 43, 'reason' => 'invalid']),
        );
    }

    public function test_failures_log_at_error_level(): void
    {
        foreach (['fail', 'blocked', 'spam'] as $outcome) {
            $this->assertTrue(Logger::isFailure($outcome), $outcome);
        }
        foreach (['pass', 'allowed'] as $outcome) {
            $this->assertFalse(Logger::isFailure($outcome), $outcome);
        }
    }
}
