# Image processing migration: Intervention Image v3

**Breaking change:** image transformations and thumbnails now use Intervention
Image **`^3.11.9`**. Intervention Image v2 is no longer supported for these
features. This is a bounded compatibility adapter, not a complete v2 API shim
or a promise of identical pixels. Audit application callbacks, extensions and
thumbnail settings before upgrading. Ordinary image/file uploads without
transformations or thumbnails do not require Intervention Image.

## 日本語: 変更点と注意事項

- 画像変換・サムネイルには任意依存の Intervention Image `^3.11.9` が必要です。
  処理なしの通常アップロードには不要で、PHP の最低要件は 8.2 のままです。
- 旧 API の互換範囲は下記の 10 メソッドに限定されます。v2 の
  `Intervention\Image\Constraint` 型は、このフォークの型または型指定なしへ
  書き換えてください。その他の操作には `imageProcessing()` と v3 API を使います。
- EXIF の自動回転とアニメーションのデコードは無効です。回転補正には
  `orientate()` を明示します。アニメーションを処理するとフレーム列は保持されません。
  処理を行わない通常アップロードは別の経路です。
- サムネイルの処理は元画像の保存前に完了させ、単一画像の置換では保存失敗時に
  旧画像・旧サムネイルの削除へ進まなくなりました。ただし複数ファイルの書き込みは
  アトミックではなく、新しいファイルの一部が残る場合があります。同一パスへの
  上書きや複数画像の先行ファイルで完了した処理はロールバックされません。
- 全 v2 動作との一致を保証する変更ではありません。Imagick、WebP/AVIF の
  コーデック、お使いの実画像・保存先を含む未検証範囲は、導入前に確認してください。
  以下の検証欄に記載した限定的な結果と、一般的な互換性保証は区別してください。

## Install and upgrade

The fork requires PHP **`^8.2`** (8.2–8.x). Install the optional processing
dependency in your consuming application, with the PHP extension required by
your selected driver:

```sh
composer require 'intervention/image:^3.11.9'
composer check-platform-reqs
composer audit --locked
```

