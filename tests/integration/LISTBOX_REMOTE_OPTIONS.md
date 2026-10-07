# Listbox remote literal options

## Behavior and upgrade cautions

An ordinary `listbox('choice')->options('/options')` accepts a flat JSON object
whose keys are IDs and values are labels. Its remote initializer now creates
native `Option` nodes with literal values and text. Previously, it interpolated
those strings into HTML: an ID such as `a"b` became `a`, `a&copy;b` became `a©b`,
and a label such as `<b>Bold</b>` lost its literal tags. Unchanged submissions
could therefore save a different ID from the one returned by the options route.

Both the current selected flag and `defaultSelected` retain their original
membership in the stored/default selection. This preserves the native form-reset
baseline as well as initial selection. The shipped dual-listbox still owns
moving and removing choices, refreshing its two lists and serializing the native
select. Zero, numeric strings, leading-zero IDs, Unicode, quotes, ampersands and
angle brackets are covered. Remote labels are text, not an HTML-rendering API.

- Review custom `Listbox::loadRemoteOptions()` overrides and remote endpoints
  that return HTML or pre-escaped entities. Return the intended plain label;
  markup and entity-looking strings now remain visible literally. Overrides
  that replace the initializer are unchanged and need their own equivalent fix.
- Reload open forms after upgrading. No dependency/minimum-version change,
  asset/view republish, schema change or stored-data migration is required.
  Previously altered stored IDs cannot be automatically recovered.
- AJAX URLs, parameters/options, plugin settings, callbacks, the stored/default
  `data-value` metadata and the legacy comma-separated ID format are unchanged.
  IDs containing commas, malformed/nested response values, transport failures,
  response races and repeated remote initialization are outside this fix.
- Old-input/validation-retry precedence, dependent loaders, ordinary Select and
  MultipleSelect, and static Listbox Blade rendering are unchanged. In
  particular, the existing static-view entity-decoding behavior is retained.
  This is not a new validation, authorization or arbitrary-HTML feature.
- Verify integration with each consuming Laravel application and its overrides;
  the bounded tests do not certify every framework version admitted by Composer.

## Regression coverage

Use the isolated [integration harness](README.md), or run:

```sh
vendor/bin/phpunit --filter ListboxRemoteOptionsTest
```

Real Form rendering and HTTP/SQLite create/update/reopen saves are combined with
offline jsdom executing the actual Admin ready wrapper and shipped dual-listbox,
Bootstrap and iCheck, independently with jQuery 2.1.4 and 3.7.1. Only AJAX
transport is substituted; response data comes from a real in-process HTTP route.
Tests inspect native options, plugin lists and FormData; they exercise unchanged
submissions, actual widget moves/removals in both move-on-select modes, clearing,
reopening and saving again, native reset with explicit widget refresh, numeric
and literal-string controls, explicit AJAX options, widget settings and static
Listbox behavior. No widget Cancel dialog is involved.

Testbench bypasses CSRF and uses disposable SQLite. No live browser/layout,
keyboard/accessibility, real network/PJAX, arbitrary consumer overrides,
non-SQLite database or broad security assessment is claimed. Native reset
coverage does not assert that plugin list refresh happens automatically.

## 日本語

通常の `listbox('choice')->options('/options')` で、ID をキー、ラベルを値とする
平坦な JSON オブジェクトから、文字列をそのまま持つネイティブ `Option` を作成
します。従来の HTML 文字列への直接埋め込みでは、`a"b` が `a` に、`a&copy;b`
が `a©b` に変化し、`<b>Bold</b>` のタグも消えていました。そのため、選択を
変更せず保存しても、サーバーが返した ID と別の値が保存される場合がありました。

選択中のフラグと `defaultSelected` は、保存値・既定値との既存の一致判定を
維持し、初期選択とネイティブのフォームリセットを保持します。選択肢の移動・
削除・表示更新と通常の送信は、引き続き同梱の dual-listbox が担当します。
ラベルはプレーンテキストです。独自の `loadRemoteOptions()` や HTML・
エスケープ済みのラベルを返す API を確認してください。タグ・実体参照のような
文字列も、そのまま表示されます。置き換え済みの独自初期化処理には別途対応が必要です。

更新後はフォームを再読み込みしてください。依存関係・最低要件、アセットや
ビューの再公開、スキーマ・データ移行の変更はありません。以前に変化して保存された
ID は自動復元しません。AJAX の URL・パラメーター・設定、ウィジェット設定、
保存値の `data-value`、カンマ区切りの ID 形式は維持します。検証エラー後の旧入力の
優先順位、依存ローダー、通常の Select/MultipleSelect、静的 Listbox ビューの
実体参照の解釈は変更しません。カンマ入り ID、不正・入れ子の応答、通信失敗・競合・
遠隔初期化の繰り返しへの対応や、検証・権限機能の追加ではありません。

HTTP/SQLite、両 jQuery と実際の同梱プラグインで、初期表示・未変更の保存、
移動と削除、消去、再表示後の再保存、ネイティブリセット後の明示的な表示更新、
各種文字列と数値 ID、AJAX・ウィジェット設定、既存の静的表示を検証します。
自動でリセット表示が更新される保証はありません。実ブラウザー・レイアウト・
キーボード操作・実ネットワーク・PJAX・全 DB・任意のアプリや独自実装・広範な
セキュリティ評価は対象外です。他の Laravel アプリとの連携は個別に確認してください。
