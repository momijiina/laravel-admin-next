# Built-in administrator login throttling

The built-in `AuthController::postLogin()` permits five failed credential checks
within 60 seconds for each guard, username field, lowercase username and request
IP combination. The first failed check starts the window. After five failures,
the next request is rejected before the guard checks credentials, even if the
password is correct. Later failures and blocked requests do not extend the
window. A successful login before the limit clears only that combination's
counter and retains the existing session regeneration, intended redirect and
remember-me behavior.

Failures retain the existing redirect and username error bag. Throttled requests
use the application's `auth.throttle` translation, including `:seconds` and
rounded-up `:minutes`, or an English fallback when it is unavailable. The
response is a redirect, not HTTP 429. Malformed credentials use the usual
validation errors: an HTML redirect or HTTP 422 for JSON requests.

Both credential fields now require strings, including through JSON. Arrays,
numbers and booleans are rejected before the guard or limiter runs. String
`"0"` is valid input. Built-in authentication/throttling failures and synthesized
HTML validation redirects flash only the string username and boolean
remember-checkbox state. Passwords, confirmation values, CSRF tokens, arbitrary
additional fields and raw remember values are excluded from newly flashed old
input. Explicit custom validation responses are preserved and remain responsible
for their own old-input handling. Existing sessions created by older code are
not scrubbed by this change.

Previously, repeated failures had no built-in limit, password arrays could cause
HTTP 500, and ordinary credential failures flashed the whole request, including
the password, into the session.

## Configuration and deployment

The following settings belong inside `auth` in the application's
`config/admin.php`:

```php
'login_throttle' => [
    'enabled' => true,
    'max_attempts' => 5,
    'decay_seconds' => 60,
],
```

Existing published configurations without these keys receive the same defaults
from the controller, so upgrading also enables throttling for those
installations. Set `enabled` to boolean `false` to disable it when the
application supplies a different login policy. Limits must be positive integers;
missing, non-integer or non-positive limits fall back to 5 attempts and 60
seconds respectively. Disabling bypasses limiter reads and writes; previously
recorded entries expire normally.

The limiter uses Laravel's configured `cache.limiter` store, falling back to the
default cache store. Use a persistent store shared by the processes and servers
that serve the same login endpoint. Cache clearing resets counters, and an
isolated per-process or per-server cache cannot enforce a shared limit.

The database cache driver requires its configured cache table. Run the
application's cache-table migration before using that driver; if the application
does not have one, generate it with `php artisan make:cache-table` and apply it
with the application's normal migrations. Missing tables or unavailable cache
connections surface the underlying error before authentication. The controller
does not silently disable the limit or switch to an array store.

No migration to administrator tables or asset/view republish is required. An
already-open login form can submit its existing fields without a reload. Rebuild
the application's configuration cache after changing settings, and reload
long-lived application workers when deploying the code or cached configuration.
Review existing login middleware limits, automation that retries credentials,
and custom forms that expect arbitrary old input. Failed login retries must
re-enter the password.

## Extension points and limits

Controllers inheriting `postLogin()` receive the behavior. A replacement
`postLogin()` or separate authentication controller must integrate its own
policy. Overrides of `username()` work for credential lookup, validation,
counter identity and error/old-input names; a matching custom login view remains
the application's responsibility. Keep string validation when overriding
`loginValidator()`. Explicit `ValidationException` responses are preserved;
synthesized redirects honor its `redirectTo` and `errorBag`. The built-in
HTML/JSON decision uses `Request::expectsJson()`. Review custom exception-handler
JSON negotiation such as `shouldRenderJsonWhen()`, because a synthesized response
takes precedence over that handler decision.

The key uses the real session guard's `getName()`, so an inherited controller
whose `guard()` returns another named session guard gets a separate counter.
For guards without `getName()`, the configured admin guard name is used, with
`admin` as its fallback. The guard class and username field also separate keys.
Applications with multiple custom realms behind the same guard identity can
override `loginThrottleKey(Request $request)`. The combined key is SHA-256 hashed
so raw usernames and IPs are not embedded in cache keys.

