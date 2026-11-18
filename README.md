# Skyrocket Cookie Consent

A lightweight, database-managed cookie consent plugin for WordPress.

**Version:** 2.0.0  
**Author:** Skyrocket Digital Development  
**License:** GPL-2.0

## Overview

Version 2 replaces the static `cookie-consent.json` configuration file with a WordPress administration interface and database-backed settings.

The plugin manages:

- Multiple languages and translated popup labels.
- Shared cookie categories.
- Shared individual cookie definitions.
- Translated category titles and descriptions.
- Translated cookie descriptions.
- Required categories.
- A configurable default language.

The configuration is stored in one WordPress option. No custom database tables are created.

## Requirements

- WordPress 6.3 or later recommended.
- PHP 7.4 or later.
- The existing frontend `cookie-consent.js` and `cookie-consent.css` assets.

## Installation

1. Upload the plugin directory to `wp-content/plugins/`.
2. Copy your existing `assets/js/cookie-consent.js` and `assets/css/cookie-consent.css` into the corresponding directories in this package.
3. Activate **Skyrocket Cookie Consent**.
4. Open **Cookie Consent** in the WordPress administration menu.

A fresh English configuration is created on first use. The old JSON file is not read or imported.

## File structure

```text
skyrocket-cookie-consent/
├── assets/
│   ├── css/
│   │   └── cookie-consent.css
│   └── js/
│       └── cookie-consent.js
├── includes/
│   ├── class-cookie-consent-admin.php
│   ├── class-cookie-consent-config.php
│   └── class-cookie-consent-rest.php
├── vendor/
│   └── lavamitts/
│       └── skyrocket-plugin-updater/
│           └── src/
│               └── plugin-updater.php
├── skyrocket-cookie-consent.php
└── README.md
```

The updater dependency is optional at runtime in this version: the plugin checks that its loader exists before including it. Include the shared updater package in release ZIPs when GitHub updates are required.

## Data model

All configuration is stored in the `skyrocket_cookie_consent_settings` WordPress option with autoload disabled.

### Languages

Each language stores its display label and translated popup/interface strings.

### Categories

Categories are shared across languages. Each category stores:

- A stable category identifier.
- A required flag.
- A title and description translation for each language.
- Its list of cookies.

### Cookies

Cookies are shared across languages. Each cookie stores:

- A unique internal identifier.
- Cookie name.
- Expiry/duration.
- A description translation for each language.

The internal cookie identifier allows two entries with the same cookie name to remain distinct.

## Language selection

The REST response includes a `language_fallbacks` array in this order:

1. A supported browser language from the request's `Accept-Language` header.
2. The WordPress site locale.
3. The configured default language.

Locale matching supports regional fallback. For example, `fr-CA` can match an available `fr` translation.

The frontend JavaScript must use this array when selecting a language. The plugin cannot guarantee this behaviour if an older frontend script independently selects its language and ignores the REST metadata.

## Frontend configuration endpoint

The public configuration endpoint is:

```text
/wp-json/skyrocket-cookie-consent/v1/config
```

It returns the familiar `languages` structure expected by the previous JSON-driven implementation. Shared category and cookie records are expanded into each language in the response, with translated text applied.

Additional metadata is included:

- `default_language`
- `language_strategy`
- `language_fallbacks`
- `wordpress_language`

Consumers that only read `languages` can ignore the additional fields.

## JavaScript integration

The frontend script receives:

```javascript
window.cookieConsentSettings = {
    configUrl: "https://example.com/wp-json/skyrocket-cookie-consent/v1/config",
    languageStrategy: "browser-wordpress"
};
```

The frontend must fetch `configUrl`, then select the first available language in `language_fallbacks`.

Example selection logic:

```javascript
const config = await fetch(window.cookieConsentSettings.configUrl)
    .then(response => {
        if (!response.ok) {
            throw new Error('Unable to load cookie consent configuration.');
        }

        return response.json();
    });

const language = config.language_fallbacks.find(
    code => Object.prototype.hasOwnProperty.call(config.languages, code)
) || config.default_language;

const languageConfig = config.languages[language];
```

Pass `languageConfig` to the existing popup rendering code.

The current frontend JavaScript was not included with this source package. Its configuration-fetching and language-selection code therefore needs to be updated to use this endpoint and fallback array. The popup rendering and consent behaviour can otherwise remain unchanged.

## Administration

The Cookie Consent administration screen provides:

- Add and delete languages.
- Edit translated popup text.
- Set the default language.
- Add and delete shared categories.
- Mark categories as required.
- Translate category titles and descriptions.
- Add and delete cookies.
- Edit cookie names and expiry values.
- Translate cookie descriptions.

The default language cannot be deleted.

The `strictly_necessary` category is protected from deletion by the initial implementation.

## Developer filter

The existing stylesheet filter is retained:

```php
add_filter('custom_cookie_consent_use_css', '__return_false');
```

This disables the plugin stylesheet without disabling its frontend JavaScript.

## Security

Administration changes require the `manage_options` capability and a WordPress nonce. Submitted values are sanitised before saving. The configuration REST endpoint is public by design because the frontend consent interface needs to read it.

Do not store secrets or private administrative data in the public configuration.

## Version history

### 2.0.0

- Replace the static JSON configuration file with WordPress database storage.
- Add language management.
- Add shared category management.
- Add shared cookie management.
- Add translated category and cookie descriptions.
- Add public REST configuration endpoint.
- Add browser → WordPress → default language fallback metadata.
- Retain the existing frontend stylesheet filter and script handle.
