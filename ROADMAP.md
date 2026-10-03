# Laravel Admin Next Roadmap

## Goal

Modernize laravel-admin while preserving existing APIs where practical. Exment
compatibility remains an important target. Target versions must be selected and
tested before they become support promises; see [COMPATIBILITY.md](COMPATIBILITY.md).

The starting point is the [2026-10-01 audit](docs/compatibility-audit-2026-10-01.md).
Prepared work is tracked in the [local PR candidate inventory](docs/pr-candidates-2026-10-01.md).

## Phase 1: Establish a verified baseline

- [x] Audit the imported code, dependency constraints, assets, tests, and CI
- [x] Run PHP 8.4 syntax checks and record existing deprecations
- [x] Prepare a small, separately tested PHP 8.2+ dynamic-property fix for Tree
      (subject to PR review; focused PHP 8.4 check only)
- [x] Rebuild the package-test harness on supported BrowserKit/PHPUnit versions
      ([isolated SQLite runner](tests/browserkit/README.md); deprecations remain visible)
- [ ] Replace legacy Faker/factories and explicitly manage the test application
- [ ] Add fresh-application install, login, CRUD, upload, menu, and export smoke tests
- [ ] Add CI for explicitly selected Laravel/PHP combinations, including lowest
      and latest compatible dependency sets
- [ ] Exercise the shipped configuration, not only historical test fixtures

Laravel 12 and 13 with supported PHP versions are candidates for the modern
matrix. Older Laravel/Exment requirements need an explicit version inventory;
do not silently remove or claim support for them.

## Phase 2: Compatibility and security repairs, one concern per PR

- Replace removed Doctrine integration in controller generation with native
  schema introspection, with real database and generated-code tests
- Repair controller generation without a model
- Migrate legacy factory/bootstrap and PHPUnit configuration separately from
  runtime compatibility changes
- Resolve PHP implicit-nullability, CSV escape-default, PJAX entity-conversion,
  and remaining dynamic-property deprecations in small regression-tested changes
- Sensitive-input redaction before operation-log persistence is implemented
  with a focused middleware regression; see [behavior and limits](docs/operation-log-redaction.md).
  Real Laravel password-change and rendered-log coverage remains to be added
- Review default credentials, login throttling, upload previews, and AJAX-option
  escaping; do not treat the audit as a penetration test
- Decide the supported dependency ranges and package publication/install path
  using verified runtime results

The first deprecation PR does not include the DBAL/factory migrations or security
behavior changes. Each needs its own impact analysis and regression tests.

## Phase 3: Frontend and maintainability

- Inventory vendored asset versions and their actual loading paths
- Replace affected jQuery/Bootstrap and other legacy components incrementally,
  preserving plugin behavior with real-browser smoke tests
- Define a reproducible asset build, integrity/license inventory, and advisory checks
- Add regression coverage for restricted-user authorization, file removal,
  exports, cacheable configuration/routes, and interrupted PJAX/navigation flows
- Improve typing and static analysis after deciding the PHP minimum
- Maintain release notes, upgrade guidance, and an evidence-backed support matrix

## Contribution workflow

Use a branch per independent fix. Keep documentation and code changes reviewable,
run the relevant checks, and state existing failures or untested combinations in
the PR. Never work directly on `main`; merging and deployment are separate steps.
