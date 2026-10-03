<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class DateRangeInitializationTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    public function test_initial_bounds_with_the_shipped_widgets(): void
    {
        $this->app['view']->share('errors', new ViewErrorBag());
        $fixtures = [];
        $previous = Admin::$script;
        try {
            foreach (['dateRange', 'datetimeRange', 'timeRange'] as $type) {
                $format = $type === 'dateRange' ? 'Y-m-d' : ($type === 'timeRange' ? 'H:i:s' : 'Y-m-d H:i:s');
                $values = array_map(fn ($day) => gmdate($format, strtotime("2026-10-$day $day:00:00 UTC")), ['03', '05', '07', '09']);
                foreach (['full', 'null-start', 'null-end', 'empty', 'inverted', 'use-current-false', 'keep-invalid', 'limits', 'strict-min', 'strict-max', 'endpoint-limits', 'parser', 'timezone'] as $mode) {
                    Admin::$script = [];
                    $form = new Form(new RangeInitializationRecord());
                    $html = '';
                    foreach (['one', 'two'] as $prefix) {
                        $start = in_array($mode, ['null-start', 'empty'], true) ? null : $values[0];
                        $end = in_array($mode, ['null-end', 'empty'], true) ? null : $values[2];
                        if ($mode === 'inverted') [$start, $end] = [$end, $start];
                        $field = $form->$type($prefix.'_start', $prefix.'_end')->value(['start' => $start, 'end' => $end]);
                        if (in_array($mode, ['use-current-false', 'limits', 'endpoint-limits'], true)) $field->options(['useCurrent' => false]);
                        if ($mode === 'keep-invalid') $field->options(['keepInvalid' => true, 'useCurrent' => 'day']);
                        if ($mode === 'limits') $field->options([
                            'minDate' => $type === 'timeRange' ? '2026-10-20T'.$values[0] : $values[0],
                            'maxDate' => $type === 'timeRange' ? '2026-10-20T'.$values[3] : $values[3],
                        ]);
                        if (in_array($mode, ['strict-min', 'strict-max'], true)) $field->options([
                            'keepInvalid' => true,
                            $mode === 'strict-min' ? 'minDate' : 'maxDate' => $type === 'timeRange' ? '2026-10-20T'.$values[1] : $values[1],
                        ]);
                        // The parser is supplied as an actual widget data option by the DOM runner.
                        if ($mode === 'timezone') $field->options(['timeZone' => 'UTC']);
                        $html .= (string) $field->render();
                    }
                    $fixtures[] = compact('type', 'mode', 'html', 'values') + [
                        'renderedScript' => $this->app['view']->make('admin::partials.script', ['script' => array_values(array_unique(Admin::$script))])->render(),
                    ];
                }
            }
            $process = new Process(['node', __DIR__.'/javascript/date-range-initialization.cjs']);
            $process->setTimeout(120);
            $process->setInput(json_encode($fixtures, JSON_THROW_ON_ERROR));
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(39, $result['fixtures']);
            $this->assertGreaterThan(600, $result['assertions']);
        } finally {
            Admin::$script = $previous;
        }
    }
}

class RangeInitializationRecord extends Model
{
    public $timestamps = false;
}
