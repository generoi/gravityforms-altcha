<?php

declare(strict_types=1);

namespace Genero\GravityFormsAltcha\Tests\Mocked;

use Brain\Monkey\Actions;
use Genero\GravityFormsAltcha\Logger;

class LoggerRecordTest extends MockedTestCase
{
    public function test_record_fires_the_log_action(): void
    {
        Actions\expectDone(Logger::HOOK)->once()->with('rate_limit', 'blocked', ['form' => 1]);

        Logger::record('rate_limit', 'blocked', ['form' => 1]);
    }
}