Lowercasing deliberately groups username case variants, even with a
case-sensitive account database. Other database-specific equivalences, such as
accent-insensitive comparisons, need application-specific key normalization.
Configure trusted proxies correctly for `Request::ip()` to represent the intended
client. This is a limit per username/IP/guard combination: different IPs or
usernames have separate counters. It does not impose an account-wide or global
IP limit. Laravel's separate limit check and failure increment do not make a
strict reservation for simultaneous requests, so concurrent attempts can exceed
the sequential threshold. Distributed attack protection and production cache
availability remain application/deployment concerns.

## Regression coverage

Run from the integration consumer after its normal setup:

```sh
composer test -- --filter LoginThrottleTest
```

The 31 tests use the real HTTP kernel, built-in routes, session guard, package
migrations and SQLite. Most cases use the array cache store; the database-cache
case creates disposable cache tables and recreates both the limiter and store to
verify that persisted failures still block attempts, expire, and clear on
success. A missing-table case verifies that authentication never proceeds when
the configured database cache is unavailable. The tests count real guard
`Attempting` events and advance a frozen clock without sleeping. Coverage also
includes the exact threshold and expiry,
missing/invalid/disabled configuration, success reset, session regeneration,
intended redirects, remember cookies, username/IP/guard separation, inherited
username and guard overrides, case variants, malformed inputs, string-zero
credentials, rendered retry forms, safe old input, custom validation responses,
redirects/error bags, and translated/fallback errors. They pass with 507
assertions on PHP 8.4.27 with Laravel 12.70.1 and 13.38.0.
Before the controller change, a focused 13-case baseline produced 11 failures
and 109 assertions, including successful sixth-attempt authentication after five
failures, leaked old input, and malformed password arrays causing HTTP 500.

These are in-process HTTP/SQLite tests, with Testbench's normal CSRF bypass. They
do not exercise real browsers, external cache backends, concurrent workers,
production proxies or arbitrary authentication overrides. The implementation
uses the modern framework RateLimiter API with decay measured in seconds;
these results do not establish support for older Laravel releases still allowed
by the package's broad dependency constraint.

## 日本語

標準の `AuthController::postLogin()` は、認証ガード・ユーザー名のフィールド・
小文字化したユーザー名・接続元 IP の組み合わせごとに、60 秒間で 5 回まで
認証失敗を許容します。最初の失敗から時間を数え、5 回失敗した後のリクエストは、
パスワードが正しくても認証処理の前に拒否します。追加の失敗や制限中の再試行で
期限は延長しません。制限に達する前に成功すると、その組み合わせの回数だけを
リセットし、セッション ID の再生成・遷移先・ログイン状態の保持を維持します。

エラーは既存と同じリダイレクトとユーザー名欄のエラーで返し、HTTP 429 には
変更しません。制限中はアプリの `auth.throttle` 翻訳に残り秒数と切り上げた分数を
渡し、翻訳がなければ英語で表示します。ユーザー名とパスワードは文字列を必須とし、
配列・数値・真偽値などは認証や回数計上の前に検証エラーにします。文字列の
`"0"` は有効です。入力形式のエラーは HTML ではリダイレクト、JSON では 422 に
なります。標準の認証・回数制限の失敗と、生成する HTML 検証リダイレクトが
セッションへ残す旧入力は、文字列のユーザー名と真偽値の「ログイン状態を保持」
だけです。パスワード・確認値・CSRF トークン・追加項目や remember の生値は
新たに保存しません。明示された独自の検証レスポンスは維持し、その旧入力の管理は
アプリ側の責任になります。旧版が作成したセッションは削除しません。

