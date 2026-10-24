# Permission paths, method rules and zero slugs

A restricted user with a permission whose `http_path` was `"0"` and whose HTTP
methods were blank previously passed every route check: PHP's `empty()` treated
the path as absent. The path is now matched normally. With an empty admin prefix,
it can match `/0`; other paths are denied. With a prefix, use the documented
leading slash, such as `/0` for `/admin/0`. Prefix concatenation for paths without
a leading slash is otherwise unchanged.

Permission slug `"0"` and integer ability `0` likewise require an assigned
permission with the exact string slug `"0"`. Previously they passed `can()`
without an assignment, including `admin.permission:check,0` and the shipped menu
partial. Assigned slugs such as `"00"` or `"0e123"` do not satisfy a zero check.
Direct and role permissions still work, and the administrator role keeps its
existing bypass. Comparisons for other abilities, including other empty values,
retain their previous handling.

Per-line HTTP method rules are parsed before the configured admin prefix is
added. For example, `GET,POST:/orders/*` matches GET and POST requests under
`/admin/orders/*` with the default prefix. Previously `admin` was included in the
first method name, and the path lost its prefix. A line without its own methods
still uses the permission's `http_method` field. Method case normalization,
custom methods, wildcards and CRLF-separated paths remain supported.

The custom HTTP method token `"0"` remains a literal method in both the
`http_method` field and a per-line rule such as `0:/orders`. It no longer becomes
an empty method list that admits GET or POST, and it survives lists such as
`0,GET:/orders`. Method names use exact string membership after the existing
uppercase normalization, so method `"00"` does not match `"0"`. Empty method
markers such as `:/orders` retain the all-methods meaning previously available
with an empty admin prefix; they now work with a configured prefix too.

The method separator is a colon before any slash in that line. Only the first
such colon is split: `/reports/period:2026:10` is a literal path, and
`GET:/reports/period:2026:10` retains both colons in the path. A nullable stored
path is normalized to an empty string without PHP's `str_replace(null)`
deprecation. Existing blank-path behavior remains: blank methods plus a blank
path allow every route; with nonblank methods and the default prefix, a blank
path matches the admin root for those methods. This change does not redefine
blank permissions.

## Upgrade considerations

Review applications that used zero as an unrestricted permission, ability or method,
and method/path records written to work around the previous prefix parsing.
Method-qualified paths are relative to `admin.route.prefix`, just like ordinary
permission paths. Slash-leading literal-colon paths and method-qualified paths
with additional colons can now match; review those records against the routes
they are intended to grant. Existing custom `can()`, `shouldPassThrough()` or
HTTP-path accessor overrides need the corresponding application review.

No dependency change, schema/data migration, asset or view republish is required.
Configured permission and role models remain in use; a custom
`shouldPassThrough()` implementation continues to decide its own matches.

## Verification and limits

Run in the isolated [integration harness](README.md):

```sh
vendor/bin/phpunit PermissionEvaluationTest.php
```

On 2026-10-24 (Japan time), PHP 8.4.27 with Laravel 12.70.1 / PHPUnit 11.5.57 and Laravel
13.38.0 / PHPUnit 12.5.40 each passed **16 tests / 161 assertions**. With the
original model and middleware classes, the same tests failed in twelve cases on
each framework.

The tests use shipped configuration, migrations and seed data with real
in-process HTTP/SQLite requests. They check seeded administrator and restricted
accounts; direct and role assignments; positive and negative zero paths and
slugs, including distinct numeric-looking slugs; the shipped menu partial;
wildcard and method boundaries; default, custom and empty prefixes; CRLF lines
and custom methods, including literal zero and mixed `0,GET` lists; literal colons;
nullable paths without deprecations; and configured models with a custom
permission matcher.

Forbidden HTTP checks explicitly use AJAX requests without PJAX, for which the
existing checker returns 403. Normal HTML/PJAX denial still uses its existing
`send()`/`exit` flow; these tests do not establish its status or browser behavior.
There is no claim of live-browser/PJAX, route-cache, non-SQLite database, arbitrary
custom-model or complete application compatibility coverage.

## 日本語

HTTP パスが `"0"`、HTTP メソッドが空の権限を持つ制限ユーザーが、従来は
`empty()` の判定により別のパスにもアクセスできました。ゼロを通常のパスとして
照合し、接頭辞が空なら `/0` のみを許可します。既定の接頭辞では、ドキュメント
どおり `/0` を保存して `/admin/0` に対応させてください。

権限 slug `"0"` と整数の能力値 `0` も、文字列 `"0"` の権限割り当てが必要に
なります。`"00"` や `"0e123"` の割り当てではゼロの条件を満たしません。
`admin.permission:check,0` とメニュー表示に反映されます。個人・ロールへの
割り当て、管理者の扱い、ゼロ以外の空値の扱いは維持します。

`GET,POST:/orders/*` のような行単位のメソッド指定は、解析後に管理画面の
接頭辞を追加します。メソッド未指定行は権限全体の設定を使い、独自メソッド・
大文字小文字・ワイルドカード・CRLF を維持します。パス中のコロンを保持し、
null のパスで PHP の非推奨警告を出さなくなります。パスとメソッドの両方が空の
権限は、従来どおり全ルートを許可します。

HTTP メソッド `"0"` は空の一覧にならず、そのメソッドのみを許可します。
`0,GET:/orders` のような混在指定も保持し、`"00"` は `"0"` と一致しません。
`:/orders` のような空のメソッド指定は全メソッドを許可する従来の意味を保ち、
接頭辞のある設定でも機能します。

ゼロや従来の接頭辞解析を前提にした権限・メソッド設定、コロンを含む保存済みパス、独自の
権限メソッドを確認してください。依存関係・DB 移行・アセットやビューの再公開は
不要です。上記の Laravel 12/13 で各 16 テスト・161 アサーションが成功しています。
403 の検証は PJAX なしの AJAX に限定し、通常の HTML/PJAX 応答・実ブラウザー・
ルートキャッシュ・他 DB・任意の独自モデルの保証ではありません。
