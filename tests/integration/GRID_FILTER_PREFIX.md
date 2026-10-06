# Named Grid filter prefix preservation

## Behavior and upgrade cautions

A Grid named `user` renders `equal('user_id')` as `user_user_id`. Input
normalization now removes only the first, already matched `user_` namespace,
leaving `user_id` as the actual column. Repeated or interior occurrences in the
column remain intact. For example, `user_user_user_id` maps to `user_user_id`
and `user_owner_user_code` maps to `owner_user_code`.

Previously, global replacement could turn `user_user_id` into `id`. With the
default ID filter enabled, this applied a condition to the wrong column; with
that filter disabled, the intended condition could be ignored. Simultaneous
`user_id` and `user_user_id` inputs could also collapse into a single condition.
The correction keeps these distinct. Filter controls are query/presentation
controls; this is not an authorization mechanism or a security-boundary repair.

Existing leading namespace matching remains in place. Unnamed grids, keys from
other namespaces, ordinary noncolliding columns, input values and nested array
shapes retain their contracts. Blank/null removal, zero values, filter operators
and SQL bindings are unchanged. No HTML names, IDs, views or production JavaScript change.

Review custom `Filter::sanitizeInputs()` overrides and hand-written URLs that
accidentally depended on the former aliasing. Use the existing named query format
for the intended column, and rerun affected queries. No new API, dependency or
minimum version, asset/view republish, schema change or data migration is needed.
Verify compatibility with each consuming Laravel application; this bounded
repair does not certify every version admitted by the Composer constraints.

## Regression coverage

Use the isolated [integration harness](README.md), or run its focused suite:

```sh
vendor/bin/phpunit --filter GridFilterPrefixTest
```

The regression covers actual rendered filter controls, offline jsdom native
`FormData` and URL encoding, followed by Laravel HTTP requests and SQLite queries.
It checks applied columns and bindings, exact rows, value redisplay, reset results
and unchanged stored records. Controls include default ID enabled/disabled,
simultaneous distinct inputs, repeated and interior prefixes, unnamed and other
Grid names, blank/zero values, and separately named grids. Range and multiple-value
inputs exercise array reconstruction after namespace removal.

## Boundaries

The checks use in-process HTTP, SQLite and offline DOM submission. They do not
establish live browser layout, PJAX/network behavior, production authentication,
all database engines, arbitrary custom filters or arbitrary application
compatibility. Multiple form payloads are combined explicitly where tested;
ordinary GET submission is not claimed to preserve another form's state.
Dotted-key normalization does not certify relation-query behavior. Existing
namespace-overlap behavior and DOM/widget ownership are outside this change.

## 日本語

Grid 名 `user` の `equal('user_id')` が生成する `user_user_id` は、先頭の
接頭辞だけを除去して `user_id` に戻します。列名に含まれる繰り返しや途中の
`user_` は保持し、`user_user_user_id` は `user_user_id`、
`user_owner_user_code` は `owner_user_code` になります。

従来はすべての接頭辞文字列を除去するため、別列への誤適用、条件の無視、複数入力の
衝突が起こり得ました。今回の修正はその変換だけを対象にします。フィルターは検索・
表示のための機能であり、認可の仕組みやセキュリティ境界の修正ではありません。
名前空間の先頭一致、名前なし Grid、他の名前空間、通常の列名、値と配列構造、
空欄・NULL の除外、ゼロ、SQL の比較とバインド値は維持します。
HTML の入力名・ID、ビュー、JavaScript は変更しません。

独自の `sanitizeInputs()` と従来の誤変換に依存する URL を確認し、意図した列の
既存の名前付きクエリ形式で再検索してください。新 API、依存関係・最低要件の変更、
アセット・ビューの再公開、スキーマ変更やデータ移行は不要です。他の Laravel
アプリとの互換性は個別に検証してください。

実際の描画フォームからネイティブ FormData を生成し、HTTP/SQLite の列・SQL・行・
再表示・リセットと保存済み行の不変性を確認します。ID 有効／無効、複数条件、
繰り返し・途中の接頭辞、名前なし・別名の Grid、空欄・ゼロ、範囲と複数値の配列を
対象とします。実ブラウザー、PJAX、認証、全 DB、独自フィルター全般の保証では
ありません。複数フォームのデータは明示的に結合し、他方の状態の自動維持は検証
しません。ドット区切りのキー処理はリレーションクエリの保証ではありません。
重なる名前空間や DOM・ウィジェット所有権の動作は今回の対象外です。
