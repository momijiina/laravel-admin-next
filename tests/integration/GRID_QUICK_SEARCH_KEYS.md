# Configured Grid quick-search keys

## Behavior and upgrade cautions

A Grid configured with `Grid::$searchKey = 'catalog_search'` now renders its
quick-search input with that key, redisplays the matching request value and
removes that same key from the form action query. Previously, query execution
used the configured Grid key while the renderer read the separate trait property;
a normally submitted custom-key form could silently show unfiltered rows.

The key is resolved when the tool renders. Normal header tools use their bound
Grid, including a subclass that redeclares the static property. A standalone
`new QuickSearch()` without a bound Grid falls back to `Grid::$searchKey`.
Changing the key after creating the tool is reflected on its next render. The
renderer does not change either the Grid or subclass static property.

The default `__search__` key, placeholder, shipped form markup, existing request
lookup and query-source precedence, search parsing, SQL operators/bindings, and
falsey-query short-circuit retain their contracts. In particular, searching for
string `0` still follows the existing unfiltered behavior. Unrelated action-query
parameters, including `__search__` when a custom key is active, are retained.
Native GET replaces the action query with successful form controls; retaining
parameters in the rendered action is not a promise that a native browser submit
or PJAX will preserve another form's state.

Review custom selectors, overridden quick-search renderers/views and hand-written
URLs that rely on the old hard-coded `__search__` name while configuring another
Grid key. Use the same configured key as query execution, and reload open forms
after updating. No new configuration API, dependency or PHP/Laravel minimum,
asset/view republish, schema change or data migration is required. Static key
configuration remains shared according to PHP inheritance rules; this does not
introduce independent per-grid namespaces. Verify integration with each consuming
Laravel application.

## Regression coverage

Run the isolated [integration harness](README.md), or its focused suite:

```sh
vendor/bin/phpunit --filter GridQuickSearchKeyTest
```

The 33 cases cover:

- Default and custom simple keys through normal `Grid::renderHeaderTools()`,
  `Tools::render()`, parent binding and the shipped Blade view.
- Single-column, multiple-column and closure searches with a matching query,
  no match, empty input and unchanged string-zero behavior.
- Actual emitted controls in offline jsdom with native `FormData` and
  `URLSearchParams`, independently matched against Symfony form URLs.
- Direct correctly keyed requests and native-form URLs through Laravel's HTTP
  kernel and SQLite, including rows, paginator totals, count/row SQL predicates
  and bindings, closure arguments, value redisplay and unchanged stored data.
- Standalone fallback, explicit parent binding, subclass redeclaration, late
  key changes, repeated custom/default switching without caching, disabled header
  tools, escaped values/placeholders and unrelated action-query values.

Each test snapshots and restores Grid search/snake-attribute state, Grid Column
state, Admin scripts and the subclass key, including assertion failures. No
production class is replaced with a stub. The fixture uses the existing Node/jsdom
and PHPUnit 11/12-compatible integration dependencies.

## Boundaries

The native-form custom key is `catalog_search`. Keys containing dots, spaces or
brackets can be normalized or interpreted by PHP/request helpers and are not
promised by this repair. This is in-process HTTP/SQLite and offline DOM coverage,
not real browser/PJAX/network, all-database or arbitrary-application certification.
The source change uses existing PHP static access and existing framework APIs;
these tests do not establish execution on historical Laravel releases. Request
parsing, search grammar, zero-search semantics and shared static-key redesign are
separate concerns and unchanged.

## 日本語

`Grid::$searchKey = 'catalog_search'` を指定した Grid のクイック検索は、入力名、
検索値の再表示、フォーム action から取り除くクエリのキーを、その設定に揃えます。
従来は検索処理が Grid のキーを使う一方で描画が trait 側の別のプロパティを参照し、
表示されたフォームから検索しても絞り込みが行われない場合がありました。

描画時に関連付けられた Grid のキーを参照し、static プロパティを再定義した
サブクラスにも対応します。Grid のない単独の `new QuickSearch()` は
`Grid::$searchKey` を使います。ツール作成後のキー変更も次の描画に反映し、描画から
共有の static 値を変更しません。既定の `__search__`、プレースホルダー、フォーム、
リクエストの値と取得順序、検索構文、SQL とバインド、falsey 値の短絡処理は維持
します。文字列 `0` は従来どおり絞り込みなしです。action 内の無関係なクエリも
保持しますが、通常の GET 送信は action のクエリを入力項目で置き換えるため、他の
フォームの状態をブラウザーや PJAX が保持する保証ではありません。

独自セレクター、上書きした描画処理・ビュー、手書き URL が、別のキーを設定しつつ
旧来の `__search__` に依存していないか確認してください。更新後は開いたフォームを
再読み込みしてください。新しい設定 API、依存関係・最低要件の変更、アセット・
ビューの再公開、スキーマ・データ移行は不要です。static 値の共有は PHP の継承規則
のままであり、Grid ごとの独立した名前空間を導入する変更ではありません。他の
Laravel アプリとの互換性はアプリごとに検証してください。

33 ケースで、実際のヘッダーツール、既定・独自キー、単一列・複数列・クロージャー、
FormData と Symfony の一致、HTTP/SQLite の行・件数・SQL・再表示、単独ツール、
サブクラス、描画前の変更、繰り返し描画と共有状態の復元を確認します。独自キーの
送信検証は `catalog_search` が対象です。ドット・空白・角括弧を含むキーの解釈、
実ブラウザー・PJAX・ネットワーク、他のデータベース、古い Laravel の実行は保証
しません。検索構文、ゼロ検索の意味、共有 static 設定の設計変更は対象外です。
