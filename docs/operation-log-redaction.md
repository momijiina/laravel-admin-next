# Operation-log input redaction

Operation logs now replace sensitive request-input values with `[REDACTED]`
before JSON encoding and database persistence. Matching uses exact,
case-insensitive field names at every array depth, including nested form rows.
The original request is unchanged so validation, authentication and form saving
still receive the submitted values. Ordinary fields and log metadata are kept.

The built-in field names are:

- `password`, `password_confirmation`, `current_password`, `new_password`,
  `new_password_confirmation`
- `_token`, `token`, `access_token`, `refresh_token`, `remember_token`, `api_token`
- `api_key`, `secret`, `client_secret`, `authorization`

These defaults also apply to applications with an older published `admin.php`
configuration. Applications can add names through
`admin.operation_log.redact_fields`, for example:

```php
'operation_log' => [
    // Keep the application's other operation-log settings.
    'redact_fields' => ['pin', 'integration_credentials'],
],
```

Additional names are additive: an empty list cannot disable the built-in
protections. A non-array configuration value is ignored; non-string entries in
the array are ignored without affecting valid entries or built-in defaults.
A matched key's entire value is replaced, even if it contains an
array. Names match at every nesting level; they are not dot paths or wildcard
patterns. Unmatched names such as `password_hint` remain unchanged. Add custom
password/token field names explicitly, and refresh Laravel's configuration
cache after changing configuration when it is enabled.

## Limits and impact

- This changes new operation logs only. It does not delete, rewrite or sanitize
  historical rows, backups or other application/server logs. Review historical
  log access and retention separately if the application previously logged
  sensitive values.
- This is field-name redaction, not a general detector for secrets embedded in
  free text or unconventional field names. Application owners should configure
  any additional sensitive fields or exclude routes whose input must not be
  logged at all.
- A consumer relying on plaintext sensitive values in operation-log input will
  now receive `[REDACTED]`. The request itself and database schema are unchanged.
- Request headers are not part of this middleware's logged input. The
  `authorization` name covers an input field with that name.

## Focused regression

```sh
php tests/compatibility/operation_log_redaction.php
```

This standalone check executes the real middleware using small request, facade
and persistence test doubles. It verifies default/custom keys, nested arrays,
case-insensitive matching, empty/non-sensitive values, log metadata, unchanged
request input, skipped logging, a database exception and a downstream failure.
The unmodified middleware fails the password assertion; the fix passes on
PHP 8.4.25. The package now requires PHP `^8.2`; the focused workflow targets
PHP 8.2, 8.3 and 8.4. A configured target alone is not a local test result.

This standalone check is not full Laravel, database, browser/rendered-log or
legacy-suite coverage. The separate [integration harness](../tests/integration/README.md)
now covers SQLite-persisted redaction through the real HTTP middleware lifecycle.
See [current coverage and limits](../COMPATIBILITY.md#current-verified-coverage-2026-10-03-after-pr-25);
the [original audit](compatibility-audit-2026-10-01.md) preserves the earlier blockers.
