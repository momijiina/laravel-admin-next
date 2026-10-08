# Model-backed Grid Select zero IDs

Grid `equal('code')->select()->model(Option::class)` now loads the selected model
option for integer `0` and string `'0'`. The previous empty-value guard skipped
both IDs. The SQL filter still ran, but the model-backed select had no matching
option, so submitting the unchanged form could remove the filter.

Only these two scalar values are newly admitted to the existing model lookup.
Other legacy empty inputs (`null`, `''`, `false`, `0.0` and `[]`), missing models,
nonzero/text IDs, record-array handling, option keys/labels and SQL conditions
keep their existing behavior. This does not redefine ordinary scalar-array
inputs, filter defaults or the separate URL-backed option loader.

## Upgrade notes

No published view change, asset rebuild, schema/data migration, dependency or
minimum-version change is required. Check custom Grid Select presenters that
override `model()` and verify each consuming Laravel application independently.
Applications that intentionally treated scalar zero as no model selection will
now see the existing zero-ID model's option and label.

## Regression coverage and limits

Run `vendor/bin/phpunit --filter GridModelSelectTest` in the isolated
[integration harness](README.md). The 17 cases cover:

- Zero, nonzero, leading-zero and text IDs in named/plain Grids and
  container/modal layouts, with actual HTTP/SQLite rows and query bindings.
- Shipped Select2 with shipped jQuery 2.1.4 and modern jQuery, displayed labels,
  two unchanged native FormData resubmissions, clearing, reset, reselection and
  reopening without modifying stored values.
- Integer/string zero option lookup, ordinary and absent models, legacy empty
  inputs, record-array inputs and a custom text field.

JavaScript runs offline in jsdom. These tests do not newly certify live-browser
layout, remote AJAX/network transport, full PJAX, authentication/CSRF, non-SQLite
databases or downstream applications. MultipleSelect scalar-array semantics and
Form Select behavior are outside this change.

## 日本語

Grid の `select()->model(...)` が整数 `0`・文字列 `'0'` のモデル選択肢を
読み込まず、検索結果だけが絞り込まれる不具合を修正します。従来は選択肢がない
ため、未変更のフォームを再送信すると絞り込みが消える場合がありました。
モデル取得前の空値判定でこの 2 値だけを許可します。他の空値、通常の ID、
モデル未検出、レコード配列、ラベル、検索条件の既存仕様は維持します。

公開済みビューの変更、アセット再構築、データ移行、依存関係や最低要件の変更は
不要です。独自の Grid Select `model()` 実装と、利用先の Laravel アプリを
個別に確認してください。ゼロを選択なしとして扱っていたアプリでは、実在する
ゼロ ID の選択肢とラベルが表示されます。

17 ケースで HTTP/SQLite、名前付き・通常 Grid、通常・モーダル表示、同梱
Select2 と新旧 jQuery、未変更の再送信 2 回、解除・リセット・再選択を確認します。
JavaScript はオフラインの jsdom で実行します。実ブラウザー、Ajax 通信、PJAX
全体、認証・CSRF、他の DB、個別アプリの動作は今回新たに保証していません。
MultipleSelect の単純な値配列や Form Select は今回の変更対象ではありません。
