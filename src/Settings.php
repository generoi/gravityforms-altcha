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
                    [
                        'name' => 'cost',
                        'type' => 'select',
                        'label' => esc_html__('Protection strength', 'gravityforms-altcha'),
                        'tooltip' => esc_html__('How hard the background proof-of-work is. Stronger settings cost bots more per submission but take longer to solve on low-end devices — the work runs while the form is being filled, so it is normally invisible. Can be overridden per form.', 'gravityforms-altcha'),
                        'default_value' => (string) Challenge::DEFAULT_COST,
                        'choices' => self::costChoices(false),
                    ],
                    [
                        'name' => 'enable_rate_limit',
                        'type' => 'toggle',
                        'label' => esc_html__('Rate limiting', 'gravityforms-altcha'),
                        'tooltip' => esc_html__('Flags submissions as spam (recoverable — never blocked) once an IP submits the same form more than a few times an hour. Applies to all Gravity Forms.', 'gravityforms-altcha'),
                        'default_value' => false,
                    ],
                    [
                        'name' => 'enable_content_filter',
                        'type' => 'toggle',
                        'label' => esc_html__('Content spam filtering', 'gravityforms-altcha'),
                        'tooltip' => esc_html__('Flags submissions as spam (recoverable — never blocked) when the message contains definite-spam keywords or several spam signals. Applies to all Gravity Forms.', 'gravityforms-altcha'),
                        'default_value' => false,
                    ],
                    [
                        'name' => 'enable_email_validation',
                        'type' => 'toggle',
                        'label' => esc_html__('Email validation', 'gravityforms-altcha'),
                        'tooltip' => esc_html__('Asks the visitor to correct a bad email address before submitting. Verifies via Bouncer — requires the BOUNCER_API_KEY environment variable, and sends the email to a third-party service. Fails open (never blocks) if the service is unavailable. Applies to all Gravity Forms.', 'gravityforms-altcha'),
                        'default_value' => false,
                    ],
                    [
                        'name' => 'email_block',
                        'type' => 'checkbox',
                        'label' => esc_html__('Reject addresses that are', 'gravityforms-altcha'),
                        'dependency' => [
                            'live' => true,
                            'fields' => [['field' => 'enable_email_validation']],
                        ],
                        'choices' => [
                            [
                                'name' => 'email_block_undeliverable',
                                'label' => esc_html__('Undeliverable — the mailbox or domain does not exist', 'gravityforms-altcha'),
                                'default_value' => true,
                            ],
                            [
                                'name' => 'email_block_risky',
                                'label' => esc_html__('Risky — catch-all, role, or low-quality (may reject some real addresses)', 'gravityforms-altcha'),
                                'default_value' => false,
                            ],
                            [
                                'name' => 'email_block_disposable',
                                'label' => esc_html__('Disposable — a temporary, throwaway inbox', 'gravityforms-altcha'),
                                'default_value' => true,
                            ],
                        ],
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
                    [
                        'name' => 'cost',
                        'type' => 'select',
                        'label' => esc_html__('Protection strength', 'gravityforms-altcha'),
                        'tooltip' => esc_html__('Override the protection strength for this form only, or inherit the site-wide ALTCHA setting.', 'gravityforms-altcha'),
                        'default_value' => '',
                        'choices' => self::costChoices(true),
                    ],
                ],
            ],
        ];
    }

    /**
     * Preset protection strengths shown in the settings dropdowns. Values are
     * proof-of-work costs (PBKDF2 iterations); labels describe the trade-off in
     * plain terms with a rough solve time so admins don't have to reason about
     * raw numbers. Power users can still set any exact value via the
     * `genero/gravityforms_altcha/cost` filter.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function costChoices(bool $includeInherit): array
    {
        $choices = [];

        if ($includeInherit) {
            $choices[] = ['label' => esc_html__('Inherit site-wide setting', 'gravityforms-altcha'), 'value' => ''];
        }

        return array_merge($choices, [
            ['label' => esc_html__('Low — lightest, weakest deterrent (~1s)', 'gravityforms-altcha'), 'value' => '50000'],
            ['label' => esc_html__('Standard — recommended balance (~4s)', 'gravityforms-altcha'), 'value' => (string) Challenge::DEFAULT_COST],
            ['label' => esc_html__('High — stronger deterrent (~10s)', 'gravityforms-altcha'), 'value' => '500000'],
            ['label' => esc_html__('Very high — strongest, may briefly delay submit on old devices (~20s)', 'gravityforms-altcha'), 'value' => '1000000'],
        ]);
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

    public static function rateLimitEnabled(): bool
    {
        return (bool) self::get_instance()->get_plugin_setting('enable_rate_limit');
    }

    public static function contentFilterEnabled(): bool
    {
        return (bool) self::get_instance()->get_plugin_setting('enable_content_filter');
    }

    public static function emailValidationEnabled(): bool
    {
        return (bool) self::get_instance()->get_plugin_setting('enable_email_validation');
    }

    /**
     * Which Bouncer verdicts the admin has opted to reject. Unchecked / unset →
     * false, so a verdict is only ever acted on when explicitly enabled.
     *
     * @return array{undeliverable: bool, risky: bool, disposable: bool}
     */
    public static function emailBlockModes(): array
    {
        $addon = self::get_instance();

        return [
            'undeliverable' => (bool) $addon->get_plugin_setting('email_block_undeliverable'),
            'risky' => (bool) $addon->get_plugin_setting('email_block_risky'),
            'disposable' => (bool) $addon->get_plugin_setting('email_block_disposable'),
        ];
    }

    /**
     * Resolves the proof-of-work cost for a form: the per-form override wins,
     * then the global setting, then the built-in default. The result is clamped
     * to a sane range so a typo can't lock visitors out (or make the proof
     * trivial). Pass null when no form context is available (the global/default
     * applies).
     */
    public static function costForForm(?int $formId): int
    {
        $addon = self::get_instance();

        $perForm = null;
        if ($formId !== null && class_exists('\GFAPI')) {
            $form = \GFAPI::get_form($formId);
            if (is_array($form)) {
                $formSettings = $addon->get_form_settings($form);
                $perForm = is_array($formSettings) ? ($formSettings['cost'] ?? null) : null;
            }
        }

        $global = $addon->get_plugin_setting('cost');

        $cost = Challenge::DEFAULT_COST;
        foreach ([$perForm, $global] as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                $cost = (int) $candidate;
                break;
            }
        }

        return Challenge::clampCost($cost);
    }
}
