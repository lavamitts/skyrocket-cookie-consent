# Changelog

## 1.0.0 - Initial release

Adds a secure REST API endpoint that triggers wp_mail() so you can test FluentSMTP delivery from outside WordPress.

## 1.0.1 - Formal release as a reusable plugin

- Addition of update mechanism
- Addition of documentation

## 2.0.0 - Move to database management

- Introduced configurable cookie consent management via an external cookie-consent.json configuration file.
- Added browser/WordPress language selection.
- Implemented shared cookie category definitions across supported languages.
- Added fresh configuration initialisation, avoiding legacy migration.
- Improved separation of plugin functionality, configuration and language-specific content.

## 2.0.2 - Added – Cookie Consent Enforcement

Introduced the working frontend consent enforcement mechanism, including:

- A browser-side cookie guard that checks cookie names against the configured cookie inventory.

- Category-based consent checks, allowing optional cookies only when the visitor has explicitly accepted the corresponding category.

- Default-deny handling for unknown or unclassified cookies.

- Support for strictly necessary cookies without requiring optional consent.

- Consent withdrawal handling, removing known cookies associated with withdrawn categories and preventing them from being recreated.

- Integration with the consent interface so changes to visitor preferences are reflected in the enforcement mechanism immediately.

- Added test coverage using manually generated cookies to verify that consent is respected, unknown cookies are blocked, and withdrawn consent prevents further cookie creation.