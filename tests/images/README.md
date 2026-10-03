# Image output regressions

Run through the isolated BrowserKit consumer (see its README):

```sh
cd tests/browserkit
composer test -- --testsuite 'Image processing output'
php ../images/optional_dependency.php
```

`ImageProcessingTest.php` runs real GD decoding, processing and encoding. It
fails on processing deprecations instead of letting Laravel log them as passes.
`fixtures/v2-oracle.json` and `v2-oracle-external-gd.json` record independently
measured v2 baselines for bundled GD and external GD 2.3.3. Both contain 49 PNG
outputs from 31 cases, each run independently as a PHPUnit data set. Expected
hashes are decoded pixels and alpha, not compressed bytes. `gd-backends.json`
selects one manifest by the exact GD version plus native resize/rotation canary
hashes and dimensions. The canaries do not invoke Intervention or the adapter.
An unknown fingerprint fails clearly; it never skips or accepts a union of
possible hashes. Do not regenerate expectations from the v3 implementation.
Characterize actual v2 on a new backend before adding a reviewed manifest.
All PNG fixtures are small synthetic images, without external assets.

The original single-build oracle failed hosted CI: oblique rotation and
resampling differ across GD builds. Paired v2/v3 checks also found that v2's
path decoder normalized fully transparent RGB to white before interpolation.
The adapter now reproduces this with the public v3 GD clone operation, retaining
alpha and metadata. Strict per-backend goldens guard the real color behavior,
not merely dimensions; the dedicated decode test protects this normalization.
The JPEG test uses a pixel tolerance and explicitly checks quality 90 encoding.
The EXIF orientation-6 case requires ext-exif; CI installs it. Its unavailable
local runtime is reported as a skip, never as a pass. The synthetic two-frame
GIF test checks first-frame processing and unchanged ordinary uploads; it does
not establish parity for arbitrary animations. Imagick and WebP/AVIF codec
parity remain unverified.

The optional-dependency script deliberately runs without Composer autoloading.
The integration consumer also includes the optional processing dependency for
its existing image-upload failure tests: thumbnail preflight now precedes
storage. None of these isolated development requirements makes Intervention a
production dependency for ordinary uploads.

See [migration behavior and cautions](../../IMAGE_MIGRATION.md).
