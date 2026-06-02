<?php

namespace Genero\GravityFormsAltcha;

class ChallengeEndpoint
{
    public const NAMESPACE = 'genero/gravityforms-altcha/v1';

    public const ROUTE = '/challenge';

    public static function register(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    public static function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        // The widget appends the form id (see Integration::injectWidget) so the
        // per-form cost setting applies. Absent/invalid → global/default cost.
        $formId = $request->get_param('form_id');
        $formId = is_numeric($formId) ? (int) $formId : null;

        /**
         * Filters the proof-of-work cost (PBKDF2 iterations per attempt). The
         * default is resolved from the per-form / global ALTCHA settings (see
         * {@see Settings::costForForm()}); the form id is passed for context.
         * Higher is more expensive for bots but slower on low-end devices.
         */
        $cost = (int) apply_filters('genero/gravityforms_altcha/cost', Settings::costForForm($formId), $formId);

        $challenge = (new Challenge(Plugin::getInstance()->hmacKey(), $cost))->create();

        // The widget calls this on every form render, so caches between the
        // browser and PHP must not pin one challenge to multiple visitors.
        $response = new \WP_REST_Response($challenge->toArray());
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public static function url(?int $formId = null): string
    {
        $url = rest_url(self::NAMESPACE.self::ROUTE);

        return $formId !== null ? add_query_arg('form_id', $formId, $url) : $url;
    }
}
