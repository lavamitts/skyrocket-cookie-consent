<?php
/**
 * Cookie consent configuration storage and transformation.
 *
 * @package SkyrocketCookieConsent
 */

defined('ABSPATH') || exit;

/** Stores and retrieves the cookie consent configuration. */
final class Skyrocket_Cookie_Consent_Config
{
    public const OPTION_NAME = 'skyrocket_cookie_consent_settings';

    /** Retrieve the saved configuration, creating defaults when necessary. */
    public function get_settings(): array
    {
        $settings = get_option(self::OPTION_NAME);

        if (!is_array($settings)) {
            $settings = $this->get_defaults();
            add_option(self::OPTION_NAME, $settings, '', false);
        }

        $settings = $this->merge_defaults($settings, $this->get_defaults());

        if (empty($settings['languages']) || !is_array($settings['languages'])) {
            $settings['languages'] = $this->get_defaults()['languages'];
        }
        if (empty($settings['categories']) || !is_array($settings['categories'])) {
            $settings['categories'] = $this->get_defaults()['categories'];
        }
        if (!isset($settings['languages'][$settings['default_language']])) {
            $settings['default_language'] = (string) array_key_first($settings['languages']);
        }

        // Normalise legacy and custom entries without replacing site-specific cookies.
        foreach ($settings['categories'] as &$category) {
            if (!is_array($category)) {
                $category = [];
            }
            $category['required'] = !empty($category['required']);
            $category['translations'] = is_array($category['translations'] ?? null) ? $category['translations'] : [];
            $category['cookies'] = is_array($category['cookies'] ?? null) ? $category['cookies'] : [];

            foreach ($category['cookies'] as $index => &$cookie) {
                if (!is_array($cookie)) {
                    unset($category['cookies'][$index]);
                    continue;
                }
                if (empty($cookie['id'])) {
                    $cookie['id'] = 'cookie_' . sanitize_key((string) $index) . '_' . wp_generate_password(5, false, false);
                }
                $cookie['name'] = (string) ($cookie['name'] ?? '');
                $cookie['expiry'] = (string) ($cookie['expiry'] ?? '');
                $cookie['translations'] = is_array($cookie['translations'] ?? null) ? $cookie['translations'] : [];
            }
            unset($cookie);
        }
        unset($category);

        return $settings;
    }

    /** Persist a complete configuration. */
    public function save_settings(array $settings): bool
    {
        return update_option(self::OPTION_NAME, $settings, false);
    }

    /** Initial interface strings and starter categories; cookie inventories start empty. */
    public function get_defaults(): array
    {
        return [
            'schema_version'   => 1,
            'default_language' => 'en',
            'languages'        => [
                'en' => [
                    'label'   => 'English',
                    'strings' => [
                        'popup_title'          => 'Cookie preferences',
                        'popup_description'    => 'We use cookies to optimise your experience. Choose which categories you allow.',
                        'cookie_detail'        => 'Cookie detail',
                        'cookie'               => 'Cookie',
                        'duration'             => 'Duration',
                        'about'                => 'About',
                        'btn_save'             => 'Save choices',
                        'btn_accept'           => 'Accept all',
                        'btn_accept_no_choice' => 'Accept',
                    ],
                ],
            ],
            'categories' => [
                'strictly_necessary' => [
                    'required' => true,
                    'translations' => ['en' => [
                        'title' => 'Strictly necessary',
                        'description' => 'Essential for the website to function properly.',
                    ]],
                    'cookies' => [],
                ],
                'analytics' => [
                    'required' => false,
                    'translations' => ['en' => [
                        'title' => 'Analytics',
                        'description' => 'Helps us understand how visitors interact with the site.',
                    ]],
                    'cookies' => [],
                ],
                'marketing' => [
                    'required' => false,
                    'translations' => ['en' => [
                        'title' => 'Marketing',
                        'description' => 'Used to deliver relevant advertisements.',
                    ]],
                    'cookies' => [],
                ],
                'functional' => [
                    'required' => false,
                    'translations' => ['en' => [
                        'title' => 'Functional',
                        'description' => 'Enables optional features and remembers convenience preferences.',
                    ]],
                    'cookies' => [],
                ],
            ],
        ];
    }

    /**
     * Return a stable version for consent-relevant policy settings.
     * Changes to category requirements, cookie names, or category assignments
     * invalidate previous consent without reacting to cosmetic translation edits.
     */
    public function get_policy_version(): string
    {
        $settings = $this->get_settings();
        $policy = [];

        foreach ($settings['categories'] as $category_id => $category) {
            $cookie_names = [];
            foreach (($category['cookies'] ?? []) as $cookie) {
                $name = trim((string) ($cookie['name'] ?? ''));
                if ($name !== '') {
                    $cookie_names[] = $name;
                }
            }
            sort($cookie_names, SORT_STRING);
            $policy[$category_id] = [
                'required' => !empty($category['required']),
                'cookies' => $cookie_names,
            ];
        }
        ksort($policy, SORT_STRING);

        return substr(hash('sha256', (string) wp_json_encode($policy)), 0, 20);
    }

    /** Build the existing frontend response shape from the stored model. */
    public function get_public_config(): array
    {
        $settings = $this->get_settings();
        $languages = [];

        foreach ($settings['languages'] as $language_code => $language) {
            $entry = is_array($language['strings'] ?? null) ? $language['strings'] : [];

            foreach ($settings['categories'] as $category_id => $category) {
                $translation = $category['translations'][$language_code]
                    ?? $category['translations'][$settings['default_language']]
                    ?? [];
                $cookies = [];

                foreach ($category['cookies'] as $cookie) {
                    $cookie_translation = $cookie['translations'][$language_code]
                        ?? $cookie['translations'][$settings['default_language']]
                        ?? [];
                    $cookies[] = [
                        'name' => $cookie['name'],
                        'expiry' => $cookie['expiry'],
                        'description' => (string) ($cookie_translation['description'] ?? ''),
                    ];
                }

                $entry['categories'][$category_id] = [
                    'required' => !empty($category['required']),
                    'title' => (string) ($translation['title'] ?? $category_id),
                    'description' => (string) ($translation['description'] ?? ''),
                    'cookies' => $cookies,
                ];
            }
            $languages[$language_code] = $entry;
        }

        return [
            'languages' => $languages,
            'default_language' => $settings['default_language'],
            'policy_version' => $this->get_policy_version(),
            'language_strategy' => 'browser-wordpress',
        ];
    }

    /** Determine browser, WordPress and configured language fallbacks. */
    public function get_language_fallbacks(): array
    {
        $settings = $this->get_settings();
        $available = array_keys($settings['languages']);
        $fallbacks = [];
        $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';

        if ($accept_language !== '') {
            foreach (explode(',', $accept_language) as $candidate) {
                $candidate = strtolower(trim(explode(';', $candidate)[0]));
                if ($candidate !== '') {
                    $this->add_language_match($fallbacks, $available, $candidate);
                }
            }
        }

        $locale = strtolower(str_replace('_', '-', determine_locale()));
        $this->add_language_match($fallbacks, $available, $locale);
        $this->add_language_match($fallbacks, $available, strtolower($settings['default_language']));

        return array_values(array_unique($fallbacks));
    }

    /** Recursively fill missing associative defaults while preserving saved lists and cookie data. */
    private function merge_defaults(array $saved, array $defaults): array
    {
        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $saved)) {
                $saved[$key] = $default;
            } elseif (is_array($default) && is_array($saved[$key]) && $default !== []) {
                $saved[$key] = $this->merge_defaults($saved[$key], $default);
            }
        }
        return $saved;
    }

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
