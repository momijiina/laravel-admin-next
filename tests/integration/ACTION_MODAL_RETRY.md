# Action modal retry recovery

An Action form puts its Submit button into Bootstrap's loading state before
submitting. Previously only AJAX success reset it. HTTP/transport failure or
cancelling the optional confirmation left the modal open with retained input,
but Submit remained disabled and another click could not retry.

The Form interactor now resets only the originating form's Submit buttons in
the AJAX error callback. Dismissing confirmation before Confirm also resets
that form. Once Confirm has started the request, its callbacks own reset;
Escape/backdrop dismissal while AJAX is pending must not enable duplicate clicks.

## Compatibility and upgrade cautions

- Ordinary Action, RowAction and BatchAction forms use the same interactor. The
  public API, methods, URLs, request FormData, row/batch keys, authorization and
  validation behavior remain unchanged. Error requests still reach the existing
  Action rejection handler; cancellation still does not produce an error toast.
- Successful AJAX responses are unchanged: `status: false` keeps the modal open
  and resets Submit; `status: true` closes it and resets the hidden button.
  Response notifications and navigation still run as before. Bootstrap's deferred
  reset means the button remains locked during success navigation dispatch.
- The fix changes PHP-generated JavaScript only. No production asset or view
  changes, republishing, schema migration or data conversion are needed. Review
  copied/overridden `Actions\Interactor\Form` implementations for equivalent
  failure/dismissal cleanup. No new JavaScript language feature is required.
- This does not add server-side idempotency. A transport interruption does not
  establish that the server failed to save; applications must handle retries
  safely for consequential actions. Request abortion, reopening a modal while a
  request is still pending, stale-response sequencing and synchronous exceptions
  in custom AJAX code are not redesigned.
- Shipped SweetAlert2 allows Escape/backdrop dismissal during a pending request.
  The existing outer response handling is then discarded: a later success may
  hide the modal without its normal navigation/toast, and a later error may have
  no toast. This change preserves that behavior but waits for the AJAX callback
  to reset Submit. It does not cancel the request or change dismissal policy.
- Each normal user click after Bootstrap enters loading is blocked until the
  relevant callback settles. This is not a new synchronous/re-entrant submit
  guard or a server-side duplicate-write guarantee.
- Other Laravel applications must validate their own versions, custom widgets,
  overrides and action side effects before claiming compatibility.

## Regression coverage

`ActionModalRetryTest.php` renders actual Action/RowAction/BatchAction HTML and
production ready scripts, including repeated initialization. Offline jsdom
executes shipped Bootstrap 3.3.4 and SweetAlert2 7.26.12 with both shipped jQuery
2.1.4 and jQuery 3.7.1. Native controls exercise:

- Two successive HTTP 500/transport failures and corrected retries, with input
  retention, original label restoration and unchanged error notices.
- Actual `status: false` validation responses, and successful controls with
  unchanged hide/reset/navigation timing.
- Confirmation Cancel and Escape before sending; actual Confirm submission;
  Escape/backdrop during pending AJAX with both late success and late error.
- Repeated Submit/Confirm clicks while loading, settled Close/reopen, and another
  independent Action form that must stay locked while its request is pending.

Only AJAX transport is intercepted. HTTP 500, validation and success bodies come
from real in-process Laravel requests. Captured native FormData is replayed in
completion order through the real HandleController, validation and Action
handlers. Disposable SQLite assertions check unchanged records on failures and
exactly one write per successful form, including the real row/batch model keys.
A synthetic network failure deliberately makes no claim about server receipt.

Run in the standard integration consumer for each framework family:

```sh
composer test -- --filter ActionModalRetryTest
```

jsdom cannot play CSS animations: the harness dispatches animation-end only after
SweetAlert itself marks a popup as closing. This is offline DOM and in-process
HTTP/SQLite coverage, not a physical-browser layout/accessibility, live network,
PJAX, real cookie/CSRF, non-SQLite or universal application-compatibility claim.
No new dependency installation or constraint change is required.

## 日本語: 変更点・注意事項・検証範囲

Action フォームは送信開始時に Bootstrap の loading 状態になりますが、従来は
AJAX 成功時のみ送信ボタンを復帰させていました。通信失敗や確認キャンセル後は
入力が残っていてもボタンが無効のままで、再試行できませんでした。

通信エラー時は元のフォームだけを復帰させます。確認画面を送信前に閉じた場合も
同様です。Confirm 後は通信コールバックが復帰を担当し、通信中に Escape や背景
クリックで確認画面を閉じても、ボタンを先に解除しません。別の通信中フォームには
影響しません。成功時のモーダルを閉じる処理、ボタン復帰、通知・遷移は維持します。

PHP が生成するスクリプトのみを変更するため、ビュー・アセットの再公開、スキーマ
変更、データ移行は不要です。独自の Form interactor は同等のエラー／キャンセル
処理を確認してください。Action・RowAction・BatchAction の API、送信データ、
行キー、認可・検証処理は変更しません。

通信失敗はサーバー側で保存されなかったことを保証しません。重要な操作の再試行は
アプリケーション側で重複処理を防ぐ必要があります。通信中の再表示、リクエストの
中断・応答順序、独自 AJAX 処理の同期例外や再入送信は対象外です。同梱 SweetAlert2
では通信中に確認画面を閉じると、その後の通知・遷移が失われる既存仕様があります。
本修正はこの仕様を変えず、通信結果が届くまでボタンの復帰を待ちます。

同梱ウィジェットと両 jQuery で、失敗・キャンセル・再試行、通信中の連打と確認画面
の取り消し、Close 後の再表示、別フォームの分離を検証します。実際の FormData を
Laravel の HTTP カーネルと HandleController に渡し、SQLite で保存結果を確認します。
実ブラウザー、実通信、PJAX、CSRF、他の DB や任意の Laravel アプリとの互換性を
保証するものではありません。バージョン・独自実装ごとに検証してください。
