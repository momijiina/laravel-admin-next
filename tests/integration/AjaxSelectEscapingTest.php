<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form\Field\MultipleSelect;
use Encore\Admin\Form\Field\Select;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class AjaxSelectEscapingTest extends TestCase
{
    private const STORED_LABEL = '<strong class="ajax-label-markup">Stored</strong> &amp; "quoted" 日本語';
    // The package's existing Blade::withoutDoubleEncoding() leaves &amp; in the
    // option HTML, so the browser reads it as & before Select2 receives the text.
    private const STORED_TEXT = '<strong class="ajax-label-markup">Stored</strong> & "quoted" 日本語';
    private const ZERO_LABEL = '<em class="ajax-label-markup">Zero</em> &lt;literal&gt; & "quoted"';
    private const OTHER_ID = 'remote"<&';
    private const OTHER_LABEL = '<img class="ajax-label-markup" src="offline.invalid">Remote &amp; \'quoted\'';
    private const PAGE_LABEL = 'Second page & <b class="ajax-label-markup">bold</b>';

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('session.driver', 'array');
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->get('/ajax-select/{surface}/{kind}/{mapping}', function ($surface, $kind, $mapping) {
            $previous = Admin::$script;
            Admin::$script = [];
            try {
                $multiple = $kind === 'multipleSelect';
                $value = $multiple ? ['stored'] : 'stored';
                $options = ['stored' => self::STORED_LABEL];
                if ($surface === 'form') {
                    $field = $multiple ? new MultipleSelect('choice') : new Select('choice');
                    $field->value($value)->options($options);
                } else {
                    $grid = new Grid(new class extends Model {});
                    $filter = $grid->getFilter()->disableIdFilter();
                    $condition = $multiple ? $filter->in('choice') : $filter->equal('choice');
                    $field = $condition->$kind($options)->default($value);
                }
                $field->config('width', '100%')->config('closeOnSelect', false);
                $field->ajax('/ajax-select-options/'.$mapping, ...$this->mapping($mapping));
                $html = $surface === 'form'
                    ? '<form>'.$field->render().'</form>'
                    : (string) $filter->render();

                return ['html' => $html, 'scriptHtml' => Admin::script()->render()];
            } finally {
                Admin::$script = $previous;
            }
        });
        $router->get('/ajax-select-options/{mapping}', function ($mapping) {
            [$id, $text] = $this->mapping($mapping);
            $page = (int) request('page', 1);

            return [
                'data' => $page === 1 ? [
                    [$id => '0', $text => self::ZERO_LABEL],
                    [$id => self::OTHER_ID, $text => self::OTHER_LABEL],
                ] : [[$id => 'page-two', $text => self::PAGE_LABEL]],
                'next_page_url' => $page === 1 ? '/ajax-select-options/'.$mapping.'?page=2' : null,
            ];
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutExceptionHandling();
        $this->app['view']->share('errors', new ViewErrorBag());
    }

    private function mapping(string $mapping): array
    {
        return $mapping === 'custom' ? ['code', 'label'] : ['id', 'text'];
    }

    public static function widgets(): iterable
    {
        foreach (['form', 'grid'] as $surface) {
            foreach (['select', 'multipleSelect'] as $kind) {
                yield $surface.' '.$kind => [$surface, $kind];
            }
        }
    }

    #[DataProvider('widgets')]
    public function test_ajax_results_and_preloaded_selections_render_literal_labels(string $surface, string $kind): void
    {
        foreach (['default', 'custom'] as $mapping) {
            $this->assertWidgets($surface, $kind, $mapping, ['default']);
        }
    }

    #[DataProvider('widgets')]
    public function test_explicit_native_dom_and_jquery_templates_keep_their_markup(string $surface, string $kind): void
    {
        $this->assertWidgets($surface, $kind, 'custom', ['dom', 'jquery']);
    }

    private function assertWidgets(string $surface, string $kind, string $mapping, array $templates): void
    {
        $fixture = $this->get('/ajax-select/'.$surface.'/'.$kind.'/'.$mapping)->assertOk()->json();
        $responses = [];
        foreach ([1, 2] as $page) {
            $responses[] = $this->get('/ajax-select-options/'.$mapping.'?q=needle&page='.$page)->assertOk()->json();
        }
        $process = new Process(['node', __DIR__.'/javascript/ajax-select-escaping.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode($fixture + [
            'url' => '/ajax-select-options/'.$mapping,
            'responses' => $responses,
            'templates' => $templates,
            'multiple' => $kind === 'multipleSelect',
            'ids' => ['0', self::OTHER_ID, 'page-two'],
        ], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $multiple = $kind === 'multipleSelect';
        $name = $multiple ? 'choice[]' : 'choice';
        foreach ($templates as $template) {
            $result = $results[$template];
            $label = fn ($text) => ['text' => $text, 'parsedLabelNodes' => 0, 'templateNodes' => $template === 'default' ? 0 : 1];
            $expectedLabels = [
                'initial' => [$label(self::STORED_TEXT)],
                'results' => array_map($label, [self::ZERO_LABEL, self::OTHER_LABEL, self::PAGE_LABEL]),
                'afterZero' => array_map($label, $multiple ? [self::STORED_TEXT, self::ZERO_LABEL] : [self::ZERO_LABEL]),
                'afterOther' => array_map($label, $multiple ? [self::STORED_TEXT, self::ZERO_LABEL, self::OTHER_LABEL] : [self::OTHER_LABEL]),
            ];
            $this->assertSame($expectedLabels, $result['labels'], $surface.' '.$kind.' '.$mapping.' '.$template);
            $selected = $multiple ? ['stored', '0', self::OTHER_ID] : [self::OTHER_ID];
            $this->assertSame($selected, $result['selected']);
            $entries = array_map(fn ($value) => [$name, $value], $selected);
            if ($surface === 'form') {
                if ($multiple) {
                    $entries[] = [$name, ''];
                } else {
                    array_unshift($entries, [$name, '']);
                }
            }
            $this->assertSame($entries, $result['entries']);
            $this->assertSame([['q' => 'needle', 'page' => 1], ['q' => 'needle', 'page' => 2]], $result['requests']);
            $this->assertSame([true, false], $result['more']);
        }
    }
}
