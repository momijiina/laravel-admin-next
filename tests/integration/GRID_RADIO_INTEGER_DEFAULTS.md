# Grid Radio integer defaults

The shipped Grid Radio presenter now matches an integer selected value to the
same string option ID used by HTML. For example, `->default(1)->radio([1 =>
'One'])` checks option `1`, just as `->default('1')` already did. Previously the
strict comparison between the string option ID and integer value left every
radio unchecked, so a native GET submission omitted that choice.

Only integer normalization changes. String IDs still match exactly, including
`'01'` versus `'1'`. Booleans, floats, arrays, NULL and empty strings retain their
existing behavior. This does not change `AbstractFilter::default()` or its
handling of zero/falsy defaults; calling `default(0)` is still ignored. Query
strings such as `?code=0` continue to override a nonzero default.

A filter default remains a presentation default: an initial request without a
filter query is unfiltered. Submitting the displayed choice applies the
ordinary equality condition. Reset removes the query and displays the configured
default again without applying it to SQL.

## Upgrade notes

- Update a published override of `admin::filter.radio` if the application uses
  one. The package cannot replace an application's overridden Blade view.
- Integer defaults that were previously unchecked now become successful native
  form controls. An unchanged submission therefore includes that intended
  choice. Use a NULL/omitted default if no initial selection is intended.
- Checkbox, ordinary form Radio, styled radios, radio option order and iCheck
  initialization are unchanged. No database migration or dependency update is
  required.

## Regression coverage

`GridRadioIntegerDefaultTest.php` covers positive and negative integer defaults
against their string equivalents, plain/named Grid filters, container/modal
views and inline/stacked radios. The offline DOM helper runs the emitted
initializer with shipped iCheck and both shipped jQuery 2.1.4 and jQuery 3.7.1.
It verifies checked/plugin state, native `FormData`, repeated serialization,
option changes and the reset link. Real HTTP/SQLite replays verify conditions,
bindings, returned rows, redisplay and unchanged stored values.

Direct view controls cover zero and PHP integer boundaries, exact string IDs,
blank/NULL and unsupported value types. Public API controls retain absent,
blank, unknown-query and falsy-default behavior. These tests do not claim
full-browser layout, screen-reader or custom-view coverage.

日本語: Grid Radio の整数の既定値を HTML の文字列 ID と照合するようにします。
`default(1)` と `default('1')` が同じ選択状態になり、変更せず送信しても値が
欠落しません。整数以外の既存の比較、`default(0)` が無視される既存仕様、
検索条件とリセットの意味は変えません。初回の既定値は表示用であり、送信前に
SQL 条件を追加するものではありません。公開済みの `admin::filter.radio`
独自ビューは別途更新してください。
