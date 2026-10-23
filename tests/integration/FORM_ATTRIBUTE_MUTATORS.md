# Modern Eloquent attributes in Form

Form now recognizes methods returning Eloquent's native
`Illuminate\Database\Eloquent\Casts\Attribute` before treating a field or input
as a relation. This follows the existing Grid/Show attribute support.

Previously, saving a field backed by a protected Attribute method could throw
`BadMethodCallException`. Editing a field backed by a public Attribute method
could attempt to eager-load the Attribute object and fail with a missing
`addEagerConstraints()` method. The same confusion affected snake-case inputs
whose Attribute declaration uses a camel-case method name.

The fix uses Eloquent's guarded `hasAttributeMutator()` capability in both
relation-input extraction and edit/update relation discovery. For dotted field
names, discovery checks the root attribute before invoking it as a relation.
It does not invoke Attribute declarations to classify them.

Inputs continue through the existing field preparation and native Eloquent
`setAttribute()` path. Getter/setter, getter-only, setter-only and computed
multi-column setters retain Eloquent's behavior. A getter-only declaration does
not make a Form field read-only; configure that separately. Editing still uses
the model's `toArray()` representation, so computed values need the application's
normal `$appends`/visibility configuration. No automatic appends or new naming
rules are added. Existing legacy getters/setters and actual relations keep their
previous paths. Frameworks without the native detection capability retain the
previous relation logic; this is not runtime certification of older Laravel.

Review Form subclasses overriding `getRelations()` or `getRelationInputs()`.
No dependency/minimum-version change, asset build, view republishing or schema
migration is required. This change does not add arbitrary custom-cast or nested
relation support. Existing structured-input preparation remains unchanged,
including `prepareInsert()`'s single-array-input flattening heuristic; the
embedded-attribute lifecycle test uses a normal scalar field before the embedded
input rather than claiming a fix for that separate limitation.

Run from `tests/integration` after its normal setup:

```sh
composer test -- --filter FormAttributeMutatorTest
```

The tests use Laravel's HTTP kernel and disposable SQLite. They compare stored
values and edit inputs against native Eloquent, exercise text/Unicode/zero/null,
public/protected declarations, snake-case names, computed multi-column setters,
embedded attributes, and a real HasOne relation. A separate capability double
checks the guarded fallback without pretending to run an old Laravel release.
There are no live-browser, external-database or arbitrary downstream-application
claims. The repository's ordinary integration CI runs this regression in its
existing PHP/Laravel matrix.

## 日本語

Form の編集・保存時に、Eloquent 標準の `Attribute` を返すメソッドを
リレーションと誤認して例外になる問題を修正します。protected/public、
snake_case の入力名、getter/setter・getter のみ・setter のみを対象にし、
計算属性の複数カラムへの保存も Eloquent 本来の動作に任せます。

入力は従来のフィールド準備・`setAttribute()` を通ります。getter のみの属性が
自動で読み取り専用になるわけではありません。編集値は従来どおり `toArray()`
から取得するため、計算属性の `$appends` や可視性はアプリ側で設定してください。
従来形式の getter/setter と実際のリレーションは維持します。

独自 Form の属性・リレーション判定を確認してください。最低要件・依存関係の
変更や、アセット・ビュー・データの移行はありません。配列入力の準備方法は
変更せず、単独の配列入力を平坦化する既存の制約も別件として残ります。
HTTP/SQLite の検証であり、実ブラウザー・外部 DB・任意のアプリ全体の対応を
保証するものではありません。
