<?php

namespace LaravelAdminNext\Images;

use Encore\Admin\Form\Field\Image;
use Encore\Admin\Form\Field\Image\Constraint;
use Encore\Admin\Form\Field\ImageProcessor;
use Encore\Admin\Form\Field\MultipleImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Interfaces\ImageInterface;
use Orchestra\Testbench\TestCase;

class ImageProcessingTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/admin-image-v3-'.bin2hex(random_bytes(8));
        mkdir($this->root);
        config([
            'admin.upload.disk' => 'image_test',
            'admin.upload.directory.image' => 'images',
            'filesystems.disks.image_test' => ['driver' => 'local', 'root' => $this->root.'/stored', 'throw' => false],
        ]);
        // Unlike Laravel's logging handler, fail on actual processing diagnostics.
        set_error_handler(static function ($severity, $message, $file, $line) {
            if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            return false;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function fixture($width = 8, $height = 4, $transparent = false, $jpeg = false)
    {
        $path = $this->root.'/source-'.bin2hex(random_bytes(4)); // deliberately extensionless
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $colors = [imagecolorallocatealpha($image, 255, 0, 0, $transparent ? 64 : 0), imagecolorallocate($image, 0, 255, 0), imagecolorallocate($image, 0, 0, 255), imagecolorallocate($image, 255, 255, 0)];
        imagefilledrectangle($image, 0, 0, $width / 2 - 1, $height / 2 - 1, $colors[0]);
        imagefilledrectangle($image, $width / 2, 0, $width - 1, $height / 2 - 1, $colors[1]);
        imagefilledrectangle($image, 0, $height / 2, $width / 2 - 1, $height - 1, $colors[2]);
        imagefilledrectangle($image, $width / 2, $height / 2, $width - 1, $height - 1, $colors[3]);
        $jpeg ? imagejpeg($image, $path, 100) : imagepng($image, $path);

        return $path;
    }

    private function pixels($bytes)
    {
        return imagecreatefromstring($bytes);
    }

    private function pixel($image, $x, $y)
    {
        $c = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
    }

    public function testDecodedPixelsMatchArchivedV2GdOracle(): void
    {
        $oracle = json_decode(file_get_contents(__DIR__.'/fixtures/v2-oracle.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($oracle['cases'] as $case) {
            $source = $this->root.'/oracle-source';
            copy(__DIR__.'/fixtures/'.$case['fixture'], $source);
            $field = (new Image('photo'))->dir($case['name'])->name('input.png')->thumbnail($case['thumbnails']);
            foreach ($case['operations'] as [$method, $arguments]) {
                $arguments = array_map(static function ($argument) {
                    if (is_array($argument) && isset($argument['fixture'])) {
                        return __DIR__.'/fixtures/'.$argument['fixture'];
                    }
                    if (is_array($argument) && isset($argument['constraint'])) {
                        return static function (Constraint $constraint) use ($argument) {
                            foreach ($argument['constraint'] as $method) {
                                $constraint->$method();
                            }
                        };
                    }

                    return $argument;
                }, $arguments);
                $field->$method(...$arguments);
            }
            $field->prepare(new UploadedFile($source, 'input.png', 'image/png', null, true));
            foreach ($case['expected'] as $filename => $expected) {
                $bytes = Storage::disk('image_test')->get($case['name'].'/'.$filename);
                $image = $this->pixels($bytes);
                self::assertSame([$expected['width'], $expected['height']], [imagesx($image), imagesy($image)], $case['name'].'/'.$filename);
                self::assertSame($expected['mime'], getimagesizefromstring($bytes)['mime']);
                $raw = '';
                for ($y = 0; $y < imagesy($image); $y++) {
                    for ($x = 0; $x < imagesx($image); $x++) {
                        $raw .= pack('C4', ...$this->pixel($image, $x, $y));
                    }
                }
                self::assertSame($expected['rgba_sha256'], hash('sha256', $raw), $case['name'].'/'.$filename);
            }
        }
    }

    public function testAnimationDecodingIsExplicitlyDisabled(): void
    {
        $manager = \Intervention\Image\ImageManager::gd();
        $first = (string) $manager->create(2, 2)->fill('ff0000')->toGif();
        $second = (string) $manager->create(2, 2)->fill('0000ff')->toGif();
        $bytes = \Intervention\Gif\Builder::canvas(2, 2)->addFrame($first, .1)->addFrame($second, .1)->encode();
        $path = $this->root.'/animated';
        file_put_contents($path, $bytes);
        self::assertSame(2, $manager->read($path)->count());
        $processor = new ImageProcessor();
        $image = $processor->read($path);
        self::assertFalse($image->isAnimated());
        self::assertSame(1, $image->count());
        self::assertSame($this->pixel($this->pixels($first), 0, 0), $this->pixel($this->pixels((string) $processor->encode($image)), 0, 0));
        // No processing means uploaded animation bytes are kept exactly.
        $stored = (new Image('photo'))->prepare(new UploadedFile($path, 'animated.gif', 'image/gif', null, true));
        self::assertSame($bytes, Storage::disk('image_test')->get($stored));
    }

    public function testExifOrientationIsOptIn(): void
    {
        if (!function_exists('exif_read_data')) {
            self::markTestSkipped('EXIF extension unavailable; the GD+EXIF CI jobs run this test.');
        }
        $source = $this->fixture(80, 40, false, true);
        $jpeg = file_get_contents($source);
        // Synthetic little-endian TIFF IFD0 with orientation=6 (90 degrees clockwise).
        $exif = "Exif\0\0".hex2bin('49492a0008000000010012010300010000000600000000000000');
        file_put_contents($source, substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2));
        self::assertSame(6, exif_read_data($source)['Orientation']);
        $processor = new ImageProcessor();
        $image = $processor->read($source);
        self::assertSame([80, 40], [$image->width(), $image->height()]);
        $image = $processor->apply($image, 'orientate', []);
        self::assertSame([40, 80], [$image->width(), $image->height()]);
        $pixel = $this->pixel($this->pixels((string) $processor->encode($image)), 10, 10);
        self::assertLessThanOrEqual(5, $pixel[0]);
        self::assertLessThanOrEqual(5, $pixel[1]);
        self::assertLessThanOrEqual(5, abs($pixel[2] - 255));
    }

    public function testNativeReplacementRequiresAnEncodableOriginAndInvalidResultsFailEarly(): void
    {
        $field = (new Image('photo'))->imageProcessing(static function (ImageInterface $image) {
            $replacement = \Intervention\Image\ImageManager::gd()->create(3, 2)->fill('ff0000');
            $replacement->origin()->setMediaType($image->origin()->mediaType());
            return $replacement;
        });
        $path = $this->fixture();
        $field->callInterventionMethods($path);
        self::assertSame([3, 2], array_slice(getimagesize($path), 0, 2));
        foreach ([static fn () => 'encoded bytes', static fn () => \Intervention\Image\ImageManager::gd()->create(2, 2)] as $callback) {
            $field = (new Image('photo'))->imageProcessing($callback);
            try {
                $field->prepare(new UploadedFile($this->fixture(), 'bad.png', 'image/png', null, true));
                self::fail('Unencodable native result accepted.');
            } catch (\UnexpectedValueException | \Intervention\Image\Exceptions\EncoderException $exception) {
                self::assertSame([], Storage::disk('image_test')->allFiles());
            }
        }
    }

    public function testRotationFlipCropAndOrderedNativeHookHaveRealPixels(): void
    {
        $path = $this->fixture();
        $field = new Image('photo');
        $field->rotate(90)->flip('v')->crop(2, 2, 0, 0)->imageProcessing(function (ImageInterface $image) {
            self::assertSame(2, $image->width());
            $image->flop();
        });
        $field->callInterventionMethods($path);
        $actual = $this->pixels(file_get_contents($path));
        self::assertSame([2, 2], [imagesx($actual), imagesy($actual)]);
        self::assertSame([255, 0, 0, 0], $this->pixel($actual, 0, 0));
    }

    public function testFlipDirectionsAndCenteredCrop(): void
    {
        $processor = new ImageProcessor();
        foreach (['h' => [0, 255, 0, 0], 'v' => [0, 0, 255, 0]] as $direction => $expected) {
            $image = $processor->apply($processor->read($this->fixture()), 'flip', [$direction]);
            self::assertSame($expected, $this->pixel($this->pixels((string) $processor->encode($image)), 0, 0));
        }
        $image = $processor->apply($processor->read($this->fixture()), 'crop', [2, 2]);
        $actual = $this->pixels((string) $processor->encode($image));
        self::assertSame([255, 0, 0, 0], $this->pixel($actual, 0, 0));
        self::assertSame([255, 255, 0, 0], $this->pixel($actual, 1, 1));
    }

    public function testResizeConstraintAndOneDimension(): void
    {
        $processor = new ImageProcessor();
        $image = $processor->apply($processor->read($this->fixture()), 'resize', [20, 20, static function (Constraint $constraint) { $constraint->aspectRatio()->upsize(); }]);
        self::assertSame([8, 4], [$image->width(), $image->height()]);
        $image = $processor->apply($image, 'widen', [4]);
        self::assertSame([4, 2], [$image->width(), $image->height()]);
        $image = $processor->apply($image, 'resize', [null, 8]);
        self::assertSame([4, 8], [$image->width(), $image->height()]);
    }

    public function testTransparentPngAndSourceJpegEncoding(): void
    {
        $processor = new ImageProcessor();
        $image = $processor->apply($processor->read($this->fixture(8, 4, true)), 'flip', ['h']);
        $actual = $this->pixels((string) $processor->encode($image));
        self::assertSame([255, 0, 0, 64], $this->pixel($actual, 7, 0));
        $source = $this->fixture(80, 40, false, true);
        $image = $processor->read($source);
        $encoded = (string) $processor->encode($image);
        self::assertSame('image/jpeg', getimagesizefromstring($encoded)['mime']);
        // The adapter intentionally specifies the old effective quality, not v3's 75.
        self::assertSame((string) $image->toJpeg(quality: 90), $encoded);
        $actual = $this->pixels($encoded);
        $pixel = $this->pixel($actual, 10, 10);
        self::assertLessThanOrEqual(5, abs($pixel[0] - 255));
        self::assertLessThanOrEqual(5, $pixel[1]);
        self::assertLessThanOrEqual(5, $pixel[2]);
    }

    public function testThumbnailsPadWhiteUpscaleAndUseIndependentTransformedOriginal(): void
    {
        $source = $this->fixture();
        $field = (new Image('photo'))->name('new.png')->rotate(90)->thumbnail(['square' => [16, 16], 'wide' => [12, 4], 'fit' => [4, 4, 'fit']]);
        $path = $field->prepare(new UploadedFile($source, 'new.png', 'image/png', null, true));
        self::assertSame('images/new.png', $path);
        $stored = Storage::disk('image_test');
        $main = $this->pixels($stored->get($path));
        self::assertSame([4, 8], [imagesx($main), imagesy($main)]);
        $square = $this->pixels($stored->get('images/new-square.png'));
        self::assertSame([16, 16], [imagesx($square), imagesy($square)]);
        self::assertSame([255, 255, 255, 0], $this->pixel($square, 0, 0));
        self::assertSame([0, 255, 0, 0], $this->pixel($square, 5, 1));
        $wide = $this->pixels($stored->get('images/new-wide.png'));
        self::assertSame([12, 4], [imagesx($wide), imagesy($wide)]);
        $fit = $this->pixels($stored->get('images/new-fit.png'));
        self::assertSame([4, 4], [imagesx($fit), imagesy($fit)]);
        self::assertNotSame([255, 255, 255, 0], $this->pixel($fit, 0, 0));
    }

    public function testOneDimensionalThumbnailsAndWatermarkAlpha(): void
    {
        $processor = new ImageProcessor();
        foreach ([[4, null, 4, 2], [null, 8, 16, 8]] as [$w, $h, $ew, $eh]) {
            $actual = $this->pixels($processor->thumbnail($this->fixture(), [$w, $h]));
            self::assertSame([$ew, $eh], [imagesx($actual), imagesy($actual)]);
        }
        $image = $processor->apply($processor->read($this->fixture()), 'insert', [$this->fixture(2, 2, true), 'top-left', 4, 0]);
        $actual = $this->pixels((string) $processor->encode($image));
        self::assertSame([126, 128, 0, 0], $this->pixel($actual, 4, 0));
        self::assertSame([255, 255, 0, 0], $this->pixel($actual, 4, 2));
    }

    public function testUnsupportedOperationsAndOldTypedCallbackFailBeforeStorage(): void
    {
        $field = new Image('photo');
        try {
            $field->mask('anything');
            self::fail('Unsupported method accepted');
        } catch (\BadMethodCallException $exception) {
            self::assertStringContainsString('imageProcessing()', $exception->getMessage());
        }
        $field->resize(4, 4, static function (\Intervention\Image\Constraint $constraint) {});
        try {
            $field->prepare(new UploadedFile($this->fixture(), 'new.png', 'image/png', null, true));
            self::fail('Old typed callback accepted');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('v2 types', $exception->getMessage());
        }
        self::assertSame([], Storage::disk('image_test')->allFiles());
    }

    public function testInvalidThumbnailAndInvalidImageLeaveOriginalsUntouched(): void
    {
        $stored = Storage::disk('image_test');
        $stored->put('images/old.png', 'old original');
        $stored->put('images/old-small.png', 'old thumbnail');
        $field = (new Image('photo'))->thumbnail(['small' => [4, 4, 'unknown']]);
        $field->setOriginal(['photo' => 'images/old.png']);
        try {
            $field->prepare(new UploadedFile($this->fixture(), 'new.png', 'image/png', null, true));
            self::fail('Invalid thumbnail action accepted');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('resize or fit', $exception->getMessage());
        }
        self::assertSame('old original', $stored->get('images/old.png'));
        self::assertSame('old thumbnail', $stored->get('images/old-small.png'));
        $source = $this->root.'/invalid';
        file_put_contents($source, 'not an image');
        try {
            (new Image('photo'))->rotate(90)->prepare(new UploadedFile($source, 'new.png', 'image/png', null, true));
            self::fail('Invalid image accepted');
        } catch (\Intervention\Image\Exceptions\DecoderException $exception) {
            self::assertSame(['images/old-small.png', 'images/old.png'], $stored->allFiles());
        }
    }

    public function testThumbnailWriteFailurePreservesOldFilesAndDoesNotClaimRollback(): void
    {
        foreach (['false', 'throw'] as $failure) {
            $disk = Storage::disk('image_test');
            $disk->put('images/old.png', 'old original');
            $disk->put('images/old-small.png', 'old thumbnail');
            $field = (new StorageImage('photo'))->useStorage(new ThumbnailFailureDisk($disk, $failure))->name('new-'.$failure.'.png')->thumbnail('small', 4, 4);
            $field->setOriginal(['photo' => 'images/old.png']);
            try {
                $field->prepare(new UploadedFile($this->fixture(), 'new.png', 'image/png', null, true));
                self::fail('Failed thumbnail write was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('thumbnail', $exception->getMessage());
            }
            self::assertSame('old original', $disk->get('images/old.png'));
            self::assertSame('old thumbnail', $disk->get('images/old-small.png'));
            self::assertTrue($disk->exists('images/new-'.$failure.'.png')); // no storage transaction
        }
    }

    public function testRetainableCollisionAndMissingOriginalSamePath(): void
    {
        $disk = Storage::disk('image_test');
        $disk->put('images/old.png', 'old original');
        $disk->put('images/old-small.png', 'old thumbnail');
        $field = (new Image('photo'))->name('old.png')->thumbnail('small', 4, 4)->retainable();
        $field->setOriginal(['photo' => 'images/old.png']);
        $path = $field->prepare(new UploadedFile($this->fixture(), 'new.png', 'image/png', null, true));
        self::assertNotSame('images/old.png', $path);
        self::assertTrue($disk->exists($path));
        self::assertSame('old original', $disk->get('images/old.png'));
        self::assertSame('old thumbnail', $disk->get('images/old-small.png'));
        $disk->delete('images/old.png');
        $field = (new Image('photo'))->name('old.png')->thumbnail('small', 4, 4);
        $field->setOriginal(['photo' => 'images/old.png']);
        self::assertSame('images/old.png', $field->prepare(new UploadedFile($this->fixture(), 'new.png', 'image/png', null, true)));
        self::assertSame('image/png', getimagesizefromstring($disk->get('images/old.png'))['mime']);
        self::assertSame('image/png', getimagesizefromstring($disk->get('images/old-small.png'))['mime']);
        // A custom uploader may deliberately overwrite the old path.
        $field = (new OverwriteImage('photo'))->name('old.png')->thumbnail('small', 4, 4);
        $field->setOriginal(['photo' => 'images/old.png']);
        $field->prepare(new UploadedFile($this->fixture(), 'new.png', 'image/png', null, true));
        self::assertTrue($disk->exists('images/old.png'));
        self::assertTrue($disk->exists('images/old-small.png'));
    }

    public function testMultipleBatchCleanupProtectsEarlierSuccessfulPaths(): void
    {
        $disk = Storage::disk('image_test');
        $disk->put('images/old.png', 'old original');
        $field = (new MultipleImage('photos'))->thumbnail('small', 4, 4);
        $field->setOriginal(['photos' => ['images/old.png']]);
        $result = $field->prepare([
            new UploadedFile($this->fixture(), 'old-small.png', 'image/png', null, true),
            new UploadedFile($this->fixture(), 'other.png', 'image/png', null, true),
        ]);
        self::assertSame(['images/old.png', 'images/old-small.png', 'images/other.png'], $result);
        self::assertTrue($disk->exists('images/old-small.png'));
        self::assertTrue($disk->exists('images/old-small-small.png'));
        self::assertTrue($disk->exists('images/other-small.png'));
    }

    public function testDriverConfigurationIsIndependentAndRejectsBadValues(): void
    {
        config(['image.driver' => 'unavailable-legacy-driver']);
        $processor = new ImageProcessor();
        self::assertSame(8, $processor->read($this->fixture())->width());
        try {
            $processor->thumbnail($this->fixture(), [4, 4]);
            self::fail('Invalid thumbnail driver was ignored.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('driver', $exception->getMessage());
        }
        config(['admin.upload.image_processing.thumbnail_driver' => 'gd']);
        self::assertSame('image/png', getimagesizefromstring($processor->thumbnail($this->fixture(), [4, 4]))['mime']);
        config(['admin.upload.image_processing.transform_driver' => []]);
        $this->expectException(\InvalidArgumentException::class);
        $processor->read($this->fixture());
    }

    public function testMultipleImagesResetNamesAndProduceIndependentThumbnails(): void
    {
        $field = new ExposedMultipleImage('photos');
        $field->thumbnail('small', 4, 4)->flip('h');
        foreach (['one.png', 'two.png'] as $name) {
            self::assertSame('images/'.$name, $field->one(new UploadedFile($this->fixture(), $name, 'image/png', null, true)));
        }
        $stored = Storage::disk('image_test');
        self::assertCount(4, $stored->allFiles());
        foreach (['one', 'two'] as $stem) {
            self::assertSame([0, 255, 0, 0], $this->pixel($this->pixels($stored->get('images/'.$stem.'.png')), 0, 0));
            self::assertSame([4, 4], array_slice(getimagesizefromstring($stored->get('images/'.$stem.'-small.png')), 0, 2));
        }
    }
}

class ExposedMultipleImage extends MultipleImage
{
    public function one($image) { return $this->prepareForeach($image); }
}

class StorageImage extends Image
{
    public function useStorage($storage) { $this->storage = $storage; return $this; }
}

class OverwriteImage extends Image
{
    public function renameIfExists(\Symfony\Component\HttpFoundation\File\UploadedFile $file) {}
}

class ThumbnailFailureDisk
{
    private $disk;
    private $failure;
    public function __construct($disk, $failure) { $this->disk = $disk; $this->failure = $failure; }
    public function __call($method, $arguments) { return $this->disk->$method(...$arguments); }
    public function put($path, $bytes, ...$options)
    {
        if ($this->failure === 'throw') {
            throw new \RuntimeException('Synthetic thumbnail write exception.');
        }
        return false;
    }
}
