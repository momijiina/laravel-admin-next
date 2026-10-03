# Canonical temporal database defaults

Newly generated date, datetime, and time fields preserve canonical literal defaults
reported by DBAL, rather than silently substituting the current PHP date/time.
This applies to required and nullable columns. Generated values are serialized
with `var_export`; application `Field::default` semantics do not change.

## Recognition boundary

Only string metadata values matching these exact forms are recognized:

- `date`: valid Gregorian `YYYY-MM-DD`, year 0001 through 9999
- `time`: `HH:MM:SS`, from `00:00:00` through `23:59:59`
- `datetime`: the date and time above separated by one ASCII space

Calendar validity uses `checkdate`, with no timezone or current-clock dependency.
Zero dates, invalid leap days, rollover dates, fractional seconds, timezone
suffixes, offsets, whitespace, quote/cast syntax, non-string values, and SQL
expressions are not interpreted. These retain the generator's existing
current-date/time scaffolding. In particular, `CURRENT_TIMESTAMP` is not evaluated
or faithfully implemented as a database-side expression. Nullable NULL defaults
still omit a generated default; required NULL defaults still scaffold now.

Recognition operates on DBAL's returned value, not raw DDL. DBAL schema managers
may remove quotes and type casts or normalize default expressions first. A
canonical value exposed after that normalization is recognized; this does not
promise recovery of a default's original SQL provenance. Drivers or formats that
return other representations remain outside this intentionally narrow boundary.

Existing controllers are not rewritten. Regenerate and review them without
losing application customizations, or update individual defaults manually.
Explicit defaults continue to provide fallback values on stored NULL attributes;
users can clear nullable inputs. This change does not alter global field fallback,
Eloquent cast serialization, timezone settings, or datepicker behavior.

## Tests

`LiteralTemporalDefaultsTest` uses both generator commands, mutable and immutable
native Eloquent casts, real HTTP create/update, rendered inputs, and raw SQLite
persistence. Required and nullable literals include a leap day and midnight.
Eloquent date casts serialize dates as `YYYY-MM-DD 00:00:00` under their default
storage format; assertions check that expected storage value, not a new cast rule.
Distinct stored values survive unchanged edits. NULL/default-NULL, ordinary string,
current-timestamp, clearing, and existing application-default controls remain.
`NullableTemporalDefaultsTest` retains all prior blank/null preservation coverage.

Standalone tests exhaustively characterize recognized and rejected metadata.
Live schema fixtures assert DBAL metadata and generated source; external database
runs require their opt-in disposable services. Local evidence uses restored PHP
8.5.11, installed Laravel 12/13 consumers, and isolated bootstrap/view caches.
Source-negative controls load the original main generator. Persistence-negative
controls bypass only early literal source/render assertions, allowing real HTTP
POSTs to prove the old generator stores today's values instead of schema literals.
No JavaScript datepicker or browser automation coverage is claimed.
