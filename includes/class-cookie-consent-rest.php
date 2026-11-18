<?php
/**
 * Public REST API endpoint.
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

/**
 * Registers the public cookie consent configuration endpoint.
 */
final class Skyrocket_Cookie_Consent_REST
{
    private Skyrocket_Cookie_Consent_Config $config;

    /**
     * @param Skyrocket_Cookie_Consent_Config $config Configuration service.
     */
    public function __construct(Skyrocket_Cookie_Consent_Config $config)
    {
        $this->config = $config;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(
            'skyrocket-cookie-consent/v1',
            '/config',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_config'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'skyrocket-cookie-consent/v1',
            '/cleanup',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'cleanup_revoked_cookies'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'categories' => [
                        'required' => true,
                        'type'     => 'object',
                    ],
                ],
            ]
        );
    }


    /**
     * Expire cookies in categories the visitor has not accepted.
     * This also handles HttpOnly cookies that JavaScript cannot delete.
     *
     * @param WP_REST_Request $request Request containing the accepted categories.
     * @return WP_REST_Response
     */
    public function cleanup_revoked_cookies(WP_REST_Request $request): WP_REST_Response
    {
        $accepted = $request->get_param('categories');
        if (is_object($accepted)) {
            $accepted = (array) $accepted;
        }
        if (!is_array($accepted)) {
            return new WP_REST_Response(['deleted' => 0], 400);
        }

        $settings = $this->config->get_settings();
        $deleted = [];
        $paths = array_unique(array_filter([
            defined('COOKIEPATH') ? COOKIEPATH : '/',
            defined('SITECOOKIEPATH') ? SITECOOKIEPATH : '/',
            '/',
        ]));
        $domain = defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';

        // Read names from the raw Cookie header so PHP's bracket-name parsing
        // (for example store_notice[123]) does not collapse distinct cookies.
        $request_cookie_names = [];
        $cookie_header = isset($_SERVER['HTTP_COOKIE']) ? (string) wp_unslash($_SERVER['HTTP_COOKIE']) : '';
        foreach (explode(';', $cookie_header) as $cookie_pair) {
            $separator = strpos($cookie_pair, '=');
            if ($separator !== false) {
                $cookie_name = trim(substr($cookie_pair, 0, $separator));
                if ($cookie_name !== '') {
                    $request_cookie_names[] = $cookie_name;
                }
            }
        }
        if (!$request_cookie_names) {
            $request_cookie_names = array_keys($_COOKIE);
        }

        foreach ($settings['categories'] as $category_id => $category) {
            if (!empty($category['required']) || (isset($accepted[$category_id]) && $accepted[$category_id] === true)) {
                continue;
            }

            foreach (($category['cookies'] ?? []) as $cookie) {
                $pattern = trim((string) ($cookie['name'] ?? ''));
                if ($pattern === '') {
                    continue;
                }

                foreach ($request_cookie_names as $actual_name) {
                    $matches = $pattern === $actual_name;
                    if (substr($pattern, -1) === '*' && strpos($actual_name, substr($pattern, 0, -1)) === 0) {
                        $matches = true;
                    }
                    if (strpos($pattern, '*') !== false && substr($pattern, -1) !== '*') {
                        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
                        $matches = (bool) preg_match($regex, $actual_name);
                    }
                    if (!$matches || $actual_name === 'skyrocket_cookie_consent') {
                        continue;
                    }

                    foreach ($paths as $path) {
                        foreach ([true, false] as $http_only) {
                            $options = [
                                'expires'  => time() - HOUR_IN_SECONDS,
                                'path'     => $path,
                                'secure'   => is_ssl(),
                                'httponly' => $http_only,
                                'samesite' => 'Lax',
                            ];
                            if ($domain !== '') {
                                $options['domain'] = $domain;
                            }
                            setcookie($actual_name, '', $options);
                        }
                    }
                    if (isset($_COOKIE[$actual_name])) {
                        unset($_COOKIE[$actual_name]);
                    } elseif (preg_match('/^([^\[]+)\[([^\]]+)\]$/', $actual_name, $bracket_name)
                        && isset($_COOKIE[$bracket_name[1]]) && is_array($_COOKIE[$bracket_name[1]])) {
                        unset($_COOKIE[$bracket_name[1]][$bracket_name[2]]);
                        if (!$_COOKIE[$bracket_name[1]]) {
                            unset($_COOKIE[$bracket_name[1]]);
                        }
                    }
                    $deleted[] = $actual_name;
                }
            }
        }

        return new WP_REST_Response(['deleted' => count(array_unique($deleted))], 200);
    }

    /**
     * Return the public configuration and language fallback order.
     *
     * @return WP_REST_Response
     */
    public function get_config(): WP_REST_Response
    {
        $response = $this->config->get_public_config();
        $response['language_fallbacks'] = $this->config->get_language_fallbacks();
        $response['wordpress_language'] = strtolower(str_replace('_', '-', determine_locale()));

        return new WP_REST_Response($response, 200);
    }
}
