# Mixed Grid inline editor submit handlers

## Change and reason

The shared submit partial previously removed every delegated `.ie-submit`
handler before registering the current editor. In a Grid containing Radio then
Text, selecting Radio option `2` could instead post and save its first option,
`0`, with a successful HTTP response. Reversing the columns could cause a typed
Text value to be omitted and a nullable column to be cleared. Depending on the
pair, the final editor could also supply an incorrect display callback or URL.

The common template now adds a CSS-safe class derived from the resource and
payload name. The shared partial replaces only the matching delegated handler.
Repeated rows of the same column reuse its binding; other resource/field
bindings survive. Bracketed payload names are hashed rather than interpolated
as CSS selector syntax. Existing `.ie-content`, `.ie-submit`, `.ie-cancel`,
trigger and template classes/identities remain in place.

This applies to the seven consumers of the shared partial: Input (including
Text), Textarea, Datetime, Select, MultipleSelect, Radio and Checkbox. It does
not change public displayer APIs, option/value coercion, NULL/empty handling,
array serialization, validation, display escaping, or server authorization.

## Upgrade cautions

- Reconcile **both** published/overridden views together:
  `grid/inline-edit/comm.blade.php` and
  `grid/inline-edit/partials/submit.blade.php`. The button marker and delegated
  selector must agree. Custom views that replace either side need the same
  resource/payload identity contract.
- No bundled JavaScript asset changes or data migration are required. Clear
  compiled views if your deployment retains stale compiled Blade templates.
- Fully reload open admin pages after deployment. A page retaining the old
  generic document handler (for example across a PJAX update) can execute both
  old and new bindings; the scoped registration deliberately removes only its
  own binding.
- This prevents future mixed-editor handler collisions; it cannot reconstruct
  values previously overwritten. Review affected application records if needed.
- Rebinding the submit partial is idempotent. Reexecuting every popover
  initializer on the same live DOM is a separate existing behavior and is not
  fixed here. Neither are same-name cross-Grid trigger/template collisions.
- The resource/name pair identifies a binding, not a security boundary. Existing
  application authorization, concurrency controls, and HTTP error handling
  remain necessary. Different editor types for the same resource/name are not
  independently identified.
- Compatibility and integration with other Laravel applications remain goals,
  not guarantees for every application/version pair. Verify downstream custom
  views and workflows individually.

日本語: 公開済み・上書き済みの共通ビューと送信 partial は、同じリソース・送信項目名
から作るクラスが一致するよう、必ず一緒に更新してください。JS アセットの再公開や
データ移行は不要ですが、古いコンパイル済みビューを保持する環境ではキャッシュを
クリアしてください。デプロイ後は開いている管理画面をページ全体で再読み込みし、
PJAX などで旧送信ハンドラーを残さないでください。過去に誤って上書きされたデータは
復元しません。Popover 全体の
再初期化、複数 Grid の同名 trigger/template、同じリソース・項目に異なる種類の
エディターを割り当てる場合の分離は対象外です。NULL・空値・型変換・検証・権限の
既存仕様を維持し、他の Laravel アプリとの互換性は個別に検証してください。

## Running the regression

The twelve scenarios cover both jQuery versions and:

- All seven editor families plus a bracketed JSON payload, two rows, and both
  column orders; exact payloads, untouched fields/rows and persisted values.
- Distinct-resource Grids in both orders with real popovers. Same-name resource
  cases use real templates and submit scripts but manually associate the
  trigger/display to isolate this binding layer from existing template clashes.
- Cancel/reopen, successful response display and cached metadata, fresh server
  rendering, and a real text validation 422 followed by correction/retry.
- Repeated submit-only registrations in both orders, one request per click,
  and preservation of an unrelated delegated handler. Datetime uses the shipped
  picker's API and `dp.change`; this does not claim visual calendar interaction.
- The legacy shipped datetimepicker calls jQuery's removed `size()` method.
  Modern-jQuery cases provide a **test-only** `size()`-to-`length` bridge so they
  can exercise submit isolation with that actual plugin. Shipped-jQuery cases
  need no bridge and are the authoritative shipped-picker coverage; the
  modern-jQuery cases are supplemental. This fix does not make the legacy picker compatible with
  unmodified jQuery 3, and does not ship the bridge to applications.

日本語: 同梱 datetimepicker は jQuery 3 で削除された `size()` を使用するため、
jQuery 3 のテストだけで `length` への互換処理を追加しています。同梱 jQuery 2 の
テストには不要です。この修正はアプリに互換処理を追加せず、旧 datetimepicker と
未変更の jQuery 3 の組み合わせを動作保証するものではありません。

Use the dependency setup in [README.md](README.md), then run:

```sh
vendor/bin/phpunit --filter GridInlineMixedEditorsTest
```

The harness uses actual Grid/Admin rendering and emitted scripts, shipped
Bootstrap popovers and widget assets in offline jsdom, and jQuery 2.1.4 and
3.7.1. AJAX transport is intercepted; captured jQuery-serialized requests are
replayed through Laravel's in-process HTTP kernel and real Form/SQLite saves.
This is not visual-browser, live-network/PJAX, external-database or complete
application certification. The committed regression specifies the exact
editor combinations and retry boundaries exercised.
