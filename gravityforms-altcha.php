<?php

use Genero\GravityFormsAltcha\Plugin;

/*
 * Plugin Name:       ALTCHA for Gravity Forms
 * Plugin URI:        https://github.com/generoi/gravityforms-altcha
 * Description:       Invisible ALTCHA spam protection for Gravity Forms — uses the MIT-licensed altcha-org/altcha PHP library and the ALTCHA widget web component to proof-of-work-verify every form submission with no user interaction.
 * Version:           0.6.1
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Genero
 * Author URI:        https://genero.fi
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       gravityforms-altcha
 * Domain Path:       /resources/lang
 */

if (! defined('ABSPATH')) {
    exit;
}

Plugin::getInstance($plugin_file = __FILE__)->boot();
