<?php

namespace Encore\Admin\Form\Field;

use Encore\Admin\Form\Field\Image\Constraint;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

/** Bounded v2-style field API, implemented exclusively with Intervention Image v3. */
class ImageProcessor
{
    private const ARGUMENTS = [
        'rotate' => [1, 2], 'flip' => [0, 1], 'crop' => [2, 4],
        'resize' => [2, 3], 'widen' => [1, 2], 'heighten' => [1, 2],
        'fit' => [1, 4], 'insert' => [1, 4], 'resizeCanvas' => [2, 5],
        'orientate' => [0, 0],
    ];

    public static function requireDependency()
    {
        if (!class_exists(ImageManager::class) || !method_exists(ImageManager::class, 'read')) {
            throw new \LogicException('Image processing requires intervention/image ^3.11.9. See IMAGE_MIGRATION.md. Ordinary uploads do not require it.');
        }
    }

    public static function validate($method, array $arguments)
    {
        if (!isset(self::ARGUMENTS[$method])) {
            throw new \BadMethodCallException("Unsupported image operation [{$method}]. Use imageProcessing() with the v3 API; see IMAGE_MIGRATION.md.");
        }
        if (!array_is_list($arguments)) {
            throw new \InvalidArgumentException('Legacy image field calls require positional arguments. Use imageProcessing() for native named arguments.');
        }
        [$minimum, $maximum] = self::ARGUMENTS[$method];
        if (count($arguments) < $minimum || count($arguments) > $maximum) {
            throw new \InvalidArgumentException("Invalid arguments for image operation [{$method}]. See IMAGE_MIGRATION.md.");
        }
    }

    public function read($source, $thumbnail = false)
    {
        self::requireDependency();
        $driver = config('admin.upload.image_processing.'.($thumbnail ? 'thumbnail_driver' : 'transform_driver'));
        $driver = $driver ?? ($thumbnail ? config('image.driver', 'gd') : 'gd');
        $drivers = ['gd' => \Intervention\Image\Drivers\Gd\Driver::class, 'imagick' => \Intervention\Image\Drivers\Imagick\Driver::class];
        if (!is_string($driver)) {
            throw new \InvalidArgumentException('Image driver must be a gd/imagick string or corresponding v3 Driver class.');
        }
        $driver = $drivers[$driver] ?? $driver;
        if (!is_string($driver) || !in_array($driver, $drivers, true)) {
            throw new \InvalidArgumentException('Image driver must be gd, imagick, or the corresponding Intervention v3 Driver class. See IMAGE_MIGRATION.md.');
        }

        return (new ImageManager($driver, autoOrientation: false, decodeAnimation: false, blendingColor: 'ffffff', strip: false))->read($source);
    }

    public function encode(ImageInterface $image)
    {
        // Uploaded temporary paths usually have no extension. Encode by decoded MIME.
        return $image->encodeByMediaType($image->origin()->mediaType(), quality: 90);
    }

    private function constraint($callback)
    {
        $constraint = new Constraint();
        if ($callback !== null) {
            if (!is_callable($callback)) {
                throw new \InvalidArgumentException('Image resize constraint must be callable. See IMAGE_MIGRATION.md.');
            }
            try {
                $callback($constraint);
            } catch (\TypeError $exception) {
                throw new \InvalidArgumentException('Image constraint callbacks must accept Encore\\Admin\\Form\\Field\\Image\\Constraint (or be untyped), not Intervention v2 types. See IMAGE_MIGRATION.md.', 0, $exception);
            }
        }

        return $constraint;
    }

    public function apply(ImageInterface $image, $method, array $arguments)
    {
        self::validate($method, $arguments);
        $a = $arguments;
        switch ($method) {
            case 'rotate':
                return $image->rotate($a[0], $a[1] ?? 'rgba(255,255,255,0)');
            case 'flip':
                $direction = $a[0] ?? 'h';
                if (!in_array($direction, ['h', 'v'], true)) {
                    throw new \InvalidArgumentException('Image flip direction must be h or v.');
                }
                return $direction === 'v' ? $image->flip() : $image->flop();
            case 'crop':
                // v2 centers a crop when offsets are omitted.
                [$x, $y] = isset($a[2]) || isset($a[3])
                    ? [$a[2] ?? 0, $a[3] ?? 0]
                    : $this->offset($image->width(), $image->height(), $a[0], $a[1], 'center');
                return $image->crop($a[0], $a[1], $x, $y, 'rgba(255,255,255,0)', 'top-left');
            case 'resize':
            case 'widen':
            case 'heighten':
                $width = $method === 'heighten' ? null : $a[0];
                $height = $method === 'heighten' ? $a[0] : ($method === 'widen' ? null : $a[1]);
                $constraint = $this->constraint($a[$method === 'resize' ? 2 : 1] ?? null);
                $proportional = $method !== 'resize' || $constraint->proportional;
                [$width, $height] = $this->resizeDimensions($image->width(), $image->height(), $width, $height, $proportional, $constraint->preventUpsizing);
                return $image->resize($width, $height);
            case 'fit':
                $constraint = $this->constraint($a[2] ?? null);
                return $this->fit($image, $a[0], $a[1] ?? $a[0], $a[3] ?? 'center', $constraint->preventUpsizing, $constraint->proportional);
            case 'insert':
                $watermark = $image->driver()->handleInput($a[0]);
                $position = $a[1] ?? 'top-left';
                [$x, $y] = $this->offset($image->width(), $image->height(), $watermark->width(), $watermark->height(), $position);
                if (!in_array($position, ['top', 'bottom'], true)) {
                    $x += str_contains($position, 'right') ? -($a[2] ?? 0) : ($a[2] ?? 0);
                }
                if (!in_array($position, ['left', 'right'], true)) {
                    $y += str_contains($position, 'bottom') ? -($a[3] ?? 0) : ($a[3] ?? 0);
                }
                return $image->place($watermark, 'top-left', $x, $y);
            case 'resizeCanvas':
                $width = !empty($a[3]) ? $image->width() + ($a[0] ?? 0) : ($a[0] ?? $image->width());
                $height = !empty($a[3]) ? $image->height() + ($a[1] ?? 0) : ($a[1] ?? $image->height());
                return $this->canvas($image, $width, $height, $a[2] ?? 'center', $a[4] ?? 'rgba(255,255,255,0)');
            case 'orientate':
                return $image->orient();
        }
    }

