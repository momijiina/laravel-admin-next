<?php

namespace Encore\Admin\Form\Field\Image;

/** The deliberately small replacement for v2 resize constraints. */
class Constraint
{
    public $proportional = false;
    public $preventUpsizing = false;

    public function aspectRatio()
    {
        $this->proportional = true;

        return $this;
    }

    public function upsize()
    {
        $this->preventUpsizing = true;

        return $this;
    }
}
