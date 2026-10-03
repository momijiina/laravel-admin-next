# Collection script root scoping

`CollectionScriptScopingTest` renders the actual ListField/KeyValue Blade views
with real Testbench forms, embeds, SQLite-backed HasMany records, and the package's
unmodified `Admin::$script` strings. PHPUnit writes an ephemeral JSON fixture,
runs `javascript/collection-scoping.cjs`, and checks its exit status and the
serialized forms with PHP's real bracket-name parser. Fixtures are removed even
when an assertion fails. The test contains no replacement collection script.

## Run

From `tests/integration`, after installing the consumer Composer dependencies:

```sh
# Node 24.15+ in the 24.x family
npm ci --ignore-scripts --prefix javascript
vendor/bin/phpunit --filter CollectionScriptScopingTest
```

Both the Laravel integration and DomCrawler compatibility workflows install
Node 24 and the locked npm dependencies before running the full suite. Any runner
executing the full integration suite must do the same; Composer does not install
these JavaScript dependencies. The model-schema workflow selects only
`ResourceGeneratorTest`, and schema-parity runs standalone PHP probes, so neither
executes this DOM suite or needs its npm setup.

The lockfile pins jsdom 30.1.1 and jQuery 3.7.1 from the official npm registry; dependencies
are test-only. Each scenario executes with both the shipped jQuery 2.1.4 asset and
pinned jQuery 3.7.1. HasMany tab mode loads the shipped Bootstrap implementation.
No package/framework or collection/HasMany handler is replaced by a stub.

## Assertions

- List and key/value fields at top level, two same-column embeds, and two existing
  HasMany children in default, tab, and table modes
- Two child insertions through each real HasMany parent handler, followed by
  independent add/remove operations on both existing and newly inserted children
- Same-column List/KeyValue fields operating independently in both directions;
  column names with CSS punctuation and a JavaScript quote
- Repeated collection initialization on retained DOM, preserving unrelated
  ordinary and namespaced consumer handlers
- Modeled PJAX content replacement with fresh Blade HTML and script execution
- Explicit collection root/body/control markers, direct-child templates, retained
  legacy CSS classes, exact existing/template/new-child input names
- Empty markers outside removable rows/templates, before visible inputs in DOM
  and serialized form order, clearing every root, and re-adding afterward
- DOM row reordering followed by serialization, removal, and addition
- Synthetic nesting of unchanged rendered roots, checking nearest-root event
  guards and outer-body ownership; unrelated table controls remain untouched

## Boundaries

This is offline DOM regression coverage, not browser E2E, visual/layout behavior,
live PJAX requests, or post-click persistence coverage. HTTP submission and
persistence assertions remain in `ExplicitEmptyCollectionsTest` and the other
field integration tests. These fields have no sortable plugin; the reorder check
moves actual DOM rows rather than claiming drag-and-drop support. Synthetic root
nesting tests defensive ownership guards, not a newly supported field API.

Repeat-initialization assertions rerun the actual collection-only scripts captured
from real top-level fields. They deliberately do not rerun the whole HasMany
parent initializer. Full ready-wrapped table-parent reinitialization, identity
allocation, consumer handlers, and post-click HTTP/SQLite persistence are covered
by the separate [table-parent regression](HASMANY_TABLE_REINITIALIZATION.md). The
actual parent scripts here still create each new child and execute their captured
nested collection initializer; default/tab parent reinitialization is covered by
the separate [default/tab suite](HASMANY_MODES_REINITIALIZATION.md), outside
this test's scope.

Consumers overriding/publishing `listfield.blade.php` or `keyvalue.blade.php` must
carry forward the new `data-admin-collection`, `data-collection-body`,
`data-collection-add`, and `data-collection-remove` markers and the direct-child
template relationship when upgrading. Existing column-based CSS classes remain
available for styling; old overridden markup without the markers does not satisfy
the updated view/script contract.

Readonly support additionally requires the field-provided `collectionReadonly`
variable, native readonly input attributes and `data-collection-locked` on locked
roots. See the [collection state contract](LIST_FIELD.md#readonly-collections).
