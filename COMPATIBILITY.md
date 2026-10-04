# Compatibility Policy

- Preserve existing laravel-admin APIs where practical
- Avoid breaking changes unless necessary, and document them when required
- Aim for compatibility and integration with other Laravel applications; verify
  specific application/version combinations before claiming support
- Separate dependency declarations, focused checks, and full runtime support

## Current maintenance summary

Updated 2026-10-04. This index describes the changes merged through
[PR #65](https://github.com/momijiina/laravel-admin-next/pull/65), at
[`a87140f`](https://github.com/momijiina/laravel-admin-next/commit/a87140f48ffd974eb5dfecfc03b36967736b09a1).
Use the linked guides for upgrade steps and each regression's precise boundary.
Historical test totals below remain evidence for their own revisions, not
current suite totals or validation of subsequent changes.

- **Image processing ([PR #35](https://github.com/momijiina/laravel-admin-next/pull/35)):**
  optional Intervention Image `^3.11.9` replaces v2 for transformations and
  thumbnails. Review callback types, driver/encoding settings, published
  configuration and non-atomic storage limits in the
  [image migration guide](IMAGE_MIGRATION.md). Ordinary uploads without
  processing do not require it; the PHP floor remains `^8.2`.
- **Application compatibility ([PR #36](https://github.com/momijiina/laravel-admin-next/pull/36)):**
  integration with other Laravel applications is a goal, not a guarantee for
  every application or every release admitted by the Composer constraints.
- **List bounds and names ([PR #37](https://github.com/momijiina/laravel-admin-next/pull/37),
  [PR #38](https://github.com/momijiina/laravel-admin-next/pull/38)):** explicit
  `min()`/`max()` apply even without item rules; embedded and HasMany lists use
  scoped input/error names. Custom validators retain precedence. See
  [list-field behavior and limits](tests/integration/LIST_FIELD.md).
- **Explicit clearing ([PR #39](https://github.com/momijiina/laravel-admin-next/pull/39)):**
  removing every ListField/KeyValue row submits an empty marker; omitting the
  field still preserves stored values. Raw-input hooks and custom validators
  must account for that scalar marker. Controls deliberately disabled to omit
  a field must disable its markers too; see
  [empty-collection cautions](tests/integration/LIST_FIELD.md#explicitly-empty-collections).
- **Collection readonly UI ([PR #46](https://github.com/momijiina/laravel-admin-next/pull/46)):**
  ListField and KeyValue now honor the previously ineffective inherited `readonly()` method. Keys/values remain submitted, while
  collection Add/Remove is locked. Disabled collections remain unsupported. This
  does not add server authorization or concurrency protection; update overridden
  views and review the [behavior cautions](tests/integration/LIST_FIELD.md#readonly-collections).
- **Collection scripts ([PR #40](https://github.com/momijiina/laravel-admin-next/pull/40)):**
  same-column fields have root-local Add/Remove behavior and repeatable
  initialization. Consumers with overridden/published ListField or KeyValue
  views must carry forward the new data markers and direct-child templates;
  see the [view/script contract](tests/integration/COLLECTION_SCRIPT_SCOPING.md#boundaries).
- **HasMany reinitialization ([PR #41](https://github.com/momijiina/laravel-admin-next/pull/41),
  [PR #42](https://github.com/momijiina/laravel-admin-next/pull/42)):** table,
  default and tab modes preserve unique pending-child names and unrelated
  consumer handlers when their ready-wrapped initialization is repeated.
  See the [table guide](tests/integration/HASMANY_TABLE_REINITIALIZATION.md) and
  [default/tab guide](tests/integration/HASMANY_MODES_REINITIALIZATION.md),
  including the pending-DOM versus full validation-redirect coverage boundary.
- **Named-grid pagination ([PR #44](https://github.com/momijiina/laravel-admin-next/pull/44)):**
  configured page sizes now retain the grid-specific page parameter; explicit
  Eloquent page names remain authoritative. Naming a grid does not isolate its
  default shared `_sort` key. [PR #45](https://github.com/momijiina/laravel-admin-next/pull/45)
  adds tests only: configure `setSortName()` before creating sortable columns.
  See [pagination](tests/integration/GRID_PAGINATION.md) and
  [explicit sorting/filter coverage](tests/integration/GRID_EXPLICIT_SORT.md).
- **NULL and zero choices ([PR #47](https://github.com/momijiina/laravel-admin-next/pull/47),
  [PR #48](https://github.com/momijiina/laravel-admin-next/pull/48),
  [PR #49](https://github.com/momijiina/laravel-admin-next/pull/49)):** ordinary
  Select/Radio no longer select zero for NULL; Checkbox retains integer/string
  zero choices. Other legacy loose comparisons and explicit defaults remain.
  Update published/overridden views. Radio omission preserves stored values and
  is not a clearing API; enforce server-side presence rules in the application.
  See the distinct [Select](tests/integration/NULLABLE_SELECT.md),
  [Checkbox](tests/integration/CHECKBOX_ZERO.md), and
  [Radio](tests/integration/NULLABLE_RADIO.md) contracts.
- **Remote Select validation retries:** URL-options Select/MultipleSelect now
  restore attempted selections and explicit clears after failed validation.
  Old input takes precedence over remote/configured selected-option fallbacks;
  without old input those overrides and defaults are unchanged. Dependent
  loaders, Listbox and AJAX-search preloads retain their existing contracts. See
  [retry behavior and custom-initializer cautions](tests/integration/REMOTE_SELECT_OLD_INPUT.md).
- **Range cast presentation ([PR #50](https://github.com/momijiina/laravel-admin-next/pull/50)):**
  eligible native DateRange/DatetimeRange endpoints display in the application
  timezone to avoid unchanged edit/save drift. This is a narrow presentation fix,
  not a change to storage or arbitrary custom casts/parsers; TimeRange is excluded.
  See [eligibility and round-trip limits](tests/integration/DATE_RANGE_CAST_PRESENTATION.md).
- **CSV and default export ([PR #51](https://github.com/momijiina/laravel-admin-next/pull/51),
  [PR #52](https://github.com/momijiina/laravel-admin-next/pull/52)):** empty results
  now contain a header after the BOM, so check for zero data records instead of
  BOM-only output. Title callbacks also run for empty results under the existing
  visibility/order rules. Ordinary default-export resolution avoids PHP 8.5's
  NULL-key deprecation. `Exporter::extend(null, ...)` registration is unchanged;
  use `''` for the empty-string driver. See
  [CSV schema, callbacks and exporter boundaries](tests/integration/CSV_HEADERS.md).
- **Initial range bounds ([PR #53](https://github.com/momijiina/laravel-admin-next/pull/53)):**
  fresh DateRange/DatetimeRange/TimeRange widget pairs seed reciprocal bounds from
  parsed endpoints without changing endpoint values during seeding. Null endpoints
  add no bound; inverted pairs, conflicting bounds, existing widget instances,
  custom parsers and non-default timezone options retain conservative exclusions. Existing
  change behavior and server validation are unchanged. See the
  [widget initialization contract](tests/integration/DATE_RANGE_INITIALIZATION.md).
- **Textarea leading newlines ([PR #55](https://github.com/momijiina/laravel-admin-next/pull/55),
  [PR #56](https://github.com/momijiina/laravel-admin-next/pull/56)):** ordinary
  and action textarea views each add one literal LF after the opening tag so
  HTML parsing preserves the value's leading LFs. Reconcile both overridden
  views separately; keep escaped interpolation unindented and do not add a
  workaround LF to stored values. CR/CRLF normalization and application trimming
  middleware remain unchanged. Ordinary coverage includes HTTP/SQLite saves;
  action coverage stops at outgoing native FormData. See the distinct
  [ordinary](tests/integration/TEXTAREA_NEWLINES.md) and
  [action](tests/integration/ACTION_TEXTAREA_NEWLINES.md) newline contracts.
- **Action Select NULL ([PR #57](https://github.com/momijiina/laravel-admin-next/pull/57)):**
  effective NULL retains the leading blank option rather than selecting zero.
  An untouched blank submits an empty string; middleware normalization and action
  persistence are not covered. Explicit zero remains valid, with other non-NULL
  loose comparisons preserved. Update overridden action views and keep their
  leading blank option. See [action Select cautions](tests/integration/ACTION_SELECT_NULL.md).
- **Action Radio NULL ([PR #58](https://github.com/momijiina/laravel-admin-next/pull/58)):**
  the option comparison no longer matches NULL to zero. The existing additive,
  label-based `checked()` fallback still applies when the original field value
  is NULL, including with old NULL input. Without a selection, native FormData
  omits the group; this is not a clearing API or a server-side presence guarantee.
  Explicit zero and other non-NULL loose comparisons remain. Update overridden
  action Radio views; see [fallback, validation and submission limits](tests/integration/ACTION_NULLABLE_RADIO.md).
- **Ordinary MultipleSelect NULL members ([PR #59](https://github.com/momijiina/laravel-admin-next/pull/59)):**
  only NULL array members are excluded from option matching, preventing a blank
  hidden marker normalized by middleware from selecting zero after validation
  failure. Zero choices, other non-NULL loose comparisons, defaults and old-input
  precedence remain. The hidden clearing marker and server preparation are
  unchanged. Update overridden ordinary views; action MultipleSelect, Checkbox,
  relationships and nested HasMany identity are outside this fix. See
  [validation-redirect and SQLite retry coverage](tests/integration/MULTIPLE_SELECT_NULL_OLD_INPUT.md).
- **Number integer editing ([PR #61](https://github.com/momijiina/laravel-admin-next/pull/61)):**
  whole-number values and bounds use exact decimal-string comparisons and unit
  +/- steps, avoiding lexical bounds and rounding above JavaScript's safe-integer
  range. Refresh the published Number asset and cached/minified copies; reconcile
  customized assets before republishing. Existing noninteger parsing remains.
  This does not recover already-rounded values or expand PHP/SQL
  integer ranges; keep server rules. See [integer and asset cautions](tests/integration/NUMBER_INTEGERS.md).
- **DateMultiple options ([PR #62](https://github.com/momijiina/laravel-admin-next/pull/62)):**
  native JSON-data options now reach flatpickr. `format()` uses flatpickr tokens;
  explicit `dateFormat` overrides it. The default locale remains `zh`, while
  multiple mode and the Clear plugin remain field-managed. Previously ignored
  formats, conjunctions and restrictions now apply: review stored strings before
  upgrading, as no data migration or server validation is added. Function-valued
  options remain unsupported. No asset republish is needed solely for this fix;
  see [format, locale and JSON-only boundaries](tests/integration/DATE_MULTIPLE_OPTIONS.md).
- **Number readonly/disabled UI ([PR #63](https://github.com/momijiina/laravel-admin-next/pull/63)):**
  +/- buttons, key events and focus/blur normalization no longer change locked
  inputs. Live properties and inherited disabled fieldsets are honored. Readonly
  values remain submitted; native FormData omits disabled values, but shipped
  jQuery 2.1.4 `serialize()` does not omit inputs disabled only by a fieldset.
  Refresh the published Number asset. These are UI guards, not server protection;
  see [state and serialization limits](tests/integration/NUMBER_FIELD_STATES.md).
- <a id="callback-bearing-widget-options"></a>**Widget callback mapping ([PR #64](https://github.com/momijiina/laravel-admin-next/pull/64)):**
  the existing Text/Inputmask and File option helper preserves distinct nested
  callbacks and literal marker-like data, with native JSON types for NULL/scalars
  and object leaves. Generated markers are opaque; remove assumptions about
  `%key%` names. The exact `function(` string convention and historical encoding
  failure policy remain. DateMultiple stays plain JSON. No asset/view refresh is
  required; see [caller and serialization boundaries](tests/integration/WIDGET_OPTION_SERIALIZATION.md).
- <a id="currency-configured-radix-preparation"></a>**Currency preparation ([PR #65](https://github.com/momijiina/laravel-admin-next/pull/65)):**
  a declared nonempty, non-dot string radix is normalized before the existing
  float cast, preserving fractions in the shipped widget's unmasked submissions.
  Validation still precedes preparation: Laravel's `numeric` rule rejects comma-radix
  strings, and accepted raw-input hooks still see them. Float precision, blank
  preparation as `0.0` and default dot behavior remain; this is not a generic
  localized/masked parser or a repair of already-truncated storage. No asset
  republish is needed; review [round-trip and subclass cautions](tests/integration/CURRENCY_RADIX.md).

Disabled-collection support and changes to Embeds replacement semantics remain
separate design work, not shipped fixes. Readonly does not imply either; see the
[collection cautions](tests/integration/LIST_FIELD.md#readonly-collections) and
[Embeds replacement boundary](tests/integration/EMBEDDED_OBJECT_ORIGINALS.md#boundary).

### Hosted evidence for PRs #61–#65

Each recorded PR revision passed **61 jobs across 13 workflows**, including
22 full integration/DomCrawler lanes with the per-lane totals below and eight
BrowserKit lanes of **125 tests** each (assertions vary with random fixtures).
The head/base and synthetic checkout identify the exact pre-merge code tested.

| PR checks | PR head | Base used by CI | Synthetic checkout | Tests / assertions / skips per full lane |
| --- | --- | --- | --- | --- |
| [#61](https://github.com/momijiina/laravel-admin-next/pull/61/checks) | `160918e` | `d8d94a4` | `dd32cfe` | 297 / 239,637 / 2 |
| [#62](https://github.com/momijiina/laravel-admin-next/pull/62/checks) | `2d71410` | `c1b04c3` | `f5867c8` | 303 / 240,693 / 2 |
| [#63](https://github.com/momijiina/laravel-admin-next/pull/63/checks) | `96731bc` | `215dae8` | `1051ae4` | 313 / 254,107 / 2 |
| [#64](https://github.com/momijiina/laravel-admin-next/pull/64/checks) | `b408b5c` | `387da56` | `eb694cf` | 323 / 254,185 / 2 |
| [#65](https://github.com/momijiina/laravel-admin-next/pull/65/checks) | `3010473` | `9399571` | `6e2ec4e` | 332 / 256,033 / 2 |

These are PR-triggered hosted snapshots, not new runs against merged main
`a87140f` or this documentation update. The two full-suite skips are the
source-verified optional MySQL/MariaDB and PostgreSQL service guards; neither
those skips nor separate generator-service checks establish ordinary form
lifecycle coverage on those databases.

Number, DateMultiple and Currency regressions combine offline shipped-widget
execution with in-process HTTP/SQLite persistence. Callback-helper coverage
exercises Inputmask offline and captures File initializer options; it does not
execute the File plugin, transfer files or verify callback persistence. These
checks do not establish live-browser layout/keyboard, PJAX, transport/cookies/CSRF,
custom-widget or universal downstream-application compatibility. Each linked guide
states its own narrower boundary; older evidence below remains unchanged.

### Hosted evidence for PRs #55–#59

Each recorded PR revision below passed **61 jobs across 13 workflows**. Each
had 22 full integration/DomCrawler lanes with the per-lane totals shown, plus
8 BrowserKit lanes of **125 tests** each (assertions vary with random fixtures).
The checks links identify the PRs; the head/base and synthetic checkout identify
exactly which code those results cover.

| PR checks | PR head | Base used by CI | Synthetic checkout | Tests / assertions / skips per full lane |
| --- | --- | --- | --- | --- |
| [#55](https://github.com/momijiina/laravel-admin-next/pull/55/checks) | `a989250` | `4bfc930` | `1b15261` | 279 / 235,069 / 2 |
| [#56](https://github.com/momijiina/laravel-admin-next/pull/56/checks) | `4ecb768` | `4bfc930` | `e7de312` | 266 / 235,164 / 2 |
| [#57](https://github.com/momijiina/laravel-admin-next/pull/57/checks) | `a43b2d4` | `4e809a8` | `c3f2cbf` | 281 / 235,564 / 2 |
| [#58](https://github.com/momijiina/laravel-admin-next/pull/58/checks) | `150295c` | `7d5f207` | `a62d725` | 282 / 235,746 / 2 |
| [#59](https://github.com/momijiina/laravel-admin-next/pull/59/checks) | `39b6bef` | `7d5f207` | `b4be9e0` | 284 / 235,785 / 2 |

These are pre-merge hosted snapshots, not CI for merged main `0895654` or this
documentation update. In particular, #56's snapshot does not include #55, and #58/#59
were tested separately against the same base: neither hosted total includes
both fixes. Earlier local combined checks are also separate evidence, not a
hosted-main result. The two full-suite skips correspond to optional external
MySQL/MariaDB and PostgreSQL service checks; they do not establish ordinary
lifecycle coverage on those databases.

The new action regressions run emitted modal scripts in offline jsdom and inspect
native FormData before network I/O, including hide/reopen/resubmission. They do
not exercise action HTTP dispatch, middleware, server validation or persistence.
The ordinary textarea and MultipleSelect regressions additionally exercise
HTTP/SQLite paths, with different middleware policies documented in their guides.
None establishes live-browser E2E, transport encoding, cookies/CSRF, arbitrary
custom views or universal downstream-application compatibility.

### Hosted evidence for PR #53

All **61 jobs across 13 workflows** passed for the final PR #53 head,
[`c024061`](https://github.com/momijiina/laravel-admin-next/commit/c0240610adebba05b12ef9025514a6256be34345);
see the [PR checks](https://github.com/momijiina/laravel-admin-next/pull/53/checks).
The hosted checkout was synthetic merge `a701103` of that head into `74d5749`.
The 22 full integration/DomCrawler lanes each completed **265 tests / 234,866
assertions / 2 optional external-database service skips**. The eight BrowserKit
lanes each completed **125 tests**; assertion counts vary with random fixtures.
The range-initialization regression is included in the full suite and exercises
39 offline widget fixtures; these results do not establish real-browser E2E.
These are hosted results for that exact pre-merge revision, not local reruns or
CI results for merge commit `f51972f` or this documentation update. The database
skips do not establish ordinary lifecycle coverage on external database services.

The earlier PR #42 and PR #25 evidence below is retained for its original
revisions and must not be read as the current test totals.

### Hosted evidence for PR #42

All **61 jobs across 13 workflows** passed for the final PR #42 head,
[`aedae96`](https://github.com/momijiina/laravel-admin-next/commit/aedae96f263e6652d816589d20da4c4e5768f235);
see the [PR checks](https://github.com/momijiina/laravel-admin-next/pull/42/checks).
The CI checkout was synthetic merge `4b2a838`; its tree matched that head.
The 22 integration/DomCrawler lanes each completed **157 tests / 189,979
assertions / 2 optional external-database service skips**. The eight BrowserKit
lanes each completed **125 tests**, with **1,227–1,236 assertions**.
These are hosted results for that exact pre-merge head, not local results or a
new run against merge commit `c09e6f8` or this documentation update. Optional
service skips do not establish ordinary lifecycle coverage on those databases.

The newer collection/HasMany checks combine offline jsdom with targeted
Testbench HTTP/SQLite checks; they are not full browser E2E, live PJAX transport,
layout, arbitrary widget or universal downstream-application certification.
See the [integration setup](tests/integration/README.md) for the required Node
and locked npm dependencies, and the [BrowserKit guide](tests/browserkit/README.md)
for separate in-process historical and image-output coverage. Local results,
configured CI matrices and hosted results for an exact commit are distinct
forms of evidence; none substitutes for the others.

<a id="image-processing-dependency-change-after-pr-25"></a>

## Image-processing dependency change (PR #35)

Transformations and thumbnails now require optional Intervention Image
`^3.11.9`; ordinary uploads without processing do not require it. The PHP floor
remains `^8.2`. This is a **breaking change** with ten supported legacy field
operations and a native v3 callback escape hatch, not a complete v2 shim.
See [IMAGE_MIGRATION.md](IMAGE_MIGRATION.md) for callback changes, separate
transform/thumbnail driver defaults, encoding, thumbnail behavior and
non-atomic storage failure boundaries.

Initial local v3 verification, before the GD-build test correction, on
PHP 8.5.11 / Intervention 3.11.9 / bundled GD: the focused
image suite passed 17 tests / 234 assertions / zero skips with EXIF enabled
and zero diagnostics. Complete BrowserKit runs completed 94 tests on Laravel
12.69.3 (1,231 assertions) and 13.34.0 (1,232 assertions), each without skips.
Separate integration runs completed 106 tests / 36,653 assertions / two expected
MySQL/PostgreSQL service skips per framework. All 330 production PHP files
passed lint; the optional-dependency check, Composer platform checks and audits
passed. These local results are separate from PR #25's hosted evidence below;
see the [detailed boundaries](IMAGE_MIGRATION.md#focused-verification).

The initial eight hosted GD lanes exposed a build-dependent 45-degree rotation
oracle failure: bundled GD produces 13×14, external libgd 2.3.3 produces 15×15
for the 12×8 fixture. Independent v2 comparison also found missing hidden-RGB
normalization in the v3 GD decode path. The adapter now uses v3's public clone
(native canvas copy) to preserve legacy transparent-RGB normalization; Imagick
is unchanged. The final test runs 31 independent cases against two actual v2
manifests, each containing 49 fixed PNG outputs. Exact GD-version/native-canary
fingerprints select the test oracle; unknown builds fail the test provider,
not production uploads. These checks do
not promise identical dimensions or pixels across different GD builds.
See the [correction and oracle scope](IMAGE_MIGRATION.md#gd-build-specific-rotation-and-the-corrected-oracle).

Corrected PHP 8.5.11 / Intervention 3.11.9 / EXIF-enabled focused runs passed
48 tests / 236 assertions on both characterized builds. Complete BrowserKit runs
passed 125 tests each: bundled GD with Laravel 12.69.3 / 1,236 assertions and
13.34.0 / 1,232 assertions; external libgd 2.3.3 with Laravel 12.69.3 / 1,228
assertions and 13.34.0 / 1,234 assertions. All had zero skips and diagnostics;
historical random fixture counts vary. The count increase reflects independent
oracle cases and a direct normalization regression. All four integration runs
completed 106 tests / 36,653 assertions / two expected database-service skips;
the optional-dependency boundary and 330-file lint passed on both builds.
Independent comparison found 53/53 decoded outputs exact against v2 on each
backend. These local results are separate from hosted CI and other GD builds.

Imagick and WebP/AVIF codecs remain unverified; see the migration guide for
focused EXIF/animation test scope and remaining parity limits. The
PR #25 snapshot below predates this migration, including its Image v2
BrowserKit dependency; its test counts, source-file count and green hosted jobs
must not be presented as validation of the changed v3 source. Historical
sections intentionally retain the dependencies and results of their revisions.

<a id="current-verified-coverage-2026-10-03-after-pr-25"></a>

## Historical verified coverage (2026-10-03, PR #25)

This snapshot records checks for the code merged in
[PR #25](https://github.com/momijiina/laravel-admin-next/pull/25), head
[`5c161f51`](https://github.com/momijiina/laravel-admin-next/commit/5c161f51c5d144c4045099877737660fd1e8a0c1),
merged as `d2ee8f4`. Historical evidence below and in individual regression notes
records the suite sizes at those earlier revisions; it is not the current total.

- **Declared requirements:** PHP `^8.2`, Laravel `>=5.5`, Doctrine DBAL
  `^2.13.9 || ^3.10.6`. These declarations do not certify every admitted
  combination. Full modern Laravel consumers resolve DBAL 3; Symfony
  HttpFoundation conflicts prevent normal full-Laravel 12/13 resolution with
  DBAL 2. Independently resolved Illuminate components exercise DBAL 2 instead.
- **Source checks:** the strict source-lint runner now covers **328 production
  PHP files** without diagnostics on PHP 8.4.25 and 8.5.11, with all 37
  formerly implicit nullable parameters explicit.
- **Local integration:** PHP 8.4.25, Laravel 12.69.3 / Testbench 10.12.0 /
  PHPUnit 11.5.56 and Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37,
  DBAL 3.10.6: **56 tests, 35,302 assertions, 2 service skips** per framework.
  PHP 8.5.11 was checked locally for the focused generator suite only at this
  revision: **10 tests, 254 assertions, 2 service skips** per framework. Earlier
  PHP 8.5 full-suite results describe smaller, earlier suites. These passes
  retain existing invalid-input deprecations described in the
  [controller regression](tests/integration/HANDLE_CONTROLLER_REQUEST.md).
- **Historical BrowserKit:** **77 tests** comprise 73 historical methods and
  four harness-isolation regressions. Local PHP 8.4.25 runs pass on Laravel 12/13
  in default and randomized order. These are in-process SQLite HTTP tests with
  real GD image processing, not JavaScript browser tests or a deprecation-free
  certification. See the [runner and diagnostic limits](tests/browserkit/README.md).
- **Hosted evidence:** all **60 jobs across 13 workflows** passed for the exact
  PR #25 head above, including integration and BrowserKit on Laravel 12 with
  PHP 8.2–8.5 and Laravel 13 with PHP 8.3–8.5. See
  [the PR checks](https://github.com/momijiina/laravel-admin-next/pull/25/checks).
  These results are separate from the local results and from future CI runs.

### Database-service evidence and remaining limits

The [model-generation run](https://github.com/momijiina/laravel-admin-next/actions/runs/37093063540)
passed all ten jobs at that exact head:

- Six full-framework DBAL **3.10.6** cells: Laravel 12 / PHP 8.2 and
  Laravel 13 / PHP 8.3, each against **MySQL 8.4.11, MariaDB 10.11.19 and
  PostgreSQL 16.15**. These exercise production schema metadata/output parity
  against an independent same-version DBAL oracle and actual model-backed
  Artisan generation, including qualified tables and PostgreSQL search paths.
- Four DBAL **2.13.9** component cells: Illuminate Database/Events 12/13 on
  PHP 8.4, each against MySQL/MariaDB. They check real metadata/output parity,
  but do **not** boot a full modern Laravel application or run Artisan.
  PostgreSQL/DBAL 2 is not covered by this matrix.

Both MariaDB matrices exercise Laravel's `mysql` and `mariadb` connection
classes. PDO transaction, reconnect, connection-state and persistent-connection
lifecycle regressions use **SQLite only**. DBAL 3 supports persistent PDO in
those tests; DBAL 2 rejects it before mutating connection state. These lifecycle
results must not be generalized to every service driver. SQL Server has
structural checks only and remains unverified against a live server.

See the [integration harness](tests/integration/README.md) and
[generator details](tests/integration/RESOURCE_GENERATOR.md) for reproducible
commands, dependency-resolution boundaries and test scope. Full JavaScript
browser flows, every PDO option/third-party driver, universal downstream support
and specific downstream application releases remain unverified. Normal Composer
security and platform checks remain enabled; DBAL 2's abandoned `doctrine/cache`
dependency is reported. Prefer DBAL 3 for maintained dependencies and persistent PDO.

## Historical migration and regression evidence

The following dated sections preserve what was verified when each patch was
prepared. For the latest maintenance scope and guide links, use the current summary above.
The PR #25 snapshot records its own exact hosted outcomes, not current totals.

## PHP baseline migration (2026-10-02)

The package now requires **PHP `^8.2` (8.2 through 8.x)**. PHP 7.x, 8.0 and
8.1 are no longer supported; an unverified PHP 9 major is not accepted. PHP 8.3+
is recommended for deployments. PHP 8.2 remains security-supported only through
2026-12-31; PHP 8.3 through 2027-12-31. Recheck the
[upstream support table](https://www.php.net/supported-versions.php) before release.

All 37 previously implicit nullable parameters in 24 source files now declare
`?Type` explicitly. Parameter names, default values, accepted types, visibility,
and behavior are preserved. This is an intentional PHP minimum-version change,
not a Laravel dependency upgrade: the broad `>=5.5` Laravel constraint remains
for downstream resolution, and does not promise support for EOL frameworks.

`php tests/compatibility/php_source_lint.php` lints all 326 production PHP files
with `E_ALL` and rejects compile-time diagnostics as well as syntax errors.
On PHP 8.4.25 it passes without diagnostics; the pre-migration source fails with
exactly 37 implicit-nullability notices in 24 files. All standalone regressions
also pass locally. Both real Laravel 12.69.3 and 13.34.0 suites pass 14 tests /
1,411 assertions each on PHP 8.4.25 without diagnostics, using the existing
isolated dependency sets with the migration source. Reflection metadata for all
37 parameters matches the old source (types, nullability, defaults, optionality,
visibility and static flags). CI requests standalone/source checks on PHP 8.2, 8.3 and 8.4,
and real integration on Laravel 12/PHP 8.2–8.4 and Laravel 13/PHP 8.3–8.4.
PHP 8.2/8.3 CI results and PHP 8.5 runtime verification are not claimed locally.

Obsolete Travis PHP 7.2–8.0 configuration has been retired. The historical root
development dependencies remain unchanged. The separate [BrowserKit runner](tests/browserkit/README.md)
restores all 73 historical methods on supported frameworks with SQLite and real
GD image processing; the [integration harness](tests/integration/README.md)
continues its focused modern-framework checks. These targeted passes are not complete framework certification.

## Historical baseline evidence (2026-10-01)

Baseline: `819837af94e1a4170a13ac7dc85dbe40bd6e8d9b`.
See the [full audit](docs/compatibility-audit-2026-10-01.md) for evidence and limits.

| Area | Declared or historical state | Verified status |
| --- | --- | --- |
| PHP | `>=7.0.0` in Composer | PHP 8.4.25 parsed all 359 non-Blade PHP files; 37 implicit-nullability notices in 24 files remain |
| Laravel | `>=5.5` in Composer | No complete Laravel/PHP runtime combination has passed the existing suite in this audit |
| Laravel 12.69.3 / 13.34.0 + PHP 8.4.25 + SQLite | Temporary consumer with production dependencies | Install, login/dashboard/menu/user-list HTTP smoke and cache commands passed; both generator modes failed; full compatibility is not established |
| Laravel 11–13 scaffolding | Allowed by the runtime constraint | Uses Doctrine connection methods removed in Laravel 11 |
| Test dependencies | BrowserKit `^6.0` | Its Illuminate constraints stop at Laravel 10; cannot resolve an 11–13 test matrix unchanged |
| Test bootstrap | Legacy Eloquent factories | Requires migration for modern Laravel |
| Historical CI | Travis PHP 7.2–8.0 | Configuration exists; no successful current run is established |
| Other Laravel applications | Compatibility and integration goal | Exact application/version combinations not yet specified or tested |

A clean syntax check or a focused deprecation regression is not a Laravel support
claim. Full support requires installation, bootstrap, database-backed tests,
browser smoke checks, and supported dependency resolution for that combination.

## Focused follow-up: legacy column cast (2026-10-02)

The existing public, untyped `Grid\Column::$cast` property is now declared to
avoid PHP 8.2+ dynamic-property deprecations. On PHP 8.4.25, the standalone
`php tests/compatibility/grid_column_cast.php` regression failed on the preceding
source with two dynamic-property notices and passes after the declaration.
It checks fluent calls, public reads/writes, null resets, untyped values,
instance isolation, and unchanged `sortable($cast)` arguments using small
framework test doubles. CI runs this focused check on PHP 8.4.

The deprecated `cast()` API still only stores its value; callers should continue
using `sortable($cast)` to configure sorting. This patch does not change sorting
behavior, PHP requirements, implicit-nullability warnings, or the blocked legacy
Laravel suite. It does not establish full framework, database, or rendering
compatibility.

## Verification matrix policy

The modern combinations below now have the targeted coverage recorded above,
not complete application or downstream certification:

- Laravel 12 with PHP 8.2, 8.3, 8.4, and 8.5
- Laravel 13 with PHP 8.3, 8.4, and 8.5
- Explicit legacy combinations required by downstream applications, tracked
  separately with their upstream end-of-life status

As of this audit date, Laravel 11's security support ended on 2026-03-12;
Laravel 12 receives security fixes until 2027-02-24, and Laravel 13 until
2028-03-17. PHP 8.2–8.5 remain under upstream security support; PHP 8.2 reaches
its scheduled end on 2026-12-31. Recheck these dates before each release.

Sources: [Laravel support policy](https://laravel.com/framework/docs/releases),
[PHP supported versions](https://www.php.net/supported-versions.php).

## Changes and releases

- Keep the existing `Encore\Admin` namespace and package identity unchanged in
  the initial maintenance patch; evaluate publication decisions separately
- Do not widen constraints merely to make Composer accept an untested version
- PHP minimum-version changes must be intentional and documented; the current
  minimum is PHP 8.2, with PHP 8.3+ recommended
- Record the exact framework, PHP, dependency set, commands, and result for each
  newly verified combination
- Distinguish passed, failed, blocked, and not-run checks in PRs and release notes

## Historical isolated integration coverage (2026-10-02)

The [real Laravel integration harness](tests/integration/README.md) boots
Laravel with Testbench and exercises the package provider, auth HTTP lifecycle,
SQLite migrations/seeding and persisted operation-log redaction. Its ten tests
and 54 assertions pass locally on PHP 8.4.25 with Laravel 12.69.3 and 13.34.0.
A negative control using the old middleware fails the three redaction tests.

This consumer has its own development dependencies; the root legacy development
requirements remain unchanged. The package PHP requirement is now `^8.2`. The historical 73-test suite now has a separate [BrowserKit runner](tests/browserkit/README.md);
these focused passes do not establish full Laravel support.
See the harness README for exact dependency versions and untested areas.
