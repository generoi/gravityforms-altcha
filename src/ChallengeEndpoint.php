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

    public static function handle(): \WP_REST_Response
    {
        $challenge = (new Challenge(Plugin::getInstance()->hmacKey()))->create();

        // The widget calls this on every form render, so caches between the
        // browser and PHP must not pin one challenge to multiple visitors.
        $response = new \WP_REST_Response($challenge->toArray());
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    public static function url(): string
    {
        return rest_url(self::NAMESPACE.self::ROUTE);
    }
}