設定例は上記の `auth.login_throttle` です。公開済み設定に項目がない場合も
既定の 5 回・60 秒で有効になるため、更新後は既存の導入先にも適用されます。
別の制限を実装している場合は `enabled` を真偽値の `false` にしてください。
回数と秒数は正の整数を指定し、省略・整数以外・0 以下はそれぞれ 5 回・60 秒へ
戻します。無効化中は回数を読み書きせず、既存の記録は通常どおり期限切れになります。

Laravel の `cache.limiter`、未指定なら既定のキャッシュを利用します。同じ
ログイン画面を提供するプロセス・サーバー間で共有される永続キャッシュが必要です。
キャッシュを消すと回数も消えます。database ドライバーでは設定したキャッシュ
テーブルが必要です。利用前にアプリのキャッシュ用マイグレーションを実行し、
未作成の場合は `php artisan make:cache-table` で生成してください。テーブルが
ない、またはキャッシュ接続が利用できない場合は、認証前にそのエラーを返します。
制限の無効化や array ストアへの自動切り替えは行いません。管理者テーブルの移行や
アセット・ビューの再公開、開いたフォームの再読み込みは不要です。設定変更後は
設定キャッシュを再構築し、コードや設定の配備時には常駐ワーカーを再起動して
ください。既存の制限ミドルウェア、自動再試行、任意の旧入力に依存する独自
フォームは確認が必要です。

親の `postLogin()` を使う派生コントローラーへ適用されます。独自に置き換えた
ログイン処理には自動適用されません。`username()` の変更は検証・認証・回数・
旧入力・エラーの名前へ反映されますが、独自ビューはアプリ側で合わせてください。
`loginValidator()` を上書きする場合も文字列検証を維持してください。明示した
検証例外レスポンスを保持し、生成するリダイレクトは `redirectTo` と `errorBag`
を尊重します。HTML/JSON の判定には `Request::expectsJson()` を使い、生成済み
レスポンスはハンドラーの判定に優先するため、`shouldRenderJsonWhen()` などの
独自設定は確認してください。`guard()` が別の名前付きセッションガードを返す場合は、
その `getName()` により回数を分離します。このメソッドがないガードでは設定の
ガード名（未指定なら `admin`）を使い、ガードのクラスとユーザー名フィールドも
キーに含めます。同じガード内に独自の認証領域がある場合は `loginThrottleKey()`
を調整できます。キャッシュキーにはハッシュを使い、ユーザー名や IP を直接
埋め込みません。

大文字小文字を区別する DB でも、ユーザー名の大文字小文字違いは回数を共有します。
アクセントなど DB 固有の同一視は必要に応じてアプリで調整してください。
接続元 IP が正しく得られるよう信頼するプロキシを設定してください。別 IP・別
ユーザー名は独立した回数になり、アカウント全体や IP 全体の制限ではありません。
回数の確認と加算は別処理なので、同時に到着した試行を厳密に予約・制限するもの
ではありません。分散攻撃や本番キャッシュの運用はこの検証の範囲外です。

PHP 8.4.27 と Laravel 12.70.1 / 13.38.0 で、31 テスト・507 アサーションが
成功しました。変更前は対象を絞った 13 件中 11 件が失敗し、5 回失敗後の 6 回目で
認証できること、旧入力への秘密情報保存、不正なパスワード配列による 500 を
確認しました。実際の HTTP・セッション・SQLite と固定した時刻を使う検証です。
array ストアに加え、使い捨ての SQLite キャッシュテーブルを作り、制限処理と
キャッシュストアの再生成後も回数が残ること、期限切れと成功時の解除を確認します。
テーブルがない場合に認証を進めないことも確認します。
Testbench では CSRF 検証を省略し、実ブラウザー・外部キャッシュ・並列ワーカー・
本番プロキシ・任意の独自認証は対象外です。秒単位の現行 RateLimiter API を使う
実装であり、依存条件が許容する旧 Laravel 全体の対応を保証する結果ではありません。
