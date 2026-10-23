# Built-in administrator password forms

The settings and administrator edit forms render both password controls blank,
including after validation errors. Leave the password and confirmation blank or
omit them to keep the exact stored hash while editing a profile. A supplied new
password must be a string with matching confirmation; confirmation by itself is
invalid. Every nonempty new password is hashed, including `"0"` and a string
equal to the previous stored hash. Creating an administrator requires a confirmed
password, including when the request omits the password field entirely.

Previously, both edit controls contained the stored hash, blank submissions
failed validation, and validation retries could redisplay submitted passwords.
The save hook also left `"0"` unhashed and treated a submitted existing hash as
an instruction to preserve it. Integrations that submitted that hash to leave a
password unchanged must now submit blank controls or omit them instead.
After updating, reload any already-open edit forms before saving: their old
embedded hash would otherwise be treated as a new password and hashed again.

This change uses field attributes, validation rules and save hooks in the two
built-in controllers. The creation validator deliberately checks omitted input,
which the ordinary field validator skips. Password widgets in custom forms,
generic old-input session flashing, password policies and session handling are
unchanged. The existing operation-log redaction still applies. No schema,
configuration, dependency or asset migration is needed. Review application
overrides of these controller forms and re-enter passwords after validation
errors; changing the generic Password widget is unnecessary.

Run from the integration consumer after its normal setup:

```sh
composer test -- --filter AdminPasswordFormTest
```

The tests use the real HTTP kernel, admin guard, built-in routes, migrations and
SQLite. They cover settings PUT, administrator PUT/PATCH, blank/null/omitted
updates, confirmation failures, malformed password arrays, required creation,
rendered creation-form submission, stored hashes, old/new login results and
redacted operation logs. On PHP 8.4.27, the 43 tests pass with 1,113 assertions on
both Laravel 12 and 13. Before the controller changes, the same suite produced
37 failures, including stored-hash rendering, unhashed zero passwords and omitted
creation passwords reaching a database error.

These are in-process HTTP/SQLite tests. They do not cover browser password
managers, live JavaScript, external databases or custom controller/view overrides.
Testbench bypasses CSRF validation in its testing environment.

## 日本語

標準の設定・管理者編集フォームは、パスワードと確認欄を常に空欄で表示します。
入力エラー後もパスワードを再表示しません。プロフィールだけを変更する場合は、
両欄を空欄にするか送信から省略すると、保存済みハッシュをそのまま維持します。
新しいパスワードは文字列と一致する確認値が必要で、確認値だけの送信はエラーに
なります。`"0"` や従来のハッシュと同じ文字列も、新しいパスワードとして必ず
ハッシュ化します。新規作成時は、項目自体を省略した場合も確認済みのパスワードを
必須とします。

以前は編集欄にハッシュを埋め込み、空欄の送信を拒否し、エラー後は送信済みの
パスワードを再表示していました。また `"0"` をハッシュ化せず、保存済みハッシュと
同じ値を特別扱いしていました。パスワードを維持するためにハッシュを送っていた
連携処理は、空欄または項目の省略に切り替えてください。
更新前から開いている編集フォームは、保存前に再読み込みしてください。古い画面に
埋め込まれたハッシュを再送すると、新しいパスワードとして再ハッシュ化されます。

修正は標準の 2 つのコントローラー内のフィールド設定・検証・保存処理に限定します。
独自フォームの Password ウィジェット、汎用フォームのセッションへの旧入力保存、
パスワード方針やセッション処理は変更しません。操作ログの既存のマスク処理は
引き続き適用されます。DB・設定・依存関係・アセットの移行は不要です。独自に
フォームを上書きしている場合は実装を確認し、入力エラー後はパスワードを再入力
してください。

検証は実際の HTTP カーネル・認証・標準ルートと SQLite を使い、設定の PUT、
管理者の PUT/PATCH、新規作成、空欄・省略・不一致・不正な配列、ハッシュ保存、
新旧パスワードでのログイン、操作ログのマスクを確認します。PHP 8.4.27 上の
Laravel 12/13 で各 43 テスト・1,113 アサーションが成功し、修正前は同じテストの
37 件が失敗しました。ブラウザーのパスワード管理機能・実際の JavaScript・
外部 DB・独自ビューは対象外で、Testbench では CSRF 検証を省略します。
