# DomCrawler compatibility

The package additionally allows `^6.4.40`, retaining its existing 3.x/4.x/5.x
constraints. The new floor is the earliest 6.4 release Composer's normal security
policy allowed in the verification below. No Laravel, DBAL or root development
constraints change. This is a dependency prerequisite for a separate historical
BrowserKit runner restoration; none of the 73 historical tests is executed here.

The dedicated DomCrawler workflow checks PHP 8.2/8.3/8.4 with Laravel 12 and
PHP 8.3/8.4 with Laravel 13
with DomCrawler 5.4 (baseline), exactly 6.4.40 (new floor), and the latest allowed
6.4. In the two modern cells it also requires `laravel/browser-kit-testing:^7.2.8`
in this isolated consumer, proving that the original Composer conflict is gone.
BrowserKit is installed but these tests do not claim to exercise its legacy suite.
This workflow runs the full integration suite, including the collection DOM
regressions. Node 24.15+ (24.x) and the locked test-only npm dependencies are
required in every Crawler matrix lane, alongside the Composer dependencies.
For example, from this directory with Node 24 available:

```sh
npm ci --ignore-scripts --prefix javascript
composer require --no-update 'laravel/browser-kit-testing:^7.2.8'
composer update --with 'orchestra/testbench:^10.0' --with 'phpunit/phpunit:^11.5' --with 'symfony/dom-crawler:6.4.40'
composer check-platform-reqs
composer test
```

For the baseline 5.4 run, omit/remove the consumer's BrowserKit requirement,
since supported BrowserKit requires DomCrawler 6.2 or later. Never disable
Composer advisory checks or platform requirements to force a matrix cell.

`DomCrawlerCompatibilityTest.php` adds eight real-framework tests covering both
production consumers, with no framework or package stubs:

- The actual HTTP kernel and PJAX middleware preserve title, selected inner HTML,
  first-match behavior, nested markup, Unicode, numeric/named entities, declared
  ISO-8859-1 input, status and response headers.
- Empty containers, missing title/container, invalid selectors, non-PJAX, guests
  and redirects preserve their existing success/fallback/bypass behavior.
- The actual action form mutates only the first matching node, replaces its old
  modal attribute, preserves input/select/textarea/table fragments and siblings,
  and retains the existing error for an absent selector. Exact serialization and
  semantic DOM assertions protect against empty fragment output.

## Why 7.x and 8.x remain excluded

Symfony's [official changelog](https://github.com/symfony/dom-crawler/blob/8.0/CHANGELOG.md)
documents the native HTML5-parser transition. On PHP 8.4, real probes with
DomCrawler 7.4.17 and 8.0.12 make `Form::addElementAttr()` return an empty string:
the added empty `head` becomes the first node selected by `children()->html()`.
PJAX table serialization also adds a `tbody` element. Each version fails the new
regression suite on those two assertions. These were isolated negative controls,
not successful package dependency installations or compatibility claims. Support
for those majors needs separate production work; this change does not alter the
parser or adapt production HTML behavior. DomCrawler 7.0 could not be installed
under normal security checks and was not tested.

## Local verification for this expansion (2026-10-02)

On PHP 8.4.25, Laravel 12.69.3 / Testbench 10.12.0 / PHPUnit 11.5.56 and Laravel
13.34.0 / Testbench 11.3.0 / PHPUnit 12.5.37 each pass **22 tests / 1,457
assertions** with each of DomCrawler **5.4.52, 6.4.40, and 6.4.44**. These include
the existing 14 lifecycle tests unchanged. Normal dependency resolution and
`composer check-platform-reqs` pass in all six consumer environments; BrowserKit
7.2.8 is present in all four modern cells. No lockfile reports known security
advisories at verification time. All existing standalone regressions and the
326-production-file lint also pass on PHP 8.4.25.

PHP 8.2/8.3 cells are CI coverage requests, not local passes. Retaining the old
3.x/4.x ranges does not establish new coverage for those families. The minimum
check pins DomCrawler 6.4.40 with normally resolved dependencies, rather than
claiming a lowest-version run of the entire dependency graph.
