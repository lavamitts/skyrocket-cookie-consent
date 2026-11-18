<?php
/**
 * Plugin Name: Skyrocket Cookie Consent
 * Description: Lightweight, database-managed cookie consent popup for WordPress.
 * Version:     2.0.0
 * Author:      Skyrocket Digital Development
 * License:     GPL-2.0
 * Text Domain: skyrocket-cookie-consent
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

define('SKYROCKET_COOKIE_CONSENT_VERSION', '2.0.0');
define('SKYROCKET_COOKIE_CONSENT_FILE', __FILE__);
define('SKYROCKET_COOKIE_CONSENT_DIR', plugin_dir_path(__FILE__));
define('SKYROCKET_COOKIE_CONSENT_URL', plugin_dir_url(__FILE__));

/**
 * GitHub Plugin Updater configuration.
 *
 * @var array
 */
$github_updater_config = [
    'username'    => 'lavamitts',
    'repository'  => 'skyrocket-cookie-consent',
    'plugin_file' => __FILE__,
];

$updater_file = __DIR__ . '/vendor/lavamitts/skyrocket-plugin-updater/src/plugin-updater.php';

if (file_exists($updater_file)) {
    require_once $updater_file;
}

require_once SKYROCKET_COOKIE_CONSENT_DIR . 'includes/class-cookie-consent-config.php';
require_once SKYROCKET_COOKIE_CONSENT_DIR . 'includes/class-cookie-consent-rest.php';
require_once SKYROCKET_COOKIE_CONSENT_DIR . 'includes/class-cookie-consent-admin.php';

/**
 * Bootstrap the plugin after all active plugins have loaded.
 *
 * @return void
 */
function skyrocket_cookie_consent_init(): void
{
    $config = new Skyrocket_Cookie_Consent_Config();

    new Skyrocket_Cookie_Consent_REST($config);

    if (is_admin()) {
        new Skyrocket_Cookie_Consent_Admin($config);
    }

    add_action('wp_enqueue_scripts', static function () use ($config): void {
        $js_path  = SKYROCKET_COOKIE_CONSENT_DIR . 'assets/js/cookie-consent.js';
        $css_path = SKYROCKET_COOKIE_CONSENT_DIR . 'assets/css/cookie-consent.css';

        if (apply_filters('custom_cookie_consent_use_css', true) && file_exists($css_path)) {
            wp_enqueue_style(
                'custom-cookie-consent',
                SKYROCKET_COOKIE_CONSENT_URL . 'assets/css/cookie-consent.css',
                [],
                filemtime($css_path) ?: SKYROCKET_COOKIE_CONSENT_VERSION
            );
        }

        if (file_exists($js_path)) {
            wp_enqueue_script(
                'custom-cookie-consent',
                SKYROCKET_COOKIE_CONSENT_URL . 'assets/js/cookie-consent.js',
                [],
                filemtime($js_path) ?: SKYROCKET_COOKIE_CONSENT_VERSION,
                true
            );

            wp_script_add_data('custom-cookie-consent', 'strategy', 'defer');

            wp_add_inline_script(
                'custom-cookie-consent',
                'window.cookieConsentSettings = ' . wp_json_encode([
                    'configUrl' => esc_url_raw(rest_url('skyrocket-cookie-consent/v1/config')),
                    'languageStrategy' => 'browser-wordpress',
                ]) . ';',
                'before'
            );
        }
    });
}
add_action('plugins_loaded', 'skyrocket_cookie_consent_init');
