<?php

namespace Encore\Admin\Grid\Tools;

use Encore\Admin\Grid;
use Illuminate\Support\Arr;

class QuickSearch extends AbstractTool
{
    /**
     * @var string
     */
    protected $view = 'admin::grid.quick-search';

    /**
     * @var string
     */
    protected $placeholder;

    /**
     * Set placeholder.
     *
     * @param string $text
     *
     * @return $this
     */
    public function placeholder($text = '')
    {
        $this->placeholder = $text;

        return $this;
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $key = $this->grid ? $this->grid::$searchKey : Grid::$searchKey;
        $query = request()->query();

        Arr::forget($query, $key);

        $vars = [
            'action'      => request()->url().'?'.http_build_query($query),
            'key'         => $key,
            'value'       => request($key),
            'placeholder' => $this->placeholder,
        ];

        return view($this->view, $vars);
    }
}
