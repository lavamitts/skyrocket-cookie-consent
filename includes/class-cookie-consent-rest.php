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
