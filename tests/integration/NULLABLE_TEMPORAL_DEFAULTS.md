# Nullable temporal generated defaults

`ResourceGenerator` omits its synthesized `->default(date(...))` only for DBAL
`date`, `datetime`, and `time` columns whose `getNotnull()` is `false` and whose
`getDefault()` is `null`. Freshly generated optional inputs then stay blank on
create and when editing stored NULLs. Submitting those blank inputs through
Laravel's ordinary HTTP middleware preserves NULL in SQLite.

Required temporal columns retain their current-date/time scaffolding. Explicit
application `Field::default(...)` behavior is unchanged, including its fallback
when an existing model attribute is NULL. This patch changes no Date presentation,
cast formatting, or global Field fallback behavior.

## Existing controllers and boundaries

This is a generator-only change: existing generated controllers are not rewritten.
After review, remove unwanted synthesized `->default(date(...))` calls from their
optional fields, or regenerate a controller without overwriting application
customizations. Intentionally configured application defaults may remain.

Non-NULL database temporal literals and expressions (such as `CURRENT_TIMESTAMP`)
still receive the generator's existing current-date/time expression rather than
faithful database-default interpretation. Tests characterize that unchanged
limitation; this patch does not parse SQL expressions or fix those defaults.

## Regression coverage

Run `vendor/bin/phpunit --filter NullableTemporalDefaultsTest` in this consumer.
The tests boot real Laravel and generate fresh controllers using both
`admin:make --model` and `admin:controller`. They exercise:

- Blank create/store and existing-NULL edit/update, with mutable and immutable
  native date/datetime casts; time-only values have no native Laravel cast
- Rendered input values and exact raw database persistence after HTTP submission
- Required date/datetime/time scaffold expressions and persistence
- Explicit application defaults on create and on stored NULL, with clearing
- Unchanged non-NULL database literal/current-timestamp generated expressions

The standalone `tests/compatibility/null_defaults.php` additionally checks exact
source for nullable/required temporal columns with NULL, empty, literal and
expression metadata defaults. The schema characterization reconstructs native
column nullability and checks temporal nullability against the independent DBAL
connection, since nullability now intentionally affects form output.

## Local evidence (2026-10-03)

Restored PHP 8.5.11, SQLite 3.46.1, DBAL 3.10.6; installed Laravel 12.69.3 /
Testbench 10.12.0 / PHPUnit 11.5.56 and Laravel 13.34.0 / Testbench 11.3.0 /
PHPUnit 12.5.37, using isolated package mappings and bootstrap/view-cache paths:

- Focused: **9 tests, 247 assertions** on each framework family
- Full integration: **81 tests, 35,971 assertions, 2 external-service skips** each
- All standalone compatibility scripts pass; null-default script covers 97 cases
- SQLite model-schema characterization passes on both framework families
- Original-generator negative persistence control fails all 8 create/edit cases
  on each family. Only the generator is replaced with the original implementation;
  a scratch copy bypasses early source/blank-input assertions so requests reach
  persistence assertions. Stored NULLs become synthesized current values.

Existing `class_exists(null)` and `str_replace(null)` deprecations in
`HandleController` and its reference test remain visible in full-suite output. Focused temporal tests are clean.
These are source-mapped existing consumers, not fresh dependency resolutions.
Other PHP versions and live database engines require hosted CI; the historical
BrowserKit suite was not rerun locally because its installed consumer is absent.
No browser automation or JavaScript datepicker execution is claimed here.
