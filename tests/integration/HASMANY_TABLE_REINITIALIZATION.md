# HasMany table retained-DOM reinitialization

`HasManyTableReinitializationTest` renders the actual table-mode HasMany Blade
view and `admin::partials.script` through Testbench. The JavaScript runner executes
the complete emitted ready callback with both shipped jQuery 2.1.4 and the locked
test-only jQuery 3.7.1. It does not flatten the ready wrapper, replace parent or
collection handlers, or stub framework classes. Preserving that wrapper is
important: separate initializations create separate JavaScript closures, which
previously reused `new_1` and registered duplicate table handlers.

## Run

From `tests/integration`, with the consumer Composer dependencies installed:

```sh
# Node 24.15+ in the 24.x family; shared existing lockfile, no new dependency
npm ci --ignore-scripts --prefix javascript
vendor/bin/phpunit --filter HasManyTableReinitializationTest
```

The existing integration and DomCrawler compatibility workflows already install
these locked JavaScript dependencies. The new regression is registered in the
ordinary integration suite.

## Assertions

Both ListField and KeyValue fixtures cover:

- One, two, and three executions of the actual ready-wrapped parent initializer
  before Add, producing exactly one new child per click
- Exactly one owned delegated Add and Remove registration, with both ordinary and
  independently namespaced same-selector consumer callbacks preserved
- Add twice, reinitialize twice, then Add: unique sequential input-name envelopes,
  earlier values unchanged, and all three independently populated children present
  after jQuery serialization and PHP's native `parse_str`
- Existing SQLite-backed child primary keys retained; existing deletion still
  hides the row, sets `_remove_` to `1`, and removes required attributes without
  changing its sibling; new-child deletion detaches that row
- Allocation continuing after deleting the latest child, after deleting all new
  children, and after another full initialization; fresh DOM replacement starts
  with fresh per-element allocation
- Two independent table parents in the same actual ready callback, including
  unequal preexisting row counts and unequal pending-identity maxima
- Pending `new_2` and `new_7` rows present before the first initializer, further
  pending markup appearing before reinitialization, and no reuse of those names
- Seeds restricted to actual table rows: inert templates and unrelated successful
  controls outside the parent row body do not advance the next child identity
- Earlier unrelated direct-child templates and tables do not replace the named
  parent template/body or receive inserted children
- Pending decimal identities beyond JavaScript's safe integer range, including
  `new_9007199254740992`, successive distinct names, deletion/reinitialization,
  and full decimal carry from `999` and from a 30-digit all-nines identity
- Customized collection names supplied through the public `setElementName()` API,
  with another pending key in the outer prefix and quote/HTML/selector punctuation;
  generated canonical hidden ID and removal fields remain in place
- A custom ordinary Text field `setScript()` initializer captured in the actual
  template: it sees the exact allocated `index`, runs once for the new field, and
  retains numeric type and arithmetic for safe integers, with exact decimal
  strings beyond that range; escaped template values survive
- Existing and newly inserted collection Add/Remove remain local and singular;
  direct-child templates and explicit empty markers keep the exact scoped names
  and DOM/submission order; a newly inserted collection can clear and re-add

For ordinary names, each jQuery run's actual populated query is parsed by PHP and
submitted through Laravel's HTTP kernel and the real `Form::update()` pipeline.
SQLite assertions verify both existing children and all three separately populated
new children, their exact List/KeyValue data, unchanged existing IDs, the independent
second relation, and escaped text values. Synthetic inserted records are reset
between the two jQuery submissions; no production records are involved.

## Boundaries

This is offline jsdom execution plus Testbench HTTP/SQLite persistence, not real
browser E2E, layout, live PJAX transport, or arbitrary third-party widget coverage.
The ordinary Text initializer is a consumer script exercising the public field
API; it is not a claim that any specific third-party widget was executed.

Pending-row scenarios materialize the unchanged production parent template with
explicit identities before initialization. They model already-present pending
DOM, not the entire validation-error redirect/old-input rendering pipeline.
Customized nested collection names test parent allocation, removal, collection
initialization, and native PHP serialization only. They do not claim a newly
supported nested HasMany persistence API.

This regression is table-only. Default/tab retained-DOM allocation and consumer
listeners are covered by the separate [default/tab suite](HASMANY_MODES_REINITIALIZATION.md). The separate
[collection scoping regression](COLLECTION_SCRIPT_SCOPING.md) continues to cover
unchanged collection-only reinitialization in all three HasMany modes.
