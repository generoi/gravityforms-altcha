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

/*
 * Settings extends \GFAddOn, which only exists once Gravity Forms has loaded
 * its add-on framework. Anything exercising Integration::shouldProtect() pulls
 * Settings in, so give the class a shell to extend.
 *
 * Deliberately inert: it reports no settings, so a test needing a particular
 * protection decision should force it through the documented
 * `genero/gravityforms_altcha/should_protect` filter rather than leaning on
 * whatever this stub happens to return.
 */
if (! class_exists('GFAddOn')) {
    class GFAddOn
    {
        /** Declared by the real GFAddOn; Settings::__construct() assigns it. */
        protected $_full_path;

        public function __construct() {}

        public static function register(string $class): void {}

        /** @return mixed */
        public function get_plugin_setting(string $name)
        {
            return null;
        }

        /**
         * @param  array<string, mixed>  $form
         * @return array<string, mixed>
         */
        public function get_form_settings(array $form): array
        {
            return [];
        }

        public function log_debug(string $message): void {}

        public function log_error(string $message): void {}
    }
}
