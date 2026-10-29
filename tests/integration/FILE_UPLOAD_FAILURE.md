# Failed main-file uploads

`File` replacement used to ignore a `false` result from `putFileAs()`, delete the
original file, and pass `false` to the model. On the real local disk with
`throw => false`, an overlong synthetic replacement filename reproduced HTTP 200
success, a SQLite document path of `'0'`, and deletion of the original bytes.

Single-file replacement now uses the shared upload helper. That helper rejects
strict `false` with `RuntimeException` before original cleanup or returning a
path to the form. Exceptions from disks configured to throw propagate unchanged.
`Image`, `MultipleFile`, and `MultipleImage` share this main-file guard. Successful
uploads retain existing collision naming, directory and visibility behavior.

`FileUploadFailureTest.php` boots real Laravel with in-memory SQLite and a real,
random temporary local disk. It covers HTTP create/update failure, unchanged
original bytes and database paths, successful replacement, initial field upload
failure, throwing disks, related upload fields, original image thumbnails,
explicit private visibility, and filename collisions. Failure is induced by an
overlong filename; no filesystem permission changes or production files are used.
The HTTP fixture uses the real form and framework kernel, with test-mode CSRF
bypass; it does not exercise browser JavaScript.

## Replacing a missing original at the same path

If a record still points to `uploads/report.txt` but that file is missing,
uploading `report.txt` again now retains the new bytes. Previously the upload
succeeded at that same path, then the old-file cleanup deleted the newly written
file. The HTTP response reported success and the database retained a path with
no file behind it.

After a successful single-file upload, `File` skips original cleanup when the
returned storage path is exactly the original path. Existing-file collisions
still receive a new name, and a different original path is still cleaned up
unless `retainable()` is set. Failed writes retain the existing failure guard.
The image field already has its own protection for successfully stored paths.

Review copies or overrides of `File::uploadAndDeleteOriginal()`. An overridden
`destroy()` is no longer called in this same-path case; explicit deletion and
cleanup of a different original path keep their behavior. No data migration,
dependency change, or asset/view update is required. Previously deleted bytes
are not recoverable from this change; affected files must be uploaded again.

日本語: DB にパスが残っていて実ファイルだけがない場合、同名で再アップロード
したファイルを直後の古いファイル削除処理が消していました。保存先と元のパスが
同一の場合は削除を行わず、新しい内容を保持します。独自の保存・削除処理を確認して
ください。過去に失われた内容の復元は含みませんが、再アップロードで保存できます。

Verification on 2026-10-29, PHP 8.4.27 with Laravel 12.70.1 / PHPUnit 11.5.57
and Laravel 13.38.0 / PHPUnit 12.5.40:

- `FileUploadFailureTest` passes **14 tests / 83 assertions** on each framework.
  New HTTP cases cover missing-file recovery on throwing/nonthrowing disks,
  a configured filename callback, same-name collisions, retention and a different
  replacement path, while keeping the previous failed-write regressions.
- Preloading the original `File` class makes the four same-path cases report
  **three failures** on each framework: the new bytes are absent despite HTTP
  success. The `retainable()` control already passes on the original code.
- The related `FileUploadFailureTest|GridInlineUploadTest|MultipleFileSortTest|UploadPreviewTextTest`
  suite passes **101 tests / 2,836 assertions** in randomized order (seed 135)
  on each framework. The historical BrowserKit/image runner also passes all
  **125 tests** on both. These are local results, not a hosted CI run or a new
  live-browser upload test.

## Boundaries

This is a main-upload failure guard, not a database/filesystem transaction.
It cannot undo bytes already overwritten by a custom storage adapter or naming
implementation, partial writes, a later database failure after a successful
upload/deletion, or successful earlier files in a subsequently failing multi-file
batch. Thumbnail writes themselves retain their existing behavior: a failed main
image upload now stops before thumbnail processing, but a later thumbnail failure
is not made atomic with the main upload or original deletion.

## Verification

Run through the normal integration PHPUnit configuration. Existing local consumer
dependency trees can also be used by mapping `Encore\\Admin\\` to this checkout.
The baseline negative control must load the unchanged `File` and `UploadField`
classes: the failed replacement and initial upload HTTP tests then receive 200
instead of 500, and the field-level initial failure does not throw.

Local verification on 2026-10-03, reusing normal resolved consumer dependencies:

- PHP 8.4.25 and 8.5.11, each with Laravel 12.69.3 and 13.34.0: all **46
  integration tests / 35,048 assertions** pass (39 existing tests plus 7 upload
  regressions).
- All nine standalone scripts pass on both PHP runtimes, including clean lint of
  326 production PHP files.
- PHP 8.4.25 with GD/JPEG: all **77 BrowserKit tests** pass on both Laravel families
  (995 assertions on Laravel 12; 991 on Laravel 13). Existing randomized fixture
  data can vary the assertion count. PHP 8.5 BrowserKit was not run locally because
  that runtime has no GD extension; CI retains that matrix job.
- The unchanged-production negative control fails all three selected regressions
  as expected. No vendor modifications, skipped image tests, disabled platform or
  advisory checks, or production permissions changes are needed.
