# Grid zero-key scope selection with defaults

## Behavior and upgrade cautions

A Grid scope with key `0` or `'0'` now remains selected when another scope calls
`asDefault()`. For example, a link containing `_scope_=0` keeps the zero-key
scope's label and SQL condition instead of being replaced by the configured
default. Previously `asDefault()` treated integer/string zero as an empty
selection and rewrote the request. Registration order does not affect the fix.

The change only protects these two scalar zero values in `Scope::asDefault()`.
Absent/null, empty-string, false and empty-array inputs retain their existing
default-assignment behavior. Other truthy inputs are not rewritten. The existing
scope lookup comparison, query key, URL construction, cancel behavior and query
methods are unchanged. An unknown nonempty scope still applies no scope, and
following Cancel with a configured default still returns to that default.
This does not redesign scope-key validation, matching without a configured
default, multiple-grid scope namespaces or pagination.

Review custom `Scope::asDefault()` overrides and code that relies on zero being
replaced by a default. No published view or JavaScript asset change, dependency
upgrade, schema change or data migration is required. Reload existing grids after
updating package PHP code. Verify each consuming Laravel application separately.

## Regression coverage

Run through the isolated [integration harness](README.md):

```sh
vendor/bin/phpunit --filter GridScopeZeroDefaultTest
```

The 16 tests render the shipped FilterButton and scope links, follow their actual
URLs through Laravel's HTTP kernel, and assert SQLite row sets, SQL bindings,
selected labels and request values. They cover integer/string scope keys,
registration of the default before/after other scopes, repeated zero selections,
ordinary scope changes, Cancel, preservation of an unrelated query parameter,
unknown scopes, scalar integer/string zero inputs, and unchanged legacy inputs.

These are in-process HTTP/SQLite and rendered-link tests, not live-browser click,
network/PJAX, external-database or arbitrary downstream-application certification.
Array inputs in the isolated default-assignment checks document the existing
rewrite predicate only; they are not supported end-to-end scope keys.

## 日本語

キーが整数 `0` または文字列 `'0'` の Grid スコープを選んだとき、別のスコープの
`asDefault()` によって選択を上書きしないようにします。`_scope_=0` のリンクを
開くと、ゼロのスコープのラベルと SQL 条件が保持されます。従来はゼロを空値と
みなして既定スコープに置き換えていました。スコープの登録順には依存しません。

変更は `Scope::asDefault()` の整数・文字列ゼロの扱いに限定します。未指定・NULL、
空文字列、false、空配列の既定値設定、その他の値、既存のスコープ比較・クエリ名・
URL・キャンセル・検索条件は維持します。不明な空でないキーではスコープを適用せず、
既定値がある画面でキャンセルした後はその既定値に戻る既存仕様です。キーの検証、
既定値がない場合の比較、複数 Grid の名前空間やページ切り替えは変更しません。

独自の `Scope::asDefault()` やゼロを既定値へ置き換える前提の処理を確認してください。
ビュー・JavaScript アセットの再公開、依存関係・スキーマ・データ移行は不要です。
更新後は Grid を再読み込みし、利用先の Laravel アプリで個別に検証してください。

16 件のテストで実際のスコープリンク、プロセス内 HTTP、SQLite の検索結果と
バインド値、ラベル、再選択、通常の切り替え、キャンセル、他のクエリ値の保持と
既存入力の扱いを確認します。実ブラウザー・実通信・PJAX・外部 DB・任意の利用先の
保証ではありません。配列の単独チェックは既存の既定値判定を記録するだけで、
配列をスコープキーとして使う API を追加するものではありません。
