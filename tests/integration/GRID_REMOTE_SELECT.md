# Remote Grid filter selections

`GridRemoteSelectTest.php` covers URL-backed Grid filter `select('/options')`
and `multipleSelect('/options')` presenters. Their initial remote response is
used to restore the IDs from an active query.

## Contract and correction

Integer `0` and string `'0'` are option IDs. They must survive the remote
initialization, just like nonzero IDs. The selected values must reach jQuery's
`.val()` as a JSON list, including when query-array keys are sparse or removing
blank entries leaves a gap.

Previously, unqualified `array_filter()` removed zero and kept the remaining
keys. A zero selection could disappear, while a sparse list serialized as an
object instead of an array. Submitting the unchanged filter could then broaden
or narrow the results because selected IDs were lost.

The presenter now retains exact integer/string zero, preserves the existing
truthiness rule for other values, and reindexes the filtered list before JSON
encoding. Null, empty string, false, float zero and empty-array entries retain
their previous empty handling. Remote option order and the filter's SQL
operators are unchanged.

## Regression coverage

- Real Laravel HTTP/SQLite queries for Select/Equal and MultipleSelect/In
- Plain and named grids, using both shipped container and modal filter views
- Numeric and string zero, mixed zero/nonzero IDs, reversed query order,
  leading-zero string IDs, blank markers and sparse query-array keys
- Shipped Select2 with shipped jQuery 2.1.4 and modern jQuery 3.7.1 in offline DOM
- Actual remote initialization, native FormData, repeated unchanged GETs,
  clear/reset, choosing another option and closing/reopening the dropdown
- Exact JSON-array serialization and legacy empty-value controls

The remote response is obtained from a real test HTTP route. The DOM helper
replaces only AJAX transport; it does not contact an external service or replace
Select2. This checks the emitted controls and replayed requests, not live-browser
layout, external network timing or downstream application behavior.

Run through the isolated harness described in [README.md](README.md):

```sh
vendor/bin/phpunit -c phpunit.xml GridRemoteSelectTest.php
```

## Upgrade boundaries

This is limited to the Grid filter presenter's eager URL-backed remote-options
path. Local arrays/callbacks, `model()`, search-on-demand `ajax()`, cascading
loads, Form fields, filter defaults, SQL semantics and dependency requirements
are unchanged. Applications that override this presenter should apply the same
selection-list normalization where needed. No data migration is required.

日本語: URL 指定の Grid 絞り込み Select/MultipleSelect で、ID `0` と疎な配列を
再表示すると選択が消え、未変更の再送信で検索結果が変わる問題を修正します。
整数・文字列のゼロを保持し、JavaScript に渡す選択値を配列にそろえます。
既存の空値処理、SQL 条件、Form の選択フィールド、既定値や依存関係は変更しません。
実際の HTTP/SQLite と同梱 Select2 のオフライン DOM で検証し、外部 API や
実ブラウザー、個別 Laravel アプリ全体の互換性を保証するものではありません。
