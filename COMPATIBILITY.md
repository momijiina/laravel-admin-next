# Compatibility Policy

- Preserve existing laravel-admin APIs where practical
- Avoid breaking changes unless necessary, and document them when required
- Aim for compatibility and integration with other Laravel applications; verify
  specific application/version combinations before claiming support
- Separate dependency declarations, focused checks, and full runtime support

## Doctrine DBAL support boundary

The production requirement remains `doctrine/dbal: ^2.13.9 || ^3.10.6`.
DBAL 4 is not supported; widening the Composer constraint alone does not migrate
the existing-PDO adapter or schema metadata APIs. Keep normal Composer checks
enabled and resolve within the declared range. Full Laravel 12/13 consumers use
DBAL 3; retained DBAL 2 coverage includes separately resolved Illuminate components
and representative older frameworks, not every framework/driver combination.
See the [generator compatibility boundaries](tests/integration/RESOURCE_GENERATOR.md#upgrade-and-compatibility-boundaries).

日本語: 本番依存は引き続き `^2.13.9 || ^3.10.6` で、DBAL 4 は非対応です。
Composer の制約変更だけでは既存 PDO アダプターやスキーマ API は移行できません。
通常の Composer 検査を有効にしたまま宣言範囲内で解決してください。Laravel 12/13
全体では DBAL 3 を使用し、DBAL 2 の限定的な検証は全組み合わせの保証ではありません。

## Grid LIKE zero searches (2026-10-07)

Grid `like()`, `startsWith()` and `endsWith()` now apply scalar string/integer zero
instead of silently omitting the condition. `ilike()` shares the guard. Other
empty values and array handling are unchanged. Review custom `Like::condition()`
overrides or reliance on zero disabling a filter. No dependency/minimum-version,
schema/data migration or asset/view republish is required. See the
[focused contract and verification limits](tests/integration/GRID_LIKE_ZERO.md).
Verify compatibility with each consuming Laravel application separately.

日本語: Grid の LIKE 系検索で文字列・整数のゼロを条件として適用します。
他の空値・配列の処理は維持します。独自の条件生成やゼロで検索を解除する使い方を
確認してください。依存関係・最低要件、移行、アセット・ビューの再公開は不要です。
検証範囲は上記ガイドを参照し、他の Laravel アプリとの連携は個別に確認してください。

## Literal Tags separators (2026-10-07)

Custom `Tags::separators()` characters are now escaped and JSON-serialized for
JavaScript regular expressions. Backslashes and line breaks no longer break
initialization; brackets, carets and hyphens no longer prevent tag creation or
match/remove ordinary tag text. Review custom separator lists and overridden
initializers/callbacks that may rely on the old regex interpretation, and reload
open admin forms after deployment. Defaults, trimming order and comma storage
remain unchanged; no asset or database migration is needed. See the
[regression contract, upgrade cautions and validation limits](tests/integration/TAGS_SEPARATORS.md),
and verify the change in each consuming Laravel application.

日本語: `Tags::separators()` の区切り文字をエスケープし、JSON 経由で正規表現へ
渡すようにしました。バックスラッシュ・改行による初期化エラーや、角括弧・
キャレット・ハイフンによる誤判定とタグ文字列の欠落を防ぎます。従来の正規表現
としての解釈に依存する設定・独自コールバックは確認し、デプロイ後は管理画面を
再読み込みしてください。既定の区切り文字・トリム順序・カンマ区切り保存は
変更せず、アセット更新・DB 移行も不要です。利用先の Laravel アプリごとに
確認してください。

## Listbox remote literal options (2026-10-07)

URL-option Listbox fields now create native options with literal IDs and labels,
preventing quotes and HTML/entity-looking text from changing values on unchanged
submissions. Current selection and native reset defaults are preserved. Review
custom `loadRemoteOptions()` overrides and endpoints returning HTML or pre-escaped
labels: remote labels now remain literal plain text. Reload forms after updating;
no asset/view republish, dependency/minimum-version, schema or data migration is
required. Old-input precedence, static Blade rendering and comma-separated ID
transport are unchanged. See [the focused contract, upgrade cautions and validation
limits](tests/integration/LISTBOX_REMOTE_OPTIONS.md), and verify each consuming
Laravel application separately.

日本語: URL から選択肢を取得する Listbox で、ID・ラベルをそのまま持つネイティブ
選択肢を作り、引用符・HTML・実体参照のような文字列による意図しない値の変化を
防ぎます。初期選択とリセット時の既定値は維持します。独自の `loadRemoteOptions()`
と HTML・エスケープ済みラベルを返す API を確認し、更新後はフォームを再読み込み
してください。リモートラベルはプレーンテキストになります。アセット・ビューの
再公開、依存関係・最低要件、スキーマ・データ移行は不要です。旧入力の優先順位、
静的 Blade 表示、カンマ区切りの ID 形式は変更しません。検証範囲と注意事項は上記の
専用ガイドを参照し、他の Laravel アプリとの連携は個別に確認してください。

## Grid Group selected-operator retention (2026-10-07)

Ordinary unnamed Grid Group filters now retain the selected operator in the
hidden input of both text and datetime presenters. Submitting an unchanged
form no longer resets a nonzero operator to the first condition. Valid zero,
first-label/hidden-zero fallback for absent or unknown scalar indexes, and query
selection semantics remain unchanged. Review overridden/published filter views
and custom Group `variables()` implementations, refresh compiled views through
normal deployment practices, and reload open forms. No dependency/floor, asset
republish, schema or data migration is required. Named Group namespaces and
multi-grid script isolation remain outside this repair. Verify each consuming
application. See the [selection contract, upgrade cautions and limits](tests/integration/GRID_GROUP_OPERATOR.md).

日本語: 名前なしの通常の Grid Group で、選択中の演算子を通常・日時表示の
hidden 入力に保持し、未変更の再送信で先頭の条件に戻る問題を修正します。有効な
ゼロ、未指定・未知のスカラー値に対する先頭ラベル／hidden 値ゼロへの表示と、
条件適用の既存仕様は維持します。公開済み・独自のフィルタービューと Group
`variables()` を確認し、通常の手順でコンパイル済みビューを更新して、フォームを
再読み込みしてください。依存関係・最低要件の変更、アセット再公開、スキーマ・
データ移行は不要です。名前付き Group の名前空間と複数 Grid のスクリプト分離は
対象外です。他の Laravel アプリとの連携は個別に検証してください。

## Grid inline Checkbox integer IDs (2026-10-07)

Grid `checkbox($options)` now checks stored integer IDs when opening its inline
popover, including zero and mixed integer/string arrays. Adding a choice no
longer silently drops the existing integer choices. Only a local comparison
array is string-normalized; strict matching, original JSON metadata, display,
callbacks and string-valued submissions retain their existing behavior. Review
published/custom `admin::grid.inline-edit.checkbox` views, apply normal compiled
view-cache deployment practices and reload open grids. No dependency/floor,
asset republish, schema or data migration is required. Previously lost choices
cannot be reconstructed. Verify each consuming Laravel application. See the
[selection contract, regression coverage and limits](tests/integration/GRID_INLINE_CHECKBOX.md).

日本語: Grid の `checkbox($options)` で、ポップオーバーを開く際に整数の ID
を選択済みとして表示します。ゼロや整数・文字列の混在にも対応し、別の選択肢を
追加しただけで既存の整数 ID が保存対象から落ちる問題を修正します。比較用の
ローカル配列だけを文字列化し、厳密な一致判定、元の JSON、表示、コールバックと
文字列での送信は維持します。公開済み・独自の対象ビューを確認し、通常の手順で
コンパイル済みビューを更新して、Grid を再読み込みしてください。依存関係・
最低要件の変更、アセット再公開、スキーマ・データ移行は不要です。過去に失われた
選択肢は復元できません。他の Laravel アプリとの連携は個別に検証してください。

## Grid editable Select callback sources (2026-10-06)

Legacy Grid `editable('select', $closure)` now HTML-escapes its per-row JSON
`data-source` attribute. Apostrophes no longer truncate the options, and literal
HTML entities remain literal keys/labels instead of being decoded or corrupting
the JSON. Static option arrays, callback binding/arguments, JSON source shape,
selection, update payloads and other editor types keep their existing behavior.
Review custom `Editable::select()` / `addAttributes()` overrides that inspect or
re-escape generated source attributes, and reload open grids. No dependency/floor,
asset/view republish, schema or data migration is needed. This does not redesign
source validation, custom attributes or X-editable/PJAX lifecycle behavior.
Verify each consuming Laravel application. See the
[source contract, regression coverage and limits](tests/integration/GRID_EDITABLE_SELECT_SOURCE.md).

日本語: Grid の従来型 `editable('select', $closure)` で、行別の選択肢 JSON を
`data-source` 属性向けにエスケープします。アポストロフィによる属性の途中切れを
防ぎ、実体参照風のキー・ラベルを文字列のまま保持します。固定配列、クロージャの
束縛・引数、JSON 構造、選択・送信形式と他の編集型は維持します。生成された属性を
参照・再エスケープする独自の `Editable::select()` / `addAttributes()` を確認し、
開いた Grid を再読み込みしてください。依存関係・最低要件の変更、アセット・ビューの
再公開、スキーマ・データ移行は不要です。入力検証、独自属性、X-editable / PJAX
のライフサイクルを再設計する修正ではありません。他の Laravel アプリでは個別に
検証してください。

## Grid copyable literal text (2026-10-06)

Grid `copyable()` now retains the original literal text through native DOM
selection, including quotes, entity/JSON-looking strings, the string `null`,
Unicode, empty strings and LF/CRLF/CR line breaks. Formatted display stays
independent. The private `data-content` attribute is now an escaped JSON string;
review custom Copyable overrides and attribute-reading scripts, and reload open
grids. PHP NULL/false still become empty text and existing scalar conversions
remain; this does not add an array/object or binary-data copy API. Public methods,
clipboard command and tooltip behavior remain unchanged. No dependency/floor,
asset/view republish, schema or data migration is required. Verify each consuming
Laravel application. The offline native-selection tests do not prove OS clipboard
writes, empty-selection clearing or browser line-ending behavior. See the
[copy-text contract, upgrade cautions and limits](tests/integration/GRID_COPYABLE_TEXT.md).

日本語: Grid の `copyable()` で、引用符・実体参照風／JSON 風の文字列・文字列
`null`・Unicode・空文字・LF/CRLF/CR を DOM の選択まで保持します。加工済みの
表示は維持します。内部の `data-content` はエスケープ済み JSON 文字列になるため、
独自の Copyable と属性参照を確認し、Grid を再読み込みしてください。PHP NULL・
false の空文字化と従来のスカラー変換を維持し、配列・オブジェクト・バイナリの API は
追加しません。公開メソッド・コピーコマンド・ツールチップは変更しません。依存関係・
最低要件・アセット／ビューの再公開・スキーマ／データ移行の変更は不要です。他の
Laravel アプリでは個別に確認してください。オフラインの選択検証は、OS への
書き込み成功・空文字による消去・ブラウザーの改行動作を保証しません。

## Named Grid filter prefix preservation (2026-10-06)

Named Grid filters now remove exactly one leading Grid namespace from matching
request keys. For `Grid::setName('user')`, `equal('user_id')` keeps the column
`user_id` after receiving `user_user_id`; repeated or interior `user_` text in
the column is preserved. Previously, all occurrences were removed, which could
apply a different filter, ignore the intended condition, or collapse distinct
inputs. Existing namespace matching, unnamed inputs, empty/zero handling, input
shapes, HTML names and SQL/filter APIs are unchanged. Review custom
`sanitizeInputs()` overrides and query URLs that relied on the incorrect aliasing;
regenerate the intended query rather than relying on the former result. No new
API, dependency/floor, asset/view republish, schema or data migration is needed.
Verify integration with each consuming Laravel application. See the
[prefix contract, regression coverage and limits](tests/integration/GRID_FILTER_PREFIX.md).

日本語: 名前付き Grid のフィルター入力で、先頭の Grid 接頭辞を 1 回だけ除去
します。Grid 名が `user`、列が `user_id` の場合、送信キー `user_user_id` を
正しく `user_id` に戻し、列名内の繰り返しや途中の `user_` は保持します。従来は
全箇所を除去し、別の条件への誤適用・条件の無視・複数入力の衝突が起こり得ました。
名前空間の照合、名前なし入力、空欄・ゼロ、配列構造、HTML の入力名、SQL・
フィルター API は維持します。独自の `sanitizeInputs()` と誤った別名変換に依存
する URL を確認し、本来の条件でクエリを生成し直してください。新 API・依存関係・
最低要件の変更、アセット・ビューの再公開、スキーマ・データ移行は不要です。
他の Laravel アプリとの連携は個別に検証してください。

## Nullable styled Radio values (2026-10-06)

RadioButton and RadioCard now leave a NULL effective value unselected instead of
silently selecting zero. Checked inputs and active labels use the same selection.
Non-NULL loose comparisons, old-input precedence, intentional scalar/closure
defaults and the additive label-based `checked()` fallback are preserved.
An unselected radio group is omitted from submission, preserving existing values
on update; this is not a NULL-clearing API. Reconcile overridden/published
`admin::form.radiobutton` and `admin::form.radiocard` views, refresh compiled views
through the normal deployment process and reload open forms. No new API,
dependency/floor, JavaScript asset republish, schema or data migration is needed.
Previously overwritten values cannot be reconstructed. See the
[selection contract and verification limits](tests/integration/STYLED_RADIO_NULL.md).

日本語: RadioButton / RadioCard で実効値が NULL の場合にゼロが自動選択される
問題を修正し、入力の checked とラベルの active を同じ判定に揃えます。NULL 以外の
緩い比較、old input の優先、明示的な値・クロージャの既定値、ラベルによる
`checked()` の追加選択は維持します。未選択の項目は送信されず、更新時には保存済み
の値を保持します。NULL に消去する API の追加ではありません。上書き・公開済みの
両ビューを確認し、通常の手順でコンパイル済みビューを更新して、フォームを
再読み込みしてください。API・依存関係・最低要件の変更、JS アセットの再公開、
スキーマ・データ移行は不要ですが、過去の誤上書きは復元できません。他の Laravel
アプリとの互換性は個別に検証してください。

## Configured Grid quick-search keys (2026-10-06)

Quick-search forms now use the bound Grid's configured search key for the input
name, redisplayed value and action-query removal. Standalone tools fall back to
`Grid::$searchKey`; subclass keys and changes before rendering are respected.
Default-key behavior, query execution, source precedence, parsing, SQL and the
existing falsey/zero short-circuit remain unchanged. Review custom selectors,
overridden quick-search renderers/views and URLs that expect `__search__` despite
a custom key; reload open forms. No new API, dependency/floor, asset/view republish,
schema or data migration is needed. See the
[search-key contract and verification limits](tests/integration/GRID_QUICK_SEARCH_KEYS.md).

日本語: クイック検索の入力名・再表示・action のクエリ除去を、関連付けられた
Grid の検索キーに揃えます。単独ツールは `Grid::$searchKey` を使い、サブクラスや
描画前のキー変更にも対応します。既定キー、検索処理・取得順序・構文・SQL・
falsey 値（文字列ゼロを含む）の既存動作は維持します。独自キーでも旧入力名を
前提とするセレクター、上書き描画・ビュー、URL を確認し、フォームを再読み込み
してください。新 API・依存関係・最低要件の変更や、再公開・データ移行は不要です。
Grid ごとの名前空間追加や、古い Laravel での実行保証ではありません。他の
Laravel アプリとの互換性は個別に検証してください。

## Named Grid Between form names (2026-10-06)

Between controls now use their named Grid's existing input namespace, including
both ordinary and datetime views. Submitted ranges are applied instead of being
silently ignored. Unnamed controls, correctly prefixed query URLs, dotted-name
formatting, SQL, IDs and scripts retain their existing behavior. Review custom
selectors, overridden filter views and name formatters that depend on the old
unprefixed HTML names; reload open forms after updating. No dependency/floor,
asset/view republish, schema or data migration is needed. See the
[form-name contract and verification limits](tests/integration/GRID_BETWEEN_NAMES.md).

日本語: Between の通常・日時表示で、名前付き Grid の既存の接頭辞を入力名に
反映し、送信した範囲条件が無視される問題を修正します。名前なし Grid、接頭辞付き
クエリ URL、ドット区切りの名前整形、SQL、ID とスクリプトの既存動作を維持します。
旧入力名に依存する独自セレクター、上書きビュー、名前整形処理を確認し、更新後は
開いたままのフォームを再読み込みしてください。最低要件・依存関係の変更、
アセット・ビューの再公開、スキーマ・データ移行は不要です。日時ピッカーや
複数 Grid の DOM ID を変更するものではありません。他の Laravel アプリとの
互換性はアプリごとに検証してください。

## Modern Eloquent Attribute dispatch (2026-10-06)

Grid `column()` and Grid/Show shorthand now recognize native Eloquent
`Illuminate\Database\Eloquent\Casts\Attribute` methods before relation dispatch,
including protected/public methods and setter-only attributes. Legacy getter
detection remains first; names, labels, native Eloquent read/serialization
behavior, Grid macros and real single relations keep their existing behavior.
The guarded `hasAttributeMutator()` check preserves the previous fallback when that framework capability is absent.
Review custom Grid/Show dispatch overrides. Computed Grid values still need the
application's normal Eloquent appends/serialization configuration. No PHP or
Laravel floor, dependency, asset, view, schema or data migration changes are
required. See the [dispatch contract and verification limits](tests/integration/MODERN_ATTRIBUTE_DISPATCH.md).

日本語: Grid の `column()` と Grid/Show の短縮記法は、リレーション判定より前に
Eloquent 標準の `Attribute` メソッドを属性として認識します。protected/public と
setter のみの属性も対象です。従来の getter の判定を先に行い、名前・ラベル、
Eloquent 標準の読み取り・シリアライズ、Grid マクロと実際の単一リレーションの
既存動作を維持します。
`hasAttributeMutator()` がない環境では従来の分岐を使用します。独自の Grid/Show
振り分け処理を確認してください。Grid の計算属性には引き続きアプリ側の通常の
appends・シリアライズ設定が必要です。最低要件・依存関係の変更、アセット・ビューの
再公開、スキーマ・データ移行は不要です。入れ子のリレーションは変更しません。
古いフレームワークでの実行や、他の Laravel アプリとの互換性を一律に保証するもの
ではありません。

## Mixed Grid inline editors (2026-10-05)

Grid inline Input, Textarea, Datetime, Select, MultipleSelect, Radio and Checkbox
submit handlers now stay with their resource and payload name. A later editor
no longer replaces another field's value extractor, display callback or save URL.
Existing APIs, values and save/display behavior remain unchanged. Reconcile both
overridden `grid/inline-edit/comm.blade.php` and `partials/submit.blade.php` views
together. Fully reload open admin pages after deployment so old generic submit
handlers cannot survive alongside the new scoped bindings, including across
PJAX updates. Refresh stale compiled views through the normal deployment process;
no JavaScript asset republish or data migration is needed. Previously overwritten
values are not reconstructed. See the
[regression contract and upgrade cautions](tests/integration/GRID_INLINE_MIXED_EDITORS.md).

日本語: Grid のインライン Input・Textarea・Datetime・Select・MultipleSelect・Radio・
Checkbox の送信処理をリソースと送信項目名ごとに分離し、後続のエディターによる
値の取得・表示更新・送信先の上書きを防ぎます。既存 API、値と保存・表示の仕様は
維持します。上書きした共通ビューと送信 partial は必ず一緒に反映してください。
デプロイ後は管理画面をページ全体で再読み込みし、PJAX などで旧送信処理を残さないで
ください。古いコンパイル済みビューも通常の手順で更新してください。JS アセットの
再公開やデータ移行は不要ですが、過去の誤った上書きは復元しません。Popover 全体の
再初期化や複数 Grid の同名フィールド識別を修正するものではありません。他の Laravel アプリとの連携は
アプリごとに検証してください。

## Action modal retry recovery (2026-10-05)

Action form Submit buttons now reset after AJAX failure or confirmation
cancellation before sending a request. Entered values and existing error notices
are preserved. Once confirmed, a pending request retains ownership of the button
state even if its confirmation is dismissed. Existing successful modal close,
button reset and navigation behavior stays unchanged. This PHP-generated script
change needs no asset/view republish or data migration; check overridden Action
Form interactors. See the [retry contract and cautions](tests/integration/ACTION_MODAL_RETRY.md).

日本語: Action フォームの通信失敗、または送信前の確認キャンセル後に送信ボタンを
復帰させ、入力を保持したまま再試行できます。送信開始後に確認画面を閉じても、
通信が完了するまではこの修正によってボタンを解除しません。成功時の画面を閉じる
処理・ボタン復帰・遷移は維持します。ビュー・アセットの再公開やデータ移行は不要
ですが、独自の Form interactor は確認してください。通信失敗時のサーバー側保存の
有無や重複処理を保証する修正ではなく、他の Laravel アプリとの互換性は個別に
検証してください。

## Dependent Select loader isolation (2026-10-05)

Each `Select::loads()` initializer now keeps its target fields, URLs and refresh
callback in its own function scope. Independent loaders in the shared Admin
ready callback no longer overwrite one another's configuration. Single-loader
selection/clearing and the existing ID and AJAX contracts remain unchanged.
No asset/view change or republish is needed; review overridden PHP `loads()`
implementations. See the [coverage and boundaries](tests/integration/SELECT_LOADS_ISOLATION.md).

日本語: `Select::loads()` ごとに初期化スクリプトの変数を分離し、後続のローダーが
先行ローダーの対象・URL・更新処理を上書きする問題を防ぎます。単独利用時の選択・
クリア、ID 形式と AJAX の既存仕様は維持します。ビュー・アセットの変更や再公開は
不要ですが、PHP の `loads()` を独自実装している場合は確認してください。
入れ子のフィールド識別や非同期応答順序を保証する変更ではありません。

## Nullable Grid inline choices (2026-10-05)

Grid inline Select/Radio now render a blank label for strict NULL instead of
failing the entire table. Non-NULL lookups and per-row options callbacks remain.
This PHP-only fix requires no asset/view republish or data migration; review
custom displayer overrides. Existing popover defaults and loose comparisons are
unchanged, and no NULL clearing protocol is added. See the
[regression contract and upgrade cautions](tests/integration/GRID_INLINE_NULLABLE_CHOICES.md).

日本語: Grid のインライン Select/Radio は NULL を空欄表示し、一覧全体の描画失敗を
防ぎます。NULL 以外の検索と行ごとのコールバックは維持します。再公開・データ移行は
不要ですが、独自表示クラスは確認してください。初期選択・緩い比較・保存形式は変更
せず、NULL クリア用 API は追加しません。他の Laravel アプリとの連携は個別に検証してください。

## Current maintenance summary

Updated 2026-10-07. This index describes the changes merged through
[PR #95](https://github.com/momijiina/laravel-admin-next/pull/95), at
[`4be2198`](https://github.com/momijiina/laravel-admin-next/commit/4be2198846d6a740d71896ea45003a6bad8e8cf3).
Use the linked guides for upgrade steps and each regression's precise boundary.
Historical test totals below remain evidence for their own revisions, not
current suite totals or validation of subsequent changes.

### Recent merged changes: PRs #87–#95

- **Grid/Show Attribute dispatch ([PR #87](https://github.com/momijiina/laravel-admin-next/pull/87)):**
  native Eloquent Attribute methods are recognized before relation dispatch.
  Review Grid/Show overrides and normal Eloquent appends/serialization settings;
  this does not change Form discovery or saving. See the
  [dispatch contract](tests/integration/MODERN_ATTRIBUTE_DISPATCH.md).
- **Grid query names ([PR #88](https://github.com/momijiina/laravel-admin-next/pull/88),
  [PR #89](https://github.com/momijiina/laravel-admin-next/pull/89),
  [PR #92](https://github.com/momijiina/laravel-admin-next/pull/92)):**
  Between controls use their named Grid's prefix, quick-search rendering follows
  the configured key, and filter normalization strips exactly one leading prefix.
  Review selectors, overridden renderers/name formatters and hand-written URLs;
  reload open forms and regenerate queries that relied on incorrect aliasing.
  SQL/filter operators, quick-search string-zero behavior and DOM IDs are unchanged.
  See [Between](tests/integration/GRID_BETWEEN_NAMES.md),
  [quick-search](tests/integration/GRID_QUICK_SEARCH_KEYS.md) and
  [prefix boundaries](tests/integration/GRID_FILTER_PREFIX.md).
- **Styled Radio NULL ([PR #90](https://github.com/momijiina/laravel-admin-next/pull/90)):**
  RadioButton/RadioCard no longer select zero solely because the effective value
  is NULL; explicit defaults and the additive label-based `checked()` fallback
  remain. Reconcile both view overrides, refresh compiled views and reload forms.
  Unselected groups are omitted on submission and preserve stored values on
  update; this adds no NULL-clearing API or recovery of earlier overwritten values.
  See [selection and upgrade cautions](tests/integration/STYLED_RADIO_NULL.md).
- **Literal Grid text ([PR #93](https://github.com/momijiina/laravel-admin-next/pull/93),
  [PR #95](https://github.com/momijiina/laravel-admin-next/pull/95)):**
  Copyable retains original literal text through DOM selection; its private
  `data-content` is now an escaped JSON string. Legacy Editable Select escapes
  per-row callback-source JSON for `data-source`, preserving quotes and entities.
  Review custom attribute readers/escaping and reload grids. No asset/view
  republish or data migration is needed for these two fixes. See the separate
  [copy-text](tests/integration/GRID_COPYABLE_TEXT.md) and
  [Editable source contracts](tests/integration/GRID_EDITABLE_SELECT_SOURCE.md).
  Offline selection does not prove OS clipboard writes or empty-clipboard clearing;
  offline plugin execution and replayed HTTP/SQLite requests do not establish
  live-browser layout, network transport or the full PJAX lifecycle.
- **CI only ([PR #91](https://github.com/momijiina/laravel-admin-next/pull/91)):**
  the full integration/DomCrawler test steps use a fresh Node compile cache per
  matrix job, with no cross-job persistence. Tests and runtime support are
  unchanged. Disable it for V8 coverage; the
  [cache notes](tests/integration/README.md#ci-node-compile-cache--ci-の-node-コンパイルキャッシュ)
  distinguish the local timing comparison from unmeasured hosted-CI savings.

日本語: Grid/Show の Attribute 判定は Form の属性検出・保存の変更ではありません。
Grid の範囲入力・検索キー・接頭辞修正では独自 selector、名前整形、URL を確認し、
開いたフォームを再読み込みしてください。文字列ゼロの検索や DOM ID は従来どおりです。
RadioButton/RadioCard は上書きビューとコンパイル済みビューを更新してください。
既定値と `checked()` の追加選択は維持し、未選択は更新時に保存値を保持します。
NULL 消去 API や過去の誤上書きの復元は追加しません。Copyable の内部属性は JSON
文字列になり、Editable Select の行別 JSON は HTML 属性用にエスケープされます。
独自の属性参照・二重エスケープを確認し、Grid を再読み込みしてください。OS の
クリップボード書き込み・空文字での消去、実ブラウザー・通信・PJAX 全体は未検証です。
CI のキャッシュはジョブ内だけで使い、V8 カバレッジ時は無効化してください。
ホスト型 CI の短縮率や対応範囲の拡大は主張しません。DBAL の制約は変更せず、
[DBAL 2/3 の対応範囲](#doctrine-dbal-support-boundary)を維持します。

### Earlier changes and upgrade cautions

- **Image processing ([PR #35](https://github.com/momijiina/laravel-admin-next/pull/35)):**
  optional Intervention Image `^3.11.9` replaces v2 for transformations and
  thumbnails. Review callback types, driver/encoding settings, published
  configuration and non-atomic storage limits in the
  [image migration guide](IMAGE_MIGRATION.md). Ordinary uploads without
  processing do not require it; the PHP floor remains `^8.2`.
- **Application compatibility ([PR #36](https://github.com/momijiina/laravel-admin-next/pull/36)):**
  integration with other Laravel applications is a goal, not a guarantee for
  every application or every release admitted by the Composer constraints.
- **List bounds and names ([PR #37](https://github.com/momijiina/laravel-admin-next/pull/37),
  [PR #38](https://github.com/momijiina/laravel-admin-next/pull/38)):** explicit
  `min()`/`max()` apply even without item rules; embedded and HasMany lists use
  scoped input/error names. Custom validators retain precedence. See
  [list-field behavior and limits](tests/integration/LIST_FIELD.md).
- **Explicit clearing ([PR #39](https://github.com/momijiina/laravel-admin-next/pull/39)):**
  removing every ListField/KeyValue row submits an empty marker; omitting the
  field still preserves stored values. Raw-input hooks and custom validators
  must account for that scalar marker. Controls deliberately disabled to omit
  a field must disable its markers too; see
  [empty-collection cautions](tests/integration/LIST_FIELD.md#explicitly-empty-collections).
- **Collection readonly UI ([PR #46](https://github.com/momijiina/laravel-admin-next/pull/46)):**
  ListField and KeyValue now honor the previously ineffective inherited `readonly()` method. Keys/values remain submitted, while
  collection Add/Remove is locked. Disabled collections remain unsupported. This
  does not add server authorization or concurrency protection; update overridden
  views and review the [behavior cautions](tests/integration/LIST_FIELD.md#readonly-collections).
- **Collection scripts ([PR #40](https://github.com/momijiina/laravel-admin-next/pull/40)):**
  same-column fields have root-local Add/Remove behavior and repeatable
  initialization. Consumers with overridden/published ListField or KeyValue
  views must carry forward the new data markers and direct-child templates;
  see the [view/script contract](tests/integration/COLLECTION_SCRIPT_SCOPING.md#boundaries).
- **HasMany reinitialization ([PR #41](https://github.com/momijiina/laravel-admin-next/pull/41),
  [PR #42](https://github.com/momijiina/laravel-admin-next/pull/42)):** table,
  default and tab modes preserve unique pending-child names and unrelated
  consumer handlers when their ready-wrapped initialization is repeated.
  See the [table guide](tests/integration/HASMANY_TABLE_REINITIALIZATION.md) and
  [default/tab guide](tests/integration/HASMANY_MODES_REINITIALIZATION.md),
  including the pending-DOM versus full validation-redirect coverage boundary.
- **Named-grid pagination ([PR #44](https://github.com/momijiina/laravel-admin-next/pull/44)):**
  configured page sizes now retain the grid-specific page parameter; explicit
  Eloquent page names remain authoritative. Naming a grid does not isolate its
  default shared `_sort` key. [PR #45](https://github.com/momijiina/laravel-admin-next/pull/45)
  adds tests only: configure `setSortName()` before creating sortable columns.
  See [pagination](tests/integration/GRID_PAGINATION.md) and
  [explicit sorting/filter coverage](tests/integration/GRID_EXPLICIT_SORT.md).
- **NULL and zero choices ([PR #47](https://github.com/momijiina/laravel-admin-next/pull/47),
  [PR #48](https://github.com/momijiina/laravel-admin-next/pull/48),
  [PR #49](https://github.com/momijiina/laravel-admin-next/pull/49)):** ordinary
  Select/Radio no longer select zero for NULL; Checkbox retains integer/string
  zero choices. Other legacy loose comparisons and explicit defaults remain.
  Update published/overridden views. Radio omission preserves stored values and
  is not a clearing API; enforce server-side presence rules in the application.
  See the distinct [Select](tests/integration/NULLABLE_SELECT.md),
  [Checkbox](tests/integration/CHECKBOX_ZERO.md), and
  [Radio](tests/integration/NULLABLE_RADIO.md) contracts.
- **Range cast presentation ([PR #50](https://github.com/momijiina/laravel-admin-next/pull/50)):**
  eligible native DateRange/DatetimeRange endpoints display in the application
  timezone to avoid unchanged edit/save drift. This is a narrow presentation fix,
  not a change to storage or arbitrary custom casts/parsers; TimeRange is excluded.
  See [eligibility and round-trip limits](tests/integration/DATE_RANGE_CAST_PRESENTATION.md).
- **CSV and default export ([PR #51](https://github.com/momijiina/laravel-admin-next/pull/51),
  [PR #52](https://github.com/momijiina/laravel-admin-next/pull/52)):** empty results
  now contain a header after the BOM, so check for zero data records instead of
  BOM-only output. Title callbacks also run for empty results under the existing
  visibility/order rules. Ordinary default-export resolution avoids PHP 8.5's
  NULL-key deprecation. `Exporter::extend(null, ...)` registration is unchanged;
  use `''` for the empty-string driver. See
  [CSV schema, callbacks and exporter boundaries](tests/integration/CSV_HEADERS.md).
- **Initial range bounds ([PR #53](https://github.com/momijiina/laravel-admin-next/pull/53)):**
  fresh DateRange/DatetimeRange/TimeRange widget pairs seed reciprocal bounds from
  parsed endpoints without changing endpoint values during seeding. Null endpoints
  add no bound; inverted pairs, conflicting bounds, existing widget instances,
  custom parsers and non-default timezone options retain conservative exclusions. Existing
  change behavior and server validation are unchanged. See the
  [widget initialization contract](tests/integration/DATE_RANGE_INITIALIZATION.md).
- **Textarea leading newlines ([PR #55](https://github.com/momijiina/laravel-admin-next/pull/55),
  [PR #56](https://github.com/momijiina/laravel-admin-next/pull/56)):** ordinary
  and action textarea views each add one literal LF after the opening tag so
  HTML parsing preserves the value's leading LFs. Reconcile both overridden
  views separately; keep escaped interpolation unindented and do not add a
  workaround LF to stored values. CR/CRLF normalization and application trimming
  middleware remain unchanged. Ordinary coverage includes HTTP/SQLite saves;
  action coverage stops at outgoing native FormData. See the distinct
  [ordinary](tests/integration/TEXTAREA_NEWLINES.md) and
  [action](tests/integration/ACTION_TEXTAREA_NEWLINES.md) newline contracts.
- **Action Select NULL ([PR #57](https://github.com/momijiina/laravel-admin-next/pull/57)):**
  effective NULL retains the leading blank option rather than selecting zero.
  An untouched blank submits an empty string; middleware normalization and action
  persistence are not covered. Explicit zero remains valid, with other non-NULL
  loose comparisons preserved. Update overridden action views and keep their
  leading blank option. See [action Select cautions](tests/integration/ACTION_SELECT_NULL.md).
- **Action Radio NULL ([PR #58](https://github.com/momijiina/laravel-admin-next/pull/58)):**
  the option comparison no longer matches NULL to zero. The existing additive,
  label-based `checked()` fallback still applies when the original field value
  is NULL, including with old NULL input. Without a selection, native FormData
  omits the group; this is not a clearing API or a server-side presence guarantee.
  Explicit zero and other non-NULL loose comparisons remain. Update overridden
  action Radio views; see [fallback, validation and submission limits](tests/integration/ACTION_NULLABLE_RADIO.md).
- **Ordinary MultipleSelect NULL members ([PR #59](https://github.com/momijiina/laravel-admin-next/pull/59)):**
  only NULL array members are excluded from option matching, preventing a blank
  hidden marker normalized by middleware from selecting zero after validation
  failure. Zero choices, other non-NULL loose comparisons, defaults and old-input
  precedence remain. The hidden clearing marker and server preparation are
  unchanged. Update overridden ordinary views; action MultipleSelect, Checkbox,
  relationships and nested HasMany identity are outside this fix. See
  [validation-redirect and SQLite retry coverage](tests/integration/MULTIPLE_SELECT_NULL_OLD_INPUT.md).
- **Number integer editing ([PR #61](https://github.com/momijiina/laravel-admin-next/pull/61)):**
  whole-number values and bounds use exact decimal-string comparisons and unit
  +/- steps, avoiding lexical bounds and rounding above JavaScript's safe-integer
  range. Refresh the published Number asset and cached/minified copies; reconcile
  customized assets before republishing. Existing noninteger parsing remains.
  This does not recover already-rounded values or expand PHP/SQL
  integer ranges; keep server rules. See [integer and asset cautions](tests/integration/NUMBER_INTEGERS.md).
- **DateMultiple options ([PR #62](https://github.com/momijiina/laravel-admin-next/pull/62)):**
  native JSON-data options now reach flatpickr. `format()` uses flatpickr tokens;
  explicit `dateFormat` overrides it. The default locale remains `zh`, while
  multiple mode and the Clear plugin remain field-managed. Previously ignored
  formats, conjunctions and restrictions now apply: review stored strings before
  upgrading, as no data migration or server validation is added. Function-valued
  options remain unsupported. No asset republish is needed solely for this fix;
  see [format, locale and JSON-only boundaries](tests/integration/DATE_MULTIPLE_OPTIONS.md).
- **Number readonly/disabled UI ([PR #63](https://github.com/momijiina/laravel-admin-next/pull/63)):**
  +/- buttons, key events and focus/blur normalization no longer change locked
  inputs. Live properties and inherited disabled fieldsets are honored. Readonly
  values remain submitted; native FormData omits disabled values, but shipped
  jQuery 2.1.4 `serialize()` does not omit inputs disabled only by a fieldset.
  Refresh the published Number asset. These are UI guards, not server protection;
  see [state and serialization limits](tests/integration/NUMBER_FIELD_STATES.md).
- <a id="callback-bearing-widget-options"></a>**Widget callback mapping ([PR #64](https://github.com/momijiina/laravel-admin-next/pull/64)):**
  the existing Text/Inputmask and File option helper preserves distinct nested
  callbacks and literal marker-like data, with native JSON types for NULL/scalars
  and object leaves. Generated markers are opaque; remove assumptions about
  `%key%` names. The exact `function(` string convention and historical encoding
  failure policy remain. DateMultiple stays plain JSON. No asset/view refresh is
  required; see [caller and serialization boundaries](tests/integration/WIDGET_OPTION_SERIALIZATION.md).
- <a id="currency-configured-radix-preparation"></a>**Currency preparation ([PR #65](https://github.com/momijiina/laravel-admin-next/pull/65)):**
  a declared nonempty, non-dot string radix is normalized before the existing
  float cast, preserving fractions in the shipped widget's unmasked submissions.
  Validation still precedes preparation: Laravel's `numeric` rule rejects comma-radix
  strings, and accepted raw-input hooks still see them. Float precision, blank
  preparation as `0.0` and default dot behavior remain; this is not a generic
  localized/masked parser or a repair of already-truncated storage. No asset
  republish is needed; review [round-trip and subclass cautions](tests/integration/CURRENCY_RADIX.md).

- **Inclusive Grid filter labels ([PR #67](https://github.com/momijiina/laravel-admin-next/pull/67)):**
  `gt()` and `lt()` labels now show `>=` and `<=`, matching their unchanged
  inclusive SQL behavior. Update published/overridden filter labels and review
  custom conditions together; no strict-comparison API is added. See
  [boundary, zero and reset coverage](tests/integration/GRID_INEQUALITY_FILTERS.md).
- **Collection conditional fields ([PR #68](https://github.com/momijiina/laravel-admin-next/pull/68)):**
  Checkbox, MultipleSelect and CheckboxButton/Card initialize cascade conditions
  from string-choice arrays and handle NULL clearing markers. Existing operators
  remain, including `oneNotIn` meaning no intersection. Collection initialization
  bypasses the scalar `getValueByJs()` hook; review trait/subclass overrides.
  Submission, disabled behavior and storage are unchanged; no asset/view refresh
  is required. See [cascade contracts and limits](tests/integration/COLLECTION_CONDITIONAL_FIELDS.md).
- **Styled Checkbox zero choices ([PR #69](https://github.com/momijiina/laravel-admin-next/pull/69)):**
  CheckboxButton/Card retain integer/string zero in both checked inputs and active
  labels, avoiding loss on untouched saves. Other falsey values remain filtered;
  loose matching, additive `checked()` fallback and clearing behavior are unchanged.
  Reconcile overridden/published Button/Card views; no asset refresh is needed.
  See [selection and storage cautions](tests/integration/STYLED_CHECKBOX_ZERO.md).
- **Slider double ranges ([PR #70](https://github.com/momijiina/laravel-admin-next/pull/70)):**
  saved/old-input `from;to` pairs restore both endpoints in effective double mode.
  Only canonical decimal-integer pairs that round-trip through JavaScript Number
  are accepted; bounds, `data-to` precedence and malformed/empty fallbacks remain.
  Custom views must supply `data-from`, and custom initializers need the restore
  logic. Do not preload `input.value`: the shipped plugin reads it as bounds.
  Storage and the integer-only widget contract are unchanged; see
  [range and validation cautions](tests/integration/SLIDER_RANGES.md).
- **Switch label strings ([PR #71](https://github.com/momijiina/laravel-admin-next/pull/71)):**
  labels, colors and sizes are serialized as JavaScript string literals, preserving
  quotes, backslashes and localized text without breaking the ready handler.
  Remove manual JavaScript escaping from configured labels and reconcile custom
  initializers. Labels still allow HTML; this is not sanitization. Hidden-state
  submission and readonly/disabled behavior remain; no asset/view refresh is
  required. See [label and server-validation cautions](tests/integration/SWITCH_LABELS.md).
- **Nullable Grid carousel ([PR #72](https://github.com/momijiina/laravel-admin-next/pull/72)):**
  NULL now renders an empty cell instead of preventing Grid rendering. Storage,
  non-NULL arrays/Arrayable conversion, filtering and URL behavior are unchanged;
  other scalar/object values are not newly supported. Custom replacement
  displayers need their own NULL handling; no asset/view refresh is required.
  See [presentation boundaries](tests/integration/GRID_CAROUSEL.md).
- **Remote Select validation retries ([PR #73](https://github.com/momijiina/laravel-admin-next/pull/73)):**
  URL-options Select/MultipleSelect restore attempted selections and explicit
  clears after failed validation. Old input takes precedence over remote/configured
  selected-option fallbacks; without old input those overrides and defaults are
  unchanged. Custom views/initializers must preserve the old-input metadata.
  Responses must still contain the IDs; dependent `load()`/`loads()`, Listbox,
  AJAX-search preloads, comma-separated IDs and request sequencing are unchanged.
  No asset/view refresh is required; see
  [retry behavior and custom-initializer cautions](tests/integration/REMOTE_SELECT_OLD_INPUT.md).
- **Grid inline MultipleSelect integers ([PR #74](https://github.com/momijiina/laravel-admin-next/pull/74)):**
  the popover preserves stored integer IDs, including zero, by comparing their
  string forms with option values. Strict matching keeps `"001"` distinct from
  `"1"`; opening/cancelling does not mutate original metadata or storage. Submit
  values still become strings in option order. Update overridden inline
  MultipleSelect views and normal view caches; no asset refresh or data migration
  is needed. This does not expand JavaScript integer precision or redesign
  malformed/NULL and empty-selection semantics. See
  [inline selection and persistence boundaries](tests/integration/GRID_INLINE_MULTIPLE_SELECT.md).


- **Grid QuickCreate retries ([PR #76](https://github.com/momijiina/laravel-admin-next/pull/76)):**
  unsuccessful JSON responses reset only the originating Submit button, retaining
  input for correction/retry. Successful responses keep it loading until reload;
  the existing HTTP-error reset is unchanged. Review custom `QuickCreate::script()`
  implementations; no asset/view refresh is needed. See the
  [retry contract and limits](tests/integration/GRID_QUICK_CREATE.md).
- **Grid uploads ([PR #77](https://github.com/momijiina/laravel-admin-next/pull/77),
  [PR #78](https://github.com/momijiina/laravel-admin-next/pull/78)):** per-cell
  targets and repeatable namespaced handlers prevent unrelated upload columns
  from submitting. The existing `uplaodMany()` API iterates native FileList by
  index, preserving every selected file and its order. Update overridden upload
  views with both changes; follow rendered `data-target` / `$target` values instead
  of constructing or retaining input IDs across renders. Custom targets must be
  unique across cells and Grids. Refresh stale compiled views; JS asset updates
  alone do not update Blade overrides. See [upload cautions](tests/integration/GRID_INLINE_UPLOAD.md).
- **Grid nested-table alignment ([PR #79](https://github.com/momijiina/laravel-admin-next/pull/79)):**
  missing keys produce empty cells in configured column order. Custom table views
  now receive explicit NULL for absent keys, so review key-presence/count logic.
  Stored JSON, first-row header inference and supported row types are unchanged;
  no asset/view refresh is needed. See [table boundaries](tests/integration/GRID_TABLE.md).
- **MultipleFile sorting ([PR #80](https://github.com/momijiina/laravel-admin-next/pull/80)):**
  ordinary top-level sortable fields retain new uploads after sorted existing
  files, in selection order; optional built-in file/image rules allow sort-only
  submissions. Combined sort/upload requests now pass the actual `UploadedFile`
  array to custom validators and saving hooks instead of the erroneous order
  string. Review those hooks; order remains in `_file_sort_[field]`, and sort-only
  hook input is unchanged. No asset/view refresh is needed. Required-rule,
  nested/relation, malformed-order and storage-atomicity semantics are unchanged;
  see [sorting and hook cautions](tests/integration/MULTIPLE_FILE_SORT.md).
- **Nullable Grid inline choices ([PR #81](https://github.com/momijiina/laravel-admin-next/pull/81)):**
  Select/Radio display strict NULL as a blank label without failing the table.
  Review custom displayers; popover defaults, loose comparisons and storage remain,
  with no new NULL-clearing protocol or asset/view refresh. See
  [nullable-choice boundaries](tests/integration/GRID_INLINE_NULLABLE_CHOICES.md).
- **Dependent Select isolation ([PR #82](https://github.com/momijiina/laravel-admin-next/pull/82)):**
  independent `Select::loads()` initializers keep their targets, URLs and callbacks
  in their own scopes. Review PHP overrides; existing ID/AJAX contracts and
  single-loader behavior remain, with no asset/view refresh. See
  [loader-isolation boundaries](tests/integration/SELECT_LOADS_ISOLATION.md).
- **Action modal retries ([PR #83](https://github.com/momijiina/laravel-admin-next/pull/83)):**
  AJAX failure and confirmation cancellation before sending restore the originating
  form's Submit button and retain input. A request already sent owns the button
  until its callback settles, even if confirmation is dismissed. Review overridden
  Form interactors; no asset/view refresh is needed. A transport failure does not
  prove that the server did not save, and this adds no server-side idempotency;
  see [retry and pending-confirmation cautions](tests/integration/ACTION_MODAL_RETRY.md).
- **Mixed Grid inline editors ([PR #84](https://github.com/momijiina/laravel-admin-next/pull/84)):**
  Input, Textarea, Datetime, Select, MultipleSelect, Radio and Checkbox submit
  bindings are scoped by resource/payload name, preventing later editors from
  replacing another field's value extractor, display callback or save URL.
  Update **both** `grid/inline-edit/comm.blade.php` and
  `grid/inline-edit/partials/submit.blade.php` overrides together, refresh stale
  compiled views and **fully reload open admin pages**. PJAX alone can retain the
  old generic handler alongside the new bindings. No JS asset republish or data
  migration is needed; this cannot restore previously overwritten values or fix
  all popover/cross-Grid identity issues. See
  [paired-view and reload cautions](tests/integration/GRID_INLINE_MIXED_EDITORS.md).
- **Root development dependency ([PR #85](https://github.com/momijiina/laravel-admin-next/pull/85)):**
  `laravel/browser-kit-testing` now allows `^6.0 || ^7.0` in root `require-dev`,
  removing the v6-only resolution conflict for modern Laravel development setups.
  The isolated [BrowserKit runner](tests/browserkit/README.md) already required
  `^7.2.8`; this does not change its requirement, runtime dependencies, or application
  APIs. It is a development-resolution fix, not a new security fix or a guarantee
  for every version allowed by the root's historical dependency graph.

### 日本語: PR #76–#85 の変更点と更新時の注意

- Grid QuickCreate は失敗 JSON 応答後、Action モーダルは通信失敗・送信前の確認
  キャンセル後に、入力を保ったまま再試行できます。独自の PHP スクリプト生成を
  確認してください。通信失敗は未保存の保証ではなく、重要な操作の重複処理対策は
  アプリ側で必要です。
- Grid アップロードはセル単位に分離し、`uplaodMany()` は native FileList を
  選択順で送信します。上書きビューに両方の修正を反映し、独自 selector は生成 ID を
  組み立てず、描画された `data-target` / `$target` を参照してください。
- 通常のトップレベル MultipleFile は並び替えと追加アップロードを同時に保存できます。
  この組み合わせの saving フック・独自 validator には `UploadedFile` 配列が渡るため、
  以前の並び順文字列を前提とする処理を確認してください。並び替えだけの入力は維持します。
- Grid 内テーブルの欠損キーは NULL の空セルになり、インライン Select/Radio の NULL は
  空欄表示になります。独自ビューのキー有無・要素数の判定や独自表示クラスを確認して
  ください。依存 Select ローダーも初期化ごとに分離しますが、既存の取得・選択仕様は維持します。
- 異種インラインエディターは共通ビューと送信 partial を必ず一緒に更新し、古い
  コンパイル済みビューを更新したうえで管理画面をページ全体で再読み込みしてください。
  PJAX の更新だけでは旧送信処理が残る場合があります。過去の誤った上書きは復元しません。
- ルート開発依存の BrowserKit は `^6.0 || ^7.0` になりました。分離されたテスト環境は
  既に `^7.2.8` を要求しており、新しいセキュリティ修正や全バージョンの動作保証ではありません。

The linked guides distinguish offline DOM/widget execution and in-process
HTTP/SQLite checks from live-browser, network/PJAX, cookies/CSRF, external-database
and downstream-override coverage. PR #78 also raised the full integration and
DomCrawler workflow timeouts to 30 minutes; that CI configuration is not evidence
of broader runtime support. No new runtime validation is claimed by this
documentation refresh, and historical test totals below keep their original scope.

日本語: 検証範囲は各ガイドを参照してください。offline DOM とプロセス内 HTTP/SQLite の
確認は、実ブラウザー・実通信/PJAX・cookie/CSRF・他の DB・独自上書きの保証ではありません。
PR #78 の統合・DomCrawler CI の制限時間延長（30 分）も対応範囲を広げるものではなく、
今回の文書更新による新たなランタイム検証や、他の Laravel アプリとの一律の互換性は主張しません。

Disabled-collection support and changes to Embeds replacement semantics remain
separate design work, not shipped fixes. Readonly does not imply either; see the
[collection cautions](tests/integration/LIST_FIELD.md#readonly-collections) and
[Embeds replacement boundary](tests/integration/EMBEDDED_OBJECT_ORIGINALS.md#boundary).

### Hosted evidence for PR #74

All **61 jobs across 13 workflows** passed for PR #74 head
[`c8a24e7`](https://github.com/momijiina/laravel-admin-next/commit/c8a24e77292cddc7ba84b3913401eb990d5f783a);
see the [PR checks](https://github.com/momijiina/laravel-admin-next/pull/74/checks).
All job logs identify synthetic checkout `5f3d8ca`, merging that head into
`1a05d13`. Its tree matches the head and actual merge `724834a`. The 22 full
integration/DomCrawler lanes each completed **386 tests / 260,583 assertions /
2 skips**; all eight BrowserKit lanes completed **125 tests with no skips**
(assertions vary with random fixtures).

These are PR-triggered hosted results for that exact checkout, not new CI runs
against merged main or this documentation update. The full-suite logs report
aggregate skips; the source/configuration identifies the two optional
MySQL/MariaDB and PostgreSQL service guards. Neither those skips nor separate
generator-service checks establish ordinary form lifecycle coverage on those
databases. Earlier per-PR and local totals remain separate historical snapshots.

The new form and inline-editor regressions combine offline shipped-widget
execution with in-process HTTP/SQLite persistence; the filter and carousel
checks inspect HTTP-rendered HTML without browser JavaScript. These results do
not establish live-browser layout/keyboard behavior, real network/PJAX,
cookies/CSRF, nested HasMany validation identity, arbitrary custom overrides or
universal downstream-application compatibility. Each linked guide defines its
own narrower boundary; older evidence below remains unchanged.

### Hosted evidence for PRs #61–#65

Each recorded PR revision passed **61 jobs across 13 workflows**, including
22 full integration/DomCrawler lanes with the per-lane totals below and eight
BrowserKit lanes of **125 tests** each (assertions vary with random fixtures).
The head/base and synthetic checkout identify the exact pre-merge code tested.

| PR checks | PR head | Base used by CI | Synthetic checkout | Tests / assertions / skips per full lane |
| --- | --- | --- | --- | --- |
| [#61](https://github.com/momijiina/laravel-admin-next/pull/61/checks) | `160918e` | `d8d94a4` | `dd32cfe` | 297 / 239,637 / 2 |
| [#62](https://github.com/momijiina/laravel-admin-next/pull/62/checks) | `2d71410` | `c1b04c3` | `f5867c8` | 303 / 240,693 / 2 |
| [#63](https://github.com/momijiina/laravel-admin-next/pull/63/checks) | `96731bc` | `215dae8` | `1051ae4` | 313 / 254,107 / 2 |
| [#64](https://github.com/momijiina/laravel-admin-next/pull/64/checks) | `b408b5c` | `387da56` | `eb694cf` | 323 / 254,185 / 2 |
| [#65](https://github.com/momijiina/laravel-admin-next/pull/65/checks) | `3010473` | `9399571` | `6e2ec4e` | 332 / 256,033 / 2 |

These are PR-triggered hosted snapshots, not new runs against merged main
`a87140f` or this documentation update. The two full-suite skips are the
source-verified optional MySQL/MariaDB and PostgreSQL service guards; neither
those skips nor separate generator-service checks establish ordinary form
lifecycle coverage on those databases.

Number, DateMultiple and Currency regressions combine offline shipped-widget
execution with in-process HTTP/SQLite persistence. Callback-helper coverage
exercises Inputmask offline and captures File initializer options; it does not
execute the File plugin, transfer files or verify callback persistence. These
checks do not establish live-browser layout/keyboard, PJAX, transport/cookies/CSRF,
custom-widget or universal downstream-application compatibility. Each linked guide
states its own narrower boundary; older evidence below remains unchanged.

### Hosted evidence for PRs #55–#59

Each recorded PR revision below passed **61 jobs across 13 workflows**. Each
had 22 full integration/DomCrawler lanes with the per-lane totals shown, plus
8 BrowserKit lanes of **125 tests** each (assertions vary with random fixtures).
The checks links identify the PRs; the head/base and synthetic checkout identify
exactly which code those results cover.

| PR checks | PR head | Base used by CI | Synthetic checkout | Tests / assertions / skips per full lane |
| --- | --- | --- | --- | --- |
| [#55](https://github.com/momijiina/laravel-admin-next/pull/55/checks) | `a989250` | `4bfc930` | `1b15261` | 279 / 235,069 / 2 |
| [#56](https://github.com/momijiina/laravel-admin-next/pull/56/checks) | `4ecb768` | `4bfc930` | `e7de312` | 266 / 235,164 / 2 |
| [#57](https://github.com/momijiina/laravel-admin-next/pull/57/checks) | `a43b2d4` | `4e809a8` | `c3f2cbf` | 281 / 235,564 / 2 |
| [#58](https://github.com/momijiina/laravel-admin-next/pull/58/checks) | `150295c` | `7d5f207` | `a62d725` | 282 / 235,746 / 2 |
| [#59](https://github.com/momijiina/laravel-admin-next/pull/59/checks) | `39b6bef` | `7d5f207` | `b4be9e0` | 284 / 235,785 / 2 |

These are pre-merge hosted snapshots, not CI for merged main `0895654` or this
documentation update. In particular, #56's snapshot does not include #55, and #58/#59
were tested separately against the same base: neither hosted total includes
both fixes. Earlier local combined checks are also separate evidence, not a
hosted-main result. The two full-suite skips correspond to optional external
MySQL/MariaDB and PostgreSQL service checks; they do not establish ordinary
lifecycle coverage on those databases.

The new action regressions run emitted modal scripts in offline jsdom and inspect
native FormData before network I/O, including hide/reopen/resubmission. They do
not exercise action HTTP dispatch, middleware, server validation or persistence.
The ordinary textarea and MultipleSelect regressions additionally exercise
HTTP/SQLite paths, with different middleware policies documented in their guides.
None establishes live-browser E2E, transport encoding, cookies/CSRF, arbitrary
custom views or universal downstream-application compatibility.

### Hosted evidence for PR #53

All **61 jobs across 13 workflows** passed for the final PR #53 head,
[`c024061`](https://github.com/momijiina/laravel-admin-next/commit/c0240610adebba05b12ef9025514a6256be34345);
see the [PR checks](https://github.com/momijiina/laravel-admin-next/pull/53/checks).
The hosted checkout was synthetic merge `a701103` of that head into `74d5749`.
The 22 full integration/DomCrawler lanes each completed **265 tests / 234,866
assertions / 2 optional external-database service skips**. The eight BrowserKit
lanes each completed **125 tests**; assertion counts vary with random fixtures.
The range-initialization regression is included in the full suite and exercises
39 offline widget fixtures; these results do not establish real-browser E2E.
These are hosted results for that exact pre-merge revision, not local reruns or
CI results for merge commit `f51972f` or this documentation update. The database
skips do not establish ordinary lifecycle coverage on external database services.

The earlier PR #42 and PR #25 evidence below is retained for its original
revisions and must not be read as the current test totals.

### Hosted evidence for PR #42

All **61 jobs across 13 workflows** passed for the final PR #42 head,
[`aedae96`](https://github.com/momijiina/laravel-admin-next/commit/aedae96f263e6652d816589d20da4c4e5768f235);
see the [PR checks](https://github.com/momijiina/laravel-admin-next/pull/42/checks).
The CI checkout was synthetic merge `4b2a838`; its tree matched that head.
The 22 integration/DomCrawler lanes each completed **157 tests / 189,979
assertions / 2 optional external-database service skips**. The eight BrowserKit
lanes each completed **125 tests**, with **1,227–1,236 assertions**.
These are hosted results for that exact pre-merge head, not local results or a
new run against merge commit `c09e6f8` or this documentation update. Optional
service skips do not establish ordinary lifecycle coverage on those databases.

The newer collection/HasMany checks combine offline jsdom with targeted
Testbench HTTP/SQLite checks; they are not full browser E2E, live PJAX transport,
layout, arbitrary widget or universal downstream-application certification.
See the [integration setup](tests/integration/README.md) for the required Node
and locked npm dependencies, and the [BrowserKit guide](tests/browserkit/README.md)
for separate in-process historical and image-output coverage. Local results,
configured CI matrices and hosted results for an exact commit are distinct
forms of evidence; none substitutes for the others.

<a id="image-processing-dependency-change-after-pr-25"></a>

## Image-processing dependency change (PR #35)

Transformations and thumbnails now require optional Intervention Image
`^3.11.9`; ordinary uploads without processing do not require it. The PHP floor
remains `^8.2`. This is a **breaking change** with ten supported legacy field
operations and a native v3 callback escape hatch, not a complete v2 shim.
See [IMAGE_MIGRATION.md](IMAGE_MIGRATION.md) for callback changes, separate
transform/thumbnail driver defaults, encoding, thumbnail behavior and
non-atomic storage failure boundaries.

Initial local v3 verification, before the GD-build test correction, on
PHP 8.5.11 / Intervention 3.11.9 / bundled GD: the focused
image suite passed 17 tests / 234 assertions / zero skips with EXIF enabled
and zero diagnostics. Complete BrowserKit runs completed 94 tests on Laravel
12.69.3 (1,231 assertions) and 13.34.0 (1,232 assertions), each without skips.
Separate integration runs completed 106 tests / 36,653 assertions / two expected
MySQL/PostgreSQL service skips per framework. All 330 production PHP files
passed lint; the optional-dependency check, Composer platform checks and audits
passed. These local results are separate from PR #25's hosted evidence below;
see the [detailed boundaries](IMAGE_MIGRATION.md#focused-verification).

The initial eight hosted GD lanes exposed a build-dependent 45-degree rotation
oracle failure: bundled GD produces 13×14, external libgd 2.3.3 produces 15×15
for the 12×8 fixture. Independent v2 comparison also found missing hidden-RGB
normalization in the v3 GD decode path. The adapter now uses v3's public clone
(native canvas copy) to preserve legacy transparent-RGB normalization; Imagick
is unchanged. The final test runs 31 independent cases against two actual v2
manifests, each containing 49 fixed PNG outputs. Exact GD-version/native-canary
fingerprints select the test oracle; unknown builds fail the test provider,
not production uploads. These checks do
not promise identical dimensions or pixels across different GD builds.
See the [correction and oracle scope](IMAGE_MIGRATION.md#gd-build-specific-rotation-and-the-corrected-oracle).

Corrected PHP 8.5.11 / Intervention 3.11.9 / EXIF-enabled focused runs passed
48 tests / 236 assertions on both characterized builds. Complete BrowserKit runs
passed 125 tests each: bundled GD with Laravel 12.69.3 / 1,236 assertions and
13.34.0 / 1,232 assertions; external libgd 2.3.3 with Laravel 12.69.3 / 1,228
assertions and 13.34.0 / 1,234 assertions. All had zero skips and diagnostics;
historical random fixture counts vary. The count increase reflects independent
oracle cases and a direct normalization regression. All four integration runs
completed 106 tests / 36,653 assertions / two expected database-service skips;
the optional-dependency boundary and 330-file lint passed on both builds.
Independent comparison found 53/53 decoded outputs exact against v2 on each
backend. These local results are separate from hosted CI and other GD builds.

Imagick and WebP/AVIF codecs remain unverified; see the migration guide for
focused EXIF/animation test scope and remaining parity limits. The
PR #25 snapshot below predates this migration, including its Image v2
BrowserKit dependency; its test counts, source-file count and green hosted jobs
must not be presented as validation of the changed v3 source. Historical
sections intentionally retain the dependencies and results of their revisions.

<a id="current-verified-coverage-2026-10-03-after-pr-25"></a>

## Historical verified coverage (2026-10-03, PR #25)

This snapshot records checks for the code merged in
[PR #25](https://github.com/momijiina/laravel-admin-next/pull/25), head
[`5c161f51`](https://github.com/momijiina/laravel-admin-next/commit/5c161f51c5d144c4045099877737660fd1e8a0c1),
merged as `d2ee8f4`. Historical evidence below and in individual regression notes
records the suite sizes at those earlier revisions; it is not the current total.

- **Declared requirements:** PHP `^8.2`, Laravel `>=5.5`, Doctrine DBAL
  `^2.13.9 || ^3.10.6`. These declarations do not certify every admitted
  combination. Full modern Laravel consumers resolve DBAL 3; Symfony
  HttpFoundation conflicts prevent normal full-Laravel 12/13 resolution with
  DBAL 2. Independently resolved Illuminate components exercise DBAL 2 instead.
- **Source checks:** the strict source-lint runner now covers **328 production
  PHP files** without diagnostics on PHP 8.4.25 and 8.5.11, with all 37
  formerly implicit nullable parameters explicit.
- **Local integration:** PHP 8.4.25, Laravel 12.69.3 / Testbench 10.12.0 /
  PHPUnit 11.5.56 and Laravel 13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37,
  DBAL 3.10.6: **56 tests, 35,302 assertions, 2 service skips** per framework.
  PHP 8.5.11 was checked locally for the focused generator suite only at this
  revision: **10 tests, 254 assertions, 2 service skips** per framework. Earlier
  PHP 8.5 full-suite results describe smaller, earlier suites. These passes
  retain existing invalid-input deprecations described in the
  [controller regression](tests/integration/HANDLE_CONTROLLER_REQUEST.md).
- **Historical BrowserKit:** **77 tests** comprise 73 historical methods and
  four harness-isolation regressions. Local PHP 8.4.25 runs pass on Laravel 12/13
  in default and randomized order. These are in-process SQLite HTTP tests with
  real GD image processing, not JavaScript browser tests or a deprecation-free
  certification. See the [runner and diagnostic limits](tests/browserkit/README.md).
- **Hosted evidence:** all **60 jobs across 13 workflows** passed for the exact
  PR #25 head above, including integration and BrowserKit on Laravel 12 with
  PHP 8.2–8.5 and Laravel 13 with PHP 8.3–8.5. See
  [the PR checks](https://github.com/momijiina/laravel-admin-next/pull/25/checks).
  These results are separate from the local results and from future CI runs.

### Database-service evidence and remaining limits

The [model-generation run](https://github.com/momijiina/laravel-admin-next/actions/runs/37093063540)
passed all ten jobs at that exact head:

- Six full-framework DBAL **3.10.6** cells: Laravel 12 / PHP 8.2 and
  Laravel 13 / PHP 8.3, each against **MySQL 8.4.11, MariaDB 10.11.19 and
  PostgreSQL 16.15**. These exercise production schema metadata/output parity
  against an independent same-version DBAL oracle and actual model-backed
  Artisan generation, including qualified tables and PostgreSQL search paths.
- Four DBAL **2.13.9** component cells: Illuminate Database/Events 12/13 on
  PHP 8.4, each against MySQL/MariaDB. They check real metadata/output parity,
  but do **not** boot a full modern Laravel application or run Artisan.
  PostgreSQL/DBAL 2 is not covered by this matrix.

Both MariaDB matrices exercise Laravel's `mysql` and `mariadb` connection
classes. PDO transaction, reconnect, connection-state and persistent-connection
lifecycle regressions use **SQLite only**. DBAL 3 supports persistent PDO in
those tests; DBAL 2 rejects it before mutating connection state. These lifecycle
results must not be generalized to every service driver. SQL Server has
structural checks only and remains unverified against a live server.

See the [integration harness](tests/integration/README.md) and
[generator details](tests/integration/RESOURCE_GENERATOR.md) for reproducible
commands, dependency-resolution boundaries and test scope. Full JavaScript
browser flows, every PDO option/third-party driver, universal downstream support
and specific downstream application releases remain unverified. Normal Composer
security and platform checks remain enabled; DBAL 2's abandoned `doctrine/cache`
dependency is reported. Prefer DBAL 3 for maintained dependencies and persistent PDO.

## Historical migration and regression evidence

The following dated sections preserve what was verified when each patch was
prepared. For the latest maintenance scope and guide links, use the current summary above.
The PR #25 snapshot records its own exact hosted outcomes, not current totals.

## PHP baseline migration (2026-10-02)

The package now requires **PHP `^8.2` (8.2 through 8.x)**. PHP 7.x, 8.0 and
8.1 are no longer supported; an unverified PHP 9 major is not accepted. PHP 8.3+
is recommended for deployments. PHP 8.2 remains security-supported only through
2026-12-31; PHP 8.3 through 2027-12-31. Recheck the
[upstream support table](https://www.php.net/supported-versions.php) before release.

All 37 previously implicit nullable parameters in 24 source files now declare
`?Type` explicitly. Parameter names, default values, accepted types, visibility,
and behavior are preserved. This is an intentional PHP minimum-version change,
not a Laravel dependency upgrade: the broad `>=5.5` Laravel constraint remains
for downstream resolution, and does not promise support for EOL frameworks.

`php tests/compatibility/php_source_lint.php` lints all 326 production PHP files
with `E_ALL` and rejects compile-time diagnostics as well as syntax errors.
On PHP 8.4.25 it passes without diagnostics; the pre-migration source fails with
exactly 37 implicit-nullability notices in 24 files. All standalone regressions
also pass locally. Both real Laravel 12.69.3 and 13.34.0 suites pass 14 tests /
1,411 assertions each on PHP 8.4.25 without diagnostics, using the existing
isolated dependency sets with the migration source. Reflection metadata for all
37 parameters matches the old source (types, nullability, defaults, optionality,
visibility and static flags). CI requests standalone/source checks on PHP 8.2, 8.3 and 8.4,
and real integration on Laravel 12/PHP 8.2–8.4 and Laravel 13/PHP 8.3–8.4.
PHP 8.2/8.3 CI results and PHP 8.5 runtime verification are not claimed locally.

Obsolete Travis PHP 7.2–8.0 configuration has been retired. The historical root
development dependencies remain unchanged. The separate [BrowserKit runner](tests/browserkit/README.md)
restores all 73 historical methods on supported frameworks with SQLite and real
GD image processing; the [integration harness](tests/integration/README.md)
continues its focused modern-framework checks. These targeted passes are not complete framework certification.

## Historical baseline evidence (2026-10-01)

Baseline: `819837af94e1a4170a13ac7dc85dbe40bd6e8d9b`.
See the [full audit](docs/compatibility-audit-2026-10-01.md) for evidence and limits.

| Area | Declared or historical state | Verified status |
| --- | --- | --- |
| PHP | `>=7.0.0` in Composer | PHP 8.4.25 parsed all 359 non-Blade PHP files; 37 implicit-nullability notices in 24 files remain |
| Laravel | `>=5.5` in Composer | No complete Laravel/PHP runtime combination has passed the existing suite in this audit |
| Laravel 12.69.3 / 13.34.0 + PHP 8.4.25 + SQLite | Temporary consumer with production dependencies | Install, login/dashboard/menu/user-list HTTP smoke and cache commands passed; both generator modes failed; full compatibility is not established |
| Laravel 11–13 scaffolding | Allowed by the runtime constraint | Uses Doctrine connection methods removed in Laravel 11 |
| Test dependencies | BrowserKit `^6.0` | Its Illuminate constraints stop at Laravel 10; cannot resolve an 11–13 test matrix unchanged |
| Test bootstrap | Legacy Eloquent factories | Requires migration for modern Laravel |
| Historical CI | Travis PHP 7.2–8.0 | Configuration exists; no successful current run is established |
| Other Laravel applications | Compatibility and integration goal | Exact application/version combinations not yet specified or tested |

A clean syntax check or a focused deprecation regression is not a Laravel support
claim. Full support requires installation, bootstrap, database-backed tests,
browser smoke checks, and supported dependency resolution for that combination.

## Focused follow-up: legacy column cast (2026-10-02)

The existing public, untyped `Grid\Column::$cast` property is now declared to
avoid PHP 8.2+ dynamic-property deprecations. On PHP 8.4.25, the standalone
`php tests/compatibility/grid_column_cast.php` regression failed on the preceding
source with two dynamic-property notices and passes after the declaration.
It checks fluent calls, public reads/writes, null resets, untyped values,
instance isolation, and unchanged `sortable($cast)` arguments using small
framework test doubles. CI runs this focused check on PHP 8.4.

The deprecated `cast()` API still only stores its value; callers should continue
using `sortable($cast)` to configure sorting. This patch does not change sorting
behavior, PHP requirements, implicit-nullability warnings, or the blocked legacy
Laravel suite. It does not establish full framework, database, or rendering
compatibility.

## Verification matrix policy

The modern combinations below now have the targeted coverage recorded above,
not complete application or downstream certification:

- Laravel 12 with PHP 8.2, 8.3, 8.4, and 8.5
- Laravel 13 with PHP 8.3, 8.4, and 8.5
- Explicit legacy combinations required by downstream applications, tracked
  separately with their upstream end-of-life status

As of this audit date, Laravel 11's security support ended on 2026-03-12;
Laravel 12 receives security fixes until 2027-02-24, and Laravel 13 until
2028-03-17. PHP 8.2–8.5 remain under upstream security support; PHP 8.2 reaches
its scheduled end on 2026-12-31. Recheck these dates before each release.

Sources: [Laravel support policy](https://laravel.com/framework/docs/releases),
[PHP supported versions](https://www.php.net/supported-versions.php).

## Changes and releases

- Keep the existing `Encore\Admin` namespace and package identity unchanged in
  the initial maintenance patch; evaluate publication decisions separately
- Do not widen constraints merely to make Composer accept an untested version
- PHP minimum-version changes must be intentional and documented; the current
  minimum is PHP 8.2, with PHP 8.3+ recommended
- Record the exact framework, PHP, dependency set, commands, and result for each
  newly verified combination
- Distinguish passed, failed, blocked, and not-run checks in PRs and release notes

## Historical isolated integration coverage (2026-10-02)

The [real Laravel integration harness](tests/integration/README.md) boots
Laravel with Testbench and exercises the package provider, auth HTTP lifecycle,
SQLite migrations/seeding and persisted operation-log redaction. Its ten tests
and 54 assertions pass locally on PHP 8.4.25 with Laravel 12.69.3 and 13.34.0.
A negative control using the old middleware fails the three redaction tests.

This consumer has its own development dependencies; the root legacy development
requirements remain unchanged. The package PHP requirement is now `^8.2`. The historical 73-test suite now has a separate [BrowserKit runner](tests/browserkit/README.md);
these focused passes do not establish full Laravel support.
See the harness README for exact dependency versions and untested areas.
