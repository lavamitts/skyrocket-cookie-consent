# Skyrocket Cookie Consent

A lightweight, JavaScript-driven cookie consent popup for WordPress, controlled through an external JSON configuration file.

**Version:** 1.2.0  
**Author:** Skyrocket Digital Development  
**License:** GPL-2.0  
**Repository:** [skyrocket-cookie-consent](https://github.com/lavamitts/skyrocket-cookie-consent)

---

## Overview

Skyrocket Cookie Consent provides a lightweight frontend cookie consent implementation for WordPress.

The plugin separates its functionality into three main components:

- **PHP:** Handles WordPress integration, asset loading and frontend configuration.
- **JavaScript:** Controls the cookie consent interface and its behaviour.
- **JSON:** Provides the external configuration used by the JavaScript.

This approach keeps the WordPress integration simple and allows the consent configuration to be maintained independently of the PHP implementation.

The plugin does not rely on jQuery or any additional JavaScript libraries.

## Features

- Lightweight WordPress integration.
- JSON-driven cookie consent configuration.
- External JavaScript and CSS assets.
- No jQuery dependency.
- Deferred JavaScript loading.
- Automatic JavaScript cache busting using file modification timestamps.
- Optional stylesheet loading.
- Filterable stylesheet functionality.
- Frontend configuration exposed through a JavaScript global.
- GitHub-based plugin updates through the shared Skyrocket Plugin Updater.

## Requirements

- WordPress.
- PHP 7.4 or later.
- JavaScript enabled in the visitor's browser.
- The included plugin asset files.
- The shared Skyrocket Plugin Updater dependency.

WordPress 6.3 or later is recommended for modern script loading strategy support.

## Installation

1. Download or clone the repository.
2. Ensure the complete plugin directory, including its `vendor` and `assets` directories, is present.
3. Upload the `skyrocket-cookie-consent` directory to `/wp-content/plugins/`.
4. Activate **Skyrocket Cookie Consent** through the WordPress Plugins screen.

The plugin automatically registers and loads its frontend assets.

## File Structure

The expected plugin structure is:

```text
skyrocket-cookie-consent/
│
├── assets/
│   ├── css/
│   │   └── cookie-consent.css
│   │
│   └── js/
│       └── cookie-consent.js
│
├── vendor/
│   └── lavamitts/
│       └── skyrocket-plugin-updater/
│           └── src/
│               └── plugin-updater.php
│
├── cookies.json
├── skyrocket-cookie-consent.php
└── README.md
```

The main plugin file is responsible for WordPress integration.

The JavaScript and CSS files provide the frontend implementation, while `cookies.json` supplies the configuration.

## Configuration

### cookies.json

The plugin expects a JSON configuration file named:

`cookies.json`

This file must be located in the root of the plugin directory.

The PHP component provides its public URL to the JavaScript through the following global object:

```javascript
window.cookieConsentSettings = {
    configUrl: "https://example.com/wp-content/plugins/skyrocket-cookie-consent/cookies.json"
};
```

The actual URL is generated dynamically using WordPress plugin URL functions.

The JavaScript can access the configuration URL using:

```javascript
window.cookieConsentSettings.configUrl
```

The JavaScript implementation is responsible for retrieving and processing the JSON configuration.

The precise configuration structure is determined by the accompanying JavaScript implementation.

## Asset Loading

The plugin registers its frontend assets using the WordPress `wp_enqueue_scripts` action.

### CSS

The stylesheet is loaded from:

`assets/css/cookie-consent.css`

The stylesheet is only enqueued if:

- Stylesheet loading has not been disabled through the plugin filter.
- The CSS file exists.

The registered stylesheet handle is:

`custom-cookie-consent`

The plugin version is used as its cache-busting version identifier.

### JavaScript

The JavaScript file is loaded from:

`assets/js/cookie-consent.js`

The registered script handle is:

`custom-cookie-consent`

The script:

- Has no registered dependencies.
- Loads in the footer.
- Uses the `defer` loading strategy.
- Uses the file modification timestamp as its version identifier when available.

Using the modification timestamp means that changes to the JavaScript file automatically invalidate the browser's cached copy without requiring a plugin version change.

If the file does not exist or its modification timestamp cannot be retrieved, the plugin version is used as a fallback.

### Frontend Configuration

The configuration URL is added to the registered JavaScript using `wp_add_inline_script()` with the `before` position.

This ensures the configuration object is available before the main cookie consent JavaScript executes.

## Developer Hooks

### Stylesheet Loading Filter

The plugin provides a filter allowing developers to disable its default stylesheet.

**Filter:**

`custom_cookie_consent_use_css`

**Default value:** `true`

**Example:**

```php
/**
 * Disable the default Skyrocket Cookie Consent stylesheet.
 *
 * Useful when implementing custom styling through a theme
 * or another stylesheet.
 */
add_filter('custom_cookie_consent_use_css', '__return_false');
```

This disables the plugin's CSS without preventing the JavaScript from loading.

Developers can then provide their own styling while retaining the plugin's JavaScript functionality.

## GitHub Updates

The plugin integrates with the shared Skyrocket Plugin Updater.

The repository configuration is defined in the main plugin file:

```php
$github_updater_config = [
    'username'   => 'lavamitts',
    'repository' => 'skyrocket-cookie-consent',
    'plugin_file' => __FILE__,
];
```

The shared updater is loaded from:

```text
vendor/lavamitts/skyrocket-plugin-updater/src/plugin-updater.php
```

The configuration identifies:

| Setting | Description |
|---|---|
| `username` | GitHub repository owner |
| `repository` | GitHub repository name |
| `plugin_file` | Absolute path to the main plugin file |

Update functionality is provided by the shared updater rather than being implemented directly within this plugin.

## Development

### Version Management

The plugin version is declared in two places:

1. The `Version` field in the main plugin header.
2. The `VERSION` constant within `Custom_Cookie_Consent`.

These values should always be kept synchronised.

The plugin version is used for stylesheet cache busting and as the fallback JavaScript version.

The JavaScript file modification timestamp takes precedence when available.

### Modifying the JavaScript

The main JavaScript file is:

`assets/js/cookie-consent.js`

Changes are automatically reflected in the browser cache version through the file modification timestamp.

### Modifying the Stylesheet

The main stylesheet is:

`assets/css/cookie-consent.css`

Changes should be accompanied by a plugin version increment if browser cache invalidation is required.

Alternatively, developers can implement their own cache-busting mechanism when overriding the default stylesheet.

### Modifying the Configuration

The JSON configuration is maintained separately in:

`cookies.json`

Changes to this file do not require changes to the PHP plugin version.

## Architecture

The plugin follows a deliberately simple loading sequence:

1. WordPress loads the main plugin file.
2. The GitHub updater configuration is established.
3. The shared plugin updater is loaded.
4. The `plugins_loaded` action initialises `Custom_Cookie_Consent`.
5. The class registers its frontend asset-loading callback.
6. WordPress fires `wp_enqueue_scripts`.
7. The plugin conditionally loads its stylesheet.
8. The plugin registers and enqueues its deferred JavaScript.
9. The JSON configuration URL is made available to the JavaScript.
10. The JavaScript handles the cookie consent functionality.

This separation keeps the PHP component lightweight and avoids coupling the consent interface to WordPress beyond the initial asset and configuration integration.

## License

This plugin is distributed under the GNU General Public License version 2 (GPL-2.0).

See the repository for licensing information and included third-party components.

---

**


## Building a release

The plugin uses [Skyrocket Plugin Updater](https://github.com/lavamitts/skyrocket-plugin-updater) as a Composer dependency.

The updater dependency also provides the distribution build script, which prepares the plugin files for release.

After installing the Composer dependencies, run the following command from the plugin root directory:

```bash
./vendor/lavamitts/skyrocket-plugin-updater/tools/build-distribution.sh
```

This builds the release candidate, preparing a distributable version of the plugin without requiring the development dependency structure to be included in the release.

The resulting distribution package is intended for installation through the standard WordPress plugin installation process.
