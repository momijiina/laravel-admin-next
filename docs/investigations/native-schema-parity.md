# Native schema characterization before a production adapter

This document records the original **native metadata investigation**. Its proposed native normalizer is still test-only. Model-backed generation now uses a separate existing-PDO DBAL bridge on modern Laravel; see [the generator integration tests](../../tests/integration/RESOURCE_GENERATOR.md) for implementation scope and verification status. The differential probe additionally compares production output with DBAL, including MariaDB JSON. The historical Laravel Doctrine path is unchanged.

## What the differential probe measures

`tests/prototypes/schema_parity.php` creates a real table in a disposable database. Laravel native schema metadata and DBAL 3's live schema manager inspect **the same table**. A test-only, deliberately incomplete normalizer translates native metadata into the existing DBAL Column interface. The DBAL oracle does not supply expected metadata to that normalizer, except that the experiment uses DBAL's platform type mapping. Version/platform detection therefore remains a production design question.

The test compares ordered column names, type names and normalized defaults, then byte-identical form/grid/show snippets and PHP parsing. It exercises a named model connection while the default connection points elsewhere, a prefix applied exactly once, reserved fields, missing tables, and (on MySQL-family servers) schema-qualified table lookup. It records exact runtime/framework/server/platform versions.

Fixture coverage: integer sizes, tinyint/boolean, decimal and floating defaults; strings including empty, zero, literal `NULL`, SQL NULL, apostrophes and backslashes; text/blob families; dates/times/current timestamp; enum, geometry, JSON, DC2Type comments, generated columns and arithmetic expression defaults where supported. Expression defaults are compared as metadata; no SQL expression is evaluated. This does not establish that evaluating generated forms is meaningful for every SQL expression.

### Known MariaDB JSON gap

MariaDB stores JSON as longtext plus a `json_valid` check. Laravel's MySQL/MariaDB native column query does not include check constraints. DBAL's MariaDb1043Platform does query `information_schema.CHECK_CONSTRAINTS` and recovers JSON semantics. A type-name map alone consequently changes the generator's JSON widget from text to textarea.

The test explicitly expects and reports that gap for `document`, including the different generated widgets. It excludes only that column when checking the remaining generated-output parity. A green job is **characterization plus partial parity**, not proof that this normalizer is production-ready or fully equivalent. Any new mismatch still fails the job.

## Verification status before CI

- Real Laravel 12.69.3 and 13.34.0, PHP 8.4.25, DBAL 3.10.6, SQLite 3.53.4: all 22 actual columns passed normalized metadata and rendered-snippet parity, including PHP parsing.
- MySQL and MariaDB: **not run locally**. Official Debian MariaDB 10.11.18 packages were extracted into a separate workspace and its system tables initialized; a separate PHP build enabled pdo_mysql. Server startup was blocked because UNIX socket creation returned EPERM, including the approved elevated attempt. No security setting, system service or network port was changed.
- The new CI matrix runs real MySQL 8.4 and MariaDB 10.11 service containers against Laravel 12 and 13. MariaDB is tested through both `mysql` and `mariadb` Laravel driver configurations. Exact resolved versions appear in the log. CI results must be reviewed before any live MySQL-family pass is claimed.
- PostgreSQL, SQL Server, DBAL 2, older Laravel, full Artisan controller generation and legacy-path compatibility are not established by this experiment. A production adapter must supply their own compatibility coverage.

## Local command

With the isolated consumer dependencies installed:

```
php tests/prototypes/schema_parity.php tests/integration/vendor/autoload.php sqlite
PARITY_DISPOSABLE=1 PARITY_DATABASE=schema_parity PARITY_SOCKET=/path/to/disposable.sock php tests/prototypes/schema_parity.php tests/integration/vendor/autoload.php mysql
```

Use only a dedicated disposable `schema_parity` database. It creates `parity_records` and drops it only after successful creation; it never drops an existing table after CREATE fails. Database credentials in CI are synthetic, job-local fixtures. Do not point the probe at an application database. `PARITY_CAPTURE=/path/output.json` optionally writes observed native and DBAL metadata.

## Design checkpoint

Do not ship the prototype as production code. At minimum retain the old Doctrine branch unchanged, preserve existing generator Column semantics and custom mappings, reuse the model connection, and avoid a generic alias map. MariaDB JSON requires extra constraint metadata or a compatible DBAL bridge. Default normalization, type comments, platform version detection and expression handling need driver-specific decisions and live evidence. PostgreSQL domains and SQL Server large-value type metadata remain additional blockers to a generic adapter.

The investigation concluded that a DBAL bridge around the existing connection may be smaller than reimplementing every driver's introspection, but must be proven against both supported DBAL majors without opening an unintended second application connection. A richer native adapter needs additional driver-specific queries and tests. At that investigation checkpoint, neither design had been selected or implemented.

A bounded DBAL bridge feasibility check found:

- DBAL 2.13.9 documents `DriverManager`'s `pdo` option but says it is deprecated and unsupported in DBAL 3. Do not pass it blindly to DBAL 3.
- DBAL 3.10.6 accepts a custom Driver (or Driver middleware) whose `connect()` can return a wrapper around the existing PDO. A separate SQLite in-memory proof verified native-PDO object identity and schema introspection of an existing table, so no second physical connection was created.
- DBAL 3's `Driver\PDO\Connection` constructor is marked `@internal`. The proof is feasibility evidence, not a stable public existing-PDO import API. A maintained driver adapter would need version-specific compatibility tests, exception/platform semantics, and handling of Laravel reconnect/read-write/custom-connection behavior. These were investigation findings; the subsequent production bridge is covered by its own tests and documentation.

## Sources

- [Laravel 11 upgrade: Doctrine DBAL removal](https://laravel.com/docs/11.x/upgrade#doctrine-dbal-removal)
- Installed Laravel 12.69.3/13.34.0 `Schema/Grammars/MySqlGrammar.php::compileColumns` and `Query/Processors/MySqlProcessor.php::processColumns`; MariaDbProcessor inherits the latter.
- Installed DBAL 3.10.6 `Schema/MySQLSchemaManager.php` default/comment normalization and `Platforms/MariaDb1043Platform.php::getColumnTypeSQLSnippet` JSON check-constraint introspection.
- [MariaDB binary installation guidance](https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/installing-mariadb/binary-packages/installing-mariadb-binary-tarballs)
- [DBAL 2.13.9 DriverManager existing-PDO documentation](https://github.com/doctrine/dbal/blob/2.13.9/lib/Doctrine/DBAL/DriverManager.php) and installed DBAL 3.10.6 `DriverManager`, `Driver/Middleware/AbstractDriverMiddleware` and `Driver/PDO/Connection` source.
- Local runtime packages came from the official Debian `deb.debian.org/debian/pool/` archive; they are not repository artifacts or dependencies.
