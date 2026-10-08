# Grid Between named bound order

`between('quantity')` treats `quantity[start]` as the lower bound and
`quantity[end]` as the upper bound regardless of their order in the URL.
For example, `quantity[end]=1&quantity[start]=0` now returns the same rows and
uses the same SQL bindings as `quantity[start]=0&quantity[end]=1`. Previously,
Laravel consumed the values in insertion order, so the first URL reversed the
bounds. The displayed form still showed the intended values, and submitting it
unchanged could unexpectedly produce a different result.

Paired bounds are explicitly ordered by name; the condition array retains its
`start` and `end` keys. This does not sort the values: `start=1&end=0` remains a
reversed range with the database's existing semantics. One-sided, empty and zero
bounds, inclusive operators, named Grid prefixes, form names and stored values
are unchanged. Review custom `Between::condition()` overrides or integrations
that deliberately relied on input-array insertion order. There are no dependency
or minimum-version changes, schema/data migrations or asset/view republishes.
Verify compatibility with each consuming Laravel application separately.

## Regression coverage and limits

Run `vendor/bin/phpunit --filter GridBetweenNameTest` in the isolated
[integration harness](README.md). Sixteen added HTTP/SQLite cases cover end-first
query strings in named and unnamed Grids through ordinary and datetime views,
with positive/zero, negative, zero-width and genuinely reversed bounds. They
assert row results, pagination counts, exact count/row SQL bindings, displayed
values, two unchanged native FormData resubmissions and reset links. The 40
existing cases retain ordinary-order, open/empty bounds and form-naming controls.
One direct case checks that condition keys and the original display value are
preserved, including integer and date-string values.

FormData runs in offline jsdom. The datetime view deliberately uses a numeric
column to isolate the shared range behavior; date parsing, time zones and picker
interaction are not certified. There is no new live-browser, PJAX, network,
relation-query or non-SQLite execution coverage.

## 日本語

`between()` の範囲は、URL で `end` が先に現れても `start` を下限、`end` を上限
として適用します。従来は配列の順序で上下限が逆転し、入力を変えずにフォームを
再送信すると結果が変わる場合がありました。条件配列のキーは維持します。
値の大小順で並べ替える変更ではなく、`start=1&end=0` のような逆順範囲、片側・
空欄・ゼロ・包含境界の扱いは従来どおりです。独自の条件生成や入力順依存を確認
してください。依存関係・最低要件、移行、アセット・ビューの再公開は不要です。

追加の 16 ケースは名前付き・名前なし、通常・日時表示、負数・ゼロ幅・逆順範囲で
HTTP/SQLite の行・件数・SQL バインド・再表示・未変更の再送信 2 回・リセットを
検証します。既存の 40 ケースは通常順・片側・空欄・名前整形の確認を維持します。
さらに 1 ケースで条件配列のキーと元の表示値を確認します。
日時表示は数値列で検証し、日付解析・タイムゾーン・ピッカー操作は対象外です。
実ブラウザー・PJAX・ネットワーク・関連モデル・他の DB は新たに検証していません。
各 Laravel アプリとの連携は個別に確認してください。
