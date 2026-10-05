# Nullable Grid inline Select and Radio labels

A Grid column using `select($options)` or `radio($options)` now renders a blank
closed-cell label when its effective value is NULL. Previously, Laravel's
`Arr::get($options, null, '')` returned the complete options array. Blade then
failed to escape that array, preventing the whole Grid table from rendering.

## Contract and upgrade cautions

- Only a strict NULL value bypasses the existing option lookup. Integer/string
  zero, other numeric/string IDs, leading-zero IDs, empty-string keys, unmatched
  values and escaping keep their existing server-rendering behavior.
- Dynamic per-row options through the existing Grid column `display()` callback
  remain supported. This does not add a direct Closure-options API or accept
  new option shapes.
- This is a PHP displayer change. No view or asset republish, schema change or
  data migration is needed. Review custom Select/Radio displayer subclasses or
  copied implementations, which do not automatically inherit a replaced method.
- The existing popover defaults and JavaScript loose comparisons are unchanged.
  With the regression's options, a NULL Select opens on its first option (zero),
  while a NULL Radio opens with nothing checked. Opening/cancelling preserves
  NULL; explicitly choosing an option and saving uses the existing update path.
  This fix does not introduce a NULL clearing/submission protocol or redesign
  ambiguous IDs such as `1` and `"001"` in the client-side editor.
- Validate application-specific required/nullable and allowed-choice rules on
  the server. Other Laravel applications must verify their own overrides,
  framework versions and persistence behavior before claiming compatibility.

## Regression coverage

`GridInlineNullableChoicesTest.php` checks direct displayers and complete Grid
HTTP responses with disposable SQLite, including mixed NULL/non-NULL rows and
per-row callback labels. Direct controls retain zero, numeric strings, leading
zeros, ordinary and literal dotted IDs, empty-string keys, absent keys, empty
options and escaped HTML labels.

The offline DOM fixture executes actual emitted scripts with shipped Bootstrap
popover and jQuery 2.1.4 / 3.7.1. It clicks the real trigger, chooses a native
option, cancels, reopens and submits through the actual AJAX handler. Only AJAX
transport is intercepted: PHP replays its serialized request through the real
web HTTP kernel and Form update, checks persisted values, delivers the actual
response to the success callback, and independently reloads the server-rendered
Grid. These checks cover NULL-to-zero/string and non-NULL zero/string edits.

Run through each normal integration consumer:

```sh
composer test -- --filter GridInlineNullableChoicesTest
```

This is offline DOM and in-process SQLite coverage, not a physical-browser,
layout/keyboard-accessibility, live network/PJAX, real CSRF/cookie, non-SQLite,
relationship-editor or arbitrary custom-override guarantee. No dependency,
production view or JavaScript changes are included.

## 日本語: 変更点とアップグレード時の注意

Grid のインライン `select()` / `radio()` で値が NULL の場合、表示ラベルを空文字に
します。従来は選択肢配列全体が Blade に渡り、一覧全体を描画できませんでした。
NULL 以外の既存の検索・エスケープと、行ごとの `display()` コールバックは維持します。

PHP の表示処理のみの修正で、ビュー・アセットの再公開やデータ移行は不要です。
独自の表示クラスやコピーした実装は別途確認してください。ポップオーバーの初期選択、
JavaScript の緩い比較、保存形式は変更しません。NULL を保存・クリアする新しい API
ではありません。必要な入力制約はサーバー側で設定してください。他の Laravel
アプリケーションとの連携は、利用するバージョンと独自実装ごとに検証が必要です。
