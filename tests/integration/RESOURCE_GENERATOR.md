# Model-based controller generation

`ResourceGeneratorTest.php` boots the real package provider in Laravel 12/13
through Testbench. It exercises the production `ResourceGenerator`,
`DoctrineSchema` and existing-PDO driver adapter; it does not substitute native
Laravel column arrays or fabricated DBAL columns for the production path.

## Upgrade and compatibility boundaries

The package now requires `doctrine/dbal: ^2.13.9 || ^3.10.6`. Consumers locked to
older DBAL releases must update their lockfile; the shorter existing-PDO bridge
is not compatible with every early DBAL 3 release. Resolve the package and DBAL
together using normal Composer checks, for example:

```sh
composer update encore/laravel-admin doctrine/dbal --with-all-dependencies
```

Frameworks that still provide the explicit Doctrine methods keep their original
code path. Representative **normally resolved** legacy probes pass with Laravel
8.83.27 / DBAL 2.13.9 and Laravel 10.49.0 / DBAL 3.10.6. Laravel 8 emits upstream
implicit-nullable deprecations on PHP 8.4. Laravel 10 / DBAL 2 fails inside the
framework's own `getDoctrineSchemaManager()` because it calls DBAL 3's
`createSchemaManager()`; this change does not alter that legacy behavior.
Current full Laravel 12/13 consumers require the DBAL 3 branch due to Symfony
HttpFoundation's Composer conflicts with DBAL 2. The retained DBAL 2 range also
serves older frameworks and separately resolvable Illuminate component users.
These are representative checks, not support for every framework/DBAL cross-product.

## Run

From `tests/integration`, resolve the desired framework and DBAL release normally:

```sh
# Laravel 12, supported DBAL 3 floor
composer update --with 'orchestra/testbench:^10.0' \
  --with 'phpunit/phpunit:^11.5' --with 'doctrine/dbal:3.10.6'
composer check-platform-reqs
vendor/bin/phpunit --filter ResourceGeneratorTest

# Laravel 13, supported DBAL 3 floor (PHP 8.3+)
composer update --with 'orchestra/testbench:^11.0' \
  --with 'phpunit/phpunit:^12.0' --with 'doctrine/dbal:3.10.6'
composer check-platform-reqs
vendor/bin/phpunit --filter ResourceGeneratorTest
```

The two database-service methods skip unless explicitly selected. Everything else
uses fresh, disposable SQLite files and real PDO connections, without a server.
The general integration suite includes this file, so `composer test` also runs
its SQLite and Artisan regressions.

## Assertions

- Actual `admin:make --model` and `admin:controller` commands create complete,
  parseable controller PHP, use the expected namespace/model and emit the
  resource route. The service fixtures also run actual model-backed Artisan
  controller creation and `--output` against qualified MySQL/MariaDB/PostgreSQL
  tables and PostgreSQL session search paths. Both `--output` command paths
  contain the independent oracle's
  exact grid, show and form bytes. Output-only generation creates no extra file.
- Ordered names, exact Doctrine type/default values and complete normalized
  `Column::toArray()` metadata match an **independent real DBAL connection of the
  same installed version**. The oracle is not the new adapter and does not consume
  Laravel's native schema arrays. A renderer using only those independently read
  columns supplies the byte-for-byte form/grid/show reference.
- Named connections and a table prefix are used while the default connection is
  unrelated. SQLite read and write PDOs intentionally have different columns.
  Schema reads must use the write PDO.
- An uncommitted SQLite schema change is visible to generation, an uncommitted
  row stays invisible on the independent reference connection, and Laravel's
  transaction level and physical PDO transaction remain active. The caller can
  roll back the change afterward.
- Disconnect/reconnect uses the replacement PDO rather than cached metadata.
  A failed reconnect propagates its exact exception and cannot yield stale or
  empty successful output; a subsequent successful reconnect recovers.
- PDO error mode and a custom statement class survive success, a missing table,
  and a real DBAL unknown-type exception. The original connection still works.
- Persistent PDO works with DBAL 3. DBAL 2 rejects it with an actionable error
  **before changing connection state**: PHP does not allow the statement-class
  change required by DBAL 2's PDO importer on persistent connections.
- A real `SQLiteConnection` subclass exposes the removed Doctrine methods to
  exercise the unchanged legacy branch. Its schema manager is real DBAL. The
  availability method and original prefixed table argument are verified, as is
  its `false` availability error. This targeted branch check is not a claim that
  every historical Laravel/DBAL dependency pairing works.

The transaction, reconnect, PDO-state and persistent-PDO lifecycle checks above
use SQLite only; the service fixtures do not establish those guarantees for
every driver.

The fixtures cover strings, embedded quotes and backslashes, empty/default-null
values, the string `NULL`, zero strings, booleans/tiny integers, other integer
sizes, decimal/floating values, text/blob, dates/times/timestamps and reserved
form columns. Generated fragments and complete controllers are PHP-parser checked.

## Live service matrix

The post-PR #25 [verified coverage snapshot](../../COMPATIBILITY.md#database-service-evidence-and-remaining-limits)
records the successful hosted run, exact commit, server versions and distinction
between the six full-framework and four component jobs.

`.github/workflows/model-schema.yml` defines six **full-framework** jobs:

