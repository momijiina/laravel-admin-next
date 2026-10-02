# Generated form default literals

Run the focused, dependency-free regression in a fresh PHP process:

```sh
php tests/compatibility/generated_default_literals.php
```

The test supplies schema metadata doubles to the real `ResourceGenerator`, parses
its generated PHP and evaluates fixed local fixtures with a recording form. It
checks that apostrophes, consecutive/trailing backslashes, newlines, Unicode,
interpolation-looking text and numeric-looking strings retain their exact values
and types. Both the string-column branch and the unknown-type fallback are covered.
Numeric columns retain numeric literals. Existing empty, null string and zero
default omission is intentionally unchanged. The test does not exercise schema
discovery, the Laravel application lifecycle, or the full legacy browser suite.

CI runs this regression on PHP 7.0 (the declared package minimum) and PHP 8.4.
No PHP minimum, Laravel dependency, database mapping, or date expression behavior
is changed by this fix. Null defaults in non-string branches may still encounter
the separate PHP 8.1+ `trim(null)` deprecation.
