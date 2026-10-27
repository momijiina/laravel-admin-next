# Built-in menu tree text

The built-in `MenuController` now escapes saved titles, icon class values and the
resolved URI before adding them to its tree HTML. A title such as
`<b>literal</b> &amp;` stays visible as that exact text. Quotes in an icon or URI
stay inside the existing attribute, and the URI label retains its literal text.
Previously these values could create elements or event-handler attributes in
the menu-management page.

The change applies to the built-in tree callback. Parent branches still omit
URI links, ordinary icons and links retain their existing structure, and child
branches and tree controls continue to work. Custom `Tree::branch()` callbacks
continue to return HTML; applications that provide one are responsible for
escaping their own interpolated values. Stored menu values are not rewritten.

URL acceptance and resolution still use Laravel's `url()->isValidUrl()` and the
package's `admin_url()`. HTML escaping is not a URL scheme allowlist. The existing
resolver can accept executable schemes, and the sidebar shares that URL policy.
A shared scheme policy for tree and sidebar links remains separate work. These
tests inspect URI text and attributes without following the supplied links.

## Checks

Run the focused HTTP/SQLite/DOM tests in the isolated [integration consumer](README.md):

```sh
vendor/bin/phpunit MenuTreeRenderingTest.php
```

They save values through the production menu form/controller and inspect full
and PJAX responses. Coverage includes Unicode, quotes, HTML-like and
entity-looking text, relative/absolute/protocol-relative links, fragments and
`mailto:` values. Assertions check the resulting text and attributes, edit/delete
links, unchanged persisted rows, parent/child structure, default icon classes,
and the existing custom-HTML callback contract. The fixture restores collected
Form assets and Admin static state.

The [fresh-application browser smoke](../fresh-app/README.md) also creates two
menu entries through authenticated POSTs with browser cookies and a real CSRF
token. Its values fit the shipped database column sizes. Chromium checks that
stored SVG-like title text and quoted icon/URI values create neither elements
nor event handlers after full navigation and a real PJAX refresh. It also
checks the seeded parent's expand/collapse controls, icon and child link.
The existing Laravel 12 and 13 browser CI jobs run these assertions.

Local verification on 2026-10-24 with PHP 8.4.27:

- Laravel 12.70.1 / PHPUnit 11.5.57: **7 tests, 194 assertions pass**.
- Laravel 13.38.0 / PHPUnit 12.5.40: **7 tests, 194 assertions pass**.
- With adjacent `JsonSessionFlashTest` and `AdminLifecycleTest`, both framework
  versions pass **35 tests, 2,024 assertions**.
- Both fresh browser flows pass with Node 24.19.0, Playwright 1.62.1 and Chromium
  154.0.8037.57. The PJAX assertion waits for `pjax:end` and replacement of a
  marked DOM node before inspecting the refreshed tree.

The saved original controller fails six of these seven focused tests; the
custom-HTML callback control passes. Negative controls preload a disposable
copy of that controller, without reverting the working checkout. Independently
omitting title, icon or URI escaping is also caught by the focused tests.
Preloading the original controller in the disposable Laravel 13 browser app
makes the smoke fail at its literal-title assertion and captures failure
artifacts; its own HTTP server is stopped normally.

### DomCrawler and libxml serialization (2026-10-26)

PR #130's initial test assumed that PJAX preserves the exact `href` spelling
of a full-page response. The five DomCrawler 5.4 CI configurations failed,
including on rerun, while the 6.4 configurations and both Chromium jobs passed.
With DomCrawler 5.4.52 and no optional `masterminds/html5` dependency, both
Laravel 12/13 reproduce five failures in the seven original tests.

Native `DOMDocument::saveHTML()` URI serialization also differs across libxml
versions. libxml 2.9.14 percent-encodes both raw quotes and spaces; 2.15.3 keeps
quotes literal in the parsed attribute but still encodes spaces. For example,
`reports/" text` becomes `reports/%22%20text` with the older library and
`reports/"%20text` with the newer one. Link text remains unchanged. The first
follow-up fixed the older spelling in the test, which still failed on modern
libxml: **6 failures / 8 tests** reproduced on both Laravel versions. Masterminds
HTML5, required by DomCrawler 6.4, has its own serialization behavior.

