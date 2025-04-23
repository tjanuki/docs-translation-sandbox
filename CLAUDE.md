# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Run Commands
- Install dependencies: `composer install`
- Run development server: `php artisan serve`
- Run all tests: `composer test`
- Run single test: `php artisan test --filter=TestName`
- Run specific test file: `php artisan test tests/Feature/ExampleTest.php`
- Run translation command: `php artisan docs:translate [--source=docs] [--target=jp] [--latest]`
- Lint PHP code: `./vendor/bin/pint`

## Code Style Guidelines
- Follow PSR-4 for autoloading: namespace `App\` for `app/`, `Tests\` for `tests/`
- Use type hints for method parameters and return types (PHP 8.2+)
- Services should be in `app/Services/` and commands in `app/Console/Commands/`
- Use Laravel's Facades (`Illuminate\Support\Facades`) for accessing core services
- Use dependency injection in constructors following Laravel conventions
- Handle exceptions with try/catch blocks and log errors using `Log::error()`
- Config values should be managed in dedicated config files (see `config/translation.php`)