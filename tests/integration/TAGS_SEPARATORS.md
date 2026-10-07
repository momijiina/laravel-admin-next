# Literal Tags separators

`Tags::separators()` customizes the Select2 token-separator list and the field's
`createTag` callback. Previously that callback interpolated the separator
characters into JavaScript regular-expression literals. A backslash or line
break could make the generated script invalid; `]` could stop matching; `^`
and a hyphen between letters could match and remove ordinary tag text.

The callback now escapes those characters and JSON-serializes the pattern for
`RegExp`. Configured separators are literal characters. Both separator detection
and trailing-separator removal use the same pattern. The default separators,
Select2 options, trimming order, ordinary text gate and comma storage are
unchanged. Only `src/Form/Field/Tags.php` changes production behavior.

## Upgrade cautions

Review custom `separators()` lists and any overridden Tags initialization or
`createTag` callbacks. Literal punctuation no longer creates regex negation,
ranges or escapes. Custom code that accidentally relied on those effects will
behave differently. Reload already-open administration forms after deployment
so they receive the new generated initializer. There is no asset or database
migration. Verify the behavior in each consuming Laravel application.

日本語: `separators()` の設定や Tags の初期化処理・`createTag` コールバックを
上書きしている場合は確認してください。記号は区切り文字そのものとして扱われ、
正規表現の否定・範囲・エスケープとして解釈されなくなります。従来の副作用に
依存する独自処理は動作が変わります。デプロイ後は開いている管理画面を再読み込みし、
利用先の Laravel アプリごとに確認してください。アセット更新・DB 移行は不要です。

## Regression coverage

`TagsSeparatorsTest.php` renders the real Form and emitted ready wrapper and
executes it with shipped Select2, Bootstrap and iCheck under both shipped
jQuery 2.1.4 and jQuery 3.7.1. It exercises default/empty lists, ordinary custom
separators, brackets, backslash, caret, a literal hyphen between letters, slash,
quotes, LF/CR, Unicode line separators, regex punctuation and Japanese punctuation.
The actual Select2 query and result-click handlers select new tags. Native
`FormData` is compared with jQuery serialization and replayed through the real
Laravel HTTP kernel and Form create/update paths into SQLite. Reopened forms
retain the saved tags. Callback checks cover internal punctuation, repeated
trailing separators, trimming, an ordinary nonseparator term and zero text.

Run with the [isolated consumer instructions](README.md):

```sh
composer test -- --filter TagsSeparatorsTest
```

## Boundaries

These are offline jsdom and in-process HTTP/SQLite tests. They do not establish
live-browser layout, keyboard/Enter/IME behavior, accessibility, actual network
or PJAX behavior, or other database-driver parity. No AJAX response is involved.
Custom invalid/non-string separator lists, invalid UTF-8, multicharacter token
semantics, generic Select2 tokenization, comma-containing tag storage, plucked
relation values and custom widget overrides are outside this repair. Existing
preparation, validation retries, selection defaults and the global Enter handler
are unchanged. No dependencies, assets or views are modified, and copied
consumer lockfiles are not a fresh dependency or security audit.
