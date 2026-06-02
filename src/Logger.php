<?php

namespace Genero\GravityFormsAltcha;

/**
 * Lightweight, opt-in logging so each protection decision can be verified
 * through the logs. Off by default; enabled by the "Debug logging" setting (or
 * the `genero/gravityforms_altcha/logging` filter).
 *
 * Every decision fires the `genero/gravityforms_altcha/log` action so it can be
 * routed anywhere (Sentry, Query Monitor, …); a default listener writes a
 * greppable line to the PHP error log (registered in {@see Plugin}).
 *
 * Privacy: callers pass only non-PII context — a form id, a salted IP hash, an
 * email *domain* (never the full address or raw IP), spam signal names, etc.
 */
class Logger
{
    public const HOOK = 'genero/gravityforms_altcha/log';

    /**
     * @param  string  $event  e.g. altcha | rate_limit | content_filter | email_validation
     * @param  string  $outcome  e.g. pass | fail | blocked | allowed
     * @param  array<string, mixed>  $context  non-PII detail
     */
    public static function record(string $event, string $outcome, array $context = []): void
    {
        if (! self::enabled()) {
            return;
        }

        do_action(self::HOOK, $event, $outcome, $context);
    }

    public static function enabled(): bool
    {
        /**
         * Filters whether decisions are logged. Defaults to the "Debug logging"
         * setting (wired in Plugin); return true/false to override.
         */
        return (bool) apply_filters('genero/gravityforms_altcha/logging', false);
    }

    /**
     * Default sink — a single greppable line per decision. Other listeners can
     * be added on the same hook, or this one removed to fully take over routing.
     *
     * @param  array<string, mixed>  $context
     */
    public static function writeToErrorLog(string $event, string $outcome, array $context = []): void
    {
        error_log(self::format($event, $outcome, $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function format(string $event, string $outcome, array $context = []): string
    {
        $suffix = $context !== [] ? ' '.json_encode($context) : '';

        return sprintf('[gravityforms-altcha] %s: %s%s', $event, $outcome, $suffix);
    }
}
