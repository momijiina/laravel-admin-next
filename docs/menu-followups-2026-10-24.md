# Menu follow-up findings — 2026-10-24

These observations come from the menu audit against `cadb3df`, using a disposable
official Laravel 13.38.0 application, PHP 8.4.27 and Chromium 154.0.8037.57.
They remain separate from the [literal menu tree rendering fix](../tests/integration/MENU_TREE_RENDERING.md).

## Deleting a nested menu

A three-level menu was created through authenticated menu POSTs, with a role
assigned to the grandchild. Deleting the root through the tree control returned
HTTP 200 and `status: true`. Database inspection showed that the root and direct
child were removed, while the grandchild and its role pivot remained, referring
to the deleted child.

The [subtree-deletion follow-up](../tests/integration/MENU_DELETION.md) replaces
that direct-child bulk delete with per-model descendant deletion and scoped
transactions. It covers role pivots, model vetoes/events, soft deletion and the
real tree/PJAX flow. The original observation above establishes persisted orphan
records; it does not establish that audit's final UI state after replacement.

## Empty menu URIs

Posting `uri=''` through the menu form invokes Laravel's normal conversion to
NULL. Rendering then emits PHP 8.4 null-argument deprecations from
`UrlGenerator::isValidUrl()` and `admin_url()` in the tree and sidebar paths.
The [NULL URI follow-up](../tests/integration/MENU_NULL_URI.md) normalizes NULL
in URL helpers and both renderers, also covering the menu-search links. It keeps
root/relative-link behavior and valid empty group URIs without rewriting data.

## Shared URL-scheme policy

The existing tree and sidebar both rely on Laravel's `isValidUrl()`. A separate
Laravel 12 check confirms it accepts `javascript://example.test/%0Aalert(1)`.
HTML escaping preserves attribute boundaries but does not restrict navigation
schemes. The [menu URL policy follow-up](../tests/integration/MENU_URL_SCHEMES.md)
now applies one allowlist to the tree, sidebar and search, preserving HTTP(S),
relative/protocol-relative URLs, fragments and mailto/tel/sms. Its Chromium
regressions check clicks with harmless local markers and allowed navigation;
the earlier literal-rendering tests alone did not follow their URI values.

## 日本語

次の対応候補として、深いメニューを削除した際に孫と中間テーブルが残る問題、
空 URI が NULL になった際の PHP 非推奨警告、管理ツリーとサイドバーに共通する
URL スキームの許可方針を記録します。今回の文字列表示修正には含めていません。
空 URI の警告、子孫の削除、共有する URL スキームの許可方針は、上記リンク先の
個別修正で対応します。それぞれの更新手順と検証範囲を確認してください。
