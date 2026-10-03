<?php

namespace Encore\Admin\Form\Field;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class MultipleImage extends MultipleFile
{
    use ImageField;

    /**
     * {@inheritdoc}
     */
    protected $view = 'admin::form.multiplefile';

    /**
     *  Validation rules.
     *
     * @var string
     */
    protected $rules = 'image';

    public function prepare($files)
    {
        // Keep successfully written paths protected throughout the whole batch.
        $this->storedImagePaths = [];

        return parent::prepare($files);
    }

    /**
     * Prepare for each file.
     *
     * @param UploadedFile $image
     *
     * @return mixed|string
     */
    protected function prepareForeach(?UploadedFile $image = null)
    {
        $this->name = $this->getStoreName($image);

        $this->callInterventionMethods($image->getRealPath());

        /* return tap($this->upload($image), function () {
            $this->name = null;
        }); */

        /* Copied from single image prepare section and made necessary changes so the return
        value is same as before, but now thumbnails are saved to the disk as well. */

        $path = $this->upload($image);
        $this->storedImagePaths[] = $path;
        $this->uploadAndDeleteOriginalThumbnail($image);
        $this->name = null;

        return $path;
    }
}
