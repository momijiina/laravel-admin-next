# MultipleFile sorting with uploads and validation

Ordinary top-level `$form->multipleFile('documents')->sortable()` updates now
keep new uploads when existing previews are reordered in the same submission.
The saved array contains the sorted existing paths followed by all new paths in
file-selection order. Sort-only saves no longer pass the comma-separated order
string to array-typed built-in file validation. Optional `file` or `image` rules
therefore allow sorting without requiring a new upload; new files still run
through those rules before any save. Other fields are still validated.

## Hook and validator compatibility

**Combined sort-and-upload requests now expose the actual `UploadedFile` array
to custom validators and saving hooks.** Previously `Form::handleFileSort()`
incorrectly replaced that array with the order string and silently discarded
the uploads when no built-in rule detected the problem. Review custom code
that depended on that erroneous string. The order remains available separately
at `_file_sort_[documents]`.

Sort-only saving hooks and custom validators continue to receive the existing
order string. Custom validators retain precedence over the built-in rules and
remain responsible for validating their input. Built-in validation normalizes
only a nonempty string equal to that same field's own sort marker; a sort flag
does not disable validation globally or make arbitrary scalar file input valid.
Unchanged saves, empty sort markers, ordinary upload-only appends, public
`sortable()` configuration, and the emitted marker format are unchanged.
No asset or view refresh is required.

Required-rule semantics with existing saved files, relation-backed uploads,
nested forms, deletion, malformed sort-order validation, filesystem/database
atomicity, and custom storage formats are outside this change. It does not
validate previously saved file bytes when no new file is submitted.

## Verification

`MultipleFileSortTest.php` renders the actual form and initialization script,
executes the shipped jQuery 2.1.4, fileinput 4.5.2 and KvSortable in jsdom, and
replays the native FormData through Laravel's web middleware and `Form::update`
into SQLite and a disposable local disk. The fixture moves real preview nodes
and enters the shipped Sortable `onSort` callback; the plugin emits `filesorted`
and the production listener updates the hidden marker. It supplies a native
FileList, dispatches the actual change event, waits for the plugin's fileloaded
events, and checks each selected file's exact bytes in both plugin and FormData.
HTTP uploads use real MIME detection. No production class, view, or event
handler is replaced.

Coverage includes optional file/image rules, sort-only saves, two-file appends,
unchanged and upload-only submissions, a second render/sort, invalid-upload
rejection and retry, unrelated field validation, independent sort markers,
custom validator precedence/rejection, raw saving inputs, and exact old/new
file bytes. Package static state and temporary files are cleaned up.

Run with the [integration consumer setup](README.md):

```sh
composer test -- --filter MultipleFileSortTest
```

This is offline widget execution plus in-process HTTP/SQLite persistence, not
physical browser drag, OS file-picker interaction, live multipart transport,
non-SQLite database coverage, or proof for arbitrary consumer overrides. jsdom
needs only an object-URL preview API shim and its FileList fixture API.
