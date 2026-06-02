<?php

/**
 * Bootstrap for the real-WordPress integration suite (wp-phpunit). Runs inside
 * wp-env or DDEV against a throwaway WP test database. Gravity Forms is loaded
 * when present; in CI it isn't (commercial), so the GF-dependent tests skip.
 *
 * Run with: composer test:integration
 */

require_once dirname(__DIR__).'/vendor/autoload.php';

$wpPhpunitDir = getenv('WP_PHPUNIT__DIR');
if (! $wpPhpunitDir) {
    fwrite(STDERR, "WP_PHPUNIT__DIR is not set. Run the integration suite inside wp-env or DDEV.\n");
    exit(1);
}

require_once $wpPhpunitDir.'/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    // wp-content/plugins, whether mounted by wp-env or installed in DDEV.
    $pluginsDir = dirname(__DIR__, 2);

    // Gravity Forms first, so the add-on framework is available when the plugin
    // registers. Commercial — absent in CI, where the GF tests skip.
    $gravityForms = $pluginsDir.'/gravityforms/gravityforms.php';
    if (file_exists($gravityForms)) {
        require_once $gravityForms;
    }

    require dirname(__DIR__).'/gravityforms-altcha.php';
});

require $wpPhpunitDir.'/includes/bootstrap.php';
