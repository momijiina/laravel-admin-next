# Menu subtree deletion

Deleting a built-in menu now deletes its visible descendants through their
individual Eloquent model methods, deepest children first and the selected
parent last. Previously, a query-builder delete removed only direct children,
skipped their model events and left grandchildren and child role pivots behind.
The normal `Menu::deleting` listener now detaches each deleted menu's role
associations. Unrelated menus and the role records themselves remain intact.

## Failure behavior

`ModelTree::delete()` wraps each subtree in a transaction on that model's
connection. A `deleting` observer returning strict `false` cancels the operation
and restores the subtree's rows and same-connection writes, including role
pivots. An exception or other `Throwable` also rolls them back and is rethrown.
Existing outer transactions keep their level. Direct callers retain Eloquent's
`true` / `false` / `null` return contract; an unpersisted instance does not delete
descendants. A projected model without its key never traverses NULL-parent rows.

The built-in Form deletion endpoint treats strict `false` from `delete()` or
`forceDelete()` as a failure and skips its successful-deletion callback. The
tree UI displays that failure without a success toast or a successful PJAX
refresh. Each root in a comma-separated Form deletion is handled independently;
this is not an atomic transaction across the entire batch.

Malformed cycles and callbacks that recursively delete an active ancestor
raise a `LogicException` and roll back the affected subtree. The guard is
cleared after completion, cancellation or an exception.

## Other models using ModelTree

- Descendant queries preserve the model's table, connection and global scopes.
  Runtime `setParentColumn()` configuration is propagated to child instances.
  Each child's model deletion override and normal events run. Custom observers
  that previously ran only for the selected root will now run for descendants.
- With `SoftDeletes`, normal deletion soft-deletes active descendants. Previously
  trashed intermediate nodes and their branches remain outside that active
  query. Forced deletion traverses both active and trashed descendants using
  `forceDelete()` while retaining other global scopes. Restore behavior is
  unchanged.
- The root's `SoftDeletes::forceDeleting` and `forceDeleted` events surround its
  call to this trait and remain outside this trait's transaction. Descendant
  force events run inside the ancestor's transaction. The built-in Menu model
  does not use soft deletion.
- Query-builder bulk deletes still bypass model methods/events. Quiet deletion
  suppresses model listeners, including Menu's role-detach listener.

Database rollback does not reverse external observer effects, filesystem
changes, writes on other connections, or restore previously loaded model
objects. Form file removal still happens before model deletion. Applications
with those behaviors should review their observers and transaction boundaries;
after-commit dispatch is appropriate when external work depends on a committed
deletion. The separate Grid row-action deletion response is unchanged.

## Verification and upgrade

Run from the [integration consumer](README.md):

```sh
vendor/bin/phpunit --filter 'MenuDeletionTest|ModelTreeDeletionTest'
```

The HTTP/SQLite tests use the real menu routes and a nondefault admin database
connection. They check four-level deletion, pivots, event order, preserved
unrelated data, vetoes, exceptions and nested transactions. Generic model tests
cover custom keys/tables/parent columns, soft/force deletion, global scopes,
cycles and unpersisted/projected instances. The original implementations are
used as negative controls. The [Chromium smoke](../fresh-app/README.md) exercises
the actual tree confirmation and PJAX refresh, then checks persisted records
and pivots so an invisible orphan cannot count as a successful deletion.
Its failure-UI check intercepts a deletion response with `status: false`;
real server observer vetoes are covered separately by the integration tests.

On PHP 8.4.27 with Laravel 12.70.1 / 13.38.0, the two deletion classes passed
a combined **30 tests / 314 assertions** on each framework. Preloading the original trait
and Form made all 30 cases fail. Randomized runs with the existing menu-rendering
suite passed **52 tests / 980 assertions** on both frameworks. Both historical
BrowserKit suites passed **125 tests** (1,234 / 1,230 assertions respectively).
Standalone compatibility checks passed, including diagnostics-free lint of
330 production PHP files. These local results do not replace hosted CI results.
Both Chromium flows also passed using existing disposable official Laravel
12/13 application fixtures, including persisted descendant/pivot cleanup and
the simulated failure response's UI behavior.
Separate Laravel 13 browser negative controls preloaded only the original trait
or only the original Tree class. The former failed at the persisted descendant/
pivot assertion; the latter failed because a rejected deletion requested PJAX.
The working checkout stayed fixed throughout those controls.

Review copied `ModelTree::delete()`, `Form::destroy()` and `Tree::script()`
implementations, plus descendant observers. Reload open tree pages to load the
updated generated script. No schema migration, asset publication, dependency
change or historical orphan cleanup is included. These regressions use SQLite;
concurrent reparenting/inserts, other database engines and custom application
hooks need their own validation.

## 日本語

標準メニューの削除で、子から末端まで各モデルの削除処理・イベントを呼び、最後に
選択した親を削除します。従来残っていた孫のレコードや子のロール関連も削除します。
削除拒否（`false`）や例外では、対象の接続内の DB 変更を巻き戻します。画面にも
失敗を返し、成功通知と PJAX 更新を実行しません。

独自の削除メソッドやオブザーバーは子孫にも呼ばれるため、コピーした実装と
副作用を確認してください。論理削除は有効な枝、強制削除は削除済みの枝も対象に
しますが、他のグローバルスコープは維持します。外部通知・ファイル・別接続の
更新は DB の巻き戻しでは戻りません。複数の親を指定した一括要求全体の原子性や、
過去の孤立データの修復は含みません。更新後は開いているツリー画面を再読み込み
してください。DB 移行とアセット再公開は不要です。
