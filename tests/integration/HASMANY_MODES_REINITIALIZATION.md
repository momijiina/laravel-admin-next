# HasMany default/tab retained-DOM reinitialization

`HasManyModesReinitializationTest` renders actual default/tab HasMany forms through
Testbench, including the emitted `admin::partials.script` ready wrapper and captured
ListField, KeyValue, and custom Text-field initializers. Its offline JavaScript
runner loads shipped Bootstrap 3.3.4 and both shipped jQuery 2.1.4 and the existing
locked test-only jQuery 3.7.1. No parent/collection handler, ready wrapper, Bootstrap
tab method, or framework class is stubbed or replaced.

## Run

From `tests/integration`, with consumer Composer dependencies installed:

```sh
# Uses the existing lockfile and Node 24.15+ in the 24.x family.
npm ci --ignore-scripts --prefix javascript
vendor/bin/phpunit --filter HasManyModesReinitializationTest
```

The suite is registered in `phpunit.xml`. Existing integration and DomCrawler
compatibility workflows install its JavaScript dependencies; no new dependency or
separate CI job is needed.

## Coverage

The default/tab × ListField/KeyValue × ordinary/customized-name PHP matrix runs
all scenarios under both real jQuery versions:

- One, two, and three complete ready-wrapper executions before Add; multiple Adds,
  more reinitializations, then another Add. Exactly one child is inserted per click.
- One owned delegated Add/Remove handler per relevant parent/header/nav target,
  while plain and independently namespaced same-selector consumer handlers survive
  initial setup and every rerun and execute exactly once per click.
- Unique canonical child envelopes and custom captured Text initializers seeing
  the actual `index` exactly once, with safe integers retaining numeric type and
  arithmetic and large decimal values retaining their exact string identity.
- Two independent relations in one real ready callback, interleaved additions,
  unequal existing counts and pending maxima, and unchanged sibling payloads.
- Retained names and values, deleted newest/middle/all-new identities never reused,
  and fresh replacement DOM starting fresh element state.
- Pending DOM materialized from unchanged production templates: gaps, leading
  zeros, late-arriving pending markup, values around `Number.MAX_SAFE_INTEGER`,
  beyond-2^53 identities, and decimal carry through 30 all-nine digits.
- Seed scope excludes inert templates, another relation, controls outside the live
  body, and noncanonical `new_999x`/`new_1e9` names. Earlier unrelated templates and
  an external lookalike body cannot receive or alter insertion.
- Customized nested collection names contain unrelated `new_40` text and HTML,
  quote, and selector punctuation while canonical ID/removal controls stay intact.
- Default Remove keeps both existing and new rows hidden in the DOM, sets their
  removal flag, and clears required inputs without altering siblings.
- Tab links map one-to-one to unremoved panes with unique IDs. Adding activates the
  new pane. Closing inactive existing/new tabs preserves selection; closing an
  active tab selects the first remaining tab. Existing panes retain removal flags
  and their prior required-attribute behavior; new panes are detached. Closing
  the final existing or new tab and adding afterward leaves the correct active
  navigation and pane, including across reinitialization.
- Actual server-rendered validation error markup on an existing child activates
  the failing tab and its warning icon before and after another complete rerun.
- Existing and new collections have singular local Add/Remove behavior, correctly
  scoped direct-child templates and empty markers, marker-before-array submission
  order, and clear/re-add behavior.

For ordinary collection names, each jQuery's independently populated successful-
control query is passed through PHP `parse_str`, Laravel's real HTTP kernel and
web middleware, and `Form::update()`. SQLite verifies both original IDs and all
three separately populated new children, exact collection values and escaped
text, plus the independent relation. Thus the formerly colliding visible children
are checked all the way through persistence. Synthetic added records are reset
between jQuery runs. Additional DOM snapshots verify exact parsed envelopes and
collection payloads after removal, clearing/re-addition, replacement, and pending
allocation.

## Boundaries

This is jsdom plus Testbench HTTP/SQLite, not real-browser E2E, layout, live PJAX
transport, MySQL/PostgreSQL, or arbitrary third-party widget coverage. The custom
Text script exercises the public child `setScript()` API only.

Pending DOM scenarios instantiate production templates directly. They do not
claim a full failed-validation/old-input round trip for unsaved integer-key child
models: blank IDs becoming canonical `children[0]` names during server old-input
rendering are a separate issue. The validation test here concerns existing IDs
and the emitted tab activation behavior.

Customized collection names test DOM initialization and native PHP serialization,
not a new nested HasMany persistence API. Distinct relation identities are tested;
duplicate same-relation parent IDs and duplicate tab targets are ambiguous markup
outside this fix. Published views and collection internals are unchanged.

The existing [table-mode regression](HASMANY_TABLE_REINITIALIZATION.md) remains
separate, and the [collection-scoping regression](COLLECTION_SCRIPT_SCOPING.md)
continues to cover collection-only initialization in all three modes.
