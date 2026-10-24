# Installer preservation of existing admin data

`php artisan admin:install` now refuses to run the legacy default seeder when
the configured user table is empty but other admin data remains. It returns
status **1**, explains that existing admin data was found, and suggests
restoring an administrator account or using `admin:create-user` with the
appropriate existing roles. It stops before seeding and before creating or
checking the admin application directory.

Previously, an empty users table was sufficient to run `AdminTablesSeeder`.
That seeder truncates users, roles, permissions and menus, so rerunning the
installer after losing all accounts could replace surviving authorization and
navigation data. Surviving pivots and operation logs could also become
associated with newly created default IDs. An existing `app/Admin` directory
did not protect database records.

## Installation and recovery contract

The installer still runs migrations first and preserves their exit status.
After a successful migration:

- If the configured user model's physical table contains an account, it skips
  seeding and continues the existing directory behavior. Global scopes,
  including soft deletion scopes, cannot hide an account from this check.
- With no configured users, any surviving users, roles, permissions, menus,
  user-role/user-permission/role-permission/role-menu pivots or operation logs
  in the configured admin tables cause refusal. Configured role, permission
  and menu models are checked on their own tables and connections as well.
- Pivot checks also use the actual connections selected by the built-in
  models' role and permission relationships. A custom related model can put
  those pivot writes on a different connection from `admin.database.connection`.
- Every existence check reads the **writer connection**, so an empty or lagging
  read replica cannot make existing writable data appear fresh. These are
  unscoped, unfiltered existence checks, including orphaned pivot rows.
- When all inspected tables are empty, the existing default seeder runs and
  its exit status is propagated. A preexisting admin directory still preserves
  its files without preventing first-time database initialization.

For a partial installation, restore the intended accounts and authorization
data, or create an account with explicitly selected existing roles using
`php artisan admin:create-user`. Review orphaned references when recovering
accounts; creating a new account does not restore the identity of a deleted
one. If no suitable roles remain, restore or establish the intended access
configuration before relying on the new account. The installer does not infer
administrator privileges, merge defaults into existing records, repair
relationships, or clear data to make a retry succeed. Once the configured user
table contains an account, rerunning `admin:install` skips seeding.

Updating the package code applies this guard. No published configuration,
assets or administrator-table migration must change. The prior
[database command failure handling](INSTALL_COMMAND_FAILURE.md), including
legacy `initDatabase()` overrides returning null or void, is retained.

## Boundaries

This is a check before the legacy seeder. It does not wrap migration or seeding
in a transaction, take locks, or protect against another process writing data
between the checks and seeding. Migrations may already have made changes before
the guard refuses to seed. Query errors, such as a missing configured model
table, propagate normally and prevent seeding; they are not treated as empty
tables.

Direct `db:seed --class='Encore\Admin\Auth\Database\AdminTablesSeeder'` calls
still perform destructive resets. The seeder still uses the built-in model
classes and the existing `admin` / `admin` default credentials. This guard does
not add custom-model seeding support or change credential setup. Consumers
overriding `initDatabase()` remain responsible for their initialization behavior.

## Regression coverage

From the repository root after integration-consumer setup:

```sh
php tests/integration/vendor/bin/phpunit -c tests/integration/phpunit.xml \
  tests/integration/InstallCommandPreservationTest.php
```

The tests run real migrations and Artisan installation commands in disposable
Testbench application directories, with SQLite in memory. Coverage includes
each of the eight non-user admin tables independently, a complete partial
installation with an existing controller, custom table names and admin
connections, configured model tables/connections and scopes, orphaned pivots on
related-model connections, missing configured tables, writer-versus-replica
visibility, and normal fresh/repeated installations. Read-replica fixtures use
separate SQLite PDOs with the same schema and no data.

Before the fix, the eight independent surviving-table cases plus the full
partial-installation case failed on both Laravel versions: **9 tests,
18 assertions, 9 failures**. The diffs showed existing roles, permissions and
menus replaced by defaults, and a new default account and pivot rows inserted.
A separate review reproduction also demonstrated replacement of an existing
writer-side role when the read replica was empty.

The completed focused suite passes **27 tests and 215 assertions** on
PHP 8.4.27, Laravel 12.70.1 / PHPUnit 11.5.57 and Laravel 13.38.0 /
PHPUnit 12.5.40. These checks exercise SQLite and the installed framework graphs;
they do not establish support for every framework version admitted by the
package constraint or for concurrent installers and other database servers.
Running the suite alongside `AdminLifecycleTest`, `CreateUserCommandTest` and
`InstallCommandFailureTest` passes **61 tests and 1,862 assertions** on both
framework versions, including exact child command statuses and null/void
initializer overrides.

## 日本語

`admin:install` は、設定されたユーザーテーブルが空でも、ロール・権限・メニュー・
中間テーブル・操作ログなどの管理データが残っている場合、終了コード **1** で停止
します。従来のシーダーや管理画面ファイルの生成を実行しないため、管理者アカウント
を失った状態での再実行による既存データの初期化を防ぎます。

存在確認ではモデルのグローバルスコープを適用せず、設定されたテーブルと接続、
関連モデルが利用する中間テーブルの接続を確認します。読み取りレプリカの遅延で
既存データを見落とさないよう、書き込み先の接続を使用します。ユーザーが存在する
場合はシードを省略し、全テーブルが空の場合は従来どおり初期データを投入します。
既存の管理画面ディレクトリだけを理由に初回のデータ投入を拒否する変更ではありません。

復旧時は本来のアカウントと権限情報を復元するか、`admin:create-user` で適切な既存
ロールを明示的に選択してアカウントを作成してください。新規アカウント作成だけで
削除されたユーザーの識別子や中間テーブルの参照を復元することはできません。

この変更はシード前の確認であり、トランザクションや並行実行時のロックは追加
しません。直接シーダーを呼び出す場合の初期化、既定の `admin` / `admin` 認証情報、
独自モデルへのシード対応は変更しません。マイグレーションやシードの失敗時に
終了コードを伝える既存の動作も維持します。
