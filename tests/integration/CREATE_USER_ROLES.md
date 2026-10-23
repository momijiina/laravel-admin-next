# Role selection in admin:create-user

`php artisan admin:create-user` now asks which roles to attach when roles exist.
Previously the condition was reversed: an existing role list skipped the prompt
and attached every role, including `administrator`; an empty list attempted to
show a choice with no available answers.

The command now displays each choice as `Name [ID]` and attaches only the selected
labels, with strict matching. Choose the displayed index, a comma-separated list
of indexes, or a complete displayed label. Adding the ID prevents numeric names
from being confused with indexes by the console choice parser: a role named `1`
cannot shadow option index `1`, and choosing `0 [17]` does not select roles named
`00` or `0.0`. Multiple selections and Unicode names are retained.
With no roles available, it creates the user without a role-selection prompt
and without role assignments. Password hashing and configured user/role models
are unchanged.

This changes future command runs. Existing users and pivot rows are not modified;
review accounts previously created by this command if they may have received
unintended roles. Scripts driving the interactive command must now answer the
role question whenever roles exist, using indexes or complete `Name [ID]` labels.
The choice labels use each configured role model's primary key. Input validation,
transactions, default install credentials and
web-based user creation are separate concerns.

日本語: ロールが存在する場合に選択を飛ばして全ロールを付与していた条件を修正し、
選んだロールだけを付与します。表示を `名前 [ID]` にし、数値の名前と選択番号の
取り違えも防ぎます。表示された番号か完全なラベルで選択してください。ロールが
存在しなければ選択を表示せず、ロールなしで作成します。既存アカウントは変更しない
ため、過去にこのコマンドで作成したユーザーの付与ロールを確認してください。
対話入力を自動化している場合は、ロール選択への回答を追加してください。

## Verification

`CreateUserCommandTest.php` runs the registered Artisan command through Laravel's
console-question test interface and Symfony's real `CommandTester` input stream,
and checks real SQLite user/role/pivot records.
It covers restricted single/multiple selections, explicitly choosing the
administrator role, selecting all or the only available role, numeric-looking
and Unicode names, and creating an account when no roles exist. It also checks
the stored password hash and resulting administrator status. Three unmocked
choice cases verify index/label normalization and keep the secret out of captured
console output.

Before the condition repair, the seven populated-list cases reported an unasked
role question, while the empty-list case failed on the empty choice. Fixing only
that condition still attached all three numeric-looking names; strict comparison
fixes that separate selection boundary. Real Symfony normalization additionally
showed numeric names shadowing indexes, motivating the identity-bearing labels.

On PHP 8.4.27, Laravel 12.70.1 / PHPUnit 11.5.57 and Laravel 13.38.0 /
PHPUnit 12.5.40 each pass **11 tests / 155 assertions**. These tests check the
command, console normalization and persistence with supplied input streams; they
do not test terminal echo handling, an operating-system password prompt, or an
existing deployment.

Using the [integration consumer](README.md):

```sh
composer test -- --filter CreateUserCommandTest
```
