# DBAL 2 schema component checks

This consumer installs actual Illuminate Database/Events and DBAL 2.13.9 using
normal Composer resolution. It loads this repository's `Encore\Admin` source
through PSR-4. It is deliberately **not a full Laravel application or a consumer
of the package's complete dependency graph**.

Current Symfony HttpFoundation 7.4 conflicts with DBAL versions below 3.6.
Consequently Laravel 12/13 full applications cannot resolve DBAL 2.13.9; their
Artisan and HTTP integration jobs use DBAL 3.10.6. Do not work around this conflict
with replaced packages, disabled security checks or source overlays and call it
a full application pass.

These component checks cover the DBAL 2 branch of the schema adapter independently
with actual database objects. DBAL 2 remains in the package range for older
framework applications. The [full generator documentation](../integration/RESOURCE_GENERATOR.md)
describes verified legacy pairings and remaining limits.

From this directory:

```sh
composer update --with 'illuminate/database:^12.0' --with 'illuminate/events:^12.0'
composer check-platform-reqs
composer audit --locked --abandoned=report
php ../prototypes/schema_parity.php vendor/autoload.php sqlite
```

Use `^13.0` for both Illuminate constraints for that component-family check.
The existing differential probe additionally supports opted-in disposable MySQL
and MariaDB services; see its [research notes](../../docs/investigations/native-schema-parity.md).
It now asserts production bridge output against the **same DBAL version's** real
schema-manager output. MariaDB JSON remains text under DBAL 2.13.9, which lacks
DBAL 3's JSON check-constraint recovery; this preserves DBAL 2's existing behavior
rather than promising cross-major metadata parity.

DBAL 2 requires the abandoned `doctrine/cache` package. The audit command reports
abandonment and still fails security advisories. No advisory or platform checks
are disabled. Composer lock and vendor files are local-only.
