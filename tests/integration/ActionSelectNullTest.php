<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Actions\Action;
use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class ActionSelectNullTest extends TestCase
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

    public function test_action_select_preserves_null_and_explicit_choices_in_emitted_form_data(): void
    {
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        $cases = ['unset' => [[], '']];
        foreach (['value', 'default', 'old'] as $mode) {
            foreach ([null, 0, '0', 1, '1', false, true, '', 'missing'] as $input) {
                $config = [$mode => $input];
                if ($mode === 'old') {
                    $config += ['value' => 1, 'default' => 1];
                }
                $expected = $input === null || $input === '' || $input === 'missing'
                    ? '' : ($input == 0 ? '0' : '1');
                $cases[$mode.' '.var_export($input, true)] = [$config, $expected];
            }
        }
        $cases += [
            'value zero wins default one' => [['default' => 1, 'value' => 0], '0'],
            'null value uses default one' => [['default' => 1, 'value' => null], '1'],
            'empty value wins default one' => [['default' => 1, 'value' => ''], ''],
            'old zero wins value and default' => [['default' => 1, 'value' => 1, 'old' => '0'], '0'],
            'explicit empty option' => [['options' => ['' => 'Empty', 0 => 'Zero', 1 => 'One'], 'value' => ''], ''],
            'null with explicit empty option' => [['options' => ['' => 'Empty', 0 => 'Zero', 1 => 'One']], ''],
            'numeric string loose comparison' => [['options' => ['01' => 'Leading zero', 2 => 'Two'], 'value' => 1], '01'],
            'empty options' => [['options' => []], ''],
            // Action Select has historically ignored groups; do not add ordinary-form semantics here.
            'groups only null' => [['options' => [], 'groups' => [['label' => 'Status', 'options' => [0 => 'Zero', 1 => 'One']]]], ''],
            'groups only value' => [['options' => [], 'groups' => [['label' => 'Status', 'options' => [0 => 'Zero', 1 => 'One']]], 'value' => 1], ''],
            'groups with flat options' => [['groups' => [['label' => 'Status', 'options' => [2 => 'Two']]], 'value' => 0], '0'],
        ];
        $fixtures = [];
        $previousHtml = Admin::$html;
        $previousScript = Admin::$script;
        try {
            foreach ($cases as $label => [$config, $expected]) {
                $this->app['session']->flashInput(array_key_exists('old', $config) ? ['choice' => $config['old']] : []);
                Admin::$html = Admin::$script = [];
                $action = new NullableSelectAction($config);
                $fixtures[] = [
                    'label' => $label,
                    'html' => $action->render().implode('', Admin::$html),
                    'script' => implode("\n", Admin::$script),
                    'expected' => $expected,
                    'options' => array_merge([''], array_map('strval', array_keys($config['options'] ?? [0 => 'Zero', 1 => 'One']))),
                ];
            }
            $process = new Process(['node', __DIR__.'/javascript/action-select-null.cjs']);
            $process->setTimeout(120);
            $process->setInput(json_encode($fixtures, JSON_THROW_ON_ERROR));
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(count($fixtures), $results);
            foreach ($results as $index => $result) {
                $fixture = $fixtures[$index];
                foreach (['before', 'initialized', 'submitted', 'reopened'] as $key) {
                    $this->assertSame([$fixture['expected']], $result[$key], $fixture['label'].' '.$key);
                }
                $this->assertSame($fixture['options'], $result['options'], $fixture['label'].' options');
            }
        } finally {
            Admin::$html = $previousHtml;
            Admin::$script = $previousScript;
        }
    }
}

class NullableSelectAction extends Action
{
    protected $selector = '.nullable-select-action';
    public $name = 'Nullable select';

    public function __construct(private array $config)
    {
        parent::__construct();
    }

    public function form()
    {
        $field = $this->select('choice', 'Choice')->options($this->config['options'] ?? [0 => 'Zero', 1 => 'One'])
            ->config('placeholder', 'Choose status')->attribute('data-probe', 'fixture');
        foreach (['default', 'value', 'groups'] as $method) {
            if (array_key_exists($method, $this->config)) {
                $field->{$method}($this->config[$method]);
            }
        }
    }

    public function html()
    {
        return '<button class="nullable-select-action">Open</button>';
    }
}
