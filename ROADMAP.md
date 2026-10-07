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

Updated through merged PR #95 (2026-10-07): the summary includes Grid/Show
Attribute dispatch, Grid query names, styled Radio NULL values, literal copy text
and Editable Select callback sources. Review the view/attribute-reader and reload
cautions, alongside earlier paired-view and upload-hook guidance. The
[DBAL 2/3 support boundary](COMPATIBILITY.md#doctrine-dbal-support-boundary) remains;
DBAL 4 is not supported.
日本語: マージ済み PR #95 までの変更点・更新時の注意は上記のまとめを参照してください。
独自ビュー・属性参照・クエリ URL と再読み込みの注意、以前の同時ビュー更新・
アップロードフックの注意を確認してください。DBAL 4 は非対応です。

## Phase 1: Establish a verified baseline

- [x] Audit the imported code, dependency constraints, assets, tests, and CI
- [x] Run PHP 8.4 syntax checks and record existing deprecations
- [x] Fix the Tree/Column dynamic-property and implicit-nullability diagnostics,
      with focused regressions and an intentional PHP `^8.2` baseline
- [x] Rebuild the package-test harness on supported BrowserKit/PHPUnit versions
      ([isolated SQLite runner](tests/browserkit/README.md)); retain 73 historical
      methods and add four package-state isolation regressions
- [x] Isolate FakerPHP, legacy factories and disposable application setup in the
      modern runner, separate from the root historical development graph
- [x] Allow BrowserKit `^6.0 || ^7.0` in root development dependencies (PR #85);
      the isolated runner already requires `^7.2.8`. This resolves the v6-only
      constraint conflict without retiring the remaining legacy development setup
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
- [x] Preserve Number integer precision/bounds and readonly/disabled widget states;
      refresh published Number assets and review [integer](tests/integration/NUMBER_INTEGERS.md)
      and [older-jQuery fieldset serialization](tests/integration/NUMBER_FIELD_STATES.md) limits
- [x] Forward DateMultiple native JSON options and repair existing widget callback
      mapping; review [date-format migration cautions](tests/integration/DATE_MULTIPLE_OPTIONS.md)
      and [the separate callback-helper contract](tests/integration/WIDGET_OPTION_SERIALIZATION.md)
- [x] Preserve Currency fractions for declared radix points during preparation;
      [preparation does not change validation or float precision](tests/integration/CURRENCY_RADIX.md).
      Exact pre-merge checks through PR #65 are recorded in
      [the hosted evidence](COMPATIBILITY.md#hosted-evidence-for-prs-6165)
- [x] Align ordinary Grid `gt()`/`lt()` labels with their existing inclusive SQL,
      render NULL carousels as empty cells, and retain integer/zero selections in
      inline MultipleSelect popovers; reconcile affected views and review
      [filter](tests/integration/GRID_INEQUALITY_FILTERS.md),
      [carousel](tests/integration/GRID_CAROUSEL.md) and
      [inline-editor limits](tests/integration/GRID_INLINE_MULTIPLE_SELECT.md)
- [x] Restore collection conditional-field initialization/clearing and styled
      Checkbox zero selections; review [cascade subclass hooks](tests/integration/COLLECTION_CONDITIONAL_FIELDS.md)
      and [Button/Card view upgrades](tests/integration/STYLED_CHECKBOX_ZERO.md)
- [x] Restore Slider double-range endpoints and serialize Switch label strings;
      retain the [integer-range contract](tests/integration/SLIDER_RANGES.md) and
      remove [manual label-escaping workarounds](tests/integration/SWITCH_LABELS.md)
- [x] Restore attempted URL-options Select/MultipleSelect choices after validation
      failure; [dependent loaders and remote availability remain separate](tests/integration/REMOTE_SELECT_OLD_INPUT.md).
      The [PR #74 hosted snapshot](COMPATIBILITY.md#hosted-evidence-for-pr-74)
      records that exact pre-merge revision and coverage limits
- [x] Restore Grid QuickCreate and Action modal retries while retaining input;
      review [QuickCreate overrides](tests/integration/GRID_QUICK_CREATE.md) and
      [Action retry/idempotency limits](tests/integration/ACTION_MODAL_RETRY.md)
- [x] Isolate Grid upload cells and iterate native FileList for `uplaodMany()`;
      update [overridden upload views and generated-target selectors](tests/integration/GRID_INLINE_UPLOAD.md)
- [x] Preserve new MultipleFile uploads during sorting and allow optional file/image
      validation on sort-only saves; review [changed combined-request hook inputs](tests/integration/MULTIPLE_FILE_SORT.md)
- [x] Keep missing Grid table cells aligned and render nullable inline Select/Radio;
      review [custom table-view input](tests/integration/GRID_TABLE.md) and
      [nullable-choice boundaries](tests/integration/GRID_INLINE_NULLABLE_CHOICES.md)
- [x] Isolate independent dependent-Select initializers; review
      [PHP overrides and loader boundaries](tests/integration/SELECT_LOADS_ISOLATION.md)
- [x] Scope mixed Grid inline submit handlers by resource/payload name; update both
      common/submit views, refresh stale compiled views and fully reload admin pages.
      See [paired-view, reload and remaining identity limits](tests/integration/GRID_INLINE_MIXED_EDITORS.md)
- [x] Recognize modern Eloquent Attributes in Grid/Show dispatch and repair named
      Grid range inputs, configured quick-search keys and single-prefix removal;
      review [dispatch/query boundaries](COMPATIBILITY.md#recent-merged-changes-prs-8795).
      Form Attribute discovery/saving and existing string-zero search behavior are unchanged
- [x] Preserve nullable styled Radio selections and literal Copyable text, and escape
      Editable Select callback-source JSON; review [view/attribute upgrades and offline verification limits](COMPATIBILITY.md#recent-merged-changes-prs-8795).
      OS clipboard writes, live-browser transport and full PJAX remain unverified
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
