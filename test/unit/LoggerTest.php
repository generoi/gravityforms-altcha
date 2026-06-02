<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests;

use Genero\GravityFormsAltcha\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    public function test_format_without_context(): void
    {
        $this->assertSame('[gravityforms-altcha] altcha: pass', Logger::format('altcha', 'pass'));
    }

    public function test_format_with_context(): void
    {
        $this->assertSame(
            '[gravityforms-altcha] altcha: fail {"form":43,"reason":"invalid"}',
            Logger::format('altcha', 'fail', ['form' => 43, 'reason' => 'invalid']),
        );
    }
}
