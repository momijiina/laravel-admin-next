# Explicit route permission middleware

Every explicit permission rule on a route is now enforced. Previously,
`admin.permission:allow,editor` followed by
`admin.permission:check,edit-records` allowed an editor who did not have
`edit-records`, because only the first rule ran. The two rules now require both
the role and the permission. Reversing their order also enforces both.

The middleware uses Laravel's resolved route middleware list, including nested
route groups, named middleware groups, controller middleware, class names and
aliases. Middleware removed with `withoutMiddleware()` no longer participates
in permission evaluation. Parameterized middleware also enforces its rules
when used with authentication outside the default `admin` group.

Explicit rules continue to override the database HTTP path/method check. If
there are no explicit rules, the ordinary database permission check runs.
Within the built-in rules, `allow` accepts membership in any listed role,
`deny` rejects membership in any listed role, and `check` requires every listed
permission slug. `free` skips the default path check but does not cancel other
explicit rules. An unknown method in a later rule throws the existing
`InvalidArgumentException` before the controller can run.

The existing permission-check configuration switch, authentication exceptions
and administrator exemption in the built-in explicit checkers retain their
behavior. Guest login redirects are provided by the authentication middleware.

## Regression coverage

Run the focused test in the isolated [integration consumer](README.md):

```sh
vendor/bin/phpunit PermissionMiddlewareTest.php
```

The tests boot the real provider, package routes, migrations and SQLite tables,
then log in through the actual admin login route and session guard. They cover
later-rule denials in both orders, successful direct and role permissions,
default path fallback, explicit overrides, aliases, class names, duplicate
registrations, nested and named groups, controller middleware, exclusions,
standalone parameterized rules, `free`, `allow`, `deny`, multiple `check` slugs,
invalid methods, disabled checks, authentication exceptions, administrators and
guest redirects.

Local verification on 2026-10-24 (Japan time) with PHP 8.4.27:

- Laravel 12.70.1 / PHPUnit 11.5.57: **20 tests, 139 assertions pass**
- Laravel 13.38.0 / PHPUnit 12.5.40: **20 tests, 139 assertions pass**
- Laravel 12 with `AdminLifecycleTest`: **35 tests, 1,579 assertions pass**

Against the middleware from `9d70304`, the same test file had **12 failures,
20 tests and 132 assertions** on Laravel 12. Eleven failures exposed skipped
checks or an ignored invalid method; one showed that class-name/group rules
were not recognized as explicit overrides and were incorrectly denied by the
default path check. The baseline loaded the original middleware before
booting the test application, without modifying the working source.

These denial assertions use AJAX requests, for which the existing
`Checker::error()` aborts with HTTP 403. Ordinary HTML denial still renders the
existing error page with HTTP 200 and uses the existing send/exit response
path. This change does not alter that status/response behavior or test full
HTML/PJAX navigation, browser behavior, CSRF handling or other databases.

## Application compatibility

The public `checkRoutePermission(Request)` signature and protected
`$middlewarePrefix` property remain available. The hook now evaluates all
resolved explicit rules and is also called by parameterized middleware
invocations, which previously returned immediately. With multiple middleware
registrations, successful built-in checks can run more than once per request;
there is no request cache or promise that a custom hook executes only once.

Review application overrides of `handle()` or `checkRoutePermission()`, custom
middleware aliases and subclasses that change permission argument syntax or
introduce side effects. Custom authentication models and replacement
middleware keep responsibility for their own authorization behavior. The
tests cover the package's built-in checker and database models. No schema,
dependency, asset or view changes are required.

日本語: `allow,editor` と `check,edit-records` のように複数の条件を付けた場合、
最初だけでなくすべての条件を確認します。別名・グループ・コントローラー上の
設定と除外も Laravel の解決結果に従います。明示条件がある場合は従来どおり
DB の HTTP パス判定に優先します。独自の `handle()` / `checkRoutePermission()`
は、引数付きの呼び出しでもフックが実行され、成功時には複数回呼ばれ得る点を
確認してください。拒否時の既存レスポンス方式は変更しません。検証した 403 は
AJAX の場合で、通常 HTML のエラーページは既存の 200・send/exit のままです。