For native-parser PJAX responses, the test now builds an independent anchor
using DOM `setAttribute()` and serializes it with the loaded library to establish
the expected URI spelling. This control neither interpolates unescaped HTML nor
calls the application's controller or PJAX implementation. Full-page attributes,
all text and class values, injected elements/attributes and persisted records
retain exact assertions. Mixed raw/percent-encoded values remain covered, and
an encoded-only URI also requires exact equality to the original resolved URI,
independently of the serialization control. `X-PJAX-URL` proves that filtering
ran rather than silently returning the full page.

The corrected suite passes **9 tests / 285 assertions** on PHP 8.4.27 with:

- Laravel 12.70.1 and 13.38.0 / DomCrawler 5.4.52 / libxml 2.9.14.
- Both framework versions / DomCrawler 5.4.52 / libxml 2.15.3 loaded for the
  individual PHP process. This is a targeted serializer check, not a complete
  verification of that substituted runtime.
- Both framework versions / DomCrawler 6.4.44 with Masterminds HTML5.

Production code, supported dependency ranges and the full-suite CI matrix are
unchanged. The focused test runs before JavaScript setup and the full suite.
Its CI annotations include PHP/Crawler/libxml versions and PHPUnit failure
details, so a future failure can be diagnosed without relying on downloadable
job logs.

## Upgrade and limits

Review application overrides of `MenuController::treeView()` that copy its
callback, then reload open menu pages. Existing titles containing intentional
HTML now display literally in the built-in management tree, matching the
sidebar's text rendering. There is no schema/data migration, asset/view
republish, public API or dependency/minimum-version change.

The integration fixture bypasses CSRF and uses an authenticated test guard;
the separate browser flow covers real cookies and CSRF with SQLite, official
Laravel skeletons and Chromium. Other databases, browsers, custom controllers,
custom branch callbacks, and arbitrary invalid/binary text are outside these
checks. Menu deletion and URL-scheme policy are separate concerns; the
[follow-up audit](../../docs/menu-followups-2026-10-24.md) records these concerns.
The separate [NULL URI follow-up](MENU_NULL_URI.md) covers empty menu paths,
sidebar/search rendering and URL helpers.

## 日本語

標準のメニュー管理ツリーで、保存済みのタイトル・アイコンクラス・解決後 URI
を HTML 用にエスケープします。`<b>literal</b>` や `&amp;` はそのまま文字列と
して表示され、引用符は属性内に保持されます。従来は要素やイベント属性を作成
できました。親子構造、通常のアイコン・リンク、編集・削除リンクは維持します。

独自の `Tree::branch()` は従来どおり HTML を返します。独自コールバックは補間
する値を自分でエスケープしてください。保存済みデータは変更しません。標準の
`MenuController::treeView()` をコピーした実装を確認し、管理画面を再読み込み
してください。スキーマ・データ移行やアセット・ビューの再公開は不要です。

URL 判定・解決の方針は変更しません。HTML エスケープは URL スキームの許可
リストではなく、現状の判定は実行可能なスキームも受け入れます。サイドバーと
管理ツリーに共通のスキーム方針は別途検討が必要です。検証では URI の文字列と
属性を確認し、そのリンクには移動しません。

実 HTTP・SQLite・DOM のテストは通常画面と PJAX 出力を検証します。別の
実ブラウザーテストでは Cookie と CSRF を通して保存し、通常画面と PJAX 更新、
親の開閉を確認します。上記の Laravel 12/13 では各 7 テスト・194 アサーション
が成功しています。他の DB・ブラウザー・独自実装・任意のバイナリ文字列や、
メニュー削除全体の互換性を保証するものではありません。

2026-10-26 追記: DomCrawler 5.4 のネイティブパーサーが使う libxml は、
バージョンによって URI 属性の引用符を保存する表記が異なります。最初の修正は
古い libxml の変換結果を固定していたため、新しい環境では失敗が残りました。
テストは、安全に DOM API で作った対照リンクの変換結果を利用するよう修正し、
通常画面・表示文字列・HTML 注入防止・保存値の厳密な検証を維持しています。
既存のパーセント表記だけの URI は対照リンクとは別に元の値との一致も確認します。
Laravel 12/13 と新旧 libxml、DomCrawler 6.4 の各対象構成で 9 テスト・285
アサーションが成功しました。製品コード・依存条件は変更していません。
CI ではランタイムの情報とテストの失敗内容を注釈にも出力します。
