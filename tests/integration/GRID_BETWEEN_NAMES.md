# Named Grid Between form names

## Behavior and upgrade cautions

`Grid::setName('orders')` with `between('quantity')` now renders
`orders_quantity[start]` and `orders_quantity[end]`. Previously, the controls
omitted the grid prefix, so the named filter silently ignored their submitted
range. Both ordinary and `datetime()` Between views use the corrected names.
The base filter formatter supplies the existing namespace and dotted-column
bracket format before Between appends its two endpoint keys.

Unnamed controls still use `quantity[start]` and `quantity[end]`. Existing
correctly prefixed query URLs, SQL operators and bindings, empty/open bounds,
zero values, value redisplay and reset behavior keep their contracts. Two named
grids emit distinct range names, and each reset link removes its own range
while retaining the other grid's query. Naming a grid after constructing the
filter is also reflected when its controls are rendered.

Review custom selectors, published/overridden filter views or custom filter
name formatters that depend on the old unprefixed HTML `name` attributes.
Hand-written query URLs for a named grid should use the existing prefixed query
API; old unprefixed range URLs remain outside that namespace. This naming correction
does not change HTML IDs, widget scripts, views, filter sanitization or SQL.
No dependency or PHP/Laravel floor change, asset/view republish, schema change
or data migration is required. Reload already open forms to get the corrected
control names. Verify integration with each consuming Laravel application.

## Regression coverage

Run the isolated [integration harness](README.md), or its focused suite:

```sh
vendor/bin/phpunit --filter GridBetweenNameTest
```

The 57 cases cover:

- End-first query strings and unchanged native resubmission, including negative,
  zero-width and genuinely reversed bounds; see the [bound-order repair](GRID_BETWEEN_BOUND_ORDER.md).
- Direct condition checks retain named keys and the unmodified display value.
- Named and unnamed paired, lower-only, upper-only, zero-width, empty and
  reversed ranges through both shipped Between views.
- Actual rendered controls filled in offline jsdom, native `FormData` and
  `URLSearchParams`, independently compared with Symfony form submission.
- Those native form URLs through Laravel's HTTP kernel and SQLite, including
  exact row results, paginator totals, count/row SQL operators/bindings, redisplayed
  endpoint values, actual reset links and unchanged stored rows. A zero count
  correctly prevents a row query from executing.
- The existing manually prefixed query API and ignored unprefixed input.
- Separate forms for two named grids, a request combining their independently
  emitted payloads, and reset isolation in both directions with unrelated
  query context preserved.
- Ordinary, dotted and multi-level dotted names, late grid naming and unchanged
  IDs/classes through both views. Dotted cases test formatting only.

The fixture restores the package static values it changes. It uses the existing
PHPUnit 11/12-compatible attribute data providers and installed Node/jsdom test
dependencies; no production class is stubbed.

## Boundaries

The datetime-view cases deliberately use a numeric SQLite column to isolate
shared form naming. They do not execute the datetime picker or establish date
parsing/time-zone behavior. Dotted-name formatting does not certify relation
queries. The two-grid test combines form payloads explicitly; it does not claim
that ordinary native GET submission or PJAX preserves another form's state.
This is in-process HTTP and offline DOM coverage, not live browser/PJAX/network,
all databases or arbitrary application certification. Same-column DOM IDs and
widget ownership across grids are unchanged and outside this repair.

## 日本語

`Grid::setName('orders')` の `between('quantity')` は、入力名を
`orders_quantity[start]` / `orders_quantity[end]` として生成します。
従来は Grid 名の接頭辞が欠け、表示されたフォームから送信しても範囲条件が無視
されていました。通常表示と `datetime()` 表示の両方を対象とし、共通の名前整形
処理を利用してから始点・終点のキーを追加します。

名前なし Grid の入力名、既存の接頭辞付きクエリ API、SQL の比較とバインド値、
空欄・片側の範囲、ゼロ、再表示とリセットの仕様を維持します。複数の名前付き
Grid は異なる入力名を生成し、リセットは他方の範囲と無関係なクエリを保持します。
フィルター作成後に指定した Grid 名も描画時に反映します。

旧入力名に依存する独自セレクター、公開済み・上書きビュー、名前整形の独自実装を
確認してください。手書き URL は既存の接頭辞付きクエリ形式を使用してください。
旧形式の接頭辞なし URL を受け付ける変更ではありません。HTML の ID、ウィジェット
スクリプト、ビュー、入力の絞り込み処理、SQL は変更しません。依存関係・最低要件の
変更、アセット・ビューの再公開、スキーマ・データ移行は不要です。開いたままの
フォームは再読み込みして新しい入力名を反映してください。他の Laravel アプリ
との連携はアプリごとに検証してください。

57 ケースで、実際のフォームのネイティブ FormData と Symfony の送信結果、
HTTP/SQLite の行・SQL・再表示・リセット、名前付き・名前なし・ゼロ・片側・空欄・
逆順範囲、複数 Grid とドット区切りの名前整形を検証します。日時表示のケースは
数値列を使うため、日時ピッカー・日付解析・タイムゾーンの検証ではありません。
ドット区切りは名前整形のみを対象とし、リレーションクエリを保証しません。
複数フォームの送信データは明示的に結合し、ブラウザーや PJAX による他方の状態維持
は検証していません。同名列の DOM ID やウィジェット所有権は従来どおりです。
