# Numeric zero in AJAX Select results

Form and Grid filter `Select::ajax()` now accept a numeric `0` result ID. Both
`MultipleSelect` implementations inherit the correction. Clicking a zero result
keeps it selected and submits the native string value `"0"`.

## Cause and bounded change

Shipped Select2 4.0.3 passes AJAX results to its array adapter without first
calling `_normalizeItem()`. When a result is selected, `option()` assigns the
native option's `value` only when the raw ID is truthy. For numeric zero it skips
that assignment, leaving the label as the option value. Its later
`_normalizeItem()` call correctly converts zero to `"0"`, but does not repair the
native option value. The subsequent `.val(0)` cannot select the intended option.
Single selections fall back to their placeholder; multiple selections omit zero.

Select2's placeholder comparison is also loose, but it is not the initiating
failure in this path: the native selection is already empty. A label that itself
happens to be `"0"` can conceal the option-value bug.

The two existing response mappers convert only JavaScript numeric zero to `"0"`
before handing results to Select2. Positive numeric IDs retain their numeric
type at the result and event boundary. String zero, leading-zero strings,
non-ASCII identifiers, custom response keys, labels, and extra row properties
retain their values. With a custom ID key such as `code`, `code: 0` stays numeric
while the mapped Select2 `id` becomes `"0"`.

## Compatibility and limits

- Native result templates and `select2:select` callbacks now receive a string
  `id` for numeric zero. This intentional normalization makes the ID consistent
  with working string-zero responses and native submitted values. Other ID
  types are not broadly converted. Native selection data continues to use
  Select2's existing string normalization.
- JavaScript treats JSON `0`, `0.0`, and `-0` as numeric zero; all meet the strict
  zero comparison. This does not coerce `false`, `null`, missing IDs, empty
  strings, or string `"0.0"` to zero, and does not add support for malformed or
  arbitrary object/boolean IDs. Empty-ID placeholder behavior is unchanged.
- The AJAX signatures, text mapper, transport settings, search and page
  parameters, pagination, and custom template extension points remain intact.
  Default label escaping remains in effect, including for the zero result.
- Preloaded options, ordinary options, URL-options initialization, dependent
  `load()`/`loads()`, PHP value preparation, validation retries, and relationship
  storage use their existing paths. The correction changes neither views nor
  shipped assets and requires no asset republishing. Reload open admin pages;
  applications with copied PHP AJAX initializers need the equivalent mapper
  change.

## Regression coverage

`AjaxSelectZeroTest.php` renders actual Form and Grid filter views and the
production Admin ready wrapper. Offline jsdom executes shipped Select2 4.0.3
with shipped jQuery 2.1.4 and jQuery 3.7.1. Only AJAX transport is replaced; both
paginated responses come from in-process HTTP routes.

The matrix covers numeric/string zero, both response-key mappings, single and
multiple widgets on both surfaces, positive numeric IDs, leading-zero and
non-ASCII/HTML-sensitive string IDs, extra metadata, preloaded selections,
native result clicks, clearing and zero reselection. It checks default literal
labels and explicit native jQuery templates, result/event ID types, pagination,
configuration, and agreement between FormData and jQuery serialization. Each
selection is replayed through real HTTP requests to `Form::store()` with SQLite
scalar/JSON persistence, or to Grid filters with SQLite result and SQL-binding
assertions. Before the fix, the four default-mapping numeric-zero widgets fail
their first native selected-value assertion.

Run on each integration framework family:

```sh
composer test -- --filter AjaxSelectZeroTest
```

This covers offline DOM behavior and in-process HTTP/SQLite paths. Physical
browser layout, keyboard interaction, live networking, PJAX, Form updates,
relationship persistence, other database engines, and arbitrary custom adapters
remain outside this regression. No new dependency support claim is made.

## 日本語: 数値のゼロを持つ AJAX 選択肢

Form / Grid フィルターの `Select::ajax()` と継承先の `MultipleSelect` で、
数値 `0` の選択肢が選択・送信できるようになります。同梱 Select2 は数値 ID
を文字列へ変換する前に `<option>` の値を設定し、`0` を空扱いしていました。
今回の変更では AJAX 応答のマッピング時に数値のゼロだけを `"0"` に変換します。

数値ゼロの `id` はネイティブテンプレートや選択イベントでも文字列になります。
その他の ID 型、カスタムキーの元の値、検索・ページング、ラベルのエスケープ、
明示的なテンプレートの動作は維持します。ビューやアセットの再公開は不要です。
回帰テストは実際の生成 HTML と同梱 Select2、HTTP リクエスト、SQLite の保存・
絞り込みを検証します。実ブラウザー、実通信、PJAX、更新処理、関連モデル保存
や任意のカスタマイズとの互換性を保証するものではありません。
