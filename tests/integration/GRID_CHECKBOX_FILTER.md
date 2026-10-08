# Grid Checkbox scalar query links

Grid `in('code')->checkbox(...)` and `notIn('code')->checkbox(...)` now render
scalar links such as `?code=1` as well as their usual one-item array form
`?code[]=1`. The filter already normalized both forms to arrays for SQL. The
checkbox view instead read the raw scalar from the request and passed it to
`in_array()`, causing a PHP type error after the query succeeded. This also
affected zero and text IDs, named Grids and both filter layouts.

The view now casts its selected-value input to an array. Existing array input,
loose membership comparisons, blank/absent handling, SQL conditions and stored
values are unchanged. Native checkbox forms still submit `code[]` (or the
existing named-Grid equivalent); scalar links are accepted for compatibility
with bookmarked or application-generated URLs. This is not a change to the
filter's default-value or query-sanitization contract.

## Upgrade notes

Reconcile the change in any published or overridden
`admin::filter.checkbox` view and refresh stale compiled views through the
application's usual process. No asset rebuild, schema/data migration,
dependency or minimum-version change is required. Check each consuming Laravel
application independently, especially custom views or presenters.

## Regression coverage and limits

Run `vendor/bin/phpunit --filter GridCheckboxFilterTest` in the isolated
[integration harness](README.md). The 33 cases include:

- 24 scalar-link cases across In/NotIn, named/plain Grids, container/modal
  layouts, and zero/nonzero/text values. They compare actual HTTP/SQLite rows
  and SQL bindings with canonical one-item arrays.
- Shipped iCheck initialization with shipped jQuery 2.1.4 and modern jQuery,
  selected/checked styling, two unchanged native FormData resubmissions,
  clearing, reselection and unchanged stored values.
- Eight layout/condition controls for absent and blank query parameters plus
  multi-value and sparse arrays, and a direct view case retaining loose
  numeric-string membership and NULL/empty fallbacks.

The JavaScript runs offline in jsdom; there is no new live-browser, network,
full PJAX, authentication/CSRF, non-SQLite or downstream-application coverage.
Nested/object inputs and unrelated Form Checkbox/default semantics are not
newly certified.

## 日本語

Grid の `in()->checkbox()` / `notIn()->checkbox()` で、`?code=1` のような
単一値の URL を開くと、SQL は実行できてもビューの `in_array()` に文字列が
渡され、表示が失敗していました。選択値をビュー側で配列化し、通常の
`?code[]=1` と同じ選択を表示します。ゼロ・文字列 ID、名前付き Grid、通常・
モーダル表示が対象です。フォームの送信名、既存の配列入力・緩い比較・空値処理、
SQL と保存値、既定値の仕様は変更しません。

公開済み・独自の `admin::filter.checkbox` ビューにも変更を反映し、必要に
応じてアプリの通常の方法でコンパイル済みビューを更新してください。
依存関係・最低要件の変更、アセット再構築、データ移行は不要です。

33 ケースで実際の HTTP/SQLite、クエリのバインド、同梱 iCheck と新旧 jQuery、
選択状態、未変更のネイティブ再送信 2 回、解除・再選択、既存の配列・空値・
緩い比較を検証します。JavaScript はオフラインの jsdom です。実ブラウザー、
ネットワーク、PJAX 全体、認証・CSRF、他の DB、個別アプリや入れ子の入力の
動作は今回新たに保証していません。各 Laravel アプリとの連携は個別に確認してください。
