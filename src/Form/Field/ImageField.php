<?php

namespace Encore\Admin\Form\Field;

use Illuminate\Support\Str;
use Intervention\Image\Interfaces\ImageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

trait ImageField
{
    /**
     * Intervention calls.
     *
     * @var array
     */
    protected $interventionCalls = [];

    /**
     * Thumbnail settings.
     *
     * @var array
     */
    protected $thumbnails = [];

    protected $preparedThumbnails = [];

    protected $storedImagePaths = [];

    /**
     * Default directory for file to upload.
     *
     * @return mixed
     */
    public function defaultDirectory()
    {
        return config('admin.upload.directory.image');
    }

    /**
     * Execute Intervention calls.
     *
     * @param string $target
     *
     * @return mixed
     */
    public function callInterventionMethods($target)
    {
        $processor = new ImageProcessor();
        $this->preparedThumbnails = [];
        if (!empty($this->interventionCalls)) {
            $image = $processor->read($target);
            foreach ($this->interventionCalls as $call) {
                if ($call['method'] === '__native') {
                    try {
                        $result = ($call['arguments'][0])($image);
                    } catch (\Throwable $exception) {
                        throw new \RuntimeException('Intervention v3 imageProcessing() callback failed: '.$exception->getMessage().' See IMAGE_MIGRATION.md.', 0, $exception);
                    }
                    if ($result !== null && !$result instanceof ImageInterface) {
                        throw new \UnexpectedValueException('imageProcessing() must return an Intervention v3 ImageInterface or null. See IMAGE_MIGRATION.md.');
                    }
                    $image = $result ?? $image;
                } else {
                    $image = $processor->apply($image, $call['method'], $call['arguments']);
                }
                $processor->encode($image)->save($target);
            }
        }
        // Decode/transform failures must happen before upload deletes any originals.
        foreach ($this->thumbnails as $name => $size) {
            $this->preparedThumbnails[$name] = $processor->thumbnail($target, $size);
        }

        return $target;
    }

    /**
     * Call intervention methods.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @throws \Exception
     *
     * @return $this
     */
    public function __call($method, $arguments)
    {
        if (static::hasMacro($method)) {
            return $this;
        }

        ImageProcessor::requireDependency();
        ImageProcessor::validate($method, $arguments);

        $this->interventionCalls[] = [
            'method'    => $method,
            'arguments' => $arguments,
        ];

        return $this;
    }

    /** Queue a native Intervention Image v3 callback at this point in the chain. */
    public function imageProcessing(callable $callback)
    {
        ImageProcessor::requireDependency();
        $this->interventionCalls[] = ['method' => '__native', 'arguments' => [$callback]];

        return $this;
    }

    /**
     * Render a image form field.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $this->options(['allowedFileTypes' => ['image'], 'msgPlaceholder' => trans('admin.choose_image')]);

        return parent::render();
    }

    /**
     * @param string|array $name
     * @param int          $width
     * @param int          $height
     *
     * @return $this
     */
    public function thumbnail($name, ?int $width = null, ?int $height = null)
    {
        if (func_num_args() == 1 && is_array($name)) {
            foreach ($name as $key => $size) {
                if (count($size) >= 2) {
                    $this->thumbnails[$key] = $size;
                }
            }
        } elseif (func_num_args() == 3) {
            $this->thumbnails[$name] = [$width, $height];
        }

        return $this;
    }

    /**
     * Destroy original thumbnail files.
     *
     * @return void.
     */
    public function destroyThumbnail()
    {
        if ($this->retainable) {
            return;
        }

        foreach ($this->thumbnails as $name => $_) {
            /*  Refactoring actual remove lofic to another method destroyThumbnailFile()
            to make deleting thumbnails work with multiple as well as
            single image upload. */

            if (is_array($this->original)) {
                if (empty($this->original)) {
                    continue;
                }

                foreach ($this->original as $original) {
                    $this->destroyThumbnailFile($original, $name);
                }
            } else {
                $this->destroyThumbnailFile($this->original, $name);
            }
        }
    }

    /**
     * Remove thumbnail file from disk.
     *
     * @return void.
     */
    public function destroyThumbnailFile($original, $name)
    {
        if (!is_string($original) || $original === '') {
            return;
        }
        $ext = pathinfo($original, PATHINFO_EXTENSION);

        // We remove extension from file name so we can append thumbnail type
        $path = @Str::replaceLast('.'.$ext, '', $original);

        // We merge original name + thumbnail name + extension
        $path = $path.'-'.$name.'.'.$ext;

        if (!in_array($path, $this->storedImagePaths, true) && $this->storage->exists($path)) {
            $this->storage->delete($path);
        }
    }

    /**
     * Upload file and delete original thumbnail files.
     *
     * @param UploadedFile $file
     *
     * @return $this
     */
    protected function uploadAndDeleteOriginalThumbnail(UploadedFile $file)
    {
        foreach ($this->thumbnails as $name => $size) {
            // We need to get extension type ( .jpeg , .png ...)
            $ext = pathinfo($this->name, PATHINFO_EXTENSION);

            // We remove extension from file name so we can append thumbnail type
            $path = Str::replaceLast('.'.$ext, '', $this->name);

            // We merge original name + thumbnail name + extension
            $path = $path.'-'.$name.'.'.$ext;

            $bytes = $this->preparedThumbnails[$name] ?? (new ImageProcessor())->thumbnail($file->getRealPath(), $size);
            $stored = !is_null($this->storagePermission)
                ? $this->storage->put("{$this->getDirectory()}/{$path}", $bytes, $this->storagePermission)
                : $this->storage->put("{$this->getDirectory()}/{$path}", $bytes);
            if ($stored === false) {
                throw new \RuntimeException("Unable to store image thumbnail [{$path}].");
            }
            $this->storedImagePaths[] = "{$this->getDirectory()}/{$path}";
        }

        $this->destroyThumbnail();
        $this->preparedThumbnails = [];

        return $this;
    }
}
