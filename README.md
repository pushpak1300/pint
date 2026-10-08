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

**Laravel Pint** is an opinionated PHP code style fixer for minimalists. Pint 2.x uses **[Mago](https://mago.carthage.software/1.53.0/en/tools/formatter/overview/)** for PHP formatting and selected code cleanup.

This branch is the Pint 2.x migration. See [UPGRADE.md](UPGRADE.md) for configuration changes and known differences. Source contributors must run `composer install`, `npm ci` (for Blade), and `python3 scripts/stage-mago.py` before running tests or building the bundled PHAR. Released builds include Mago; users do not install or download it separately.

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
