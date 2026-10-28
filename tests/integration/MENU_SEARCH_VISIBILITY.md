# Menu-search visibility

The built-in menu search now applies the same role and permission checks as the
sidebar. Previously, `Admin::menuLinks()` flattened every leaf in the menu tree,
including descendants of hidden parents. A restricted user could therefore find
the seeded administrator-only Users, Roles, Permission, Menu and Operation log
entries in search even though the sidebar hid their Admin parent.

Each node must pass both the current user's `visible()` role check and `can()`
permission check before it or its descendants can contribute search results.
An allowed child does not override a hidden ancestor. Allowed leaves retain
their ordering and their `title`, `uri`, and `icon` values. Permission-free and
role-free nodes retain their existing meaning, as does the administrator
exemption. The permission slug `"0"` still requires the existing explicit grant.

The checks use the configured admin guard and user model. Disabling
`check_menu_roles` continues to omit roles from the built-in database tree,
matching the sidebar. `menu_bind_permission` controls the editing UI; it does
not bypass saved permission checks. Menu visibility does not grant permission
to a route, and this change does not change route authorization or URL handling.

## Application compatibility

Review overrides of `Admin::menuLinks()` and menu-search views that build their
own link lists. Authenticated callers now receive only leaves visible to their
user. Custom user models must provide the same `visible()` and `can()` methods
already required by the sidebar. Calls without an authenticated user retain the
legacy flattening behavior; this helper is not an authorization API for public
endpoints. Authentication middleware still protects the built-in sidebar.

Reload open pages to replace their already-rendered search list. PJAX normally
replaces the content container, not the sidebar, so permissions changed during an
open session require a full page load to refresh the displayed menu. Route
authorization remains responsible for every request. There is no schema/data
migration, dependency change, asset rebuild or built-in view change.

日本語: サイドバーで非表示の項目が検索に出ていた問題を修正します。親から順に
ロール・権限の両方を確認し、非表示の親の子孫も検索から除外します。検索への表示と
URL へのアクセス権限は別の判定です。独自の検索一覧生成処理を確認し、開いたままの
画面は全体を再読み込みしてください。ログインしていない状態でのヘルパーの既存動作は
維持するため、公開エンドポイントの認可処理としては使用しないでください。

## Verification

Run the focused regression in the [integration consumer](README.md):

```sh
vendor/bin/phpunit MenuSearchVisibilityTest.php
```

The tests render the real sidebar through Laravel's HTTP middleware and SQLite
models. They check independent role/permission restrictions and their conjunction,
hidden parents/grandparents, direct and role grants, disabled database role
loading, explicit menu arrays, ordering, administrator and guest behavior, and
grant/revoke of the zero-valued permission without caching filtered output.

On PHP 8.4.27, Laravel 12.70.1 / PHPUnit 11.5.57 and Laravel 13.38.0 /
PHPUnit 12.5.40 each pass **11 tests / 87 assertions**. The original implementation
fails eight cases on each version (**11 tests / 47 assertions / 8 failures**);
the sidebar expectations and administrator/guest controls already pass there.
Running this test with `MenuTreeRenderingTest`, `PermissionMiddlewareTest` and
`PermissionEvaluationTest` in randomized order (seed 134) passes **69 tests /
1,053 assertions** on each framework version. These results were recorded on
2026-10-28 and are local checks, not a hosted CI result.
The historical BrowserKit runner also passes all **125 tests** on both versions.

The separate [fresh-application browser runner](../fresh-app/README.md) exercises
restricted-user login and real menu search with cached official applications.
See its README for the browser scenario and local verification results.
