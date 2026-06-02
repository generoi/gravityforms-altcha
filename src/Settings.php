<?php

namespace Genero\GravityFormsAltcha;

/**
 * Gravity Forms add-on that exposes the ALTCHA configuration as admin UI:
 *
 * - A global "Enable for all forms" toggle under Forms → Settings → ALTCHA.
 *   Off by default — the plugin is opt-in.
 * - A per-form "Enable ALTCHA for this form" toggle under each form's
 *   Settings → ALTCHA tab.
 *
 * A form is protected when either the global toggle is on, or that form's
 * own toggle is on. See {@see self::isEnabledForForm()}.
 *
 * The class extends \GFAddOn, which only exists once Gravity Forms has loaded
 * its add-on framework. It is therefore registered on `gform_loaded` (see
 * {@see Plugin::registerAddon()}) and never autoloaded before that point.
 */
class Settings extends \GFAddOn
{
    protected $_version = Plugin::VERSION;

    protected $_min_gravityforms_version = '2.5';

    protected $_slug = Plugin::SLUG;

    protected $_title = 'ALTCHA for Gravity Forms';

    protected $_short_title = 'ALTCHA';

    private static ?Settings $instance = null;

    public function __construct()
    {
        // Lets GFAddOn::update_path() derive _path/_url from the real plugin
        // file rather than this class file inside src/.
        $this->_full_path = Plugin::getInstance()->file;

        parent::__construct();
    }

    public static function get_instance(): Settings
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Global settings page (Forms → Settings → ALTCHA).
     *
     * @return array<int, array<string, mixed>>
     */
    public function plugin_settings_fields()
    {
        return [
            [
                'title' => esc_html__('ALTCHA spam protection', 'gravityforms-altcha'),
                'description' => esc_html__('Invisible proof-of-work spam protection for Gravity Forms — no captcha puzzles, no third-party requests.', 'gravityforms-altcha'),
                'fields' => [
                    [
                        'name' => 'enable_all_forms',
                        'type' => 'toggle',
                        'label' => esc_html__('Enable for all forms', 'gravityforms-altcha'),
                        'tooltip' => esc_html__('When on, ALTCHA protects every Gravity Form on the site. When off, enable it per form from the form\'s ALTCHA settings tab.', 'gravityforms-altcha'),
                        'default_value' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Per-form settings tab (form → Settings → ALTCHA).
     *
     * @param  array<string, mixed>  $form
     * @return array<int, array<string, mixed>>
     */
    public function form_settings_fields($form)
    {
        return [
            [
                'title' => esc_html__('ALTCHA spam protection', 'gravityforms-altcha'),
                'fields' => [
                    [
                        'name' => 'enabled',
                        'type' => 'toggle',
                        'label' => esc_html__('Enable ALTCHA for this form', 'gravityforms-altcha'),
                        'tooltip' => esc_html__('Adds invisible ALTCHA spam protection to this form. Has no extra effect when "Enable for all forms" is turned on globally.', 'gravityforms-altcha'),
                        'default_value' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * Whether ALTCHA should protect a given form, based on the saved settings:
     * the global "enable for all forms" toggle wins, otherwise the form's own
     * per-form toggle decides. Both default off.
     *
     * @param  array<string, mixed>  $form
     */
    public static function isEnabledForForm(array $form): bool
    {
        $addon = self::get_instance();

        if ($addon->get_plugin_setting('enable_all_forms')) {
            return true;
        }

        $formSettings = $addon->get_form_settings($form);

        return is_array($formSettings) && ! empty($formSettings['enabled']);
    }
}
