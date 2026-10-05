# Dependent Select loader isolation

`Select::loads()` registers scripts inside the shared Admin ready callback.
Previously, its function-scoped `fields`, `urls` and `refreshOptions` variables
were shared by every `loads()` script in that callback. A later initializer
overwrote them, so changing an earlier source could request the later loader's
URLs and replace options in unrelated target selects.

Each emitted `loads()` script now runs in an immediately invoked function
expression (IIFE). Its change handler retains that initializer's target fields,
URLs, response mapping and `allowClear` setting, regardless of registration
order. For example, a region loader can update country and currency while a
separate category loader updates product.

## Compatibility and upgrade cautions

- The public `loads()` signature, existing field/URL pairing, response ID/text
  mapping, target lookup within the closest `.fields-group`, and single-loader
  selection/clear behavior are unchanged.
- This is a PHP-generated script change in `Select::loads()`. No production
  JavaScript asset or Blade view changes, asset/view republishing, schema changes
  or data migration are required. Review subclasses or copied PHP implementations
  that replace `loads()`; they need equivalent local script scope.
- ID formats, option payloads and form submission protocols are unchanged.
  There is no new ID encoding or decoding behavior.
- Existing delegated change-handler removal/rebinding remains. Reinitializing
  the covered ordinary fields preserves the loader isolation; this is not a
  redesign of event namespaces or arbitrary consumer-handler coexistence.
- `load()`, URL-options initialization and AJAX search retain their existing
  behavior. This fix adds no AJAX ordering, cancellation, stale-response guard,
  or dependent-target validation-retry restoration.
- Existing selectors still determine target identity. This does not guarantee
  isolation for nested HasMany rows, duplicate selectors or arbitrary embedded
  form structures. Application-side validation and allowed-choice rules remain
  necessary. Other Laravel applications must verify their own versions,
  overrides and persistence behavior before claiming compatibility.

## Regression coverage

`SelectLoadsIsolationTest.php` renders real Form fields and the production Admin
ready wrapper. Offline jsdom executes the emitted scripts with shipped Select2
and both shipped jQuery 2.1.4 and jQuery 3.7.1. The independent region-to-country/
currency and category-to-product loaders run in both registration orders, with
custom response ID/text mappings, different `allowClear` settings and repeated
initialization. The checks verify requested URLs, changed targets and untouched
unrelated selections, plus preserved single-loader selection and clearing.

Option responses come from real in-process HTTP routes with intercepted AJAX
transport. Native FormData from the resulting controls is submitted through the
real Laravel HTTP kernel and Form save path, then checked in disposable SQLite.

Run from the standard integration consumer on each framework family:

```sh
composer test -- --filter SelectLoadsIsolationTest
```

This is offline DOM and in-process HTTP/SQLite coverage. It does not establish
physical-browser rendering, layout or keyboard accessibility, live networking,
PJAX navigation, real CSRF/cookie handling, non-SQLite persistence or arbitrary
application compatibility. Testbench bypasses CSRF validation. No dependency
changes are required.

## 日本語: 変更点と互換性の範囲

`Select::loads()` の各スクリプトは、共通の Admin ready コールバック内で実行されます。
従来は `fields`・`urls`・`refreshOptions` が同じ関数スコープを共有し、後から登録した
ローダーが先行ローダーの設定を上書きしていました。各スクリプトを即時実行関数
（IIFE）で囲み、対象フィールド、URL、レスポンスの ID/表示名マッピング、
`allowClear` をローダーごとに保持します。

公開 API、単独利用時の選択・クリア、ID・送信形式、既存の対象検索とイベント再登録は
維持します。PHP が生成するスクリプトのみの修正で、ビュー・アセットの変更や再公開、
データ移行は不要です。`loads()` を置き換える独自 PHP 実装には、同等の変数分離が
必要です。`load()` などの別の初期化経路、AJAX の応答順序・キャンセル、入れ子の
HasMany 行や重複セレクターの識別を保証する変更ではありません。

回帰テストでは、region から country/currency、category から product への独立した
連動を両方の登録順で検証し、独自マッピング、クリア設定、再初期化も確認します。
同梱 Select2 と jQuery 2.1.4 / 3.7.1 をオフライン DOM で動かし、native FormData を
実際のプロセス内 HTTP 処理に渡して SQLite 保存を確認します。実ブラウザー、実通信、
PJAX、CSRF、入れ子の識別や任意の Laravel アプリとの互換性を保証するものでは
ありません。利用するバージョンと独自実装ごとに検証してください。
