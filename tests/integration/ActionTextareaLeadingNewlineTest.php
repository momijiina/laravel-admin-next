<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Actions\Action;
use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class ActionTextareaLeadingNewlineTest extends TestCase
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

    public function test_action_rendering_and_emitted_form_data_preserve_multiline_values(): void
    {
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        $cases = [
            'one LF' => "\nFirst\nSecond",
            'two LFs' => "\n\nFirst",
            'only LF' => "\n",
            'only two LFs' => "\n\n",
            'CRLF' => "\r\nFirst\r\nSecond\r\n",
            'CR' => "\rFirst\rSecond\r",
            'internal and trailing' => "First\nSecond\n",
            'spaces' => '  text  ',
            'empty' => '',
            'zero' => '0',
            'Unicode' => "日本 café 🌿\nSecond",
            'escaped markup' => "\n</textarea><script>window.injected=true</script>& <b>text</b>",
        ];
        $fixtures = [];
        $previousHtml = Admin::$html;
        $previousScript = Admin::$script;
        try {
            foreach (['value', 'default', 'old'] as $mode) {
                foreach ($cases as $label => $input) {
                    $this->app['session']->flashInput($mode === 'old' ? ['body' => $input] : []);
                    Admin::$html = Admin::$script = [];
                    $action = new MultilineTextareaAction($mode, $input);
                    $html = $action->render().implode('', Admin::$html);
                    $fixtures[] = [
                        'label' => $mode.': '.$label,
                        'html' => $html,
                        'script' => implode("\n", Admin::$script),
                        'expected' => str_replace(["\r\n", "\r"], "\n", $input),
                    ];
                }
            }
            // Shared Textarea's array-to-JSON conversion still reaches the action view.
            $this->app['session']->flashInput([]);
            Admin::$html = Admin::$script = [];
            $array = ['first' => '日本', 'second' => ['line' => "\ntext"]];
            $action = new MultilineTextareaAction('value', $array);
            $fixtures[] = [
                'label' => 'array value',
                'html' => $action->render().implode('', Admin::$html),
                'script' => implode("\n", Admin::$script),
                'expected' => json_encode($array, JSON_PRETTY_PRINT),
            ];
            $process = new Process(['node', __DIR__.'/javascript/action-textarea-leading-newline.cjs']);
            $process->setTimeout(120);
            $process->setInput(json_encode($fixtures, JSON_THROW_ON_ERROR));
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(count($fixtures), $results);
            foreach ($results as $index => $result) {
                $fixture = $fixtures[$index];
                foreach (['value', 'defaultValue', 'submitted', 'reopened'] as $key) {
                    $this->assertSame($fixture['expected'], $result[$key], $fixture['label'].' '.$key);
                }
                $this->assertTrue($result['visible'], $fixture['label']);
                $this->assertSame(7, $result['rows']);
                $this->assertSame('Multiline body', $result['placeholder']);
                $this->assertSame('fixture', $result['attribute']);
            }
        } finally {
            Admin::$html = $previousHtml;
            Admin::$script = $previousScript;
        }
    }
}

class MultilineTextareaAction extends Action
{
    protected $selector = '.multiline-textarea-action';
    public $name = 'Multiline textarea';

    public function __construct(private string $mode, private mixed $input)
    {
        parent::__construct();
    }

    public function form()
    {
        $field = $this->textarea('body', 'Body')->rows(7)
            ->placeholder('Multiline body')->attribute('data-probe', 'fixture');
        // Distinct fallback values make value/default/old precedence observable,
        // including explicit old empty and zero values.
        $field->default($this->mode === 'default' ? $this->input : 'default fallback');
        if ($this->mode !== 'default') {
            $field->value($this->mode === 'old' ? 'value fallback' : $this->input);
        }
    }

    public function html()
    {
        return '<button class="multiline-textarea-action">Open</button>';
    }
}
