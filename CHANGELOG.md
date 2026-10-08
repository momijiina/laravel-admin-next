# Changelog

## Unreleased

For subsequent merged fixes and upgrade steps, including the image-processing
migration and root BrowserKit development constraint, see the
[current maintenance summary](COMPATIBILITY.md#current-maintenance-summary)
and its linked guides. This index separates tested coverage from support claims.
日本語: その後の修正・画像処理の移行・BrowserKit 開発依存と更新時の注意は、
上記の最新まとめを参照してください。検証範囲と対応保証は区別しています。

### Historical PHP baseline migration (2026-10-02)

These entries record the initial PHP-baseline change, not the complete current
unreleased state or CI matrix.

- Breaking platform change: require PHP `^8.2`; PHP 7.x, 8.0 and 8.1 are no
  longer supported. PHP 8.3+ is recommended.
- Explicitly declare the existing nullability of 37 parameters to avoid PHP 8.4
  compile-time deprecations, preserving names, defaults and accepted values.
- Replaced obsolete PHP 7 CI coverage with PHP 8.2–8.4 checks at that time.
  That migration left Laravel and root legacy development dependency constraints
  unchanged; subsequent dependency and CI changes are covered by the index above.

Historical upstream documentation: https://laravel-admin.org/docs/
