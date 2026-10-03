# Database defaults and canonical temporal literals

Run this dependency-free regression in a fresh PHP process:

```sh
php tests/compatibility/null_defaults.php
```

Metadata doubles exercise the real `ResourceGenerator` with 774 cases. The test
promotes all diagnostics to failures, compares complete generated source, parses
it, and evaluates fixed local fixtures to check default values and PHP types.
Both required and nullable columns are covered throughout.

## Temporal default boundary

Only unquoted strings already supplied by DBAL in these exact forms are emitted
as literal defaults, using `var_export`:

- `date`: valid Gregorian `YYYY-MM-DD`, years 0001–9999
- `time`: `HH:MM:SS`, from 00:00:00 through 23:59:59
- `datetime`: the same valid date and time separated by exactly one ASCII space

The matrix includes leap-year and century rules, year limits, midnight, the end
of the day, and cross-type rejection. Zero or impossible dates, invalid clock
components, fractional seconds, zones, noncanonical widths or separators,
Unicode digits, surrounding whitespace, quotes, casts, SQL/PHP expressions and
non-string values retain the existing `date(...)` scaffolding. A nullable
column whose metadata default is NULL still has no generated default.

Non-temporal controls preserve empty/zero omission, ordinary string, numeric and
textarea defaults, unknown-type handling, and the existing `timestamp` numeric
mapping. No temporal literal is coerced into a different temporal field type.

## Schema-discovery limit

These doubles test the generator's DBAL metadata boundary, not SQL parsing or
live schema discovery. In the installed DBAL 2.13.9 and 3.10.6 implementations,
PostgreSQL removes surrounding literal/cast syntax; MariaDB and SQLite unquote
literal defaults; MySQL passes its reported default through. Once normalization
has produced a canonical string, the generator cannot reconstruct whether the
original SQL included a cast or an equivalent expression. Quotes, casts and
expressions that **remain in metadata** are rejected by the literal recognizer.

The integration and schema-parity fixtures separately assert actual canonical
DBAL defaults and exact generated source for required/nullable temporal literals.
The service fixtures cover MySQL/MariaDB and PostgreSQL when their opt-in
services are run; their presence is not evidence of a live-service pass.

The existing null guard also avoids passing null to `trim`, deprecated since
PHP 8.1. This focused regression is not the full Laravel browser suite. The
package requires PHP 8.2+, and the dedicated standalone workflow runs PHP
8.2, 8.3 and 8.4.
