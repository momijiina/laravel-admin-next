# Structured fields during Form creation

Form now preserves the complete value of a registered field when its existing
insert-preparation fallback flattens nested input. The field receives the array
through its normal `prepare()` method, followed by native Eloquent assignment.
Unmatched roots still use the existing dotted-field lookup and unknown inputs
are still discarded. The protected `isHasOneRelation()` decision remains in use.

Previously, when the first submitted value was an associative array beginning
with a scalar, `prepareInsert()` flattened the entire submission. An
`embeds('settings', ...)` value became `settings.theme`, etc.; these did not
match the registered `settings` field and were dropped. An array/object-cast
column or native Attribute setter could consequently receive no value. A sparse
MultipleSelect array was affected by the same fallback. Putting an unrelated
scalar first could hide the failure. The shipped form puts its CSRF field after
its ordinary controls, so a real rendered embedded-only form was affected too.

This does not change each field's preparation rules, model casts/setters, update
semantics, or relation handling. Computed JSON edit values still require normal
model serialization. This is not a general replacement for dotted-input
normalization: unmatched roots retain the pre-existing fallback decision and
its limits. No dependency, minimum-version, schema, view or asset changes are
required. Review overrides of `prepareInsert()` and custom field preparation,
and validate structured inputs as usual. Previously discarded submissions cannot
be recovered automatically from the database.

Run from the integration consumer after normal setup:

```sh
composer test -- --filter 'StructuredFormInsertTest|FormAttributeMutatorTest'
```

The HTTP/SQLite regressions exercise array/object casts and native Attribute
setters, scalar-first/array-first inputs, CSRF position, nested-first controls,
unregistered inputs, multiple structured fields and sparse MultipleSelect keys.
They verify creation, stored JSON, edit inputs, updates, dotted JSON leaf fields
and a real HasOne save. One case submits values obtained from the actual rendered
form using DomCrawler's form parsing, including its CSRF control. These are
in-process requests; they do not claim browser JavaScript, external databases or
arbitrary custom-field/application compatibility.

## 日本語

Form の作成時、先頭の入力が連想配列だと全入力を平坦化し、登録済みの
`embeds` などの値まで保存対象から落ちる問題を修正します。先頭に文字列の
フィールドを追加しなくても、配列を本来のフィールドの `prepare()` と
Eloquent の cast／setter に渡します。疎なキーを持つ MultipleSelect と、
通常のフォームで CSRF 項目が末尾にある場合も検証しています。

未知の入力は引き続き保存せず、既存の JSON 個別項目や実際のリレーションの
保存を維持します。未登録の配列ルートに対する既存の平坦化判定や、個々の
フィールドの処理・更新時の仕様は変更しません。独自の Form・フィールドの
準備処理を確認し、通常どおり入力を検証してください。依存関係・最低要件・
スキーマ・アセット・ビューの移行は不要です。過去に捨てられた値は DB から
自動復元できません。検証範囲は HTTP/SQLite と生成フォームの解析です。