    private function resizeDimensions($sourceWidth, $sourceHeight, $width, $height, $proportional, $down)
    {
        if ($width === null && $height === null) {
            throw new \InvalidArgumentException('An image width or height is required.');
        }
        foreach ([$width, $height] as $dimension) {
            if ($dimension !== null && (!is_int($dimension) || $dimension < 1)) {
                throw new \InvalidArgumentException('Image dimensions must be positive integers or null.');
            }
        }
        // Preserve v2's two-axis integer rounding, including tiny-image upscaling.
        $resize = static function ($firstWidth) use ($sourceWidth, $sourceHeight, $width, $height, $proportional, $down) {
            $size = [$sourceWidth, $sourceHeight];
            foreach ($firstWidth ? [0, 1] : [1, 0] as $axis) {
                $target = $axis === 0 ? $width : $height;
                if ($target === null) {
                    continue;
                }
                $old = $size;
                $size[$axis] = $down ? min($target, $old[$axis]) : $target;
                if ($proportional) {
                    $other = 1 - $axis;
                    $size[$other] = max(1, (int) round($size[$axis] * $old[$other] / $old[$axis]));
                    if ($down) {
                        $size[$other] = min($size[$other], $old[$other]);
                    }
                }
            }

            return $size;
        };
        $heightDominant = $resize(true);

        return $heightDominant[0] <= ($width ?? 0) && $heightDominant[1] <= ($height ?? 0)
            ? $heightDominant : $resize(false);
    }

    private function offset($width, $height, $targetWidth, $targetHeight, $position)
    {
        if (!in_array($position, ['top-left', 'top', 'top-right', 'left', 'center', 'right', 'bottom-left', 'bottom', 'bottom-right'], true)) {
            throw new \InvalidArgumentException('Unsupported image anchor ['.$position.'].');
        }
        // v2 independently floors the two centers; floor((a-b)/2) is not equivalent.
        $x = str_contains($position, 'left') ? 0 : (str_contains($position, 'right') ? $width - $targetWidth : intdiv($width, 2) - intdiv($targetWidth, 2));
        $y = str_contains($position, 'top') ? 0 : (str_contains($position, 'bottom') ? $height - $targetHeight : intdiv($height, 2) - intdiv($targetHeight, 2));

        return [$x, $y];
    }

    private function canvas(ImageInterface $image, $width, $height, $position, $background)
    {
        [$x, $y] = $this->offset($image->width(), $image->height(), $width, $height, $position);

        return $image->crop($width, $height, $x, $y, $background, 'top-left');
    }

    private function fit(ImageInterface $image, $width, $height, $position, $down = false, $proportional = false)
    {
        $modifier = new \Intervention\Image\Modifiers\CoverModifier($width, $height);
        $crop = $modifier->getCropSize($image);
        [$resizeWidth, $resizeHeight] = $this->resizeDimensions($crop->width(), $crop->height(), $width, $height, $proportional, $down);
        [$x, $y] = $this->offset($image->width(), $image->height(), $crop->width(), $crop->height(), $position);

        return $image->crop($crop->width(), $crop->height(), $x, $y)->resize($resizeWidth, $resizeHeight);
    }

    public function thumbnail($source, array $size)
    {
        $action = $size[2] ?? 'resize';
        if (!in_array($action, ['resize', 'fit'], true)) {
            throw new \InvalidArgumentException('Thumbnail action must be resize or fit. See IMAGE_MIGRATION.md.');
        }
        $image = $this->read($source, true);
        if ($action === 'fit') {
            $image = $this->fit($image, $size[0], $size[1] ?? $size[0], 'center', false, true);
        } else {
            [$width, $height] = $this->resizeDimensions($image->width(), $image->height(), $size[0], $size[1], true, false);
            $image->resize($width, $height);
        }
        $this->canvas($image, $size[0] ?? $image->width(), $size[1] ?? $image->height(), 'center', 'ffffff');

        return (string) $this->encode($image);
    }
}
