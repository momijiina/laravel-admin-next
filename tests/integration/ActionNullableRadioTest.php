<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Actions\Action;
use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class ActionNullableRadioTest extends TestCase
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

    protected function defineEnvironment($app)
    {
        $app['config']->set('session.driver', 'array');
    }

    public function test_action_radio_selection_survives_modal_initialization_and_resubmission(): void
    {
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        // Each expectation is the native successful-control list, not a NULL payload.
        $cases = [
            'unset' => [[], []],
            'null getter' => [['value' => null], []],
            'null default' => [['default' => null], []],
            'closure null default' => [['default' => static fn () => null], []],
            'closure zero default' => [['default' => static fn () => 0], ['0']],
            'null with empty option' => [['options' => ['' => 'Empty', 0 => 'Zero']], []],
            'integer zero' => [['value' => 0], ['0']],
            'string zero' => [['value' => '0'], ['0']],
            'integer default zero' => [['default' => 0], ['0']],
            'string default zero' => [['default' => '0'], ['0']],
            'value beats default' => [['default' => 1, 'value' => 0], ['0']],
            'old integer zero' => [['default' => 1, 'value' => 1, 'old' => 0], ['0']],
            'old string zero' => [['default' => 1, 'value' => 1, 'old' => '0'], ['0']],
            'old null' => [['default' => 1, 'value' => 1, 'old' => null], []],
            'old empty' => [['default' => 1, 'value' => 1, 'old' => ''], []],
            'old one beats zero' => [['default' => 0, 'value' => 0, 'old' => 1], ['1']],
            'default one' => [['default' => 1], ['1']],
            'integer one' => [['value' => 1], ['1']],
            'string one' => [['value' => '1'], ['1']],
            'false' => [['value' => false], ['0']],
            'true' => [['value' => true], ['1']],
            'numeric string' => [['value' => '00'], ['0']],
            'float one' => [['value' => 1.0], ['1']],
            'unmatched value' => [['value' => 9], []],
            'explicit empty option' => [['options' => ['' => 'Empty', 0 => 'Zero', 1 => 'One'], 'value' => ''], ['']],
            'checked zero label' => [['checked' => ['Zero']], ['0']],
            'checked one label' => [['checked' => ['One']], ['1']],
            'checked key is not label' => [['checked' => [0]], []],
            'old zero keeps additive label fallback' => [['checked' => ['One'], 'old' => 0], ['1']],
            'old null keeps label fallback' => [['checked' => ['One'], 'old' => null], ['1']],
            'old empty keeps label fallback' => [['checked' => ['One'], 'old' => ''], ['1']],
            'nonnull value disables fallback' => [['value' => 0, 'checked' => ['One']], ['0']],
            'old null with nonnull value disables fallback' => [['value' => 1, 'old' => null, 'checked' => ['One']], []],
            'required unset' => [['required' => true], []],
            'required zero' => [['required' => true, 'value' => 0], ['0']],
            'escaped option' => [['options' => ['<zero&"' => '<b>Zero</b>'], 'value' => '<zero&"'], ['<zero&"']],
        ];
        $fixtures = [];
        $previousHtml = Admin::$html;
        $previousScript = Admin::$script;
        try {
            foreach ($cases as $label => [$config, $expected]) {
                $this->app['session']->flashInput(array_key_exists('old', $config) ? ['choice' => $config['old']] : []);
                Admin::$html = Admin::$script = [];
                $action = new NullableRadioAction($config);
                $fixtures[] = [
                    'label' => $label,
                    'html' => $action->render().implode('', Admin::$html),
                    'script' => implode("\n", Admin::$script),
                    'expected' => $expected,
                    'valid' => empty($config['required']) || count($expected) > 0,
                    'count' => count($config['options'] ?? [0, 1]),
                ];
            }
            $process = new Process(['node', __DIR__.'/javascript/action-radio-null.cjs']);
            $process->setTimeout(120);
            $process->setInput(json_encode($fixtures, JSON_THROW_ON_ERROR));
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(count($fixtures), $results);
            foreach ($results as $index => $result) {
                foreach (['initial', 'initialized', 'submitted', 'reopened'] as $key) {
                    $this->assertSame($fixtures[$index]['expected'], $result[$key], $fixtures[$index]['label'].' '.$key);
                }
                $this->assertSame($fixtures[$index]['valid'], $result['valid'], $fixtures[$index]['label']);
            }
        } finally {
            Admin::$html = $previousHtml;
            Admin::$script = $previousScript;
        }
    }
}

class NullableRadioAction extends Action
{
    protected $selector = '.nullable-radio-action';
    public $name = 'Nullable radio';

    public function __construct(private array $config)
    {
        parent::__construct();
    }

    public function form()
    {
        $field = $this->radio('choice', 'Choice')->options($this->config['options'] ?? [0 => 'Zero', 1 => 'One'])
            ->attribute('data-probe', 'fixture');
        foreach (['default', 'value', 'checked'] as $method) {
            if (array_key_exists($method, $this->config)) {
                $field->{$method}($this->config[$method]);
            }
        }
        if (!empty($this->config['required'])) {
            $field->required();
        }
    }

    public function html()
    {
        return '<button class="nullable-radio-action">Open</button>';
    }
}
