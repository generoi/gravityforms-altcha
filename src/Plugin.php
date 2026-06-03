<?php

namespace Genero\GravityFormsAltcha;

class Plugin
{
    public const VERSION = '0.5.0';

    public const SLUG = 'gravityforms-altcha';

    private static ?Plugin $instance = null;

    public readonly string $file;

    public readonly string $path;

    public readonly string $url;

    private function __construct(string $file)
    {
        $this->file = $file;
        $this->path = plugin_dir_path($file);
        $this->url = plugin_dir_url($file);
    }

    public static function getInstance(?string $file = null): self
    {
        if (self::$instance === null) {
            if ($file === null) {
                throw new \RuntimeException('Plugin::getInstance() requires the bootstrap file path on first invocation.');
            }
            self::$instance = new self($file);
        }

        return self::$instance;
    }

    public function boot(): void
    {
        add_action('plugins_loaded', [$this, 'loadTextdomain']);
        add_action('plugins_loaded', [$this, 'registerHooks'], 20);
        add_action('gform_loaded', [$this, 'registerAddon'], 5);
    }

    public function loadTextdomain(): void
    {
        load_plugin_textdomain(
            'gravityforms-altcha',
            false,
            dirname(plugin_basename($this->file)).'/resources/lang'
        );
    }

    /**
     * Wire up the integration only when Gravity Forms is available; without it
     * there's nothing to protect. The check also avoids fatal-loading the
     * Integration class against a missing GF API on plugin activation order
     * edge cases.
     */
    public function registerHooks(): void
    {
        if (! class_exists(\GFForms::class)) {
            return;
        }

        // Route decision logs into Gravity Forms' logging framework
        // (Forms → Settings → Logging — per-add-on level + downloadable file).
        add_action(Logger::HOOK, [Settings::class, 'logToGravityForms'], 10, 3);

        Integration::register();
        ChallengeEndpoint::register();
        SpamFilter::register();
        EmailValidator::register();
    }

    /**
     * Registers the Gravity Forms add-on that powers the global + per-form
     * settings UI. Runs on `gform_loaded` so the add-on framework — and thus
     * the `\GFAddOn` base class — is guaranteed to be available.
     */
    public function registerAddon(): void
    {
        if (! method_exists('\GFForms', 'include_addon_framework')) {
            return;
        }

        \GFForms::include_addon_framework();
        \GFAddOn::register(Settings::class);
    }

    /**
     * The HMAC key used to sign + verify challenges. Resolved from (in order):
     * 1. The `genero/gravityforms_altcha/hmac_key` filter (lets consumers point
     *    at a dedicated secret).
     * 2. A persistent option, lazily generated on first use.
     *
     * Stored as an option rather than reusing AUTH_KEY so rotating WP salts
     * doesn't silently invalidate every in-flight challenge.
     */
    public function hmacKey(): string
    {
        $filtered = apply_filters('genero/gravityforms_altcha/hmac_key', null);
        if (is_string($filtered) && $filtered !== '') {
            return $filtered;
        }

        $key = get_option('gravityforms_altcha_hmac_key');
        if (! is_string($key) || $key === '') {
            $key = bin2hex(random_bytes(32));
            update_option('gravityforms_altcha_hmac_key', $key, false);
        }

        return $key;
    }
}
