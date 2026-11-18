<?php
/**
 * WooCommerce cookie inventory and consent integration.
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

/** Adds known WooCommerce cookies to the inventory and gates optional tracking. */
final class Skyrocket_Cookie_Consent_WooCommerce
{
    private const INVENTORY_VERSION = 2;

    private Skyrocket_Cookie_Consent_Config $config;

    public function __construct(Skyrocket_Cookie_Consent_Config $config)
    {
        $this->config = $config;

        // WooCommerce registers its product-view tracker on template_redirect.
        // Replace it before that hook runs so the optional cookie respects consent.
        add_action('wp', [$this, 'register_product_view_tracking'], 1);

        // Seed the inventory once per integration version. Existing administrator
        // edits and site-specific cookie entries are left untouched.
        // Seed early enough for both frontend PHP cookie writers and the head guard.
        add_action('init', [$this, 'maybe_seed_inventory'], 1);
    }

    /** Replace WooCommerce's recently-viewed tracker with a consent-aware version. */
    public function register_product_view_tracking(): void
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_track_product_view')) {
            return;
        }

        remove_action('template_redirect', 'wc_track_product_view', 20);
        add_action('template_redirect', [$this, 'track_product_view_if_consented'], 20);
    }

    /**
     * Record recently viewed products only when the functional category is accepted.
     *
     * WooCommerce itself sets this cookie server-side during template_redirect,
     * so script blocking alone would not prevent it.
     */
    public function track_product_view_if_consented(): void
    {
        if (!$this->has_functional_consent() || !function_exists('is_active_widget')) {
            return;
        }

        if (!is_singular('product') || !is_active_widget(false, false, 'woocommerce_recently_viewed_products', true)) {
            return;
        }

        if (!function_exists('wc_setcookie')) {
            return;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        $viewed_products = empty($_COOKIE['woocommerce_recently_viewed'])
            ? []
            : wp_parse_id_list(explode('|', wp_unslash((string) $_COOKIE['woocommerce_recently_viewed'])));

        // Move the current product to the end of the viewed list.
        $viewed_products = array_values(array_diff($viewed_products, [(int) $post->ID]));
        $viewed_products[] = (int) $post->ID;

        if (count($viewed_products) > 15) {
            $viewed_products = array_slice($viewed_products, -15);
        }

        wc_setcookie('woocommerce_recently_viewed', implode('|', $viewed_products));
    }

    /** Check the browser's saved consent cookie without treating missing consent as acceptance. */
    private function has_functional_consent(): bool
    {
        if (empty($_COOKIE['skyrocket_cookie_consent'])) {
            return false;
        }

        $raw_consent = wp_unslash((string) $_COOKIE['skyrocket_cookie_consent']);
        $consent = json_decode(rawurldecode($raw_consent), true);

        if (!is_array($consent) || empty($consent['categories']) || !is_array($consent['categories'])) {
            return false;
        }

        // Required categories are always permitted. Functional is optional by default.
        $settings = $this->config->get_settings();
        if (!empty($settings['categories']['functional']['required'])) {
            return true;
        }

        return !empty($consent['expiresAt'])
            && (int) $consent['expiresAt'] >= time()
            && !empty($consent['policyVersion'])
            && hash_equals($this->config->get_policy_version(), (string) $consent['policyVersion'])
            && isset($consent['categories']['functional'])
            && $consent['categories']['functional'] === true;
    }

    /** Seed the documented WooCommerce core cookie inventory once, preserving edits. */
    public function maybe_seed_inventory(): void
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $settings = $this->config->get_settings();
        $seeded_version = (int) ($settings['integrations']['woocommerce_core_inventory'] ?? 0);
        if ($seeded_version >= self::INVENTORY_VERSION) {
            return;
        }

        // Version 2 broadens the original illustrative store-notice name into a
        // prefix pattern so variable notice IDs are matched. Preserve any manual
        // administrator change to that inventory entry.
        if ($seeded_version < 2) {
            foreach ($settings['categories'] as &$existing_category) {
                foreach ($existing_category['cookies'] as &$existing_cookie) {
                    if (($existing_cookie['id'] ?? '') === 'wc_core_store_notice'
                        && ($existing_cookie['name'] ?? '') === 'store_notice[notice id]') {
                        $existing_cookie['name'] = 'store_notice*';
                    }
                }
                unset($existing_cookie);
            }
            unset($existing_category);
        }

        // Functional is an optional category, not a blanket exemption for WooCommerce.
        if (!isset($settings['categories']['functional'])) {
            $settings['categories']['functional'] = [
                'required' => false,
                'translations' => [],
                'cookies' => [],
            ];
        }

        foreach ($settings['languages'] as $language_code => $language) {
            if (empty($settings['categories']['functional']['translations'][$language_code]['title'])) {
                $settings['categories']['functional']['translations'][$language_code] = [
                    'title' => 'Functional',
                    'description' => 'Enables optional features and remembers convenience preferences.',
                ];
            }
        }

        $definitions = [
            [
                'id' => 'wc_core_cart_hash',
                'name' => 'woocommerce_cart_hash',
                'expiry' => 'Session',
                'category' => 'strictly_necessary',
                'description' => 'Helps WooCommerce detect changes to the contents of the shopping basket.',
            ],
            [
                'id' => 'wc_core_items_in_cart',
                'name' => 'woocommerce_items_in_cart',
                'expiry' => 'Session',
                'category' => 'strictly_necessary',
                'description' => 'Indicates that the shopping basket contains items.',
            ],
            [
                'id' => 'wc_core_session',
                'name' => 'wp_woocommerce_session_*',
                'expiry' => '2 days',
                'category' => 'strictly_necessary',
                'description' => 'Contains a customer-specific session identifier used by WooCommerce to retrieve session and basket data. The asterisk represents the variable suffix.',
            ],
            [
                'id' => 'wc_core_recently_viewed',
                'name' => 'woocommerce_recently_viewed',
                'expiry' => 'Session',
                'category' => 'functional',
                'description' => 'Stores recently viewed product IDs for the Recently Viewed Products feature. This optional feature is only recorded after Functional consent.',
            ],
            [
                'id' => 'wc_core_store_notice',
                'name' => 'store_notice*',
                'expiry' => 'Session',
                'category' => 'functional',
                'description' => 'Remembers when a visitor dismisses a WooCommerce store notice. The notice ID varies; the wildcard matches the variable suffix.',
            ],
        ];

        foreach ($definitions as $definition) {
            $target_category = $definition['category'];
            if (!isset($settings['categories'][$target_category])) {
                // Never silently place an optional cookie into a required category.
                continue;
            }

            $already_exists = false;
            foreach ($settings['categories'] as $category) {
                foreach (($category['cookies'] ?? []) as $cookie) {
                    if (($cookie['id'] ?? '') === $definition['id']) {
                        $already_exists = true;
                        break 2;
                    }
                }
            }

            if ($already_exists) {
                continue;
            }

            $translations = [];
            foreach ($settings['languages'] as $language_code => $language) {
                $translations[$language_code] = ['description' => $definition['description']];
            }

            $settings['categories'][$target_category]['cookies'][] = [
                'id' => $definition['id'],
                'name' => $definition['name'],
                'expiry' => $definition['expiry'],
                'translations' => $translations,
                'integration_source' => 'woocommerce-core',
            ];
        }

        $settings['integrations']['woocommerce_core_inventory'] = self::INVENTORY_VERSION;
        $this->config->save_settings($settings);
    }
}
