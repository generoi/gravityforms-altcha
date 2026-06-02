<?php

/**
 * Bootstrap for the CI-friendly suites (unit + mocked). No WordPress, no DB —
 * just the autoloader and the handful of WP time constants the code references
 * at runtime. The mocked suite stubs WP functions with Brain\Monkey.
 */

require_once dirname(__DIR__).'/vendor/autoload.php';

defined('MINUTE_IN_SECONDS') || define('MINUTE_IN_SECONDS', 60);
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
