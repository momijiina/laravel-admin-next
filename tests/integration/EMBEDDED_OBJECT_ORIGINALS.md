# Native object originals in embedded forms

`EmbeddedObjectOriginalTest.php` exercises real generated controllers with an
explicit `embeds('settings', ...)` configuration. Both native Eloquent `array`
and `object` casts use the same configured fields. The generated textarea is
replaced explicitly by the consumer fixture; the generator's default is unchanged.

The regression covers create/edit/update, unchanged and changed configured values,
nested embedded fields and multiple-select lists, and original null, `[]`, `{}`,
and populated objects. Before the fix, updating an object-cast record fails in
`EmbeddedForm::setFieldOriginalValue()` because `array_key_exists()` receives a
`stdClass`. Empty object originals fail too.

The fix makes a shallow local array view of `stdClass` original metadata only
at key lookup. It does not mutate that metadata, recursively convert nested
values, alter the model's cast, or decode submitted text. Preparation observes
the same original-value contract as the existing array path, including null keys;
non-stdClass objects and unsupported scalar originals keep their existing errors.
Existing JSON-string original handling remains unchanged.

## Boundary

Embeds is a structured form for configured controls, not a lossless arbitrary JSON
editor. Saving the configured controls replaces the attribute with their submitted
values; unrepresented original sibling keys are not merged back. This existing
array-cast behavior also applies to object casts. Empty original shapes may become
populated objects when fields are submitted. Raw JSON textarea decoding, arbitrary
document/empty-shape fidelity, and unrelated per-field original-assignment behavior
are outside this fix.

Run in the integration consumer after its normal Composer setup:

```sh
vendor/bin/phpunit EmbeddedObjectOriginalTest.php
```

These are HTTP-kernel/SQLite tests, not browser JavaScript or external-database
coverage. The test is also registered in the full integration suite.
