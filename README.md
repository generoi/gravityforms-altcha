# ALTCHA for Gravity Forms

Invisible [ALTCHA](https://altcha.org/) proof-of-work spam protection for Gravity Forms.
No user interaction, no third-party requests, no captcha puzzles — every submission carries
a signed PBKDF2 proof that the browser quietly solved in the background.

* MIT-licensed end to end ([altcha-org/altcha](https://github.com/altcha-org/altcha-lib-php) for PHP, [altcha](https://www.npmjs.com/package/altcha) widget for JS, this plugin for the glue).
* No external API calls — challenges are issued and verified by your own WordPress install.
* No license keys, no quotas.
* Opt-in: enable globally for all forms, or per individual form.

## Requirements

* WordPress 6.0+
* PHP 8.2+
* Gravity Forms 2.5+

## Installation

### Composer (Bedrock-style sites)

The plugin is published through [`generoi/packagist`](https://github.com/generoi/packagist).
With that repository configured in your root `composer.json`:

```bash
composer require generoi/gravityforms-altcha
wp plugin activate gravityforms-altcha
```

### Manual

1. Download the latest `gravityforms-altcha.zip` from
   [Releases](https://github.com/generoi/gravityforms-altcha/releases).
2. Upload to `wp-content/plugins/` (or via Plugins → Add New → Upload).
3. Activate.

## Settings

ALTCHA is **off by default**. Enable it one of two ways:

* **Globally** — Forms → Settings → **ALTCHA** → toggle *Enable for all forms*.
  Every Gravity Form is then protected.
* **Per form** — a form's Settings → **ALTCHA** tab → toggle *Enable ALTCHA for
  this form*. Use this when you only want protection on selected forms.

A form is protected when the global toggle is on **or** that form's own toggle
is on. Both are stored in the standard Gravity Forms settings (the global one as
the `gravityformsaddon_gravityforms-altcha_settings` option, the per-form one in
the form meta). The `genero/gravityforms_altcha/should_protect` filter can still
override the saved settings programmatically.

## How it works

1. **Form render** — when enabled for the form, the plugin injects a hidden
   `<altcha-widget>` web component above the submit button.
2. **Browser-side proof-of-work** — the widget fetches a fresh challenge from
   `/wp-json/genero/gravityforms-altcha/v1/challenge` (signed with a per-site
   HMAC secret) and brute-forces a PBKDF2/SHA-256 derived-key match.
3. **Submission** — the widget writes the solution into a hidden `altcha` field
   that Gravity Forms posts back with the rest of the form.
4. **Server-side verification** — `gform_validation` decodes the payload,
   reconstructs the challenge, and runs `altcha-org/altcha::verifySolution()`.
   On failure the submission is rejected with a generic error message.

Day-to-day configuration lives in the admin UI (see [Settings](#settings)); the
filters below cover advanced overrides.

## Filters

### `genero/gravityforms_altcha/should_protect`

Override the saved settings — force protection on (or off) for specific forms
regardless of the global / per-form toggles:

```php
add_filter('genero/gravityforms_altcha/should_protect', function (bool $protect, array $form): bool {
    // Always protect the high-value lead form, whatever the toggles say.
    if ((int) $form['id'] === 7) {
        return true;
    }
    return $protect;
}, 10, 2);
```

### `genero/gravityforms_altcha/hmac_key`

Use an externally-managed secret (for example a dedicated vault entry shared
across a fleet of sites):

```php
add_filter('genero/gravityforms_altcha/hmac_key', fn () => getenv('ALTCHA_HMAC_KEY') ?: null);
```

Returning `null` falls back to the auto-generated `gravityforms_altcha_hmac_key`
option.

### `genero/gravityforms_altcha/error_message`

Localise or rewrite the validation error:

```php
add_filter('genero/gravityforms_altcha/error_message', fn () => __('Spam check failed. Please reload and try again.', 'your-textdomain'));
```

## Development

```bash
composer install
npm install
npm run build       # outputs build/widget.js
composer test       # PHPUnit suite, no WordPress dependency
composer lint:fix   # Pint
```

## License

MIT — see [`LICENSE`](./LICENSE).

Bundles the MIT-licensed
[altcha-org/altcha](https://github.com/altcha-org/altcha-lib-php) PHP library
and the MIT-licensed [altcha](https://www.npmjs.com/package/altcha) widget.
Neither ships with this repository — both are installed through Composer and
npm respectively.
