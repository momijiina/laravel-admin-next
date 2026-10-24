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

`ModelTree::delete()` deletes direct children through a query builder, bypassing
their model deletion callbacks. A follow-up needs an explicit descendant,
model-event and transaction contract, including role-pivot cleanup and other
models using this trait. The observation establishes persisted orphan records;
it does not establish the final UI state after PJAX replacement.

## Empty menu URIs

Posting `uri=''` through the menu form invokes Laravel's normal conversion to
NULL. Rendering then emits PHP 8.4 null-argument deprecations from
`UrlGenerator::isValidUrl()` and `admin_url()` in the tree and sidebar paths.
A normalization change should cover both renderers and preserve the current
root and relative-link behavior. Empty URI values should remain valid for
menu groups; requiring a URL would change that contract.

## Shared URL-scheme policy

The existing tree and sidebar both rely on Laravel's `isValidUrl()`. A separate
Laravel 12 check confirms it accepts `javascript://example.test/%0Aalert(1)`.
HTML escaping preserves attribute boundaries but does not restrict navigation
schemes. A follow-up should define one policy for both renderers, covering
HTTP(S), relative/protocol-relative URLs, fragments and intentionally supported
mailto/tel/sms links. Verify actual navigation in a disposable browser with
harmless local markers; the literal-rendering regressions do not follow their
supplied URI values.

## 日本語

次の対応候補として、深いメニューを削除した際に孫と中間テーブルが残る問題、
空 URI が NULL になった際の PHP 非推奨警告、管理ツリーとサイドバーに共通する
URL スキームの許可方針を記録します。今回の文字列表示修正には含めていません。
削除イベントとトランザクション、グループの空 URI、既存リンクの用途を確認し、
それぞれの回帰テストと互換性説明を伴う変更として進めてください。
