<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Genero\GravityFormsAltcha\Logger;

class LoggerRecordTest extends MockedTestCase
{
    public function test_records_nothing_when_disabled(): void
    {
        Functions\when('apply_filters')->justReturn(false);
        Actions\expectDone(Logger::HOOK)->never();

        Logger::record('altcha', 'pass', ['form' => 1]);
    }

    public function test_fires_the_log_action_when_enabled(): void
    {
        Functions\when('apply_filters')->justReturn(true);
        Actions\expectDone(Logger::HOOK)->once()->with('rate_limit', 'blocked', ['form' => 1]);

        Logger::record('rate_limit', 'blocked', ['form' => 1]);
    }
}
