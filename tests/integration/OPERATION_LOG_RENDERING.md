# Operation log display

## Behavior

The built-in operation log page now displays method, path and IP values as
literal text inside the existing badges and labels. A path containing
`<b>literal</b>` previously created a child element, and `&copy;` became a
copyright character. These values now retain their original text. Known method
colors and the grey fallback are unchanged.

Request input keeps its existing pretty JSON format and `JSON_HEX_TAG`
representation. The JSON is additionally escaped for HTML so named and numeric
entities such as `&quot;`, `&amp;` and `&#10;` survive rendering. Previously, HTML
entity decoding could change values and make the displayed JSON invalid. Keys,
nested arrays, Unicode, line breaks and scalar types are covered by the JSON
round-trip assertions.

Valid scalar JSON in historical or imported rows now renders without passing it
to Laravel's array helpers. Malformed JSON and empty stored strings render as
escaped text in a `pre` element instead of crashing the page. This fallback
shows the stored content; it does not repair the JSON. Valid arrays/objects still
omit top-level `_pjax`, `_token`, `_method` and `_previous_` keys, and an empty
result retains the existing `{}` display. The default user-name column continues
to use the Grid's ordinary text escaping.

Display escaping does not change stored rows or redact historical secrets.
Newly persisted inputs use the existing recursive, field-name
[redaction behavior](../../docs/operation-log-redaction.md). Historical rows can
still contain sensitive values, including malformed content that cannot be
filtered as an object. Access and retention decisions remain with the consuming
application.

## Regression coverage

Run this focused regression in the isolated [integration consumer](README.md):

```sh
vendor/bin/phpunit OperationLogRenderingTest.php
```

The tests boot the real provider, admin routes, guard, HTTP middleware, package
migrations and SQLite tables, then inspect the production log page through
DomCrawler. They cover:

- Actual HTTP persistence and rendering of nested input with built-in and custom
  redaction, literal JSON text, and preserved controller input after Laravel's
  normal empty-string conversion
- An actual middleware-persisted path containing markup and entity-looking text
- Imported method/IP/path values, known method colors and the grey fallback
- Historical malformed, empty, scalar, array and object input; existing hidden
  metadata and empty-result presentation
- Unchanged stored rows when viewing logs, the explicit historical-secret
  boundary, escaped user names and the guest login redirect

The metadata fixtures use harmless markup to verify that no child elements are
created. Imported method/IP values test the renderer; they do not claim that a
real HTTP server or trusted-proxy configuration accepts those strings.

Local verification on 2026-10-23 with PHP 8.4.27:

- Laravel 12.70.1 / PHPUnit 11.5.57: **23 tests, 278 assertions pass**
- Laravel 13.38.0 / PHPUnit 12.5.40: **23 tests, 278 assertions pass**
- Laravel 12 with the adjacent `AdminLifecycleTest`: **38 tests, 1,718 assertions pass**

Before changing the controller, the same focused regression had 10 errors and
9 failures. Entity decoding changed displayed values, metadata became markup,
and scalar/malformed input raised `array_key_exists()` type errors. Four
unaffected controls passed. The additional output-buffer warnings in that
baseline followed the rendering exceptions; the fixed focused runs are clean.

## Upgrade and verification limits

Review custom `LogController::grid()` overrides that copy these display
callbacks, then reload open log pages. There is no schema/data migration,
asset/view republish, public API or dependency/minimum-version change. Global
Grid callbacks and the `Label` displayer keep their existing contracts.

These checks cover server-rendered HTML and DOM text, using SQLite and an
authenticated test guard. Testbench bypasses CSRF validation. They do not prove
live-browser JavaScript execution, layout, cookies, full PJAX navigation,
non-SQLite behavior, arbitrary binary/invalid-UTF-8 text preservation or broad
security coverage. JSON number conversion and HTML text normalization retain
their existing platform limits. Verify application-specific controllers,
middleware and published/custom views in each consumer.

## 日本語

操作ログ画面の method・path・IP を、既存のバッジやラベル内で文字列として
表示します。従来は `<b>literal</b>` が要素になり、`&copy;` などの実体参照が
別の文字へ変換されていました。入力欄の整形済み JSON も HTML 用にエスケープし、
`&quot;`・`&amp;`・`&#10;` などを保持します。メソッドの色、JSON の既存の
`JSON_HEX_TAG` 表現、空データの `{}` 表示は維持します。

過去・取込済み行の JSON がスカラーや不正な文字列でも画面全体が停止しなく
なります。不正な JSON は保存済み内容をエスケープして表示し、修復は行いません。
有効な配列・オブジェクトでは、既存のトップレベルの `_pjax`・`_token`・
`_method`・`_previous_` の非表示を維持します。

表示のエスケープは保存済みデータを書き換えず、過去の秘密情報も伏せ字にしません。
新規ログの再帰的なフィールド名による伏せ字処理とは別の変更です。不正な JSON
にはフィールド単位の除外を適用できないため、保存済みの機密情報が表示される
場合があります。アクセス権限と保存期間は利用アプリで確認してください。

実 HTTP・ミドルウェア・SQLite・本番コントローラーの出力を DOM 解析し、
新規ログの伏せ字、文字列の保持、既存データの不変性、過去データとゲストの
リダイレクトを検証します。method・IP の特殊文字は取込行の表示テストであり、
実 HTTP サーバーでそれらを受信できるという意味ではありません。上記の
Laravel 12/13 と PHP 8.4.27 で各 23 テスト・278 アサーションが成功しています。

独自の `LogController::grid()` を確認し、更新後にログ画面を再読み込みしてください。
スキーマ・データ移行、アセット・ビューの再公開、公開 API・依存関係・最低要件の
変更はありません。実ブラウザー・JavaScript・レイアウト・Cookie・CSRF・PJAX
全体・他 DB・任意のバイナリ文字列・広範なセキュリティ検証は対象外です。
各アプリ独自のコントローラー、ミドルウェア、ビューでの動作も確認してください。
