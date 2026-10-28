# Menu URL schemes

The menu-management tree, sidebar and menu search share `MenuUrl::resolve()`.
The previous Laravel URL validator could accept values such as
`javascript://example.test/%0Aalert(1)` as complete URLs. HTML escaping kept
attribute boundaries intact but did not prevent a browser from executing that
navigation. The menu resolver now applies an explicit scheme allowlist.

| Stored URI | Menu behavior |
| --- | --- |
| `http:` / `https:` | Allowed, case-insensitively |
| `mailto:` / `tel:` / `sms:` | Allowed, case-insensitively |
| `//example.test/path` or `#section` | Allowed as a direct link |
| Relative path, NULL, empty string, or `0` | Resolved using the configured admin prefix |
| Any other explicit scheme, including `javascript:`, `data:`, `vbscript:`, `file:`, `ftp:` and custom schemes | No navigable link |

For scheme classification, the resolver trims ASCII bytes `0x00`–`0x20` from
the edges and removes TAB, LF and CR, matching the relevant browser URL parsing
rules. It does not HTML-decode or percent-decode stored text. For example,
`java\tscript:` is rejected, but `javascript%3Aexample` and
`javascript&#58;example` remain literal relative paths after HTML escaping.
Colons within a relative path or query do not turn it into an explicit scheme.

Only the classification copy is normalized. Stored values are not rewritten,
and allowed direct URLs retain their original spelling. Mixed-case schemes such
as `MAILTO:` now receive the same direct-link treatment as their lowercase form.
The existing sidebar target convention remains: direct links use `_blank`,
while relative links do not. Tree and search links retain no target attribute.
URI attributes explicitly double-encode HTML entities, independently of the
package's global Blade setting, so literal `&amp;` or `&#58;` in a saved URI
does not become a different URL after HTML parsing.

Rejected URIs remain escaped text in the management tree, with edit/delete
controls intact. Sidebar and search retain the label and icon in an anchor
without `href` or `target`, marked `aria-disabled="true"`. This preserves the
search widget's existing label matching without allowing navigation. Parent
menu groups retain their expand/collapse link.

## Upgrade and boundaries

Merge the changes into published `partials/menu.blade.php` and
`partials/sidebar.blade.php` overrides under `resources/views/vendor/admin/`.
Refresh compiled views with `php artisan view:clear` and, if used,
`php artisan view:cache`; reload open pages. Review copied
`MenuController::treeView()` callbacks and previously stored FTP/custom-scheme
links, which now display without navigation.
Remove manual HTML-encoding workarounds from stored URL values: save an ordinary
query separator as `&`, not `&amp;`. Literal entity spellings now stay literal
in sidebar/search URI attributes, matching the management tree.

The public `admin_url()` helper is unchanged and is not a scheme sanitizer.
Custom menu renderers must adopt the resolver and retain normal HTML escaping.
No schema/data migration, asset publication or dependency change is required.

This is a menu scheme policy, not an origin allowlist or a complete URL syntax
validator. HTTP(S) links may lead to other hosts; malformed allowed-scheme
URLs may still be rejected or normalized by the browser. For example,
`https:\\example.test` retains an allowed HTTPS scheme. A leading backslash
without a scheme is resolved as an admin-relative path, with an absolute
admin URL prefix, rather than emitted as a raw network path. Other link types,
redirect destinations, custom application helpers and rendering outside these
three built-in menu surfaces retain their own validation responsibilities.

## Verification

Run `vendor/bin/phpunit MenuTreeRenderingTest.php` from the
[integration consumer](README.md). Tests cover full and PJAX output, stored
values, disabled sidebar/search entries, repair controls, supported schemes,
case variants, literal entity/percent text and control-character obfuscation.
Existing NULL/root/zero and HTML-escaping regressions remain in the suite.

The [Chromium smoke](../fresh-app/README.md) clicks rejected links in all three
surfaces after full and PJAX loads, checks a harmless execution marker and
requires no popup or navigation. It exercises the real search input, plus
allowed relative/PJAX, fragment and loopback HTTP navigation. These checks use
SQLite and Chromium; other application overrides and browsers need their own
verification.

Local checks on 2026-10-28 used PHP 8.4.27 and Laravel 12.70.1 / 13.38.0.
Each framework passed **72 tests / 1,970 assertions** with DomCrawler 6.4.44
and Masterminds, native DomCrawler 5.4.52/libxml 2.9.14, and native DomCrawler
5.4.52/libxml 2.15.3 loaded only for the test process. Native PJAX href checks
use the independently constructed DOM control described in the
[serialization guide](MENU_TREE_RENDERING.md#domcrawler-and-libxml-serialization-2026-10-26).
Full-page attributes and ordinary URI labels retain exact assertions.
Preloading the original controller made all **15 rejected-URI HTTP cases**
fail on both framework versions. Both historical BrowserKit suites passed
**125 tests**; standalone compatibility checks and diagnostics-free lint of
331 production PHP files also passed.

Both Chromium flows passed using existing disposable official Laravel 12/13
application fixtures with refreshed compiled views. The 18 rejected-link clicks
cover three URI spellings, three surfaces and full/PJAX loads; allowed relative,
fragment and loopback HTTP navigation also passed. A separate Laravel 13 run
preloaded only the original controller: clicking its management-tree link set
the harmless JavaScript marker to `1`, failing the explicit execution assertion.
The working checkout remained fixed throughout that negative control.

## 日本語

管理ツリー・サイドバー・メニュー検索で、HTTP(S)、mailto、tel、sms、通常の
相対パス・フラグメント等を共通の判定で扱います。JavaScript など、それ以外の
明示的なスキームはリンクとして出力しません。先頭の制御文字やスキーム内の
タブ・改行も考慮します。保存済みの値は変更せず、管理画面で内容を確認・修正
できます。拒否した項目も検索結果には残ります。

公開済みの `partials/menu.blade.php` と `partials/sidebar.blade.php`、コピーした
管理ツリー実装を更新し、ビューキャッシュを更新して画面を再読み込みしてください。
FTP や独自スキームの既存項目はリンクとして動作しなくなるため確認してください。
汎用 `admin_url()` の仕様は変更しません。許可された HTTP(S) リンクの送信先を
同一ホストに限定する機能や、保存データの移行は含みません。
