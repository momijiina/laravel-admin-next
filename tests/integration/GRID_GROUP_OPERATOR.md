# Grid Group selected-operator retention

## Behavior and upgrade cautions

For an ordinary unnamed Grid, `group('amount', ...)` now renders the hidden
operator index that matches the selected label. With `equal()` followed by
`gt()`, `amount=10&amount_group=1` keeps the `>` condition when the displayed
filter form is submitted without changing its operator. Previously the label
showed the selected operator but the hidden input always submitted index `0`,
so the next request unexpectedly used `=`. Both the text and `datetime()`
presenters use the resolved index. Choosing a different operator continues to
use the existing click handler.

Valid index `0` remains valid. An absent or unknown scalar index retains the
existing first-label/hidden-zero rendering fallback. Query selection is unchanged:
a request with a value but no matching operator still applies no Group condition;
a subsequent form submission uses the rendered zero index. Blank values, SQL
operators, bindings, request names, DOM classes and scripts retain their existing
behavior. This is a rendering repair, not new input validation or a default-query
policy. Array/object operator inputs are not added as a supported API.

Review published/overridden `admin::filter.text` and `admin::filter.datetime`
views and custom Group `variables()` overrides; the views now consume the
`group_index` variable supplied alongside the existing `default` label. Apply
normal compiled-view cache deployment practices and reload open filter forms.
No dependency or PHP/Laravel floor change, JavaScript asset republish, schema
change or data migration is needed. Verify each consuming Laravel application.

## Regression coverage

Use the isolated [integration harness](README.md):

```sh
vendor/bin/phpunit --filter GridGroupOperatorTest
```

The 28 tests cover all six ordinary comparison operators and render the actual Group controls in the real filter form, serialize
native `FormData` in offline jsdom, and replay the generated GET URLs through
Laravel's HTTP kernel and SQLite. They check retained labels/indexes, unchanged
and repeated submissions, a changed operator through the emitted click handler,
query results and SQL bindings, zero, and the existing default/fallback behavior.
Both shipped presenters are covered with jQuery 2.1.4 and 3.7.1. The datetime
fixture uses actual SQLite datetime values and initializes the shipped picker;
it does not automate calendar selections.

## Boundaries

Coverage concerns a single ordinary unnamed Grid. Named Group parameter
namespaces and same-column/multiple-grid script ownership are separate existing
issues and are not repaired here. The datetime presenter uses the shared filter
contract; these checks do not certify picker interaction, timezone conversion or
all date/database formats. Offline DOM and in-process HTTP are not live browser,
network or PJAX coverage. External databases, arbitrary subclasses and downstream
applications require their own verification.

## 日本語

名前なしの通常の Grid で `group('amount', ...)` を使用したとき、選択中の
ラベルと同じ演算子インデックスを hidden 入力に保持します。`equal()` の次に
`gt()` を登録した場合、`amount=10&amount_group=1` の画面で演算子を変更せず
再送信しても `>` 条件が維持されます。従来はラベルだけが選択状態を示し、hidden
入力が常に `0` を送るため、次のリクエストで `=` に切り替わっていました。
通常表示と `datetime()` 表示の両方を修正し、演算子変更時の既存のクリック処理は
変更しません。

有効なインデックス `0`、未指定・未知のスカラー値に対する先頭ラベル／hidden
値 `0` への表示上のフォールバックを維持します。値があっても一致する演算子が
ないリクエストでは Group 条件を適用しない既存仕様も維持し、その後のフォーム
送信では表示された `0` が使われます。空欄、SQL、バインド値、入力名、DOM クラス、
スクリプトの仕様は変更しません。入力検証や既定条件の再設計ではなく、配列や
オブジェクトを演算子として扱う新しい API も追加しません。

公開済み・上書きの `admin::filter.text` / `admin::filter.datetime` ビューと
独自の Group `variables()` を確認してください。両ビューは既存の `default`
ラベルとともに渡す `group_index` を使用します。通常のデプロイ手順でコンパイル済み
ビューを更新し、開いているフォームを再読み込みしてください。依存関係・最低要件の
変更、JavaScript アセット再公開、スキーマ・データ移行は不要です。

検証は単一の名前なし Grid、実際の描画、オフライン DOM のネイティブ FormData、
Laravel HTTP カーネルと SQLite を対象とします。名前付き Group のパラメーター
名前空間や複数 Grid のスクリプト分離は別の既存課題として対象外です。日時ピッカー、
タイムゾーン、ライブブラウザー、ネットワーク、PJAX、外部 DB や任意の独自実装を
保証するものではありません。他の Laravel アプリとの連携は個別に検証してください。
