# Grid editable Select callback sources

## Contract

The legacy Grid `editable('select', $closure)` API computes options for each row.
Its JSON source is embedded in a single-quoted HTML `data-source` attribute,
parsed by the DOM, read by jQuery and consumed by the shipped X-editable plugin.
Escape that JSON for the HTML attribute so each stage preserves the option keys
and labels. `O'Reilly` previously ended the attribute early. Literal `&amp;`
was decoded, and quote entities could invalidate the JSON.

The change is limited to this callback-source attribute. Static option arrays
still travel in the generated script. Callback binding and its row argument,
source array structure and key types, current values, text escaping, public
methods, save parameters and endpoint behavior remain unchanged. Empty callback
options remain `[]`. This does not introduce arbitrary object, binary or invalid
UTF-8 source support or change X-editable's option matching semantics.

## Regression coverage

`GridEditableSelectSourceTest.php` boots real Laravel and SQLite:

- Production displayer output is parsed through the DOM and JSON with apostrophes,
  double quotes, ampersands, literal named/numeric entities, markup-looking and
  attribute-looking strings, Unicode, blank/zero labels, LF/CRLF and backslashes
- Actual HTTP Grid rendering retains independent per-row callback sources and
  verifies the callback's existing displayer binding and row argument
- The shipped X-editable and Bootstrap plugins run under both shipped jQuery
  2.1.4 and fixture jQuery 3.7.1; assertions inspect the parsed attribute, jQuery
  data, actual native option values/labels and the initial selection
- Real plugin opening, cancellation, reopening, saving a changed option and
  saving back to the original emit the existing exact AJAX payload; those
  serialized requests are replayed through the HTTP kernel and package Form
  update path to verify SQLite persistence without changing the options/other rows
- Static-array Select sources, an empty callback source and ordinary text
  Editable escaping retain their previous behavior

The Node helper runs offline in jsdom. It supplies popup dimensions for the
plugin's visibility check because jsdom has no layout, stubs unrelated PJAX
navigation and captures the AJAX transport. It does not replace X-editable's
source parsing, input rendering, selection, cancel or submit handlers. Replayed
HTTP requests verify server persistence separately from the captured browser
transport. These tests do not prove live-browser layout, navigation, network
transport, full PJAX lifecycle compatibility or all X-editable types.

Run this focused regression after installing the integration consumer and pinned
JavaScript dependencies described in [README.md](README.md):

```sh
vendor/bin/phpunit GridEditableSelectSourceTest.php
```

## Upgrade cautions / 更新時の注意

Reload open grids after updating the PHP package. No asset/view republish,
dependency or minimum-version change, schema migration or data migration is
required. Review custom `Editable::select()` and `addAttributes()` overrides,
particularly code that reads the raw JSON attribute or escapes it a second time.
Verify the behavior in each consuming Laravel application. Custom HTML attributes,
source validation, AJAX source loading and plugin-wide lifecycle changes are
outside this patch.

日本語: 行別クロージャの選択肢 JSON を HTML 属性向けにエスケープし、引用符での
途中切れや実体参照の誤変換を防ぎます。固定配列、クロージャの束縛・引数、キーの型・
JSON 構造、選択・送信形式、空の選択肢とテキスト編集の既存動作は維持します。
更新後は Grid を再読み込みしてください。アセット・ビューの再公開、依存関係・
最低要件の変更、スキーマ・データ移行は不要です。生の属性を読む、または二重に
エスケープする独自の `Editable::select()` / `addAttributes()` を確認してください。
他の Laravel アプリとの連携はアプリごとに検証してください。

検証は実際の出力・同梱プラグイン・DOM の選択肢・キャンセル・再編集・送信を通し、
生成されたリクエストを HTTP カーネルに再送して SQLite の保存まで確認します。
jsdom の配置情報と通信・PJAX の境界を補助するため、実ブラウザーの表示・通信や
PJAX 全体、すべての編集型を保証する結果ではありません。独自属性、選択肢検証、
AJAX による選択肢取得、プラグイン全体の再設計は対象外です。
