# Grid LIKE searches for zero

`like()`, `startsWith()` and `endsWith()` now apply scalar `"0"` and integer `0`
as search terms. Previously, PHP's `empty()` check omitted the condition and
returned unfiltered rows even though the form still displayed `0`. The generated
patterns are `%0%`, `0%` and `%0`, respectively. `ilike()` shares the same guard
and emits its existing `ilike` operator with `%0%`.

Only integer/string zero handling changes. Missing input, null, blank strings,
false, floating-point zero and arrays retain their previous handling. In
particular, this does not introduce array-valued LIKE searches. Wildcard meaning,
escaping, relation dispatch, defaults and named-filter prefixes are unchanged.

Review custom `Like::condition()` overrides and application behavior that relied
on `0` omitting a filter. No dependency/minimum-version, schema/data migration,
asset or view republish is required. Verify each consuming Laravel application
separately; these tests do not certify every declared framework or database.

## Regression coverage

Run in the isolated [integration harness](README.md):

```sh
vendor/bin/phpunit --filter GridLikeZeroTest
```

Real HTTP/SQLite tests check result rows and bindings for both the count and row
queries with all three LIKE forms, in named and unnamed Grids. The production
GET filter form is serialized with native FormData in offline jsdom and submitted
unchanged twice, then cleared/reset. Nonzero/leading-zero text, a configured
fallback default, and unchanged database contents are controls. Direct condition
checks cover integer/string zero and the legacy empty cases for all four classes.
ILIKE coverage checks the condition shape only; no PostgreSQL execution is
claimed. There is no live-browser, real-network/PJAX, non-SQLite execution or
arbitrary custom-presenter coverage.

## 日本語

`like()`・`startsWith()`・`endsWith()` で、文字列 `"0"` と整数 `0` を検索条件
として適用します。従来は `empty()` により条件が省略され、入力欄に `0` が
表示されていても全件が返る場合がありました。検索パターンは `%0%`・`0%`・`%0`
です。`ilike()` も同じ判定を使い、既存の演算子と `%0%` を維持します。

変更は整数・文字列のゼロのみです。未指定・null・空文字列・false・浮動小数点の
ゼロ・配列の扱い、ワイルドカード、関連モデルの条件、既定値、名前付き Grid の
接頭辞は変更しません。独自の `Like::condition()` と、ゼロを条件省略として
使っていたアプリを確認してください。依存関係・最低要件、スキーマやデータの
移行、アセットやビューの再公開は不要です。

HTTP/SQLite と実際の GET フォームからの FormData で検索結果・SQL バインド・
未変更の再送信・消去・リセットを検証します。ILIKE は条件生成のみで、PostgreSQL
での実行は未検証です。実ブラウザー・実ネットワーク・PJAX・他の DB・任意の
独自実装の保証ではありません。他の Laravel アプリとの連携は個別に確認してください。
