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

### DomCrawler 5.4 and PJAX serialization (2026-10-26)

PR #130's original test assumed that PJAX preserves the exact `href` spelling
of a full-page response. The five DomCrawler 5.4 CI configurations failed,
including on rerun, while the 6.4 configurations and both Chromium jobs passed.
With DomCrawler 5.4.52 and no optional `masterminds/html5` dependency, both
Laravel 12/13 reproduce five failures in the seven original tests.

The native `DOMDocument::saveHTML()` serializer used by that configuration
percent-encodes raw quotes and spaces in URI attributes during PJAX filtering.
For example, `reports/" text` becomes `reports/%22%20text` in `href`; its visible
label remains unchanged. Masterminds HTML5, required by DomCrawler 6.4, preserves
the attribute spelling. This is the existing PJAX serializer behavior.

The regression now expects that exact transformation only for native-parser
PJAX responses. Full-page attributes and all labels retain exact comparisons,
and element/attribute injection checks remain. A mixed raw/percent-encoded URI
ensures existing `%22`, `%20`, `%2522`, `%2F`, plus signs and other delimiters are
neither decoded nor double-encoded. The test also requires `X-PJAX-URL` to prove
that PJAX filtering ran rather than silently returning the full page.

The corrected suite passes **8 tests / 246 assertions** in each of four local
PHP 8.4.27 combinations: Laravel 12.70.1 and 13.38.0, each with DomCrawler
5.4.52 (without Masterminds) and 6.4.44 (with Masterminds). Production code,
supported dependency ranges and the existing full-suite CI matrix are unchanged.
The DomCrawler workflow now runs this focused test before JavaScript setup and
the full integration suite, so this failure is reported early.

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
[follow-up audit](../../docs/menu-followups-2026-10-24.md) records these and the
separate NULL-URI diagnostics for the next changes.

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

2026-10-26 追記: DomCrawler 5.4 のネイティブパーサーでは、PJAX 処理時に URI
属性内の引用符・空白を `%22`・`%20` に変換します。従来のテストがこの表記差を
考慮していなかったため、再実行しても失敗していました。PJAX の場合だけ正確な
変換結果を期待し、通常画面・表示文字列・HTML 注入防止の検証は維持しました。
既存のパーセント表記を壊さないケースと PJAX 実行確認も追加しています。
Laravel 12/13 × DomCrawler 5.4.52/6.4.44 の各構成で 8 テスト・246 アサーション
が成功しました。製品コードや依存条件は変更せず、CI の冒頭にも対象テストを
追加しています。
