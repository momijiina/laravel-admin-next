# AJAX Select label escaping

Form and Grid filter `Select::ajax()` previously installed an `escapeMarkup`
callback that returned every string unchanged. The shipped Select2 widget then
parsed ordinary option labels as HTML, both in search results and in the selected
value. This also affected preloaded options: Blade escaped their `<option>` text,
but Select2 read that text and parsed it again when building its selection UI.
Both `MultipleSelect` implementations inherit the affected initializer.

The two AJAX initializers now use Select2's native escaping behavior. An AJAX
response label such as `<strong>Example</strong> &amp;` appears literally,
including its tag and entity text, instead of creating a `strong` element or
decoding `&amp;`. Preloaded labels retain the existing Blade handling of entities;
Select2 now preserves the resulting option text when it renders the selection.

## Compatibility and upgrade cautions

- The `ajax()` signatures, response ID/text mapping, query and page parameters,
  pagination, selected values and native submission format remain unchanged.
  No public or protected API, schema, Blade view or published asset changes.
- Consumers that intentionally returned HTML in the response's text field, or
  in preloaded option labels, must move formatting into an explicit Select2
  template. Consumers that manually HTML-encoded plain labels to compensate for
  the previous behavior should return the original plain text instead; this fix
  does not migrate previously encoded data or change Blade's existing
  `withoutDoubleEncoding()` policy for preloaded options.
- Native `templateResult` and `templateSelection` callbacks returning DOM nodes
  or jQuery objects retain their intentional markup. The regression checks real
  callbacks registered with `$.fn.select2.defaults.set()` before the production
  initializer runs. For example, a template may return
  `$('<strong>').text(option.text)`: formatting is explicit and label text stays
  literal. Global defaults affect every subsequently initialized Select2 widget,
  so applications should retain their own intended initialization scope.
- This does not add PHP callback-string support to `config()`. The affected
  initializers still JSON-encode PHP configuration. Custom JavaScript escaping,
  templates that insert untrusted HTML, or subclasses replacing `ajax()` need
  their own review; native extension points can override default escaping.
- Ordinary options, URL-options initialization, and dependent `load()`/`loads()`
  use separate initializers. Their behavior is unchanged by this fix. No asset
  or view republishing is required; copied PHP AJAX initializers must remove the
  equivalent identity escaping override to receive the correction. Reload open
  admin pages so their widgets use the updated initializer.

## Regression coverage

`AjaxSelectEscapingTest.php` renders real Form field and Grid filter views and
the production Admin ready wrapper. Offline jsdom runs shipped Select2 4.0.3 with
shipped jQuery 2.1.4 and jQuery 3.7.1. Only AJAX transport is replaced; both page
responses come from real in-process HTTP option routes.

The checks cover single and multiple selection in each surface, preloaded
selected labels, search results and subsequent selected labels, tags, entity
strings, quotes, Unicode, a string zero ID and an HTML-sensitive string ID.
They also exercise default/custom response keys, the native next-page loading
path, preserved configuration and query parameters, and native FormData versus
jQuery serialization. Separate cases check explicit native DOM/jQuery templates
for both result and selection rendering.

Run from the integration consumer on each framework family:

```sh
composer test -- --filter AjaxSelectEscapingTest
```

This is offline DOM and in-process HTTP coverage. It demonstrates unintended
HTML node creation and its removal; it does not execute attack payloads or
constitute a penetration test. Physical-browser layout, keyboard accessibility,
live networking, PJAX navigation, arbitrary custom overrides, and database
persistence are outside this regression. Existing selected-value regressions
cover their own persistence paths. No new dependency support claim is made.

## 日本語: 変更点と互換性の範囲

Form / Grid フィルターの `Select::ajax()` は、文字列をそのまま返す
`escapeMarkup` を設定していたため、検索結果だけでなく選択済みラベルも
HTML として解釈していました。Blade がエスケープした初期選択肢も、Select2
での再描画時に影響を受けます。継承先の `MultipleSelect` も対象です。

今回の修正では Select2 標準のエスケープを使います。HTML 文字列による装飾を
利用していた場合は、DOM / jQuery オブジェクトを返す明示的なテンプレートへ
移してください。従来の回避策として文字列を事前エンコードしていた場合は、
元の平文を返す必要があります。PHP `config()` への JavaScript 関数文字列の
対応を追加する変更ではありません。

公開 API、ID と送信形式、検索・ページングの契約は維持します。ビューや同梱
アセットの変更・再公開は不要です。更新後は開いたままの管理画面を再読み込み
してください。回帰テストは実際の生成 HTML と同梱 Select2
を使うオフライン DOM 検証で、実ブラウザー・実通信・PJAX・データベース保存や
任意のアプリケーションとの互換性を保証するものではありません。
