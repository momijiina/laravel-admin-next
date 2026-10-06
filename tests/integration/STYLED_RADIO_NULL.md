# Nullable RadioButton and RadioCard choices

RadioButton and RadioCard previously compared option keys loosely with NULL.
With `[0 => 'Draft', 1 => 'Published']`, a NULL effective value selected zero
and activated its label. Saving an untouched nullable edit or blank create could
therefore store zero. Explicit old NULL after a validation error could also
redisplay zero over a stored choice and cause an unintended overwrite on retry.

Both styled views now use ordinary Radio's strict NULL guard before the existing
loose option-key comparison. Each option uses one computed selection for its
`checked` input and `active` label. Names, attributes, escaping, markup structure,
shipped label-click handlers and cascade scripts are unchanged.

## Preserved behavior

- The effective selection still uses `old($column, $value)`. Non-NULL matching
  stays loose, including false/zero, true/one and numeric strings; this does not
  redefine ambiguous option aliases or enforce strict option identity.
- Explicit scalar and closure defaults retain their meaning. A NULL stored value
  with an intentional zero default still selects zero. Old input takes precedence
  over the effective value/default, including explicit old NULL.
- The existing label-based `checked()` fallback remains additive when the field
  value is NULL. It is not changed to key-based matching or suppressed by the
  new guard, even when old input is present.
- Radio groups have no hidden clearing marker. No selected option means omission
  from native successful controls. A blank nullable create can store NULL and a
  NULL edit can retain NULL; omission when updating a non-NULL choice preserves
  its stored value. This is not a new clearing API. A present empty value still
  passes through normal Laravel empty-to-NULL middleware and form persistence.
- On validation redisplay, explicit old NULL can leave the group unchecked over
  a non-NULL stored value/default. Correcting only an unrelated field then omits
  the radio and preserves its stored value.
- HTML `required()` rejects an unchecked group and accepts selected zero.
  Existing server field validation skips absent columns, even with
  `rules('required')`; present NULL fails that rule. Applications requiring
  server-side presence enforcement must enforce it in their request rules.

## Upgrade cautions

Applications overriding/publishing `admin::form.radiobutton` or
`admin::form.radiocard` must reconcile their own templates. Refresh stale compiled
views through the normal deployment process and reload open forms. Application
views are not overwritten. No JavaScript asset republish, dependency/floor,
schema or data migration is needed. Values already overwritten before this
repair cannot be reconstructed by changing the renderer.

日本語: NULL をゼロと比較して自動選択する処理のみを修正します。既定値、old input、
NULL 以外の緩い比較、ラベルによる `checked()` の追加選択は維持します。未選択の
ラジオ項目は送信されず、更新では保存済みの値が残ります。NULL への消去 API では
ありません。上書き・公開済みの Button/Card ビューを確認し、通常の方法で
コンパイル済みビューを更新してフォームを再読み込みしてください。JS アセット
の再公開やデータ移行は不要ですが、過去の誤上書きは復元しません。検証対象は
Laravel 12/13 のテスト用アプリであり、他の Laravel アプリとの互換性や古い
フレームワークでの実行を一律に保証するものではありません。

## Regression coverage

`StyledRadioNullSelectionTest.php` covers NULL, zero/one, string and boolean
values, defaults, old-input precedence, additive checked fallback, option order,
escaping, required constraints, real create/edit persistence and validation
redisplay/retry. Ordinary Radio and styled Checkbox remain regression controls.

`javascript/styled-radio-null-selection.cjs` runs production-rendered forms and
actual ready-wrapped scripts with shipped Bootstrap/iCheck, shipped jQuery 2.1.4
and jQuery 3.7.1 in offline jsdom. It checks checked/active agreement, native
FormData against jQuery serialization, repeated selection clicks and independent
groups. PHP decodes the native query for requests through the real Laravel HTTP
kernel, web middleware and Form store/update into disposable SQLite.

```sh
composer test -- --filter 'StyledRadioNullSelectionTest|NullableRadioTest|StyledCheckboxZeroTest'
```

Use the integration README's Laravel 12/13 and Node setup. This is offline DOM
and in-process HTTP/SQLite coverage, not live-browser layout, live PJAX/network,
production cookie/CSRF behavior, relationship storage, nested HasMany identity,
non-SQLite databases or arbitrary application certification. Reused installed
consumer graphs do not establish a fresh dependency-resolution result. No new
package or public API is introduced.
