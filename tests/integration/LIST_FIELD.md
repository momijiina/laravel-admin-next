# List field bounds

`$form->list('items')->min(2)->max(3)` validates the submitted list size even
without item `rules()`. Item rules still apply independently to each value;
custom `validator()` callbacks retain precedence. The default unbounded list
(minimum zero, no maximum, no item rules) keeps its validation fast path.

The real Laravel HTTP regression checks rendered controls, rejected creates
without insertion, rejected updates without changing the record, valid bound
endpoints, and omitted-field updates. Field-level cases cover min-only,
max-only, zero bounds, an explicit empty array, rule callbacks, creation/update
rules and validation messages.

An omitted field retains the existing skip behavior. Explicitly removing all
rows submits an empty marker as described below. These tests use the
in-process HTTP kernel and SQLite, not browser JavaScript.

## Scoped list input names

Existing rows and row templates use the field's scoped HTML element name.
Embedded lists therefore submit `settings[items][values][]`, and HasMany rows
submit `children[record-id][items][values][]`. Top-level names remain
`items[values][]`. Old input and displayed errors use the matching scoped error
key, so an unrelated validation failure retains each submitted list separately.

`ListFieldScopedNameTest` checks escaped names/values/errors, native PHP form
parsing of rendered controls, embedded and two-record HasMany update round trips,
and validation redisplay without writes. This is rendered-HTML and HTTP-kernel
coverage, not browser JavaScript. Collection Add/Remove scoping is covered by the
later [collection-script regression](COLLECTION_SCRIPT_SCOPING.md), rather than
this scoped-name test. Nested validator aggregation is unchanged. The scoped-name
fix is also used by the empty markers below.


## Explicitly empty collections

ListField and KeyValue render hidden empty scalar defaults outside removable rows,
before the table. Later row controls replace these scalars with arrays through
PHP's native form parsing. With no rows left, only these explicit defaults remain;
Laravel may convert their empty strings to null. Field preparation normalizes only
those exact null/empty-string envelope parts to empty arrays. Real blank, null,
and zero-valued rows are preserved. Missing fields still preserve stored values.

ListField's local validator treats the marker as a zero-length array: `min(0)`
allows clearing and a positive minimum rejects it without changing the record.
Custom field validators retain precedence and receive the original input, as do
raw-input hooks; consumers of those hooks must account for the scalar marker.
Old explicit empty input redisplays zero rows after validation failure, including
scoped fields. KeyValue's existing per-item/distinct rules and duplicate/blank-key
preparation semantics remain unchanged; it has no collection minimum API.

`ExplicitEmptyCollectionsTest` uses real rendered controls, native PHP parsing,
the HTTP kernel, and SQLite. DOM removal simulates removal of row controls; no
JavaScript is executed by this test. Repeated-instance JavaScript correctness is
covered separately by the [collection-script regression](COLLECTION_SCRIPT_SCOPING.md).
Nested validator aggregation remains unchanged; these tests do not establish
nested list-bound validation.

## Readonly collections

ListField and KeyValue now honor inherited `readonly()` (also callable as
`readOnly()`). This locks collection Add/Remove and applies the native readonly
state to all visible keys/values and row-template inputs. Values remain submitted,
including zero/blank values and the explicit empty markers. Existing Laravel
request middleware can still normalize blank strings to null. Normal editable
collections retain Add/Remove and explicit-clearing behavior.

Only `readonly` is newly supported; arbitrary field attributes are not propagated
across repeated inputs. As with HTML boolean attributes, presence is what matters:
`attribute('readonly', false)` still makes the collection readonly. Use
`removeAttribute('readonly')` before rendering to remove that state.

This is an opt-in UI enhancement, not a regression fix: applications previously
calling this method while relying on its ineffective behavior will now see locked
controls. Published/overridden collection views must carry forward the
`collectionReadonly` view variable, native readonly input attributes and root
`data-collection-locked` marker.

Readonly prevents accidental edits in the normal collection UI; it is not
server-side access control. Validation, preparation and omission handling are
unchanged. Submitted readonly values can overwrite concurrent changes when a
stale form is saved; applications must enforce authorization and concurrency
rules themselves. It does not lock an enclosing HasMany relation's Add/Remove
controls.

`disable()` remains unsupported by these collection templates. Custom scripts
that disable controls directly must disable the hidden empty markers too (for
example, via a disabled ancestor fieldset), or a submission can clear stored
values. The hidden-cascade handler already disables all `:input` controls in the
hidden group. Omission is not a recursive merge contract: embedded objects retain
their existing [replacement semantics](EMBEDDED_OBJECT_ORIGINALS.md), so submitting
sibling keys can remove an omitted child.

`CollectionFieldStatesTest` renders production Blade and executes emitted scripts
with both shipped jQuery 2.1.4 and modern jQuery 3.7.1 in offline jsdom, then parses
actual serialized controls and submits to the Laravel HTTP kernel with SQLite.
It covers readonly presence/removal, empty/populated fields, marker ordering,
readonly submission, normal clearing and nested child controls. This does not
establish live browser layout, PJAX transport or third-party widget compatibility;
nested validation/HasMany identity contracts are unchanged.
