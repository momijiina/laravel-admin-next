# Real Laravel integration tests

This is an isolated Composer **consumer** of the repository via a local path
repository. It uses [Orchestra Testbench](https://github.com/orchestral/testbench)
to boot real Laravel and SQLite without changing the root legacy development
dependencies. Both the shipped package and this harness require PHP `^8.2`;
Laravel 13 / Testbench 11 requires PHP 8.3+.

Run from this directory, using a PHP CLI with the required extensions and Node.js
24.15+ (24.x, for shipped Moment and collection DOM regressions):

```sh
npm ci --ignore-scripts --prefix javascript

# Laravel 12 / Testbench 10, PHP 8.2+
composer update --with 'orchestra/testbench:^10.0' --with 'phpunit/phpunit:^11.5'
composer check-platform-reqs
composer test

# Laravel 13 / Testbench 11, PHP 8.3+
composer update --with 'orchestra/testbench:^11.0' --with 'phpunit/phpunit:^12.0'
composer check-platform-reqs
composer test
```

Composer's normal security-advisory and platform checks remain enabled. Locks
and vendor files are local only so CI resolves each selected framework family.
Testbench's [compatibility table](https://github.com/orchestral/testbench-core)
explains the framework/version mapping.

## CI Node compile cache / CI の Node コンパイルキャッシュ

The ordinary full-suite test steps in `laravel-integration.yml` and
`domcrawler-compatibility.yml` create a fresh private directory with `mktemp`
under `RUNNER_TEMP`, then export `NODE_COMPILE_CACHE` for `composer test` and
its Node children. Each matrix job starts cold; nothing is restored, shared
between jobs, or saved with `actions/cache`. The runner cleans its temporary
directory at job boundaries. Tests, fresh helper processes, assertions, matrix
entries, dependency/security checks and timeouts are unchanged.

These steps do not collect V8 coverage. If coverage is introduced, set
`NODE_DISABLE_COMPILE_CACHE=1` for the coverage run: Node warns that cached
functions can produce less precise V8 coverage. See the
[Node 24 module compile-cache documentation](https://nodejs.org/download/release/v24.15.0/docs/api/module.html#module-compile-cache).
The cache can also be disabled with that variable when diagnosing failures.

A local comparison on 2026-10-06, before this workflow/documentation-only
change, used Node 24.19.0 and the unchanged source tree of
`c8b6d5226e624dee14be7ba09cd33956275ca531`:

| Full integration lane | Uncached | Cold per-lane cache | Observed reduction |
| --- | ---: | ---: | ---: |
| PHP 8.2.34 / Laravel 12.69.3 | 1,565.852s | 1,381.725s | 11.76% |
| PHP 8.3.35 / Laravel 13.34.0 | 1,533.889s | 1,410.767s | 8.03% |

All four runs passed with 756 tests, 273,419 assertions and the same two
optional external-database skips. Each retained 158 PHP and 1,234 fresh Node
processes. Timing includes cold cache creation and writes, but excludes
application preparation and receipt summarization. This was one pair per
graph in one local session with opposing run order and concurrent lanes,
including provenance-check overhead. It establishes neither repeatability
nor hosted-CI savings, and does not validate every matrix entry or external
database. Measure hosted results separately before claiming a CI speedup.

日本語: 上記 2 ワークフローの通常の全件テストだけで、`RUNNER_TEMP` 内に
`mktemp` で空の専用ディレクトリを作り、`NODE_COMPILE_CACHE` を子プロセスへ
渡します。各マトリクスジョブは空のキャッシュから開始し、ジョブ間の共有・復元・
永続保存は行いません。テスト、アサーション、独立した補助プロセス、マトリクス、
依存関係・セキュリティ検査、タイムアウトは変更しません。V8 カバレッジを導入する
場合は、精度低下を避けるため、その実行で `NODE_DISABLE_COMPILE_CACHE=1` を
設定してください。障害調査時にも同じ変数で無効化できます。

表は変更前の同一ソースで測ったローカル結果です。4 回とも 756 テスト・273,419
アサーション、同じ任意 DB テスト 2 件のスキップで成功し、各回の PHP 158・Node
1,234 プロセスも維持しました。空のキャッシュ作成・書き込み時間を含み、アプリ準備・
検証記録の集計時間は除きます。各構成 1 組のみを同じセッションで順序を入れ替えて
並行実行した測定で、検証用のハッシュ照合負荷も含みます。再現性、全マトリクス・
外部 DB の検証、ホスト型 CI での短縮率を保証する結果ではありません。

## CI PHP 8.5 timeout headroom / CI の PHP 8.5 タイムアウト余裕

The two PHP 8.5 lifecycle jobs have a 40-minute whole-job limit. The other
five lifecycle jobs and all DomCrawler jobs retain their 30-minute limits.
This adds runtime headroom without changing test selection, assertions,
matrix entries, dependency checks or the test command.

On 2026-10-08, the PHP 8.5 / Laravel 13 [PR #103 job](https://github.com/momijiina/laravel-admin-next/actions/runs/37719254135/job/113122861692)
passed 1,023 tests and 282,419 assertions with two existing optional-database
skips in 26m40s. The [post-merge job](https://github.com/momijiina/laravel-admin-next/actions/runs/37725545480/job/113142751304)
used the identical repository tree and the same PHP, Laravel, PHPUnit and
Testbench-core versions, but reached only 854/1,023 tests before cancellation
at the 30-minute job budget. Its log contains no final suite result; it is
not a pass. The runner images and regions differed, so these observations
do not establish the underlying performance cause or a speedup.

Forty minutes provides bounded headroom over both observations. The maximum
additional allowance is ten minutes per PHP 8.5 job, twenty across the two
jobs; already-completing jobs are not made to run longer. A subsequent hosted
run must establish whether the extra headroom is sufficient.

日本語: PHP 8.5 の lifecycle 2 構成だけ全ジョブの上限を 40 分にし、他の
lifecycle 5 構成と DomCrawler は 30 分のままにします。テスト対象・検証内容・
構成・依存関係チェック・実行コマンドは変えません。同一ソースの PR 検証は
26 分 40 秒で完走しましたが、マージ後は 30 分の上限付近で 854/1,023 件まで
進んだところで中断し、完走結果はありません。実行環境の差による原因や高速化
を断定せず、余裕が十分かどうかは変更後の CI で確認します。

## Current evidence

See the [current maintenance summary](../../COMPATIBILITY.md#current-maintenance-summary)
for recent changes, upgrade cautions and scoped regression guides. The separate
[PR #25 snapshot](../../COMPATIBILITY.md#current-verified-coverage-2026-10-03-after-pr-25)
retains its exact local and hosted results.
The dated results below are historical snapshots, not the current suite totals.

## What this covers

- Remote Grid Select/MultipleSelect zero IDs and sparse query arrays through shipped
  Select2, native resubmission and HTTP/SQLite; [selection contract and limits](GRID_REMOTE_SELECT.md)
  - 日本語: リモート選択肢のゼロ・疎な配列の保持、再送信・検索結果と検証範囲

- Grid Between named lower/upper bounds independent of query-key order, with
  unchanged native form resubmission and HTTP/SQLite results;
  [range contract and limits](GRID_BETWEEN_BOUND_ORDER.md)
  - 日本語: 範囲のキー順に依存しない条件・再送信・SQL と既存範囲処理の境界
- Scalar zero LIKE/starts-with/ends-with Grid searches, native GET resubmission
  and HTTP/SQLite results; [zero-term contract and limits](GRID_LIKE_ZERO.md)
  - 日本語: ゼロの検索条件・再送信・SQL と既存の空値処理の境界
- Listbox remote literal IDs and labels, shipped widget moves/reset and HTTP/SQLite saves;
  [text contract, custom-initializer cautions and limits](LISTBOX_REMOTE_OPTIONS.md)
  - 日本語: リモート選択肢の ID・ラベル保持、保存・リセットと独自処理の注意事項
- Grid Group selected-operator retention through both shipped presenters, native
  FormData and repeated HTTP/SQLite queries;
  [selection contract, upgrade cautions and limits](GRID_GROUP_OPERATOR.md)
  - 日本語: グループ条件の演算子保持、未変更の再送信、既存の既定値と検証範囲
- Grid inline Checkbox integer IDs, cancel/reopen and replayed HTTP/SQLite saves;
  [selection contract and upgrade cautions](GRID_INLINE_CHECKBOX.md)
- Real package service provider, published-default configuration, middleware
  aliases/groups, session guard, migrations and seed data
- Login view, guest redirect, valid/invalid login, authenticated request, logout
- Remember-input parity with the legacy getter across 1,334 real request shapes,
  including source precedence, null/array values, JSON and absent input; real
  remember cookies/tokens for accepted and rejected credentials
- Real Artisan blank-controller generation and rejection of model-less `--output`
- Generated model-backed controllers executing index/create/store/show/edit/update/delete
  through the real HTTP kernel; [CRUD regression scope](GENERATED_CONTROLLER_CRUD.md)
- DateMultiple native flatpickr options, actual calendar selections and HTTP/SQLite saves;
  [format precedence, JSON-only scope and upgrade cautions](DATE_MULTIPLE_OPTIONS.md)
- Native mutable/immutable Date/Datetime edit/save round trips with shipped Moment;
  [date-cast presentation scope](DATE_CAST_PRESENTATION.md) and boundaries
- Native mutable/immutable DateRange/DatetimeRange endpoint edit/save round trips;
  [range-cast presentation scope](DATE_RANGE_CAST_PRESENTATION.md) and boundaries
- Initial DateRange/DatetimeRange/TimeRange bounds with the shipped picker in offline DOM;
  [initialization contract and exclusions](DATE_RANGE_INITIALIZATION.md)
- Nullable temporal generated create/edit/save NULL preservation;
- Canonical temporal database literal defaults in generated source and HTTP persistence;
  [generator default scope](NULLABLE_TEMPORAL_DEFAULTS.md) and existing-controller caveats
- Model-backed Artisan generation and independent DBAL metadata/output parity;
  [generator regressions](RESOURCE_GENERATOR.md) cover SQLite and opt-in services
- Action modal HTTP/transport failure and confirmation-cancellation retries, pending
  request locks, independent forms and HTTP/SQLite persistence;
  [retry behavior and upgrade cautions](ACTION_MODAL_RETRY.md)
- Action modal Select NULL/zero choices through actual emitted native FormData;
  [selection contract and upgrade cautions](ACTION_SELECT_NULL.md)
- Action modal textarea leading blank lines through actual emitted native FormData;
  [parser behavior and custom-view cautions](ACTION_TEXTAREA_NEWLINES.md)
- Real request-input parity for grid, row/batch actions and controller dispatch;
  DomCrawler compatibility and multiple-select null handling
- Modern native Eloquent `Attribute` dispatch for explicit Grid columns and
  Grid/Show shorthand through HTTP/SQLite, with legacy getter, macro and single
  relation controls; [attribute scope, serialization and fallback limits](MODERN_ATTRIBUTE_DISPATCH.md)
  - 日本語: Grid/Show の標準 `Attribute` 判定、既存動作との互換性、
    appends 設定と旧フレームワークに関する検証の限界
- Named grid pagination with configured sizes, independent links and explicit
  Eloquent arguments; [pagination regression scope](GRID_PAGINATION.md)
- Configured Grid quick-search keys through shipped header tools, native FormData,
  HTTP/SQLite searches, value redisplay and action-query removal;
  [static-key behavior, custom-renderer cautions and limits](GRID_QUICK_SEARCH_KEYS.md)
  - 日本語: クイック検索の設定キー、単独ツール・サブクラス、既存検索仕様と検証範囲
- Grid legacy editable Select callback sources through HTML/JSON parsing, shipped
  X-editable controls, cancel/reopen and HTTP/SQLite saves;
  [source escaping, upgrade cautions and offline limits](GRID_EDITABLE_SELECT_SOURCE.md)
  - 日本語: 行別選択肢の引用符・実体参照の保持、再編集・保存と検証範囲
- Grid copyable literal-text transport through native DOM selection, with quotes,
  entities, JSON-like strings, multiline text and unchanged display:
  [copy contract, upgrade cautions and limits](GRID_COPYABLE_TEXT.md)
- Named Grid filter prefixes removed once while preserving repeated/interior column
  names, distinct inputs and array values through native FormData and HTTP/SQLite;
  [prefix contract, custom-override cautions and limits](GRID_FILTER_PREFIX.md)
  - 日本語: 名前付き Grid の接頭辞除去、列名・配列の保持、独自実装と検証範囲
- Named Grid Between form namespaces through both shipped views, native FormData,
  HTTP/SQLite bounds, redisplay and independent resets;
  [field-name changes, custom-selector cautions and limits](GRID_BETWEEN_NAMES.md)
  - 日本語: 名前付き Grid の範囲入力名、既存のクエリ形式、独自セレクターと検証範囲
- Nullable RadioButton/RadioCard choices through checked/active state, native FormData,
  real form create/edit and validation-retry persistence;
  [selection, omission and overridden-view cautions](STYLED_RADIO_NULL.md)
  - 日本語: NULL の誤選択防止、既定値・再表示、未送信時の保存仕様と検証範囲
- Ordinary Grid `gt()` / `lt()` inclusive labels, SQL bindings and HTTP/SQLite
  boundaries, including zero, negative values, blanks and reset;
  [query compatibility and overridden-view cautions](GRID_INEQUALITY_FILTERS.md)
- Explicit per-grid sort keys configured before sortable columns, with applied
  named filters and follow-up rendered links; [sort regression scope](GRID_EXPLICIT_SORT.md)
- Nullable Grid inline Select/Radio labels, mixed rows and dynamic options, shipped
  popover cancel/reopen and HTTP/SQLite saves; [NULL contract and upgrade cautions](GRID_INLINE_NULLABLE_CHOICES.md)
- Mixed Grid inline editor submit-handler ownership across types and resources;
  [binding scope, regression coverage and overridden-view cautions](GRID_INLINE_MIXED_EDITORS.md)
- Grid inline MultipleSelect integer/zero IDs, shipped popover cancellation and reopening,
  actual AJAX payloads and HTTP/SQLite saves; [selection contract and override cautions](GRID_INLINE_MULTIPLE_SELECT.md)
- Grid QuickCreate validation retries, cancel/reopen, scoped submit-button resets,
  and corrected HTTP/SQLite saves; [response behavior and coverage limits](GRID_QUICK_CREATE.md)
- Ordinary Grid inline uploads isolated across fields, rows and repeated instances,
  with idempotent handlers, native single/multiple FileLists and HTTP/SQLite
  replacement/append file retention;
  [generated-target compatibility and coverage limits](GRID_INLINE_UPLOAD.md)
- Nullable array-cast Grid carousel images, actual HTTP/SQLite page rendering and
  unchanged array/URL controls; [empty-cell contract and limits](GRID_CAROUSEL.md)
- Ordinary Grid nested-table missing cells, configured column order, exact view
  data and unchanged HTTP/SQLite storage; [alignment and custom-view cautions](GRID_TABLE.md)
- Checkbox/MultipleSelect conditional collection initialization and selection/clear visibility;
  [operator semantics, scalar-hook compatibility and limits](COLLECTION_CONDITIONAL_FIELDS.md)
- Ordinary MultipleSelect null markers after failed validation, shipped Select2 clear/remove,
  old-input redisplay and corrected SQLite retries; [regression scope](MULTIPLE_SELECT_NULL_OLD_INPUT.md)
- URL-options Select/MultipleSelect selection and clearing across validation retries,
  shipped Select2 and corrected HTTP/SQLite saves; [retry precedence and compatibility limits](REMOTE_SELECT_OLD_INPUT.md)
- Independent `Select::loads()` initializers in both registration orders, custom
  response mappings, clear settings and repeated initialization, with shipped
  Select2 and native FormData HTTP/SQLite saves;
  [loader isolation and unchanged AJAX boundaries](SELECT_LOADS_ISOLATION.md)
- Ordinary nullable Select zero/NULL rendering and serialized HTTP persistence;
  [selection contract, defaults, and upgrade notes](NULLABLE_SELECT.md)
- Ordinary Switch localized labels, shipped native/plugin clicks and HTTP/SQLite saves;
  [literal strings, HTML labels and override cautions](SWITCH_LABELS.md)
- Ordinary double Slider saved/old-input integer pairs, shipped widget initialization and HTTP/SQLite round trips;
  [endpoint precedence, defaults and override cautions](SLIDER_RANGES.md)
- Ordinary Number exact integer keyup/blur/buttons, numeric bounds and HTTP/SQLite saves;
  [integer contract, asset refresh and storage cautions](NUMBER_INTEGERS.md)
- Currency configured-radix unmasking, actual create/update persistence and raw hooks;
  [validation, float-cast and custom-mask boundaries](CURRENCY_RADIX.md)
- Text/Mobile nested Inputmask callbacks, scalar options and File initializer configuration;
  [callback convention, JSON parity and caller boundaries](WIDGET_OPTION_SERIALIZATION.md)
- Ordinary Number readonly/disabled widget guards, live state and disabled fieldsets;
  [state contract, native submission and UI limitations](NUMBER_FIELD_STATES.md)
- Ordinary nullable Radio NULL/zero rendering, native omission, iCheck and HTTP saves;
  [defaults, validation and clearing boundaries](NULLABLE_RADIO.md)
- CheckboxButton/Card zero-valued checked/active states, shipped clicks and HTTP/SQLite retries;
  [styled selection contract and overridden-view cautions](STYLED_CHECKBOX_ZERO.md)
- Checkbox zero-valued choices in flat/grouped views, native DOM successful controls,
  JSON/CSV saves and validation redisplay; [selection contract](CHECKBOX_ZERO.md)
- Ordinary Textarea leading-LF preservation through native HTML parsing and HTTP saves;
  [textarea rendering contract and normalization boundaries](TEXTAREA_NEWLINES.md)
- Ordinary Tags hidden-input create/update/clear with exact storage parity;
  [Tags regressions](TAGS.md)
- Default NULL export-driver resolution under strict PHP 8.5 diagnostics, driver
  registry/cache compatibility, and actual unconfigured HTTP export scopes
- Headers for empty actual CSV exports and cross-chunk header-once controls;
  [CSV schema and compatibility notes](CSV_HEADERS.md)
- Explicit native-object grid display callbacks and actual streamed CSV round trips;
  [scalar/null result contract and boundaries](OBJECT_DISPLAY.md)
- Native object/array casts in configured embedded forms;
  [original-metadata regression and replacement boundary](EMBEDDED_OBJECT_ORIGINALS.md)
- Explicit ListField bounds, scoped input/error names, and ListField/KeyValue
  empty-marker submission; [behavior and upgrade cautions](LIST_FIELD.md)
- Readonly collection controls and unchanged submission;
  [UI behavior and cautions](LIST_FIELD.md#readonly-collections)
- Production-rendered List/KeyValue collection scripts with shipped and modern jQuery;
  [offline DOM scope, setup, and limits](COLLECTION_SCRIPT_SCOPING.md)
- Ready-wrapped HasMany table-parent reinitialization, unique child identities, and
  actual serialized HTTP/SQLite saves; [regression scope](HASMANY_TABLE_REINITIALIZATION.md)
- Ready-wrapped HasMany default/tab reinitialization, consumer handlers, safe child
  identities, real Bootstrap tab behavior and HTTP/SQLite saves;
  [regression scope](HASMANY_MODES_REINITIALIZATION.md)
- Main-file upload failure preservation and successful replacement
- Ordinary sortable MultipleFile saves, validated sort-and-append uploads, and
  raw hook/custom-validator input compatibility;
  [sorting contract and coverage limits](MULTIPLE_FILE_SORT.md)
- Actual HTTP-kernel middleware lifecycle, including consumer bootstrap execution
- SQLite-persisted operation logs, recursive redaction, preserved controller input,
  validation failures, custom redaction fields, logging disablement and exclusions

No package/framework classes are replaced with stubs. Small fixture routes return
JSON or validate input to observe the real middleware before/after a controller.
The modern-attribute regression also uses a small receiver double without
`hasAttributeMutator()` to exercise the guarded fallback. It does not replace
Eloquent in the HTTP tests or prove execution on a historical Laravel release.
日本語: この補助的な代役による検証は、旧 Laravel での実行確認ではありません。
Tests use a fresh application with disposable SQLite databases; the opt-in
generator fixtures additionally use dedicated database services. Testbench registers
the provider explicitly and Laravel's testing environment bypasses CSRF validation;
this is not package-discovery or browser-cookie/CSRF end-to-end coverage.

## Limits

The root PHPUnit configuration excludes this consumer directory, keeping its
classes and vendor tree out of legacy test discovery. This suite does not replace
or claim to pass the 73 legacy BrowserKit tests; use the separate
[historical BrowserKit runner](../browserkit/README.md) for that coverage.
Main-file upload failures and successful replacement are covered by the
[upload failure regressions](FILE_UPLOAD_FAILURE.md), including their non-atomic
filesystem/database limitations. Other upload behavior,
CRUD beyond the [generated scalar-field lifecycle](GENERATED_CONTROLLER_CRUD.md)
and browser end-to-end JavaScript remain outside this suite. The collection
regression executes real emitted scripts in jsdom; it does not cover browser
layout, live PJAX transport, or arbitrary third-party widgets. Non-SQLite
coverage is limited to the dedicated generator service matrix; the ordinary
lifecycle suite uses SQLite. The PHP baseline migration explicitly
declares all 37 previously implicit nullable parameters; these integration runs
now emit no nullable notices. The PHPUnit configuration keeps E_ALL enabled and
routes Laravel/Testbench deprecation logs to stderr. The intentional legacy-getter
reference call suppresses only its own Symfony deprecation; the controller
regression turns that same notice into a failure. These focused passes do not
certify the absence of all deprecations in untested paths or full framework support.

## Historical local verification (2026-10-02)

PHP 8.4.25, SQLite 3.53.4, DBAL 3.10.6 and DomCrawler 5.4.52:

- Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56: **14 tests, 1,411 assertions pass**
- Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37: **14 tests, 1,411 assertions pass**
- Normal Composer resolution and `check-platform-reqs` pass for both; neither
  lockfile reports known Composer security advisories at verification time
- Negative control on Laravel 13: loading the actual pre-redaction middleware
  from `b79f56d` causes the three persisted-redaction assertions to fail with
  the synthetic inputs visible in SQLite. Restoring the current middleware
  passes the original ten lifecycle tests. No production data is used.

The remember-input negative control loads the pre-fix `AuthController` from
`0a45463`; on Laravel 12 the regression fails immediately on the Symfony
`Request::get()` deprecation. The fixed controller passes on both frameworks.
The value matrix stops at the real guard's `Attempting` event to compare the exact
argument without authenticating; separate HTTP tests check cookies, tokens and
login outcomes with the real guard.

The workflow additionally requests PHP 8.2/8.3 with Laravel 12 and PHP 8.3 with Laravel 13.
Those PHP runtimes were not executed locally; their results must come from CI.

After the PHP baseline migration, both 14-test / 1,411-assertion suites pass again
on PHP 8.4.25 without diagnostics. The existing resolved dependency sets were
reused with the package PSR-4 source mapped to the migration worktree; dependency
resolution on PHP 8.2/8.3 remains a CI check. The standalone source regression
separately lints 326 production PHP files with no compile-time diagnostics.


### Historical PHP 8.5 verification (2026-10-02)

[PHP 8.5.11](https://www.php.net/downloads.php?source=Y) was built from the
official source archive and checked against its published SHA-256. Fresh normal
Composer resolutions on that runtime retained DBAL 3.10.6 and DomCrawler 5.4.52:

- Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56: **14 tests, 1,411 assertions pass**
- Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37: **14 tests, 1,411 assertions pass**
- Both actual-runtime `check-platform-reqs` checks pass, and Composer reports no
  known security advisories at verification time
- All eight standalone scripts pass, including strict lint of 326 production PHP
  files; the lifecycle runs emit no diagnostics with the existing E_ALL settings

The standalone column test no longer calls
[`ReflectionProperty::setAccessible()`](https://www.php.net/manual/en/reflectionproperty.setaccessible.php),
which has no effect since PHP 8.1 and is deprecated in 8.5. This fixture-only change
preserves the reflected sorter-value assertions under the package's PHP 8.2 floor.
No production code, warning handling or dependency constraints changed.

The source/standalone workflow now includes PHP 8.5, and the real lifecycle
workflow includes PHP 8.5 with both framework families. These are focused results;
the limitations of this focused suite above still apply; historical coverage is
tracked by the separate BrowserKit runner.

Action modal NULL/zero radio selection and emitted FormData are covered by
[the dedicated action Radio regression](ACTION_NULLABLE_RADIO.md).

- [Literal Tags separators](TAGS_SEPARATORS.md): escaped custom characters, shipped
  Select2 selection, native form serialization and HTTP/SQLite create/update/reopen.
