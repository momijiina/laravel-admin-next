# Laravel Admin Next Roadmap

## Goal

Modernize laravel-admin while preserving existing APIs where practical.
Compatibility and integration with other Laravel applications remain important
goals. Target versions must be selected and tested, including application-specific
integration checks, before they become support promises; see
[COMPATIBILITY.md](COMPATIBILITY.md).

The starting point is the [2026-10-01 audit](docs/compatibility-audit-2026-10-01.md).
The [original PR candidate inventory](docs/pr-candidates-2026-10-01.md) is a
historical snapshot. Current completed coverage and its limits are recorded in
[the current maintenance summary](COMPATIBILITY.md#current-maintenance-summary).

## Phase 1: Establish a verified baseline

- [x] Audit the imported code, dependency constraints, assets, tests, and CI
- [x] Run PHP 8.4 syntax checks and record existing deprecations
- [x] Fix the Tree/Column dynamic-property and implicit-nullability diagnostics,
      with focused regressions and an intentional PHP `^8.2` baseline
- [x] Rebuild the package-test harness on supported BrowserKit/PHPUnit versions
      ([isolated SQLite runner](tests/browserkit/README.md)); retain 73 historical
      methods and add four package-state isolation regressions
- [x] Isolate FakerPHP, legacy factories and disposable application setup in the
      modern runner without changing the root historical development graph
- [x] Add selected Laravel 12/13 and PHP 8.2–8.5 CI combinations, respecting each
      framework's PHP minimum; record exact tested dependency floors separately
- [x] Exercise shipped configuration through the real integration consumer and
      publish/install through the historical runner (with documented fixture overlays)
- [ ] Replace legacy factory APIs and decide whether to retire the root legacy
      development dependencies/configuration; the isolated runner still uses factories
- [ ] Extend install/login/CRUD/upload/menu/export coverage to fresh applications
      and real browsers, including JavaScript, cookies and CSRF
- [ ] Establish a comprehensive lowest/latest dependency policy; the existing
      exact DBAL/DomCrawler floors are targeted checks, not every dependency minimum

Laravel 12/13 have the targeted runtime coverage linked above. Older Laravel and
downstream application requirements still need an explicit version inventory
and application checks; do not silently remove or claim support for them.

## Phase 2: Compatibility and security repairs, one concern per PR

- [x] Restore model-based controller generation on modern Laravel using the
      existing PDO and real DBAL metadata, preserving the legacy Doctrine path;
      verify SQLite and hosted MySQL/MariaDB/PostgreSQL generation. See
      [generator scope and DBAL 2/3 limits](tests/integration/RESOURCE_GENERATOR.md).
      The native-metadata investigation remains characterization, not the shipped
      replacement for Doctrine's type/default behavior
- [x] Repair controller generation without a model
- [x] Fix CSV escape defaults, PJAX entity conversion, multiple-select null input,
      and the exercised grid/action/controller request-getter deprecations
- [x] Redact sensitive input before operation-log persistence with standalone and
      real SQLite/HTTP lifecycle regressions; see [behavior and limits](docs/operation-log-redaction.md)
- [x] Preserve original main-upload files/records when replacement storage fails;
      see [non-atomic filesystem/database limits](tests/integration/FILE_UPLOAD_FAILURE.md)
- [x] Migrate optional image processing to Intervention Image `^3.11.9` with a
      bounded legacy adapter and GD-output regressions; review the breaking
      changes and unverified drivers/codecs in the [migration guide](IMAGE_MIGRATION.md)
- [x] Repair explicit list bounds, scoped names, and ListField/KeyValue clearing;
      see [validation, marker and override cautions](tests/integration/LIST_FIELD.md)
- [x] Scope collection scripts and repair repeated HasMany table/default/tab
      initialization with offline DOM and targeted HTTP/SQLite persistence checks;
      see [coverage and upgrade links](COMPATIBILITY.md#current-maintenance-summary).
      Real-browser/PJAX and full unsaved-child validation-redirect coverage remain
      outside these regressions
- [x] Preserve ordinary/action textarea leading newlines and distinguish NULL
      from zero in action Select/Radio and ordinary MultipleSelect validation
      redisplay; see [view upgrades and distinct submission/HTTP boundaries](COMPATIBILITY.md#current-maintenance-summary).
      These regressions do not resolve nested HasMany old-input identity
- [ ] Add real password-change and rendered-operation-log coverage
- [ ] Review remaining deprecations, default credentials, login throttling,
      upload previews and AJAX-option escaping; these tests are not a penetration test
- [ ] Extend driver-specific PDO lifecycle checks beyond SQLite and decide whether
      live SQL Server and additional historical framework combinations are required
- [ ] Decide supported dependency ranges and package publication/install path
      using verified runtime results; no separate fork release is announced here

Each remaining change needs its own impact analysis and regression tests. Passing
focused or hosted checks does not establish universal application compatibility.

## Phase 3: Frontend and maintainability

- Inventory vendored asset versions and their actual loading paths
- Replace affected jQuery/Bootstrap and other legacy components incrementally,
  preserving plugin behavior with real-browser smoke tests
- Define a reproducible asset build, integrity/license inventory, and advisory checks
- Add regression coverage for restricted-user authorization, file removal,
  exports, cacheable configuration/routes, and interrupted PJAX/navigation flows
- Improve typing and static analysis against the PHP `^8.2` baseline
- Maintain release notes, upgrade guidance, and an evidence-backed support matrix

## Contribution workflow

Use a branch per independent fix. Keep documentation and code changes reviewable,
run the relevant checks, and state existing failures or untested combinations in
the PR. Never work directly on `main`; merging and deployment are separate steps.