- Laravel 12 / Testbench 10 / PHP 8.2 and Laravel 13 / Testbench 11 / PHP 8.3
- Exact DBAL 3.10.6
- MySQL 8.4, MariaDB 10.11 and PostgreSQL 16

Each full-framework job runs the SQLite/Artisan tests plus the matching service
fixture. MariaDB runs twice, through Laravel's `mysql` and `mariadb` connection
classes.

Four additional jobs use the independently resolvable `tests/schema-components`
consumer: Illuminate Database/Events 12 or 13, exact DBAL 2.13.9, and MySQL 8.4 or
MariaDB 10.11 on PHP 8.4. They run `tests/prototypes/schema_parity.php` against the
production bridge with SQLite and their selected service. MariaDB again runs via
both Laravel connection classes. These jobs cover components and metadata/output
parity; they do **not** boot full Laravel or exercise Artisan, and do not cover
PostgreSQL with DBAL 2.

This split is necessary: current Symfony HttpFoundation constraints conflict
with DBAL 2 in the full-framework consumer. Normal Composer resolution rejects
that combination. The component consumer has no HttpFoundation dependency;
no platform requirements, security checks or dependency conflicts are bypassed.

MySQL/MariaDB use both unqualified and database-qualified model tables with the
prefix applied exactly once. PostgreSQL uses a fresh schema, schema-qualified
model names and a changed session `search_path` to verify reuse of the existing
connection rather than reconstruction from Laravel config.

Additional MySQL/MariaDB columns cover enum/spatial mappings, native JSON,
comment type hints, tiny/medium/long text and blob, generated columns and
expression defaults. PostgreSQL covers JSON/JSONB, UUID, a domain over integer,
a comment type hint, generated columns and expression defaults.

Parity is **same-version DBAL parity**, not equality between DBAL majors:
DBAL 2.13.9 reports MariaDB's JSON alias as `text` and generates `textarea`;
DBAL 3.10.6 recognizes its JSON check constraint and generates a `text` field.
The regression asserts each installed major's behavior explicitly. The bridge
must not replace either with a native-metadata approximation.

Dependency resolution and platform checks remain enabled. The workflow runs
`composer audit --locked --abandoned=report`: DBAL 2 depends on the abandoned
`doctrine/cache` package, which is reported without turning abandonment alone
into a failure. Security advisory failures remain enabled. Prefer DBAL 3 for
maintained dependencies and persistent-PDO support.

To run a service fixture manually, supply only a dedicated disposable database
named `schema_generator`:

```sh
MODEL_SCHEMA_DISPOSABLE=1 \
MODEL_SCHEMA_DATABASE=pgsql \
MODEL_SCHEMA_HOST=127.0.0.1 \
MODEL_SCHEMA_PORT=5432 \
MODEL_SCHEMA_DB_NAME=schema_generator \
MODEL_SCHEMA_USER=postgres \
MODEL_SCHEMA_PASSWORD=disposable-schema-only \
vendor/bin/phpunit --filter ResourceGeneratorTest
```

Use `MODEL_SCHEMA_DATABASE=mysql` or `mariadb` and the appropriate port/user for
those services. Both the opt-in and exact database-name guard are checked before
service DDL. Tables/schema names are randomized; cleanup drops only fixture-owned
objects. Never point this runner at production or a shared application database.

## Local verification (2026-10-03)

PHP 8.4.25, Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56 and Laravel
13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37, with DBAL 3.10.6:

- Focused SQLite/Artisan checks: **10 tests, 254 assertions, 2 service skips** on
  both framework families.
- Complete integration suite: **56 tests, 35,302 assertions, 2 service skips** on
  both framework families.
- PHP 8.5.11 / DBAL 3.10.6: the same focused result on both framework families.
- Negative control: loading the actual pre-fix `ResourceGenerator` from `01713cb`
  makes the real Artisan test fail at the removed
  `SQLiteConnection::isDoctrineAvailable()` method. Restoring current source
  passes the regression.

The local DBAL 3 checks reuse existing normally Composer-installed Testbench
consumers and map the package PSR-4 namespace to this worktree without changing
vendor files. Supplemental DBAL 2 source-overlay execution probes ran the same
SQLite assertions, but **are not valid resolved full-framework consumers** and
must not be reported as full-Laravel/DBAL-2 compatibility passes. Normal Composer
resolution rejected that combination, which is why hosted DBAL 2 coverage uses
the separate component consumer described above. DBAL 2's independent SQLite
connection emits an upstream `PDO::sqliteCreateFunction()` deprecation on PHP 8.5;
component CI uses PHP 8.4. The production existing-PDO bridge does not create
that extra SQLite connection.

The independent component consumer also passed the production SQLite parity
probe with normally resolved Illuminate Database 12.69.3 / DBAL 2.13.9 and
Illuminate Database 13.34.0 / DBAL 2.13.9. The latter's actual-runtime platform
check passed. This is legitimate component coverage, separate from the rejected
full-framework dependency pairing.

Local service processes cannot be started in this environment, so MySQL,
MariaDB and PostgreSQL were not run locally. Their successful hosted results
are recorded in the linked coverage snapshot; a configured matrix alone is not
evidence of a pass. SQL Server remains **unverified against a
live server**; this workflow has no SQL Server service and accepts no SQL Server
license terms. General SQL Server support, all third-party drivers, all PDO
options, historical framework combinations and full application CRUD are not
certified by this focused suite.
