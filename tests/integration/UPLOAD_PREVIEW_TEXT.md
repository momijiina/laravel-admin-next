# Literal upload preview captions and URLs

Generated saved-file previews for `File`, `Image`, `MultipleFile`, and
`MultipleImage` now preserve literal ampersands, entity-looking strings such as
`&copy;`, quotes, and HTML-looking filename text. The input caption, preview
footer, download filename, and zoom caption retain the filename text; preview
and download URL attributes retain the URL returned by the configured disk.
Previously the shipped fileinput 4.5.2 widget interpreted generated captions as
HTML and inserted URLs into quoted HTML attributes without encoding them. A
saved path containing `&copy; <b>draft "quote".png` changed visible text, created
a formatting element, and truncated preview/download URLs at the quote.

The PHP fields now encode generated caption/download metadata at the widget
boundary. Generated preview URLs use the effective `initialPreviewConfig` type
and `previewAsData` setting, falling back to `initialPreviewFileType` and
`initialPreviewAsData`. The widget already escapes `text` preview data, so that
case keeps its original string and avoids a second encoding layer. Other
preview templates receive HTML-encoded generated URLs. The existing text/HTML
previews display the URL string; this change does not fetch file contents.

## Compatibility and overrides

Stored values, public `objectUrl()` results, related-model keys, and deletion
payloads retain their original values. Upload preparation, filesystem writes,
image transformations, and sorting are unchanged. Generated metadata inside
the initializer now contains the HTML entities required by fileinput's
templates; code that reads that internal metadata should account for this.

Explicit `options()` configuration retains its existing precedence. In
particular, caller-provided `initialPreviewConfig` and MultipleFile
`initialPreview` HTML remain caller-controlled; their custom captions/templates
are not automatically encoded. File/Image continue to supply their generated
preview through the input's `data-initial-preview` attribute, which the widget
gives precedence over an `initialPreview` option. Custom preview rendering
remains responsible for its own HTML handling.

Subclasses should review overrides of `preview()`, `initialCaption()`, and
`setupPreviewOptions()`. The default render methods now establish preview
configuration before calling `preview()` so the new protected
`formatPreviewUrl($url, $index = 0)` helper can use the effective template.
`setupPreviewOptions()` encodes generated `caption` and `downloadUrl` strings;
subclasses that already encoded those returned values should remove that extra
encoding. Repeated rendering of the same field does not add another layer.

Reload forms that are already open to obtain the new PHP-generated metadata.
No asset publication or view-template update is required.

日本語: 保存済みファイルのプレビューで、ファイル名の引用符・`&copy;` などの
文字列・HTML に見える文字を、そのまま表示するための修正です。保存パスや
削除キーは変更しません。開いたままのフォームは再読み込みしてください。
アセットの再公開やビューの更新は不要です。独自の `preview()`、
`initialCaption()`、`setupPreviewOptions()` を使っている場合は、呼び出し順と
二重エスケープの有無を確認してください。明示したプレビュー設定と独自
テンプレートの HTML 処理は利用側の責任です。画像の title/alt のスラッグ変換や、
下記の特殊トークン・区切り文字に関する既存の制約は残ります。

## Scope and remaining widget behavior

The vendored plugin is unchanged. Its separate template replacement and scalar
preview-delimiter behavior still affects filenames/URLs containing tokens such
as `$&`, `{data}`, or `*$$*`; this change does not promise preservation of every
possible filename. The plugin still derives preview image titles/alt text with
its slug function; these attributes can contain slugged entity spellings from
the encoded caption metadata. Newly selected filenames retain the plugin's
existing slug behavior. Explicit custom templates, preview HTML content, URL
scheme policy, percent-encoding of storage paths, file deletion transport, and
new-upload UI are outside this regression.

## Verification

`UploadPreviewTextTest.php` renders the actual PHP fields and views and executes
the shipped jQuery 2.1.4, fileinput 4.5.2, and Bootstrap modal in jsdom. The main
four-field cases also run with jQuery 3.7.1. The fixture opens the native zoom
button and checks exact DOM text, URL attributes, download filenames, and the
absence of elements parsed from generated filename text. It preserves genuine
widget initialization and handlers; the only host shim supplies jsdom's
missing object-URL API.

Coverage includes ordinary and entity-looking names, quotes, Unicode, saved
image/text/HTML/PDF/audio/video/other previews, an absolute URL with query
parameters, disabled downloads, empty fields, repeated rendering, sparse array
keys, related-model path columns, and explicit preview/configuration overrides.
PHP production diagnostics fail the render fixture.

Run with the [integration consumer setup](README.md):

```sh
composer test -- --filter UploadPreviewTextTest
```

The unchanged-production baseline on PHP 8.4.27 / Laravel 12.70.1 produced 24
failures and 11 passing controls across the 35 tests. After the change, the
focused suite passes **35 tests / 530 assertions** on both Laravel 12.70.1 and
13.38.0 with PHP 8.4.27. The related
`FileUploadFailureTest|MultipleFileSortTest|WidgetOptionSerializationTest` filter
passes **37 tests / 365 assertions** on each framework. These are offline DOM
checks with real Laravel rendering, not live browser/network downloads or OS
file-picker coverage. The existing [failed-upload](FILE_UPLOAD_FAILURE.md),
[multiple-file sorting](MULTIPLE_FILE_SORT.md), and
[image processing](../../IMAGE_MIGRATION.md) regressions retain their separate
coverage and limits.
