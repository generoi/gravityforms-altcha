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

### Protection strength

Forms → Settings → **ALTCHA** has a *Protection strength* dropdown (Low /
Standard / High / Very high) controlling how hard the background proof-of-work
is — stronger costs bots more per submission but takes longer to solve on
low-end devices. Because the widget solves on page load while the form is being
filled, even the higher settings are normally invisible. Each form's ALTCHA tab
can override the site-wide strength or inherit it. For an exact custom value,
use the `genero/gravityforms_altcha/cost` filter.

### Extra spam layers (optional)

Two independent, fully first-party layers can be toggled on under Forms →
Settings → **ALTCHA**. Both **mark suspicious submissions as spam** (via
`gform_entry_is_spam`) rather than rejecting them — a real visitor is never
blocked or shown an error, and a false positive stays recoverable in the entry
*Spam* view. They apply to every Gravity Form, independent of whether ALTCHA
itself is enabled.

* **Rate limiting** — flags submissions once an IP exceeds a per-form
  allowance within a window (default 3 per hour). The IP is resolved independently of Gravity
  Forms (sites often blank GF's stored IP for GDPR) and kept only as a salted
  HMAC in a 60-second transient — the raw IP is never stored or logged. Behind
  a CDN/proxy, point it at the right client-IP header (see
  `genero/gravityforms_altcha/client_ip_headers`).
* **Content spam filtering** — flags submissions whose text contains a
  definite-spam keyword (matched on word boundaries), or accumulates enough
  weaker signals (link farms, a URL in the name field, injected markup,
  wrong-script text). Scans all visitor text including composite name/address
  fields, and ignores zero-width characters used to evade matching.
* **Email validation** — unlike the two above, this *blocks* the field (with a
  corrective message) so the visitor fixes a bad address rather than silently
  never hearing back. Per-verdict checkboxes decide what to reject —
  **undeliverable** (default on), **risky** (default off — may catch some real
  catch-all/role addresses), and **disposable** (default on). Verifies via
  [Bouncer](https://usebouncer.com); requires the `BOUNCER_API_KEY` environment
  variable. **Fails open** — `unknown`, a missing key, or an API error never
  block. Note: this sends the submitted email to a third-party service, so
  cover it in your privacy policy / DPA.

## Logging

Turn on **Debug logging** (Forms → Settings → ALTCHA) to record every decision —
useful for verifying behaviour in staging or chasing a report. Each decision
writes one greppable line to the PHP error log:

```
[gravityforms-altcha] altcha: pass {"form":43}
[gravityforms-altcha] altcha: fail {"form":43,"reason":"replay"}
[gravityforms-altcha] rate_limit: blocked {"form":12}
[gravityforms-altcha] content_filter: spam {"form":7,"score":3,"signals":["keyword"]}
[gravityforms-altcha] email_validation: blocked {"form":7,"status":"undeliverable","disposable":false,"reason":"rejected_email","domain":"exmaple.com"}
```

`reason` for ALTCHA is `missing` / `invalid` / `replay`. **No PII is logged** —
IPs only ever appear as a salted hash, and emails as the domain only.

Route the records elsewhere (Sentry, Query Monitor, …) via the
`genero/gravityforms_altcha/log` action — and optionally drop the default
error-log line:

```php
remove_action('genero/gravityforms_altcha/log', ['Genero\GravityFormsAltcha\Logger', 'writeToErrorLog']);
add_action('genero/gravityforms_altcha/log', function (string $event, string $outcome, array $context) {
    // ship $event/$outcome/$context to your sink
}, 10, 3);
```

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
5. **Replay protection** — each challenge is single-use. A solved payload's
   unique signature is remembered for the rest of its lifetime, so the same
   proof can't be replayed across many submissions — a bot must solve a fresh
   challenge every time rather than paying the cost once.

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

### `genero/gravityforms_altcha/cost`

Set an exact proof-of-work cost (PBKDF2 iterations), overriding the
*Protection strength* dropdown. Receives the form id for context:

```php
add_filter('genero/gravityforms_altcha/cost', fn (int $cost, ?int $formId) => 750000, 10, 2);
```

### `genero/gravityforms_altcha/client_ip_headers`

Only relevant when *Rate limiting* is on. Ordered list of `$_SERVER` keys to
read the client IP from; defaults to `['REMOTE_ADDR']`. Behind a CDN, **prepend
the single header your CDN sets** — never trust a forwarded header it doesn't,
as it can be spoofed to evade the limit or push a real visitor over it.

```php
add_filter('genero/gravityforms_altcha/client_ip_headers', fn () => [
    'HTTP_CF_CONNECTING_IP', // Cloudflare
    'REMOTE_ADDR',
]);
```

Common headers: `HTTP_CF_CONNECTING_IP` (Cloudflare), `HTTP_FASTLY_CLIENT_IP`
(Fastly), `HTTP_TRUE_CLIENT_IP` (Akamai), `HTTP_X_REAL_IP` (nginx).

### `genero/gravityforms_altcha/rate_limit_max` and `…/rate_limit_window`

Per-IP, per-form submission allowance and the window (seconds) it applies over
before a submission is flagged as spam. Default: 3 per hour. For "1 per minute":

```php
add_filter('genero/gravityforms_altcha/rate_limit_max', fn () => 1);
add_filter('genero/gravityforms_altcha/rate_limit_window', fn () => MINUTE_IN_SECONDS);
```

### `genero/gravityforms_altcha/spam_keywords` and `…/spam_score_threshold`

Tune the content filter — definite-spam keywords (a single match flags) and the
score the weaker heuristics must reach (each signal contributes 2; default 3):

```php
add_filter('genero/gravityforms_altcha/spam_keywords', fn (array $words) => [...$words, 'crypto']);
add_filter('genero/gravityforms_altcha/spam_score_threshold', fn () => 4);
```

### `genero/gravityforms_altcha/logging`

Force decision logging on or off regardless of the *Debug logging* setting:

```php
add_filter('genero/gravityforms_altcha/logging', fn () => defined('WP_DEBUG') && WP_DEBUG);
```

### `genero/gravityforms_altcha/bouncer_api_key`

Provide the Bouncer key in code instead of the `BOUNCER_API_KEY` env var (e.g.
from a secrets manager):

```php
add_filter('genero/gravityforms_altcha/bouncer_api_key', fn () => get_option('my_bouncer_key'));
```

### `genero/gravityforms_altcha/email_should_validate` and `…/email_error_message`

Skip email validation for specific fields/forms, or customise the rejection
message:

```php
add_filter('genero/gravityforms_altcha/email_should_validate', fn (bool $v, $field, $form) => (int) $form['id'] !== 12, 10, 3);
add_filter('genero/gravityforms_altcha/email_error_message', fn () => __('That email looks undeliverable — please check it.', 'your-textdomain'));
```

## Development

```bash
composer install
npm install
npm run build       # outputs build/widget.js
composer lint:fix   # Pint
```

## Tests

Two layers:

* **`composer test`** — the `unit` (pure logic) and `mocked` (WP functions stubbed
  with Brain\Monkey) suites. No WordPress, no database — runs in CI on PHP
  8.2–8.4.
* **`composer test:integration`** — real-WordPress integration tests
  (`wp-phpunit`) covering the settings, the `gform_entry_is_spam` spam layers,
  and cost resolution against an actual Gravity Forms install. Gravity Forms is
  commercial, so these **skip when it isn't present** (e.g. CI) and run for real
  via wp-env or DDEV.

Run the integration suite with [`wp-env`](https://www.npmjs.com/package/@wordpress/env)
(place a `gravityforms` checkout alongside this repo so it mounts):

```bash
npx @wordpress/env start
npx @wordpress/env run tests-cli --env-cwd=wp-content/plugins/gravityforms-altcha \
  vendor/bin/phpunit -c phpunit.integration.xml.dist
```

## License

MIT — see [`LICENSE`](./LICENSE).

Bundles the MIT-licensed
[altcha-org/altcha](https://github.com/altcha-org/altcha-lib-php) PHP library
and the MIT-licensed [altcha](https://www.npmjs.com/package/altcha) widget.
Neither ships with this repository — both are installed through Composer and
npm respectively.