The default driver needs GD with support for your input/output formats. Imagick
requires its PHP extension. Resolve any application or extension dependency on
v2 rather than bypassing Composer checks. A Laravel facade integration package
is not required by this adapter: it constructs an Intervention v3 manager
directly. Existing application code using v2 facades/managers needs its own
migration. Consult the [official Intervention v3 upgrade guide](https://image.intervention.io/v3/getting-started/upgrade)
for native API changes; the field adapter below is specific to this fork.

## Supported legacy field calls

The following positional calls work on both `image()` and `multipleImage()`
fields. Only these ten dynamically dispatched processing methods are supported;
this list does not restrict ordinary, explicitly declared field methods.
Optional arguments are shown with their defaults.

| Field call | v3 behavior and limits |
| --- | --- |
| `rotate(angle, background = 'rgba(255,255,255,0)')` | `rotate`; default exposed background is transparent white. |
| `flip(direction = 'h')` | `'h'` maps to v3 `flop()`; `'v'` maps to v3 `flip()`. Other directions are rejected. |
| `crop(width, height, x = null, y = null)` | Centered when offsets are omitted/null; with either non-null offset, uses top-left positioning and zero for the missing offset. Transparent-white background. |
| `resize(width, height, callback = null)` | Legacy dimension calculation followed by v3 `resize`; `aspectRatio()` preserves proportions and `upsize()` prevents enlargement. Supply both dimension arguments, using `null` where appropriate. |
| `widen(width, callback = null)` | Proportional legacy dimension calculation followed by v3 `resize`; `upsize()` prevents enlargement. |
| `heighten(height, callback = null)` | Proportional legacy dimension calculation followed by v3 `resize`; `upsize()` prevents enlargement. |
| `fit(width, height = width, callback = null, position = 'center')` | Calculate cover crop size, apply legacy anchor positioning, then resize using the constraint flags. Omitted/null height makes a square. |
| `insert(source, position = 'top-left', x = 0, y = 0)` | Legacy anchor/offset calculation followed by v3 `place`; source must be readable by v3. |
| `resizeCanvas(width, height, anchor = 'center', relative = false, background = 'rgba(255,255,255,0)')` | Legacy anchored crop/pad canvas; dimensions are relative changes when `relative` is truthy, otherwise absolute. |
| `orientate()` | Explicit v3 `orient()` based on orientation metadata. |

Argument-count bounds are enforced: rotate 1–2, flip 0–1, crop 2–4,
resize 2–3, widen/heighten 1–2, fit 1–4, insert 1–4, resizeCanvas 2–5,
orientate 0. v3 still validates dimensions, positions, colors and source data;
acceptance by the adapter is not a guarantee that every v2 argument value is
valid in v3. `resize` with a null dimension does not implicitly request
proportional scaling: use `aspectRatio()`, `widen()` or `heighten()` when that
is your intent. Resize dimensions must be positive integers or null, with at
least one non-null dimension. The compatibility calculations preserve v2's
two-axis, per-step integer rounding, including tiny-image upscaling; they are
not direct aliases for v3's native scale/cover methods.

For `fit`, `insert` and `resizeCanvas`, supported anchors are exactly
`top-left`, `top`, `top-right`, `left`, `center`, `right`, `bottom-left`,
`bottom`, `bottom-right`. v2 aliases and case variants are rejected. Centering
uses `floor(source / 2) - floor(target / 2)` per axis so odd-sized images retain
the legacy offset. `insert` ignores the x offset for `top`/`bottom`, and ignores
the y offset for `left`/`right`, as v2 did; right/bottom offsets point inward.

### Replace v2 constraint type hints

`Intervention\Image\Constraint` is a v2 type and must not appear in these
callbacks. Replace it with the fork's small constraint class, or omit the type
hint. Its only supported methods are `aspectRatio()` and `upsize()`; the latter
means **prevent enlargement**, as in the old field API.

```php
use Encore\Admin\Form\Field\Image\Constraint;

$form->image('photo')->resize(800, 600, function (Constraint $constraint) {
    $constraint->aspectRatio()->upsize();
});

$form->multipleImage('gallery')->widen(1200, function ($constraint) {
    $constraint->upsize();
});
```

This class is not an alias for the removed v2 class and does not expose the
entire v2 constraint implementation. `fit` uses these flags when resizing its
cover crop. A constraint callback is invoked once to set the flags; do not rely
on v2 repeated-invocation side effects.

### Use native v3 operations with `imageProcessing()`

Operations outside the bounded list, such as `greyscale`, belong inside a
native callback, not a direct field call. Type the callback with v3's
`ImageInterface`. It may return an `ImageInterface` (including a replacement
image) or return `null` after mutating the supplied image.

```php
use Intervention\Image\Interfaces\ImageInterface;

$form->image('photo')
    ->orientate()
    ->imageProcessing(function (ImageInterface $image): ImageInterface {
        return $image->scaleDown(width: 1200)->greyscale();
    })
    ->rotate(90);

$form->multipleImage('gallery')
    ->imageProcessing(function (ImageInterface $image): void {
        $image->cover(400, 300);
    });
```

Native callbacks and legacy calls run in their chain order. A callback must
return the image, not encoded bytes or an encoder result. The adapter still
controls file encoding after each callback. Migrate v2 image callback type
hints as well as resize constraint hints. A thrown `Throwable` is wrapped in a
`RuntimeException` with the original exception retained as its previous cause.

A replacement image must have a supported origin media type because encoding
uses the returned image's origin. When constructing a replacement, copy the
source MIME type to its origin with
`$replacement->origin()->setMediaType($image->origin()->mediaType())` before
returning it, or explicitly set a supported output MIME type. Do not assume a
newly created canvas has the uploaded image's origin metadata.

## Driver configuration and decoding defaults

Add this under `upload` in your application's published `config/admin.php`
if you want explicit driver selection (the same fallbacks work when absent):

```php
'image_processing' => [
    'transform_driver' => 'gd',
    'thumbnail_driver' => null,
],
```

- `admin.upload.image_processing.transform_driver` defaults to **GD**, including
  when null. It does not inherit `image.driver`.
- `admin.upload.image_processing.thumbnail_driver`, when absent or null, uses
  `config('image.driver', 'gd')`. This preserves the separate legacy thumbnail
  driver selection. If that configuration is absent, it falls back to GD.
- Either setting accepts `'gd'`, `'imagick'`, or the corresponding v3 class
  name: `Intervention\Image\Drivers\Gd\Driver::class` or
  `Intervention\Image\Drivers\Imagick\Driver::class`. Other drivers, driver
  objects and arbitrary driver class names are rejected.

Both paths explicitly use `autoOrientation: false`, `decodeAnimation: false`,
white `blendingColor`, and `strip: false`. Processing does **not** automatically
correct EXIF orientation or preserve animated sequences. Use `orientate()` (or
native `orient()`) explicitly when needed; it runs before thumbnail creation
when queued on the field. `strip: false` is not a guarantee of identical
metadata preservation across encoders/drivers. A synthetic two-frame GIF test
verifies first-frame-only processing and unchanged bytes for an ordinary upload.
This is not general v2 animation parity. With the EXIF extension enabled, a
synthetic JPEG with orientation 6 verifies that decoding does not auto-rotate
and that explicit orientation correction rotates 90 degrees clockwise, with
dimensions and pixels checked. This does not establish all EXIF parity. Imagick and WebP/AVIF codecs are untested; test representative inputs
yourself before depending on them.

## Encoding

Transformed images and thumbnails are encoded using the decoded source image's
MIME type via `encodeByMediaType(..., quality: 90)`, not the temporary upload
path's extension. The value 90 is passed to the encoder; its effect depends on
the format. There is no automatic JPEG conversion or byte-identical output
promise. BMP encoding is supported by v3 where the old path failed; do not rely
on that old failure. Processing a JPEG can recompress it. Ordinary uploads without
processing keep the upload path's existing behavior.

## Thumbnails

```php
$form->image('photo')->thumbnail('small', 160, 120);

$form->multipleImage('gallery')->thumbnail([
    'small' => [160, 120],          // default: resize
    'square' => [160, 160, 'fit'],  // cover/crop
]);
```

Only `'resize'` and `'fit'` actions are supported. `resize` proportionally
scales to the bounding dimensions; `fit` covers/crops the requested dimensions.
Both then apply a **centered white canvas** of the requested size; source PNG
alpha is retained rather than flattening all source pixels onto white. Both allow
upscaling; thumbnail configuration has no constraint callback/no-upsize flag.
Use positive explicit width and height values for fixed-size thumbnails.
Thumbnails use the processed upload as their source and retain the naming
pattern `basename-thumbnailName.extension` on the configured upload disk.
Their bytes are encoded by source MIME, not inferred from that filename.

`multipleImage()` retains an existing naming quirk: a custom name callback or
literal name is applied to the first file, then reset for subsequent files in
the batch. This migration does not change that behavior.

## Processing order and failure boundaries

For each uploaded image, the normal field pipeline is:

1. Run queued transformations/native callbacks in declaration order on the
   temporary upload. Encode and save the result to that temporary path **after
   every queued operation**.
2. Decode, transform and encode **all configured thumbnails into memory**, using
   the final temporary image. Invalid thumbnail actions and image-processing
   errors therefore fail before original-image storage/deletion starts.
3. Store the new image through the existing upload path. Single-image replacement
   now defers deletion of the old original until thumbnail writes succeed.
4. Write the prepared thumbnail bytes. Only after all thumbnail writes succeed
   does old-thumbnail cleanup run (subject to `retainable`).
5. For single-image replacement, delete the old original after successful
   thumbnail storage, again respecting `retainable`. Multiple-image uploads keep
   their existing per-file upload/deletion workflow.

A thumbnail storage operation returning `false` throws `RuntimeException` and
skips that file's old-file cleanup. In single-image replacement with distinct
old/new paths, a thumbnail storage failure preserves the old original and old
thumbnails. A later failure in a multiple-image batch does not undo cleanup
that already completed for an earlier file. This does **not** make the upload
atomic: the new original and some new thumbnails may already have been written
before a later write fails. Storage exceptions propagate, and there is no
rollback across disk writes, multiple images or database updates. Successfully
stored main/thumbnail paths are protected from cleanup, including earlier files
in the same `multipleImage()` batch. That guard prevents deletion of new files;
it cannot undo a same-path overwrite or restore previous bytes. Plan recovery
and cleanup accordingly. The temporary upload can already
have been changed when a later processing step fails, although the original
stored image has not yet been replaced.

## Errors and upgrade checklist

- Missing Intervention or a v2-only installation: `LogicException` with the
  required version and a link to this guide. Plain uploads remain available.
- Unknown dynamic processing call: `BadMethodCallException`, rather than
  forwarding arbitrary names to an Intervention image.
- Invalid argument counts, flip direction, driver, thumbnail action or resize
  constraint callback/type: `InvalidArgumentException`.
- Native callback returning anything other than `ImageInterface` or null:
  `UnexpectedValueException`. Native callback exceptions become `RuntimeException`
  with the original cause; decode/encode errors propagate.
- Failed thumbnail storage (`false`): `RuntimeException`; old-file cleanup
  is not reached. New files already written are not rolled back.

Before deploying, search for v2 imports/facades, direct calls outside the ten
methods, typed constraint callbacks, non-resize/fit thumbnails and assumptions
about animation, orientation, output format or driver parity. Exercise both
single and multiple upload/replacement/deletion workflows with your real image
formats and storage disk. Review the [compatibility policy](COMPATIBILITY.md)
and [historical BrowserKit runner](tests/browserkit/README.md): earlier v2-era
suite passes are historical evidence, not certification of v3 or every
downstream application combination.

## Focused verification

The new `Image processing output` suite is in
[`tests/images/ImageProcessingTest.php`](tests/images/ImageProcessingTest.php).
Run it with the isolated BrowserKit dependency set:

```sh
cd tests/browserkit
composer test -- --testsuite 'Image processing output'
php ../images/optional_dependency.php
```

The initial bundled-GD run on **PHP 8.5.11 / Laravel 13.34.0 / Intervention
3.11.9** passed **17 tests / 234 assertions / 0 skips**, with EXIF enabled and
zero diagnostics. This predates the GD-build-specific correction described
below; it was not a cross-build guarantee. The corpus contains **49 PNG outputs
from 31 archived v2 cases**. The
[oracle fixture](tests/images/fixtures/v2-oracle.json) records provenance:
Intervention Image 2.7.2, GD, PHP 8.5.11 and source revision
`47d0db5e3b6edbcb8aaabdebb2532da6edf397e9`. It compares dimensions, MIME and
row-major decoded RGBA (GD alpha 0–127), not encoded-file hashes.

### GD-build-specific rotation and the corrected oracle

The initial eight hosted GD lanes failed the archived 45-degree rotation case.
The source is 12×8 pixels: bundled GD produces 13×14, whereas external system
libgd 2.3.3 produces 15×15 for the same native rotation. Oblique rotation bounds
and resampling can depend on the GD build; identical adapter behavior does not
imply identical numerical output across those builds.

Independent v2 baselines on both builds also exposed a real input difference:
the v2 GD path decoder normalized hidden RGB in fully transparent pixels to
white; a raw v3 decode did not. Resampling could expose that hidden RGB as a
visible edge-color difference. The production adapter now clones the decoded
GD image using v3's public cloning behavior, which performs the equivalent
native canvas copy while preserving alpha, origin, EXIF and resolution. No
per-pixel normalization loop or vendor patch is used. Imagick is unchanged.
Native `imageProcessing()` callbacks receive this normalized GD input; operations
inside those callbacks still use native v3 semantics.

The final test runs all **31 oracle cases independently through a data provider**.
It checks **49 fixed archived PNG outputs per characterized backend**, including
oblique rotation, against actual Intervention v2 results. The
[bundled-GD manifest](tests/images/fixtures/v2-oracle.json) and
[external-libgd manifest](tests/images/fixtures/v2-oracle-external-gd.json) are
selected using [recorded GD fingerprints](tests/images/fixtures/gd-backends.json).
A fingerprint combines the exact GD version string with dimensions and decoded
RGBA hashes from independent native-GD resize/rotation canaries; those canaries
do not invoke Intervention or the adapter. An unknown fingerprint fails clearly
and requires an independently recorded v2 baseline. This is a **test-oracle
restriction only**: production uploads do not consult these fingerprints or
reject other GD builds. Expectations are never
regenerated from v3, selected by accepting a union of output hashes, weakened
with broad tolerances, or skipped for an unknown build. All selected output
checks compare exact dimensions, MIME and complete decoded RGBA hashes.

A separate independent comparison found all **53 decoded outputs** (including
JPEG/GIF originals and thumbnails beyond the committed PNG corpus) pixel-exact
against v2 on both tested backends. This is scoped evidence for those fixtures,
not a guarantee that oblique-rotation dimensions or pixels are portable between
GD builds. Applications requiring identical output should control and test their
GD build as well as package versions.

### Corrected local runs

With **PHP 8.5.11 / Intervention Image 3.11.9 / EXIF enabled**, the final focused
suite passed **48 tests / 236 assertions** on both characterized GD builds.
Complete BrowserKit results were:

| Backend | Laravel | Tests | Assertions |
| --- | --- | --- | --- |
| Bundled GD | 12.69.3 | 125 | 1,236 |
| Bundled GD | 13.34.0 | 125 | 1,232 |
| External libgd 2.3.3 | 12.69.3 | 125 | 1,228 |
| External libgd 2.3.3 | 13.34.0 | 125 | 1,234 |

All focused and complete runs had **zero skips and zero diagnostics**. Historical
random fixture counts explain varying assertion totals. The increase from 17 to
48 focused tests reflects 31 independently named oracle cases replacing one
loop-based test, plus a direct transparent-RGB normalization regression.

All four framework/backend integration runs completed **106 tests / 36,653
assertions / 2 expected database-service skips** each. The optional-dependency
boundary and **330-file production lint** passed on both builds. Removing the
normalizing clone fails two regressions; an unknown GD fingerprint fails the
data provider. Independent review checked both 49-output manifests against
actual v2 results. See [image test details](tests/images/README.md).
These local results are separate from hosted CI and untested runtime builds.

### Other coverage and initial local results

Cases cover
synthetic portrait/landscape/square/odd/tiny/alpha images, the bounded geometry
operations, constraints, watermarks and thumbnails. Additional tests cover
JPEG MIME/quality, callback order, invalid inputs, separate driver settings,
single/multiple storage cleanup, collisions, retained files and write failures.
Native replacement-image MIME/error behavior and two-frame GIF decoding/ordinary
upload bytes are also checked. The EXIF regression passed for the synthetic
orientation-6 JPEG; it is skipped when the PHP EXIF extension is unavailable. The separate no-autoloader optional-dependency
check passed.

These are focused GD checks, not global v2 compatibility or production-driver
certification. Imagick and WebP/AVIF codecs are untested. General EXIF and
full animation parity remain outside the focused cases recorded above.
Before the data-provider correction, on the same bundled-GD PHP 8.5.11 local
environment, the complete BrowserKit runner (including the output suite) completed:

- Laravel 12.69.3: **94 tests / 1,231 assertions / 0 skips**.
- Laravel 13.34.0: **94 tests / 1,232 assertions / 0 skips**.

Random historical fixture counts make assertion totals vary. Separate integration
runs on each framework completed **106 tests / 36,653 assertions / 2 expected
MySQL/PostgreSQL service skips**. All **330 production PHP files** passed source
lint. Composer platform checks and audits passed; root Composer validation
retains the existing warning about the unbounded Laravel `>=5.5` requirement.
These local checks are not hosted CI results or validation of every admitted
PHP/framework combination. The historical BrowserKit HTTP assertions remain
distinct from the output oracle and do not by themselves prove transformed
pixel correctness.
