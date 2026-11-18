<?php
/**
 * Consent enforcement for browser cookies and supported server-side writers.
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

/** Applies category policy before supported cookie writers run. */
final class Skyrocket_Cookie_Consent_Enforcement
{
    private const CONSENT_COOKIE = 'skyrocket_cookie_consent';

    private Skyrocket_Cookie_Consent_Config $config;

    public function __construct(Skyrocket_Cookie_Consent_Config $config)
    {
        $this->config = $config;

        // This must run before ordinary scripts in the document head.
        add_action('wp_head', [$this, 'print_browser_guard'], 0);

        // WooCommerce exposes a supported filter around wc_setcookie().
        add_filter('woocommerce_set_cookie_enabled', [$this, 'filter_woocommerce_cookie'], 10, 5);

        // Integrations can register WordPress script handles by category.
        add_filter('script_loader_tag', [$this, 'filter_script_tag'], 10, 3);
    }

    /** Build the current cookie-name to category map from the admin inventory. */
    public function get_cookie_policy(): array
    {
        $settings = $this->config->get_settings();
        $policy = [];

        foreach ($settings['categories'] as $category_id => $category) {
            foreach (($category['cookies'] ?? []) as $cookie) {
                $name = trim((string) ($cookie['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $rule = [
                    'category' => (string) $category_id,
                    'required' => !empty($category['required']),
                ];
                if (isset($policy[$name]) && $policy[$name]['category'] !== $rule['category']) {
                    // The same cookie name assigned to different purposes is ambiguous;
                    // do not let whichever category happens to be processed last win.
                    $policy[$name] = ['category' => '__ambiguous__', 'required' => false];
                } else {
                    $policy[$name] = $rule;
                }
            }
        }

        return $policy;
    }

    /** Check consent using the server-visible consent cookie. Missing/invalid consent denies optional categories. */
    public function has_category_consent(string $category_id): bool
    {
        $settings = $this->config->get_settings();
        if (!empty($settings['categories'][$category_id]['required'])) {
            return true;
        }

        if (empty($_COOKIE[self::CONSENT_COOKIE])) {
            return false;
        }

        $consent = json_decode(rawurldecode(wp_unslash((string) $_COOKIE[self::CONSENT_COOKIE])), true);
        if (!is_array($consent) || empty($consent['categories']) || !is_array($consent['categories'])) {
            return false;
        }
        if (empty($consent['expiresAt']) || (int) $consent['expiresAt'] < time()
            || empty($consent['policyVersion'])
            || !hash_equals($this->config->get_policy_version(), (string) $consent['policyVersion'])) {
            return false;
        }

        return isset($consent['categories'][$category_id]) && $consent['categories'][$category_id] === true;
    }

    /** Determine whether a named cookie is permitted. Unknown cookies are denied by default. */
    public function can_set_cookie(string $cookie_name): bool
    {
        $policy = $this->find_cookie_policy($cookie_name);
        if ($policy === null) {
            return false;
        }
        return !empty($policy['required']) || $this->has_category_consent((string) $policy['category']);
    }

    /** WooCommerce server-side cookie gate, including cookies set by compatible extensions. */
    public function filter_woocommerce_cookie(bool $enabled, string $name, string $value = '', int $expire = 0, bool $secure = false): bool
    {
        if (!$enabled) {
            return false;
        }

        // Do not interfere with authenticated administration screens. AJAX and REST
        // requests can be customer-facing checkout flows and remain subject to policy.
        if (is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            return $enabled;
        }

        return $this->can_set_cookie($name);
    }

    /**
     * Gate registered WordPress script handles. Map handles through the
     * skyrocket_cookie_consent_script_categories filter.
     */
    public function filter_script_tag(string $tag, string $handle, string $src): string
    {
        if (is_admin()) {
            return $tag;
        }

        $mapping = apply_filters('skyrocket_cookie_consent_script_categories', []);
        if (!is_array($mapping) || empty($mapping[$handle])) {
            return $tag;
        }

        $category = sanitize_key((string) $mapping[$handle]);
        if ($category === '' || $this->has_category_consent($category)) {
            return $tag;
        }

        // The registered script is held inert until the frontend consent manager
        // explicitly activates it. Inline scripts attached to the handle are not
        // automatically held by this filter and need an integration of their own.
        return sprintf(
            '<script type="text/plain" data-scc-script-handle="%1$s" data-cookie-category="%2$s" data-src="%3$s"></script>',
            esc_attr($handle),
            esc_attr($category),
            esc_url($src)
        );
    }

    /** Print the earliest practical browser-side cookie setter guard. */
    public function print_browser_guard(): void
    {
        if (is_admin() || is_feed()) {
            return;
        }

        $payload = [
            'policy' => $this->get_cookie_policy(),
            'consentCookie' => self::CONSENT_COOKIE,
            'policyVersion' => $this->config->get_policy_version(),
            'consent' => $this->get_request_consent(),
        ];
        ?>
        <script id="skyrocket-cookie-consent-guard">
        (function (w, d, p) {
            'use strict';
            var policy = p.policy || {};
            var consent = p.consent || null;
            var cookieName = p.consentCookie;
            var original = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');
            if (!original || !original.get || !original.set) return;

            function categoryFor(name) {
                if (Object.prototype.hasOwnProperty.call(policy, name)) return policy[name];
                var names = Object.keys(policy);
                for (var i = 0; i < names.length; i++) {
                    var pattern = names[i];
                    if (pattern.slice(-1) === '*' && name.indexOf(pattern.slice(0, -1)) === 0) return policy[pattern];
                }
                return null;
            }

            function permitted(name) {
                if (name === cookieName) return true;
                var rule = categoryFor(name);
                if (!rule) return false; // Unknown cookie names fail closed.
                if (rule.required) return true;
                return !!(consent && consent.policyVersion === p.policyVersion
                    && consent.expiresAt && Number(consent.expiresAt) >= Math.floor(Date.now() / 1000)
                    && consent.categories && consent.categories[rule.category] === true);
            }

            try {
                Object.defineProperty(d, 'cookie', {
                    configurable: true,
                    enumerable: true,
                    get: function () { return original.get.call(d); },
                    set: function (value) {
                        var first = String(value).split(';', 1)[0];
                        var equals = first.indexOf('=');
                        var name = (equals < 0 ? first : first.slice(0, equals)).trim();
                        var deleting = /(?:^|;)\s*max-age\s*=\s*0(?:;|$)/i.test(String(value))
                            || /expires\s*=\s*(?:Thu,\s*01\s*Jan\s*1970|Thu,\s*01-Jan-1970)/i.test(String(value));
                        if (deleting || permitted(name)) return original.set.call(d, value);
                        if (w.console && console.info) console.info('[Skyrocket Cookie Consent] Blocked unapproved cookie:', name);
                    }
                });
            } catch (error) {
                // Browser restrictions may prevent accessor replacement. The PHP
                // integrations and explicit script gating remain available.
            }
            w.SkyrocketCookieConsentGuard = {
                update: function (nextConsent) { consent = nextConsent || null; },
                permitted: permitted
            };
        })(window, document, <?php echo wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        </script>
        <?php
    }

    /** Provide the consent cookie to the early inline guard. */
    private function get_request_consent(): ?array
    {
        if (empty($_COOKIE[self::CONSENT_COOKIE])) {
            return null;
        }
        $consent = json_decode(rawurldecode(wp_unslash((string) $_COOKIE[self::CONSENT_COOKIE])), true);
        if (!is_array($consent) || empty($consent['categories']) || !is_array($consent['categories'])) {
            return null;
        }
        if (empty($consent['expiresAt']) || (int) $consent['expiresAt'] < time()
            || empty($consent['policyVersion'])
            || !hash_equals($this->config->get_policy_version(), (string) $consent['policyVersion'])) {
            return null;
        }
        return $consent;
    }

    /** Resolve exact names and trailing-wildcard prefixes. */
    private function find_cookie_policy(string $cookie_name): ?array
    {
        $policy = $this->get_cookie_policy();
        if (isset($policy[$cookie_name])) {
            return $policy[$cookie_name];
        }
        foreach ($policy as $pattern => $rule) {
            if (substr($pattern, -1) === '*' && strpos($cookie_name, substr($pattern, 0, -1)) === 0) {
                return $rule;
            }
        }
        return null;
    }
}

/**
 * Public helper for integrations that own their PHP cookie-writing code.
 * Prefer this over calling setcookie() directly.
 */
function skyrocket_cookie_consent_set_cookie(
    string $name,
    string $value,
    int $expires = 0,
    string $path = '/',
    string $domain = '',
    bool $secure = false,
    bool $httponly = false
): bool {
    $enforcement = $GLOBALS['skyrocket_cookie_consent_enforcement'] ?? null;
    if (!$enforcement instanceof Skyrocket_Cookie_Consent_Enforcement || !$enforcement->can_set_cookie($name)) {
        return false;
    }

    $options = [
        'expires' => $expires,
        'path' => $path,
        'secure' => $secure || is_ssl(),
        'httponly' => $httponly,
        'samesite' => 'Lax',
    ];
    if ($domain !== '') {
        $options['domain'] = $domain;
    }
    return setcookie($name, $value, $options);
}
