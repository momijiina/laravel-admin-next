# Empty menu URIs on modern PHP

Laravel converts an empty menu URI submitted through the built-in form to NULL.
NULL remains a valid value for menu groups and leaves. Previously, rendering it
passed NULL to Laravel's URL validator and PHP's `trim()`, producing PHP 8.4
deprecation diagnostics in the management tree, sidebar and menu search.

`admin_url(null)` and `admin_base_path(null)` now resolve like an empty string.
The tree and sidebar also normalize NULL before their direct URL validation.
Normalization happens while resolving a URL; saved menu values are not changed.
An empty leaf links to the configured admin root, while a parent with children
keeps its expand/collapse link. The string `"0"` remains a path segment.
Relative, absolute, protocol-relative and fragment links retain their existing
resolution and sidebar target behavior.

## Upgrade

Merge the change in `resources/views/partials/menu.blade.php` into a published
override under `resources/views/vendor/admin/partials/menu.blade.php`. Refresh
compiled views with `php artisan view:clear` and, if normally used,
`php artisan view:cache`. Reload open admin pages. Review copied
`MenuController::treeView()` implementations for the NULL normalization.
No schema migration, data rewrite, asset publication or dependency change is
required. Custom URL helpers defined before the package's helpers retain
responsibility for their own NULL handling.

## Verification

Run `vendor/bin/phpunit MenuTreeRenderingTest.php` from the isolated
[integration consumer](README.md). The regression exercises blank form input,
persisted NULL/empty values, full-page tree/sidebar/search links, real PJAX
filtering, and URL helpers with configured prefixes. It explicitly collects
deprecation diagnostics and requires none. Existing literal-value rendering
assertions remain in the same suite.

Local verification on 2026-10-27 with PHP 8.4.27 passed **22 tests / 666
assertions** in each of six configurations: Laravel 12.70.1 and 13.38.0, each
with DomCrawler 6.4.44/Masterminds, native DomCrawler 5.4.52/libxml 2.9.14,
and native DomCrawler 5.4.52/libxml 2.15.3. The last library was loaded only for
each test process, as a targeted serialization check. Before the production
change, the same suite failed its three NULL form-input cases and direct
NULL-helper case on deprecation assertions. The historical BrowserKit suite
also passed **125 tests / 1,235 assertions** on Laravel 12, and all standalone
compatibility checks passed, including diagnostics-free lint of 330 PHP files.

The [fresh application smoke](../fresh-app/README.md) covers blank menu URIs
through real cookies/CSRF and full/PJAX rendering with cached Laravel apps.
These tests use SQLite. Other databases, application-specific overrides and
other browsers need their own checks. URL-scheme acceptance and nested-menu
deletion remain separate [follow-up work](../../docs/menu-followups-2026-10-24.md).

## 日本語

メニューの URI を空欄で保存すると Laravel が NULL に変換します。URL を解決する
際に NULL を空文字として扱い、管理ツリー・サイドバー・メニュー検索で発生していた
PHP の非推奨警告を防ぎます。空欄の末端メニューは管理ルートへのリンク、子を持つ
親は開閉用リンクを維持します。保存済みデータと `"0"` のパスは変更しません。

公開済みの `partials/menu.blade.php` とコピーした `MenuController::treeView()` を
更新し、コンパイル済みビューを更新して画面を再読み込みしてください。
DB 移行・アセットの再公開・依存条件の変更は不要です。URL スキームの許可方針と
深いメニューの削除は、この修正の対象外です。
