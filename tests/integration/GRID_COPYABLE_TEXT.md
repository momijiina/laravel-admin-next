# Grid copyable literal text

## Behavior and upgrade cautions

`copyable()` continues to copy the original column value, independently of an
optional `display()` transformation. Quotes, entity-looking text such as `&amp;`,
JSON-looking strings such as `[1,2]`, the literal string `null`, Unicode, empty
strings and LF/CRLF/CR line breaks now survive the transport to native DOM
selection. Previously, raw attribute interpolation could truncate quotes or
decode entities, jQuery data conversion changed some strings, and the temporary
single-line input removed line breaks.

The private `data-content` attribute now contains an HTML-escaped JSON string.
This retains control characters through both the Grid's server-side HTML
parse/serialize step and the browser HTML parser. The click handler reads the
attribute without jQuery data conversion and decodes that string. An off-screen,
whitespace-preserving text element and native Range select the original text
before the existing `document.execCommand('copy')` call. The temporary element
and selection are then removed. The visible copy icon, classes, formatted label,
delegated Grid binding and `Copied!` tooltip retain their existing contracts.

PHP NULL and false still convert to empty text, true to `1`, and numeric and
stringable originals retain PHP string conversion. Arrays still raise a
conversion warning and non-stringable objects still fail: JSON transport does
not introduce an array/object copy API. SQL NULL is distinct from the literal
string `null`. This is text transport, not arbitrary binary-data preservation;
invalid UTF-8 is replaced rather than supported as binary text.

Review custom Copyable overrides or scripts that read `data-content`: its
internal representation is now a JSON string, so use raw attribute access and
JSON decoding rather than treating it as unencoded text. Reload open grids after
upgrading. No public method, dependency/minimum version, asset/view republish,
schema change or data migration is required. Verify integration in each consuming
Laravel application; this bounded fix does not certify every version admitted
by the Composer constraints.

## Regression coverage

Use the isolated [integration harness](README.md), or run:

```sh
vendor/bin/phpunit --filter GridCopyableTextTest
```

The tests render real Grids through the Laravel HTTP kernel and SQLite, then
execute the actual emitted ready wrapper and delegated click handler in offline
jsdom. They load shipped jQuery 2.1.4 and modern jQuery 3.7.1 independently, with
actual Bootstrap and iCheck. At the clipboard command boundary they inspect the
native selected text, the temporary element and cleanup. Cases cover literal
strings, unchanged database values, SQL NULL, scalar/stringable characterization,
formatted display, existing prior selection, repeated clicks, multiple controls,
Grid-scoped binding and the existing tooltip behavior when the command returns
false. There is no Copyable Cancel dialog or asynchronous approval flow.

## Limits

DOM selection is the tested result, not successful browser/OS clipboard access.
An empty selection does not establish that an existing OS clipboard is cleared.
`execCommand` remains the existing clipboard mechanism; browsers may reject it
or normalize clipboard line endings. The existing success tooltip does not
certify a clipboard write, and exception/permission handling is not redesigned.
No live browser/layout/PJAX/network, real clipboard, arbitrary application,
non-SQLite database or broad security assessment is claimed.

## 日本語

`copyable()` は、`display()` による表示の加工とは別に、元の列値をコピーする
既存の API を維持します。引用符、`&amp;` のような文字列、`[1,2]` のような
JSON 風の文字列、文字列 `null`、Unicode、空文字、LF・CRLF・CR を DOM の選択
境界まで保持します。従来は属性への直接埋め込み、jQuery の型変換、単一行 input
によって、一部の文字や改行が失われていました。

内部の `data-content` は HTML エスケープ済みの JSON 文字列になります。
Grid のサーバー側 HTML 解析・再出力とブラウザーの HTML 解析を通過した後に、
生の属性値を取得して JSON として復元します。画面外のテキスト要素をネイティブ
Range で選択し、既存の `document.execCommand('copy')` を呼び、仮要素と選択を
除去します。コピーアイコン、クラス、加工済み表示、Grid 内のイベント登録と
`Copied!` ツールチップは維持します。

PHP の NULL と false は空文字、true は `1`、数値と文字列化可能な値は従来の
PHP 文字列変換を維持します。配列の変換警告と文字列化できないオブジェクトの失敗も
維持し、配列・オブジェクトのコピー API は追加しません。SQL NULL と文字列
`null` は区別します。任意のバイナリを扱うものではなく、不正な UTF-8 は置換されます。

独自の Copyable や `data-content` を参照するスクリプトを確認してください。
内部属性は JSON 文字列のため、生の属性取得と JSON 復元が必要です。更新後は Grid を
再読み込みしてください。公開メソッド、依存関係・最低要件、アセット・ビューの
再公開、スキーマやデータ移行の変更は不要です。他の Laravel アプリとの連携は
個別に検証してください。

実 HTTP/SQLite とオフライン DOM、両 jQuery と実 Bootstrap/iCheck を使い、
選択テキスト、表示・保存済み値の不変性、NULL・スカラー、既存の選択、連続クリック、
複数ボタン、Grid の範囲、後始末を検証します。OS クリップボードへの書き込み成功や、
空文字による既存クリップボードの消去は保証しません。ブラウザーが操作を拒否したり
改行を変換する可能性があり、ツールチップだけでは成功を判断できません。クリップ
ボード API・権限・例外処理の再設計、実ブラウザー・レイアウト・PJAX・全 DB・任意の
アプリ・広範なセキュリティ検証は対象外です。キャンセル画面は存在しません。
