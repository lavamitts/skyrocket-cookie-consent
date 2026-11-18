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