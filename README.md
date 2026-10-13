<p align="center"><img src="/art/logo.svg" width="50%" alt="Logo Laravel Pint"></p>

<p align="center">
    <img src="/art/overview.png" alt="Overview Laravel Pint" style="width:70%;">
</p>

<p align="center">
    <a href="https://github.com/laravel/pint/actions"><img src="https://github.com/laravel/pint/workflows/tests/badge.svg" alt="Build Status"></a>
    <a href="https://packagist.org/packages/laravel/pint"><img src="https://img.shields.io/packagist/dt/laravel/pint" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/laravel/pint"><img src="https://img.shields.io/packagist/v/laravel/pint" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/laravel/pint"><img src="https://img.shields.io/packagist/l/laravel/pint" alt="License"></a>
</p>

<a name="introduction"></a>
## Introduction

**Laravel Pint** is an opinionated code quality tool for Laravel. This experimental 2.x branch uses **[Mago](https://mago.carthage.software/1.56.0/en/tools/formatter/overview/)** for formatting, linting, static analysis, architecture checks, and selected refactors, configured through one optional `pint.json`.

This branch is the Pint 2.x migration. See [UPGRADE.md](UPGRADE.md) for configuration changes and known differences. Source contributors must run `composer install`, `npm ci` (for Blade), and `python3 scripts/stage-mago.py` before running tests or building the bundled PHAR. Released builds include Mago; users do not install or download it separately.

```sh
vendor/bin/pint                   # Read-only checks; requires Laravel 12 or 13
vendor/bin/pint fix               # Apply all enabled fixes, then check again
vendor/bin/pint format            # Formatting-only compatibility workflow
vendor/bin/pint lint              # Laravel lint rules
vendor/bin/pint analyze           # Project-aware static analysis
vendor/bin/pint guard             # Laravel conventions / configured architecture
vendor/bin/pint refactor -v       # Preview refactors; --apply writes them
```

`fix` can change behavior for enabled rules: review its diff and run your tests.
PHP 8.4+ is required. Formatting supports only Laravel (the default) and PSR-12
(`--preset=psr12`). Analysis uses Mago plus Pint's bundled Laravel extension
for model properties, Eloquent query calls, local scopes, static configuration
types, standard framework helpers, and HTTP response types.
It does not boot your application or run PHPStan,
Larastan, or Rector. Full Larastan inference and Rector migration sets are not
yet supported; see [analysis limits](UPGRADE.md#laravel-model-analysis).

## Laravel and Pest code standards

Pint checks the same configured standards for developers and coding agents;
there is no separate agent ruleset or automatic repair loop. Defaults reject
focused Pest tests and debug helpers in application code, and advise on request
input, strict membership checks, simpler Pest expectations, and environment
reads outside configuration. See [rule defaults and exceptions](UPGRADE.md#laravel-defaults-and-current-limits).

```sh
vendor/bin/pint lint -v                   # Read-only diagnostics with remediation
vendor/bin/pint lint --strict             # Also fail on advisory warnings
vendor/bin/pint lint --format=agent       # Compact actionable JSON; no automatic edits
```

Use `lint.rules` in `pint.json` to disable rules, change severities, or exclude
paths from a particular rule. An explicit `fix` or `lint --fix` can apply enabled
behavior-changing fixes; warnings are not permission to rewrite code silently.

Pint checks source code without booting Laravel. For environment, configuration,
and infrastructure health, use [Laravel Doctor](https://github.com/laravel/doctor)
separately. Pint does not run Doctor or its operational repairs implicitly.

## Official Documentation

Documentation for Pint can be found on the [Laravel website](https://laravel.com/docs/pint).

<a name="contributing"></a>
## Contributing

Thank you for considering contributing to Pint! You can read the contribution guide [here](.github/CONTRIBUTING.md).

<a name="code-of-conduct"></a>
## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

<a name="security-vulnerabilities"></a>
## Security Vulnerabilities

Please review [our security policy](https://github.com/laravel/pint/security/policy) on how to report security vulnerabilities.

<a name="license"></a>
## License

Pint is open-sourced software licensed under the [MIT license](LICENSE.md).
