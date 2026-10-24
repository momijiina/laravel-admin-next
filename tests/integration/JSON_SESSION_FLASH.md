# Flash notifications with JSON sessions

Laravel's JSON session serializer reads a flashed `MessageBag` back as an
array. Fresh Laravel 13 applications use JSON serialization in their session
configuration. Before this fix, successful admin login redirected to a page
that failed with `Call to a member function get() on array` in the toastr
partial. The success, error, info, and warning alert partials had the same
assumption after a redirect.

The shipped toastr and alert views now wrap array payloads in a collection
before reading their fields. Existing `MessageBag` objects and custom objects
with a `get()` method retain their normal rendering behavior. The flash
helpers still create `MessageBag` objects, and the application's session
driver and serialization setting remain in control. Laravel continues to
restore its own validation `ViewErrorBag` for the `errors` session key.

Toasts are still pulled once; alerts retain normal flash expiration. Toast
types, option values, empty defaults, and existing rendering of preescaped
messages are preserved. Alert titles remain escaped and alert bodies retain
their existing HTML rendering. Array payloads are read without reconstructing
a `MessageBag`, so their nested options and duplicate option values are not
deduplicated. The existing helper's `MessageBag` option handling is unchanged.
This change does not make arbitrary PHP objects round-trip through JSON;
applications using custom object behavior should continue to choose a session
serializer appropriate for those objects.

Applications with published overrides of
`resources/views/vendor/admin/partials/toastr.blade.php` or
`resources/views/vendor/admin/partials/alerts.blade.php` should merge the
corresponding array normalization from the shipped partials. Preserve any
application-specific message encoding in those overrides. After updating
views, run `php artisan view:clear`; deployments that precompile views can
then run `php artisan view:cache`. No browser asset publication is needed.

## Verification

`JsonSessionFlashTest.php` uses Laravel's database session handler, persists the
response, and replays the encrypted session cookie on the next HTTP request.
It discards cached session stores and authentication guards between requests
and inspects the stored payload, so an in-memory `MessageBag` cannot hide the
JSON conversion. Each case also snapshots and restores the package's static
asset registries and form flags so authenticated requests do not affect later
tests. Both PHP and JSON serialization cover successful login,
all four alert helpers, expiration/consumption, toast types and options,
preescaped content, missing/empty toast values, and named validation bags.
Separate cases cover array options with duplicate/nested values and custom
`get()` providers plus formatted `MessageBag` objects under PHP serialization.

The initial focused baseline had two failures: JSON login and JSON alerts
returned HTTP 500, while their PHP counterparts passed. After the fix, all
13 focused cases pass (390 assertions) on Laravel 12.70.1 and 13.38.0 with
PHP 8.4.27. Running the flash cases together with `AdminLifecycleTest.php`
and `LoginThrottleTest.php` passes 59 tests / 2,337 assertions on each version.

```sh
cd tests/integration
php vendor/bin/phpunit --configuration phpunit.xml JsonSessionFlashTest.php
```

## 日本語

Laravel の JSON セッションでは、保存した `MessageBag` が次のリクエストで
配列になります。Laravel 13 の新規アプリは標準のセッション設定で JSON を
使用するため、管理画面へのログイン成功後に toastr のビューで
`Call to a member function get() on array` が発生しました。success、error、
info、warning のアラートにも同じ問題がありました。

同梱ビューは、配列の通知だけをコレクションとして読み取るようになりました。
ヘルパーは引き続き `MessageBag` を作成し、既存の `MessageBag` や独自の
`get()` を持つオブジェクトの動作を維持します。アプリのセッションドライバーや
シリアライズ設定は変更しません。検証エラーの `ViewErrorBag` は従来どおり
Laravel が復元します。toastr の一度だけの表示、アラートの有効期間、種類、
オプションの値、空の通知の既定値、事前にエスケープしたメッセージの出力を
維持します。アラートの見出しはエスケープし、本文の HTML 表示も従来どおりです。
配列のオプションは再度 `MessageBag` に変換しないため、重複した値や入れ子を
保持します。ヘルパー自身の既存のオプション処理は変更しません。
任意の PHP オブジェクトを JSON から復元する機能ではありません。

`resources/views/vendor/admin/partials/` の `toastr.blade.php` または
`alerts.blade.php` を公開・変更している場合は、同梱ビューの配列対応を
取り込んでください。独自のメッセージエンコードは維持してください。
更新後は `php artisan view:clear` を実行し、ビューを事前コンパイルする
環境では続けて `php artisan view:cache` を実行します。
ブラウザー用アセットの再公開は不要です。

テストは実際のデータベースセッションと暗号化 Cookie を使い、リクエスト間で
セッションストアと認証ガードのキャッシュを破棄します。保存済みデータが JSON
では配列、PHP では `MessageBag` であることも確認しています。各テストの終了時に
静的なアセット登録とフォームの設定も元に戻し、後続のテストへの影響を防ぎます。
修正前は JSON の
ログインとアラートの 2 ケースが HTTP 500 で失敗し、修正後は Laravel 12.70.1 と
13.38.0（PHP 8.4.27）の両方で 13 テスト・390 アサーションが成功しました。
認証・ログイン制限の既存テストと合わせると、両方で 59 テスト・2,337
アサーションが成功しています。
