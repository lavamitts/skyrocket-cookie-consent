<?php
/**
 * Plugin Name: Skyrocket Cookie Consent
 * Description: Lightweight cookie consent popup driven by a JSON config.
 * Version:     1.2.0
 * Author:      Skyrocket Digital Development
 * License:     GPL2
 *
 * Plugin URI:  https://github.com/lavamitts/skyrocket-cookie-consent
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;


/**
 * GitHub Plugin Updater Configuration
 *
 * Defines the GitHub repository used by the shared Skyrocket Plugin Updater.
 *
 * @var array{
 *     username: string,
 *     repository: string,
 *     plugin_file: string
 * }
 */
$github_updater_config = [
    'username' => 'lavamitts',
    'repository' => 'skyrocket-cookie-consent',
    'plugin_file' => __FILE__,
];


/**
 * Load the shared Skyrocket Plugin Updater.
 *
 * The updater handles checking the configured GitHub repository for
 * available plugin updates and integrating them with WordPress.
 */
require_once __DIR__ . '/vendor/lavamitts/skyrocket-plugin-updater/src/plugin-updater.php';


/**
 * Main Skyrocket Cookie Consent plugin class.
 *
 * Handles frontend asset registration and loading, including:
 *
 * - Conditionally loading the plugin stylesheet.
 * - Registering and enqueueing the cookie consent JavaScript.
 * - Applying file modification timestamps for JavaScript cache busting.
 * - Adding the plugin configuration URL to the JavaScript environment.
 *
 * The actual consent interface and behaviour are handled by the
 * external JavaScript and CSS assets.
 *
 * @package SkyrocketCookieConsent
 * @since   1.0.0
 */
final class Custom_Cookie_Consent
{
    /**
     * Current plugin version.
     *
     * Used as the default asset version and as the stylesheet cache
     * identifier.
     *
     * Keep this value synchronised with the plugin header version.
     *
     * @var string
     */
    const VERSION = '1.2.0';

    /**
     * Absolute path to the main plugin file.
     *
     * @var string
     */
    private string $plugin_file;

    /**
     * Absolute filesystem path to the plugin directory.
     *
     * @var string
     */
    private string $plugin_dir;

    /**
     * Public URL of the plugin directory.
     *
     * @var string
     */
    private string $plugin_url;


    /**
     * Initialise the plugin.
     *
     * Stores the plugin filesystem path and URL, then registers
     * the frontend asset-enqueueing hook.
     *
     * @param string $file Absolute path to the main plugin file.
     */
    public function __construct(string $file)
    {
        $this->plugin_file = $file;
        $this->plugin_dir = plugin_dir_path($file);
        $this->plugin_url = plugin_dir_url($file);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    }


    /**
     * Register and enqueue frontend styles and scripts.
     *
     * The stylesheet can be disabled using the
     * `custom_cookie_consent_use_css` filter.
     *
     * JavaScript is always registered and enqueued, provided WordPress
     * can process the registered script. Its version is based on the
     * file modification timestamp when available, allowing browsers
     * to automatically request updated files after changes.
     *
     * The cookie configuration URL is made available to JavaScript
     * through the global `window.cookieConsentSettings` object.
     *
     * Assets:
     *
     * - assets/css/cookie-consent.css
     * - assets/js/cookie-consent.js
     * - cookies.json
     *
     * @return void
     */
    public function enqueue_assets(): void
    {
        // Resolve asset filesystem paths and public URLs.
        $js_path = $this->plugin_dir . 'assets/js/cookie-consent.js';
        $css_path = $this->plugin_dir . 'assets/css/cookie-consent.css';

        $js_url = $this->plugin_url . 'assets/js/cookie-consent.js';
        $css_url = $this->plugin_url . 'assets/css/cookie-consent.css';


        /**
         * Enqueue stylesheet.
         *
         * Allows themes or other plugins to disable the default
         * stylesheet while retaining the cookie consent functionality.
         *
         * Filter:
         * custom_cookie_consent_use_css
         *
         * Default: true
         */
        $use_css = apply_filters('custom_cookie_consent_use_css', true);

        if ($use_css && file_exists($css_path)) {
            wp_enqueue_style(
                'custom-cookie-consent',
                $css_url,
                [],
                self::VERSION
            );
        }


        /**
         * Determine JavaScript asset version.
         *
         * Uses the plugin version as a fallback. If the JavaScript
         * file exists and its modification timestamp can be retrieved,
         * the timestamp is used instead to provide automatic cache busting.
         */
        $js_version = self::VERSION;

        if (file_exists($js_path)) {
            $mtime = filemtime($js_path);

            if ($mtime !== false) {
                $js_version = (string) $mtime;
            }
        }


        /**
         * Register the cookie consent JavaScript.
         *
         * No dependencies are declared.
         *
         * The script is configured to load in the footer and uses
         * the defer loading strategy to avoid blocking page rendering.
         */
        wp_register_script(
            'custom-cookie-consent',
            $js_url,
            [],
            $js_version,
            true
        );


        /**
         * Apply deferred script loading.
         *
         * Uses the WordPress script loading strategy API.
         * The defer strategy allows HTML parsing to continue while
         * the browser downloads the script.
         */
        wp_script_add_data(
            'custom-cookie-consent',
            'strategy',
            'defer'
        );


        // Enqueue the registered JavaScript.
        wp_enqueue_script('custom-cookie-consent');


        /**
         * Pass configuration to the frontend JavaScript.
         *
         * Exposes the public URL of cookies.json through
         * window.cookieConsentSettings.
         *
         * The configuration is added before the main JavaScript
         * executes, ensuring it is available when the script loads.
         *
         * wp_json_encode() safely serialises the configuration
         * for use in JavaScript.
         */
        $settings = [
            'configUrl' => esc_url_raw($this->plugin_url . 'cookies.json'),
        ];

        wp_add_inline_script(
            'custom-cookie-consent',
            'window.cookieConsentSettings = ' . wp_json_encode($settings) . ';',
            'before'
        );
    }
}


/**
 * Capture the main plugin file.
 *
 * Store the absolute path immediately so it remains available to the
 * bootstrap callback, even if the bootstrap code is subsequently moved
 * or reorganised.
 *
 * @var string
 */
$custom_cookie_consent_root_file = __FILE__;


/**
 * Bootstrap the plugin.
 *
 * Initialise the main plugin class once WordPress has loaded its
 * active plugins.
 *
 * Passing the captured plugin file into the class ensures that
 * filesystem paths and URLs are resolved relative to the correct
 * plugin installation directory.
 */
add_action('plugins_loaded', function () use ($custom_cookie_consent_root_file) {
    new Custom_Cookie_Consent($custom_cookie_consent_root_file);
});
