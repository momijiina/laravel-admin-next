# Column-header text searches for zero

Column-header `filter()` / `filter('equal')` and `filter('like')` now apply
scalar integer `0` and string `"0"` and show the active filter indicator.
Previously, the input displayed `0` while both the count and row queries
silently omitted the condition and the indicator remained inactive.

Only these two text modes gain scalar-zero handling. The `date`, `time` and
`datetime` header modes retain their existing zero/empty handling and query
methods. Missing input, null, blank strings, false, floating-point zero and
empty arrays retain their previous treatment. Nonempty arrays are not a new
supported text-input format. Existing wildcard semantics, form names, URLs,
query dispatch and reset behavior are unchanged. Range/checkbox headers and
the separate main Grid filter presenters are outside this change.

Review custom `InputFilter` subclasses/overrides and application code that uses
zero to omit a header search. No dependency/minimum-version change, schema or
data migration, asset build or published-view refresh is required. Verify each
consuming Laravel application separately; this is not a claim of compatibility
with every declared framework or database.

## Regression coverage

Run in the isolated [integration harness](README.md):

```sh
vendor/bin/phpunit --filter GridColumnInputZeroTest
```

The tests exercise the actual column-header registration, query application,
rendering and in-process HTTP/SQLite requests. They check exact rows and both
paginator count and row-query bindings for default equal, explicit equal and
LIKE. Native FormData in offline jsdom captures the shipped header form for
repeated unchanged submissions, clearing, reset and reselecting zero. Leading
zeros and ordinary nonzero text are controls, and database contents stay intact.

Direct binding checks cover integer/string zero, null, blank, false, float zero
and empty arrays across all five InputFilter modes; scalar rendering checks
require the active indicator to agree with query binding. Real temporal HTTP
queries cover ordinary date/time/datetime values and unchanged zero/blank input.
No live-browser layout, real-network/PJAX, temporal widget interaction,
non-SQLite database or arbitrary consuming-application coverage is claimed.

## 日本語

列ヘッダーの `filter()` / `filter('equal')` と `filter('like')` で、整数 `0`
と文字列 `"0"` を検索条件に適用し、有効な絞り込みの表示も一致させます。
従来は入力欄に `0` が表示されても検索条件が省略され、全件が返る場合がありました。

変更は上記 2 種類のテキスト検索だけです。`date`・`time`・`datetime` のゼロ・
空値と検索メソッド、未指定・null・空文字列・false・浮動小数点のゼロ・空配列の
扱いは維持します。配列をテキスト入力形式として新たに対応する変更ではありません。
ワイルドカード、送信名、URL、リセット、範囲・チェックボックスのヘッダーや
通常の Grid 絞り込みは変更しません。

独自の `InputFilter` と、ゼロで検索条件を省略する前提を確認してください。
依存関係・最低要件、スキーマやデータの移行、アセット・ビューの再公開は不要です。
HTTP/SQLite、実際のフォームからの FormData、再送信・消去・再選択、SQL と表示の
一致を検証します。実ブラウザー・ネットワーク・PJAX・日時ウィジェット操作・
他の DB・任意のアプリの保証ではありません。各 Laravel アプリで個別に確認してください。
