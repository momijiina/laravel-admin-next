# Model-less controller generation

`php artisan admin:make BlankController` selects the existing blank controller
stub without initializing a model or inspecting database columns. Namespace and
custom-stub options still use the normal generator path. Since no model supplies
a resource name, this mode does not print a resource-route suggestion.

`--output` prints model-derived grid, show and form snippets, so it requires
`--model`. Using it without a model now reports that requirement with exit status 1 rather
than constructing an invalid ResourceGenerator. Model-backed generation and output
retain their existing behavior, including their existing schema dependencies.

## Focused regression

Run `php tests/compatibility/make_without_model.php` without Composer or a
database. It exercises the actual MakeCommand and ResourceGenerator with a
small framework-generator double and Doctrine-compatible schema fixture:

- Omitted and empty model options produce the exact blank stub
- Namespace, title and custom-stub options work without a model
- No model-less schema lookup or empty resource-route suggestion
- Model-less output and missing model/stub validation fail cleanly
- Model-backed title/class/field substitution, snippets and route are retained
- Parent generation failure does not print a route

The test fails before this patch and passes with it under PHP 8.4. It treats
runtime diagnostics as failures. Its framework double does not verify real
Artisan parsing, filesystem writes, or modern Laravel schema compatibility.
In particular, the separate `ResourceGenerator` Doctrine API migration remains
outside this change. No Composer constraints or PHP minimum are changed.
