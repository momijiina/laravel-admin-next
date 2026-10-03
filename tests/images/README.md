# Image output regressions

Run through the isolated BrowserKit consumer (see its README):

```sh
cd tests/browserkit
composer test -- --testsuite 'Image processing output'
php ../images/optional_dependency.php
```

`ImageProcessingTest.php` runs real GD decoding, processing and encoding. It
fails on processing deprecations instead of letting Laravel log them as passes.
`fixtures/v2-oracle.json` records the archived v2 baseline and pixel-hash method;
all PNG fixtures are small synthetic images, without external assets. Expected
hashes are decoded pixels and alpha, not compressed bytes. Do not regenerate
expected hashes from the implementation under test when a regression fails.
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
