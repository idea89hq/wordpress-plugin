# Contributing

## Dev setup

```
cd woocommerce-plugin
composer install
```

## Checks

Run all three before committing. Each covers something the others don't.

| Command | What it checks | What it does NOT check |
|---|---|---|
| `vendor/bin/phpunit` | Unit tests (Brain Monkey stubs WordPress, no WP install needed). | Coding style, PHP-version compatibility. |
| `vendor/bin/phpcs` | WordPress coding standards, plus `phpcompatibility/php-compatibility` for pre-2020 cross-version issues (removed/deprecated functions, changed signatures). | PHP 8-only **syntax** — the installed phpcompatibility version (9.3.5, released 2019-12-27) predates PHP 8 and has no sniffs for constructor property promotion, `match`, nullsafe (`?->`), named arguments, enums, or `readonly`. It will not flag any of these. |
| `composer lint:php74` | Parses every tracked `.php` file (excluding `vendor/`) under a real PHP 7.4 CLI, via Docker (`php:7.4-cli`). This is the actual floor gate for "no PHP 8-only syntax" — a real parser rejects these constructs outright, with no sniff-coverage gaps. Requires Docker running locally. | Coding style, deprecated-function usage, anything `phpcs` covers. |

The plugin source must run on PHP 7.4 through 8.3: no constructor property promotion, `readonly`, enums, or named arguments anywhere in shipped code. `composer lint:php74` is what actually proves that, not `phpcs`.
