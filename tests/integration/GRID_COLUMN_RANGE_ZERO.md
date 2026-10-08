# Zero bounds in numeric column-header ranges

Column-header `filter('range')` and `filter('range', 'equal')` now retain
scalar integer `0` and string `"0"` bounds and show an active filter indicator.
Previously, a displayed zero bound was silently removed: `0`–`2` became
`quantity < 2`, and `0`–`0` applied no predicate at all.

The change is limited to the default/equal range type. Paired bounds still
use `whereBetween`; existing one-sided `<` and `>` operators remain strict.
Nonzero values, blank/null/false/floating-point-zero/empty-array handling,
request-bound ordering, names, URLs and reset links are unchanged. Temporal
`date`, `time` and `datetime` ranges and other explicitly supplied range types
retain their prior zero handling. Nested arrays are not a new supported range
input format. Text/checkbox column headers and the separate main Grid
`Between` filter are outside this change.

Review custom `RangeFilter` overrides and code that relies on zero to omit a
numeric range. No dependency/minimum-version change, schema/data migration,
asset build or published-view refresh is required. Verify each consuming
Laravel application separately.

## Regression coverage

Run in the isolated [integration harness](README.md):

```sh
vendor/bin/phpunit --filter GridColumnRangeZeroTest
```

Tests use real column registration, query application, header rendering,
in-process HTTP and SQLite. Default and explicit equal modes cover zero in
either or both bounds, positive/negative/cross-zero ranges, one-sided bounds
and empty ranges. Native FormData from the actual rendered controls drives
initial submission, repeated unchanged submissions, clear/reset and zero
reselection. Assertions check both paginator count and row SQL, bindings,
rows, redisplayed bounds, active indicators and unchanged database contents.

Direct query tests cover integer/string zero and legacy empty values in
start/end/both positions across equal, temporal and other explicit range
types. Scalar-bound rendering must agree with the query state. Temporal HTTP
controls verify unchanged direct-column range dispatch and strict operators;
they use timestamp literals independently of picker display formats.

No live-browser layout, real-network/PJAX, temporal picker interaction,
non-SQLite database or arbitrary consuming-application coverage is claimed.

## 日本語

列ヘッダーの `filter('range')` と `filter('range', 'equal')` で、整数 `0`
と文字列 `"0"` を境界値として保持し、有効な絞り込みの表示も一致させます。
従来は `0`–`2` が `2` 未満だけの検索になり、`0`–`0` は条件なしになりました。

変更は既定の数値範囲だけです。両端の BETWEEN と、片側だけの厳密な `<`・`>`、
非ゼロ値と他の空値、境界の受信順序、名前・URL・リセットは維持します。
日時範囲や他の明示的な種類、通常の Grid Between と他のヘッダーは変更しません。
独自の RangeFilter と、ゼロを条件省略に使うコードを確認してください。
依存関係・最低要件、データ移行、アセット・ビューの再公開は不要です。

HTTP/SQLite と実際のフォームの FormData で、初回・再送信・消去・再選択、
SQL と表示の一致を検証します。日時のテストは既存のクエリ生成を対象とし、
ピッカーの表示形式や操作は対象外です。実ブラウザー・ネットワーク・PJAX・
他の DB・任意のアプリの保証ではありません。利用先ごとに確認してください。
