<?php
/**
 * Cookie consent configuration storage and transformation.
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

/**
 * Stores and retrieves the cookie consent configuration.
 *
 * Languages contain translated interface strings. Categories and cookies
 * are stored once and contain per-language translations.
 */
final class Skyrocket_Cookie_Consent_Config
{
    public const OPTION_NAME = 'skyrocket_cookie_consent_settings';

    /**
     * Retrieve the saved configuration, creating defaults when necessary.
     *
     * @return array
     */
    public function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME);

        if (!is_array($settings)) {
            $settings = $this->get_defaults();
            add_option(self::OPTION_NAME, $settings, '', false);
        }

        return wp_parse_args($settings, $this->get_defaults());
    }

    /**
     * Persist a complete configuration.
     *
     * @param array $settings Configuration data.
     * @return bool
     */
    public function save_settings(array $settings): bool
    {
        return update_option(self::OPTION_NAME, $settings, false);
    }

    /**
     * Return the initial English configuration.
     *
     * @return array
     */
    public function get_defaults(): array
    {
        return [
            'schema_version' => 1,
            'default_language' => 'en',
            'languages' => [
                'en' => [
                    'label' => 'English',
                    'strings' => [
                        'popup_title' => 'Cookie preferences',
                        'popup_description' => 'We use cookies to optimise your experience. Choose which categories you allow.',
                        'cookie_detail' => 'Cookie detail',
                        'cookie' => 'Cookie',
                        'duration' => 'Duration',
                        'about' => 'About',
                        'btn_save' => 'Save choices',
                        'btn_accept' => 'Accept all',
                        'btn_accept_no_choice' => 'Accept',
                    ],
                ],
            ],
            'categories' => [
                'strictly_necessary' => [
                    'required' => true,
                    'translations' => [
                        'en' => [
                            'title' => 'Strictly necessary',
                            'description' => 'Essential for the website to function properly.',
                        ],
                    ],
                    'cookies' => [],
                ],
                'analytics' => [
                    'required' => false,
                    'translations' => [
                        'en' => [
                            'title' => 'Analytics',
                            'description' => 'Helps us understand how visitors interact with the site.',
                        ],
                    ],
                    'cookies' => [],
                ],
                'marketing' => [
                    'required' => false,
                    'translations' => [
                        'en' => [
                            'title' => 'Marketing',
                            'description' => 'Used to deliver relevant advertisements.',
                        ],
                    ],
                    'cookies' => [],
                ],
            ],
        ];
    }

    /**
     * Build the legacy frontend JSON shape from the shared stored model.
     *
     * The resulting structure retains `languages -> language -> categories`,
     * so the frontend can continue consuming the familiar data format.
     *
     * @return array
     */
    public function get_public_config(): array
    {
        $settings = $this->get_settings();
        $languages = [];

        foreach ($settings['languages'] as $language_code => $language) {
            $entry = is_array($language['strings'] ?? null) ? $language['strings'] : [];

            foreach ($settings['categories'] as $category_id => $category) {
                $category_translation = $category['translations'][$language_code]
                    ?? $category['translations'][$settings['default_language']]
                    ?? [];

                $cookies = [];

                foreach (($category['cookies'] ?? []) as $cookie) {
                    $cookie_translation = $cookie['translations'][$language_code]
                        ?? $cookie['translations'][$settings['default_language']]
                        ?? [];

                    $cookies[] = [
                        'name' => (string) ($cookie['name'] ?? ''),
                        'expiry' => (string) ($cookie['expiry'] ?? ''),
                        'description' => (string) ($cookie_translation['description'] ?? ''),
                    ];
                }

                $entry['categories'][$category_id] = [
                    'title' => (string) ($category_translation['title'] ?? $category_id),
                    'description' => (string) ($category_translation['description'] ?? ''),
                    'cookies' => $cookies,
                ];
            }

            $languages[$language_code] = $entry;
        }

        // Return only the public frontend contract, not the internal storage model.
        return [
            'languages' => $languages,
            'default_language' => $settings['default_language'],
            'language_strategy' => 'browser-wordpress',
        ];
    }

    /**
     * Determine the preferred language fallback sequence.
     *
     * Browser language is derived from Accept-Language. WordPress locale is
     * the second choice, followed by the configured default language.
     *
     * @return array
     */
    public function get_language_fallbacks(): array
    {
        $settings = $this->get_settings();
        $available = array_keys($settings['languages']);
        $fallbacks = [];

        $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE']))
            : '';

        if ($accept_language !== '') {
            foreach (explode(',', $accept_language) as $candidate) {
                $candidate = strtolower(trim(explode(';', $candidate)[0]));

                if ($candidate === '') {
                    continue;
                }

                $this->add_language_match($fallbacks, $available, $candidate);
            }
        }

        $wordpress_locale = strtolower(str_replace('_', '-', determine_locale()));
        $this->add_language_match($fallbacks, $available, $wordpress_locale);

        $default = strtolower($settings['default_language']);
        $this->add_language_match($fallbacks, $available, $default);

        return array_values(array_unique($fallbacks));
    }

    /**
     * Add an exact or base-language match to the fallback list.
     *
     * @param array  $fallbacks Current matches.
     * @param array  $available Available language codes.
     * @param string $candidate Candidate locale.
     * @return void
     */
    private function add_language_match(array &$fallbacks, array $available, string $candidate): void
    {
        if (in_array($candidate, $available, true)) {
            $fallbacks[] = $candidate;
            return;
        }

        $base = explode('-', $candidate)[0];

        if (in_array($base, $available, true)) {
            $fallbacks[] = $base;
        }
    }
}
