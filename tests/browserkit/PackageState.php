<?php

/**
 * Test-owned boundary for package state changed by the historical fixtures.
 *
 * Snapshot values, rather than class defaults, so consumer bootstrap/plugin
 * registrations survive. This is deliberately not a process-wide static backup:
 * Laravel owns its own lifecycle, and arbitrary plugin object internals are not
 * cloned. Keep this inventory explicit when adding fixture behavior.
 */
final class HistoricalBrowserKitPackageState
{
    private $values = [];

    public function __construct()
    {
        $properties = [
            \Encore\Admin\Admin::class => [
                'metaTitle', 'favicon', 'extensions', 'bootingCallbacks', 'bootedCallbacks',
                'script', 'deferredScript', 'style', 'css', 'js', 'html', 'headerJs',
                'manifest', 'manifestData', 'min', 'baseCss', 'baseJs', 'jQuery', 'minifyIgnores',
            ],
            \Encore\Admin\Form::class => ['collectedAssets', 'availableFields', 'fieldAlias', 'initCallbacks', 'snakeAttributes'],
            \Encore\Admin\Grid::class => ['initCallbacks', 'snakeAttributes'],
            \Encore\Admin\Show::class => ['extendedFields', 'initCallback', 'snakeAttributes'],
            \Encore\Admin\Grid\Column::class => [
                'originalGridModels', 'defined', 'htmlAttributes', 'rowAttributes', 'model', 'displayers',
            ],
            \Encore\Admin\Actions\Action::class => ['selectors'],
            \Encore\Admin\Grid\Tools\Selector::class => ['selected'],
            \Encore\Admin\Grid\Displayers\BelongsToMany::class => ['otherKey'],
            \Encore\Admin\Grid\Exporter::class => ['drivers', 'exporter'],
            \Encore\Admin\Auth\Database\Menu::class => ['branchOrder'],
            \Tests\Models\Tree::class => ['branchOrder'],
        ];

        foreach ($properties as $class => $names) {
            foreach ($names as $name) {
                $property = new ReflectionProperty($class, $name);
                $this->values[] = [$property, $property->getValue()];
            }
        }
    }

    public function restore(): void
    {
        foreach ($this->values as [$property, $value]) {
            $property->setValue(null, $value);
        }
    }
}
