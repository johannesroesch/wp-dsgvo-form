# Contributing to wp-dsgvo-form

## Prerequisites

- **PHP 8.1+**
- **Composer** — [getcomposer.org](https://getcomposer.org/)
- **Node.js 18+** and **npm**
- **WP-CLI** — required for i18n scripts (`wp i18n make-pot`, `make-mo`, `make-json`). Install: [wp-cli.org](https://wp-cli.org/#installing)

## Setup

```sh
composer install
npm install
```

## Build

```sh
npm run build          # Build JS/CSS for production
npm run start          # Watch mode for development
```

## Linting

```sh
composer lint           # PHP_CodeSniffer (WordPress standards)
composer lint:fix       # Auto-fix PHP lint issues
composer analyze        # PHPStan static analysis (level 6)
npm run lint:js         # ESLint (WordPress preset)
npm run lint:css        # Stylelint (WordPress preset)
```

## Testing

```sh
composer test           # PHPUnit (unit + integration)
npm run test:js         # Jest (JavaScript tests)
```

## Internationalization (i18n)

Text-Domain: `wp-dsgvo-form` | Supported locales: de_DE, en_US, fr_FR, es_ES, it_IT, sv_SE

```sh
npm run i18n:pot        # Extract strings to POT template
npm run i18n:mo         # Compile PO → MO (binary)
npm run i18n:json       # Compile PO → JSON (for JS)
npm run i18n:build      # All three in sequence
```

All translatable strings must use `__()`, `_e()`, `esc_html__()` etc. with text-domain `wp-dsgvo-form`.

## Environment

Copy `.env.example` to `.env` for local configuration. Never commit `.env` with real values.
