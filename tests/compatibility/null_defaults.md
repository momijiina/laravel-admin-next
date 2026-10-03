# Missing database defaults

Run this dependency-free regression in a fresh PHP process:

```sh
php tests/compatibility/null_defaults.php
```

Metadata doubles exercise the real `ResourceGenerator` with 97 cases. The test
promotes all diagnostics to failures, compares complete generated source, parses
it, and evaluates fixed local fixtures. It covers missing defaults for every
supported type plus the unknown-type fallback; empty/zero omission; ordinary
string, numeric and textarea defaults; required/defaulted date/time expressions;
and omitted synthesized defaults for nullable temporal columns with NULL defaults.

PHP 8.1+ deprecates passing null to `trim`. The generator now skips that call
when the derived default is null, preserving its previous omission behavior.
There is no PHP minimum, schema-discovery, literal-escaping, or default-value
policy change. This is a focused regression, not the full Laravel browser suite.
CI runs it on the declared PHP 7.0 minimum and PHP 8.4.
