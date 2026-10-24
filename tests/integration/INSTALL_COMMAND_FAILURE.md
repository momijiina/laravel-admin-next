# Installer database failure handling

`php artisan admin:install` now returns the nonzero exit status from its
`migrate` or `db:seed` command and stops immediately. A failed or declined
migration prevents the administrator count query, seeding and application
scaffolding. Failed or prohibited seeding prevents scaffolding. The child
command's normal diagnostic output is retained.

Previously, a production confirmation declined through `--no-interaction`
could lead to a query against a missing `admin_users` table. If the schema had
already been migrated, both database commands could be cancelled while the
installer still created controllers and routes and returned success with no
administrator account. Laravel's `SeedCommand::prohibit(true)` reproduced the
same false success after a successful migration.

Successful installations still return zero. A repeat installation still runs
migrations, skips seeding when the configured user model contains an account,
and preserves an existing admin directory. The command does not add `--force`
or bypass Laravel's production confirmations and prohibited-command policy.

## Overrides and deployment

`InstallCommand::initDatabase()` now returns the child status, or zero when no
seeding is needed. `handle()` treats a null return as successful for older
overrides that have no return statement or declare `: void`. No native return
type has been added to either public method. Overrides that call
`parent::initDatabase()` should return its result to retain failure propagation;
an override that discards the result remains responsible for its own behavior.

Scripts should inspect the `admin:install` exit status. If a database command
fails after making changes, the installer does not roll back those changes or
remove files from an earlier attempt. Resolve the database failure and inspect
the partial state before retrying. Updating the package code applies this fix;
no published configuration, assets or administrator-table migration is needed.

This is limited to database command statuses in `admin:install`. Exceptions
continue through Laravel's normal command handling. Filesystem write failures,
`admin:publish`, replacements of the installer's `handle()` method and older
Laravel releases have separate behavior. The existing seeder's `admin` / `admin`
default credentials and destructive table resets are unchanged. In particular,
an existing admin directory does not prevent database initialization: an empty
user table can still cause the legacy seeder to reset surviving roles,
permissions and menus.
Changing credential setup and making that initialization non-destructive need
their own migration contract.

## Regression coverage

Run from the integration consumer after its normal setup:

```sh
composer test -- --filter InstallCommandFailureTest
```

The eight tests run the real Artisan command in disposable application
directories under the system temporary directory, with SQLite in memory. They
cover production cancellation with fresh and pre-migrated schemas, real
prohibited seeding after migration, exact custom child statuses of 7 and 23,
successful installation and repetition, and inherited initializers returning
null or void. Synthetic child commands only supply the unusual status codes;
the cancellation, prohibition, successful migration and successful seeding
cases use Laravel's actual commands.

The package migration directory is registered directly with the migrator to
share its named migration class with the other integration tests. These tests
do not publish a second copy of that class, resolve a fresh Composer graph,
exercise a live browser, or test database servers other than SQLite.

Before the source change, the same eight tests produced four failures and one
error, with 33 assertions, on both Laravel versions. After the change they pass
with 52 assertions on PHP 8.4.27, Laravel 12.70.1 / PHPUnit 11.5.57 and Laravel
13.38.0 / PHPUnit 12.5.40. Running the new tests after `AdminLifecycleTest` and
`CreateUserCommandTest` also passes on both versions: 34 tests and 1,647
assertions. These focused results do not establish compatibility with every
framework version admitted by the package's dependency constraint.

## 日本語

`admin:install` は、内部で実行する `migrate` または `db:seed` が失敗した場合、
その終了コードを返して処理を止めます。マイグレーションを拒否・中断した後に
管理者テーブルを照会したり、シードの実行に失敗した後にコントローラーやルートを
生成したりすることを防ぎます。本番環境の確認や Laravel のコマンド禁止設定を
無効にする変更ではありません。

従来の `initDatabase()` オーバーライドが値を返さない場合や `: void` を宣言して
いる場合は、引き続き成功として扱います。親メソッドを呼び出す独自実装では、
失敗を伝えるためにその戻り値も返してください。成功時と通常の再実行時の動作は
維持されます。すでに完了したデータベース変更を自動的に取り消す機能はありません。

既定の `admin` / `admin` 認証情報、既存ディレクトリの確認順序、旧シーダーによる
テーブル初期化はこの変更の対象外です。管理者が存在しない状態での再実行は、
残っているロールやメニューを初期化する可能性があります。認証情報と初期データの
安全な導入方法は、別の互換性検討が必要です。
