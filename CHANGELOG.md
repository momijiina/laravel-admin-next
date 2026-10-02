# Changelog

## Unreleased

- Breaking platform change: require PHP `^8.2`; PHP 7.x, 8.0 and 8.1 are no
  longer supported. PHP 8.3+ is recommended.
- Explicitly declare the existing nullability of 37 parameters to avoid PHP 8.4
  compile-time deprecations, preserving names, defaults and accepted values.
- Replace obsolete PHP 7 CI coverage with PHP 8.2–8.4 checks. Laravel and root
  legacy development dependency constraints are otherwise unchanged.

See https://laravel-admin.org/docs/
