# Grid inline uploads

## Behavior and compatibility

Each rendered ordinary Grid `upload()` or `uplaodMany()` cell has its own
generated input target. Its trigger and change handlers bind only to that cell, and re-running
the initializer replaces only the package's own namespaced handlers. Consumer
click/change handlers on the same controls are retained.

Previously, targets used only the row key and each component bound handlers to
every inline upload on the page. With two upload columns, choosing a file for
one column could emit updates for both. Normal successful replacement then
overwrote the unrelated field and deleted its original file. Repeated Grid
instances could also open the wrong input, and reinitialization multiplied the
handlers.

The `upload()` and existing `uplaodMany()` APIs, trigger/input CSS classes,
`data-target` linkage, row keys, field names, resource URLs, multipart options, token and method fields, success
notification/reload, and server-side upload preparation/storage stay unchanged.
The multiple-file branch iterates the native FileList by index, preserving the
selection order and appending each file under the existing `field[]` multipart
name. It no longer calls the unsupported `FileList.forEach` method, which
previously threw before a native multiple-file selection could send its request. The existing
`Form::multipleFile()` behavior still appends the selected files after the
stored originals. This does not rename the public `uplaodMany()` method.
Generated input IDs are now opaque per-render values: custom selectors should
follow the rendered `data-target` or supplied `$target`, rather than construct
`inline-upload-{rowKey}`. They must not persist a target across fresh renders.

This change is in PHP and Blade; there are no JavaScript asset or database
changes. Applications with published/overridden
`admin::grid.inline-edit.upload` views must carry forward the per-cell selectors
and namespaced idempotent bindings, plus the indexed FileList iteration in the
multiple-file branch. Updating JavaScript assets alone does not update a
published Blade view. A custom `Upload` displayer supplying its own
target must keep it unique across all rendered cells and Grid instances.
Refresh compiled views through the application's normal deployment process if
needed. Consumer view/script overrides are not automatically migrated.

## Regression coverage

`GridInlineUploadTest.php` renders actual Grids and runs their emitted ready
scripts in offline jsdom, with shipped jQuery 2.1.4 and modern jQuery 3.7.1.
The fixtures cover a one-field control, two columns and two rows, repeated
same-resource/key Grid instances, and separate resources with overlapping keys.
Each layout exercises single-file replacement and multiple-file selections of
one, two and five files through the existing `uplaodMany()` API.
They reinitialize the scripts, check consumer click/change handlers survive,
and verify each trigger activates precisely its own input once. Canceling a
picker without a change event sends no request.

The fixture constructs native jsdom FileLists using jsdom's test construction
API and verifies they have no `forEach` method; production scripts and FileList
methods are not patched. Captured native FormData is checked for exactly the
intended field, every selected file's original bytes and order,
the token and PUT method override, the correct resource/key, and unchanged AJAX
multipart options. Requests are replayed through Laravel's in-process web
middleware and `Form::update()`. SQLite and disposable storage assertions check
single-file replacement, multiple-file append order and retention of all prior
files and every unrelated path and file's bytes, followed by another render.

Run `composer test -- --filter GridInlineUploadTest` from `tests/integration`
after the normal PHP and JavaScript dependency setup in the [README](README.md).

## Limits

These are offline DOM and in-process HTTP/SQLite tests, not native OS file-picker,
live browser, AJAX/PJAX transport, browser-cookie/CSRF, or non-SQLite coverage.
The captured AJAX transport is replaced for observation and replay; production
handler code and actual server save logic execute unchanged.

This is ordinary Grid upload isolation and native FileList iteration. It does
not redesign nested HasMany identity, validate arbitrary consumer overrides,
or change upload failure/transaction semantics. Artificial empty-list change
events and browser-specific same-file retry/cancel behavior are outside this
change; a cancel with no change event simply does not submit.
