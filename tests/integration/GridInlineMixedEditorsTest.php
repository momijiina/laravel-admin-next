<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class GridInlineMixedEditorsTest extends TestCase
{
    private array $packageState = [];
    private const OPTIONS = [0 => 'Zero', 1 => 'One', 2 => 'Two'];
    private const EDITORS = [
        'title' => 'text', 'body' => 'textarea', 'happened' => 'datetime',
        'status' => 'select', 'tags' => 'multipleSelect', 'choice' => 'radio',
        'flags' => 'checkbox', 'details.note' => 'text',
    ];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('m', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/grid-inline-mixed/render/{mode}', function ($mode) {
                return response()->json($this->renderGrids($mode));
            });
            $router->put('/grid-inline-mixed/{resource}/{id}', function ($resource, $id) {
                $form = new Form(new GridInlineMixedItem());
                $form->text('title')->rules('required|min:3');
                $form->textarea('body');
                $form->datetime('happened')->format('YYYY-MM-DD HH:mm:ss');
                $form->select('status')->options(self::OPTIONS);
                $form->multipleSelect('tags')->options(self::OPTIONS);
                $form->radio('choice')->options(self::OPTIONS);
                $form->checkbox('flags')->options(self::OPTIONS);
                $form->text('details->note');

                return $form->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'html', 'style'],
            Form::class => ['snakeAttributes'],
            Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'rowAttributes', 'model', 'originalGridModels'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            Schema::create('grid_inline_mixed_items', function ($table) {
                $table->increments('id');
                foreach (['title', 'body', 'happened', 'status', 'tags', 'choice', 'flags', 'details'] as $name) {
                    $table->text($name)->nullable();
                }
            });
            foreach ([1, 2] as $id) {
                GridInlineMixedItem::create([
                    'title' => 'Original '.$id, 'body' => "Original body\nRow ".$id,
                    'happened' => '2026-10-0'.$id.' 10:20:30', 'status' => (string) $id,
                    'tags' => [$id], 'choice' => (string) $id, 'flags' => [(string) $id],
                    'details' => ['note' => 'Original nested '.$id],
                ]);
            }
        } catch (\Throwable $exception) {
            $this->restorePackageState();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restorePackageState();
        }
    }

    private function restorePackageState(): void
    {
        foreach ($this->packageState as [$property, $value]) {
            $property->setValue(null, $value);
        }
    }

    private function renderGrids(string $mode): array
    {
        $previousScript = Admin::$script;
        $previousHtml = Admin::$html;
        try {
            Admin::$script = Admin::$html = [];
            Admin::script('window.inlineBefore = true;');
            if (str_starts_with($mode, 'resources')) {
                $groups = [
                    ['first', 'items', ['title' => 'text'], [1]],
                    ['second', 'other', ['choice' => 'radio'], [2]],
                ];
            } elseif (str_starts_with($mode, 'same-name')) {
                $groups = [
                    ['first', 'items', ['choice' => 'radio'], [1]],
                    ['second', 'other', ['choice' => 'select'], [2]],
                ];
            } else {
                $editors = $mode === 'reverse' ? array_reverse(self::EDITORS, true) : self::EDITORS;
                $groups = [['all', 'items', $editors, [1, 2]]];
            }
            if (str_ends_with($mode, '-reverse')) {
                $groups = array_reverse($groups);
            }
            $html = '';
            $cases = [];
            foreach ($groups as [$group, $resource, $editors, $ids]) {
                $grid = new Grid(new GridInlineMixedItem());
                $grid->model()->whereIn('id', $ids)->orderBy('id');
                $grid->setResource('/grid-inline-mixed/'.$resource);
                $grid->column('id');
                foreach ($editors as $field => $editor) {
                    $column = $grid->column(str_replace('.', '->', $field));
                    if (in_array($editor, ['select', 'multipleSelect', 'radio', 'checkbox'], true)) {
                        $column->{$editor}(self::OPTIONS);
                    } elseif ($editor === 'datetime') {
                        $column->datetime('YYYY-MM-DD HH:mm:ss');
                    } else {
                        $column->{$editor}();
                    }
                }
                $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();
                $html .= '<section data-fixture-grid="'.$group.'">'.$grid->render().'</section>';
                foreach ($ids as $id) {
                    $item = GridInlineMixedItem::findOrFail($id);
                    foreach ($editors as $field => $editor) {
                        $stored = data_get($item, $field);
                        $saved = match ($field) {
                            'title' => 'Changed '.$id,
                            'body' => "Changed body\nRow ".$id,
                            'happened' => '2026-10-1'.$id.' 11:22:33',
                            'status', 'choice' => $id === 1 ? '2' : '0',
                            'tags', 'flags' => $id === 1 ? ['0', '2'] : ['0', '1'],
                            'details.note' => 'Changed nested '.$id,
                        };
                        $label = static function ($value) use ($editor) {
                            if (in_array($editor, ['multipleSelect', 'checkbox'], true)) {
                                return implode(';', array_intersect_key(self::OPTIONS, array_flip($value)));
                            }
                            return in_array($editor, ['select', 'radio'], true) ? self::OPTIONS[$value] : $value;
                        };
                        $cases[] = [
                            'group' => $group, 'id' => $id, 'field' => $field,
                            'name' => $field === 'details.note' ? 'details[note]' : $field,
                            'editor' => $editor, 'stored' => $stored,
                            'initial' => is_array($stored) ? array_map('strval', $stored) : (string) $stored,
                            'label' => $label($stored), 'saved' => $saved, 'savedLabel' => $label($saved),
                            'url' => '/grid-inline-mixed/'.$resource.'/'.$id,
                            'invalid' => $field === 'title' && $id === 1 ? 'x' : null,
                        ];
                    }
                }
            }
            $html .= Admin::html()->render();
            // Keep only emitted submit registrations for idempotence checks.
            // Re-running all ready/popover scripts is a separate existing issue.
            $submitScripts = array_values(array_filter(Admin::$script, static function ($script) {
                return str_contains($script, "data[\$trigger.data('name')] = val;");
            }));
            Admin::script('window.inlineAfter = true;');

            return [
                'html' => $html, 'scriptHtml' => Admin::script()->render(),
                'submitScripts' => $submitScripts, 'cases' => $cases,
                'bindingOnly' => str_starts_with($mode, 'same-name'),
            ];
        } finally {
            Admin::$script = $previousScript;
            Admin::$html = $previousHtml;
        }
    }

    public static function scenarios(): iterable
    {
        foreach (['shipped', 'modern'] as $jquery) {
            foreach (['forward', 'reverse', 'resources-forward', 'resources-reverse', 'same-name-forward', 'same-name-reverse'] as $mode) {
                yield $mode.' / '.$jquery => [$mode, $jquery];
            }
        }
    }

    private function widget(array $fixture, string $jquery): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-inline-mixed-editors.cjs']);
        $process->setInput(json_encode($fixture + ['jquery' => $jquery], JSON_THROW_ON_ERROR));
        $process->setTimeout(90);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function databaseRows(): array
    {
        return GridInlineMixedItem::orderBy('id')->get()->toArray();
    }

    #[DataProvider('scenarios')]
    public function test_submit_handlers_keep_their_editor_row_and_resource(string $mode, string $jquery): void
    {
        $fixture = $this->get('/grid-inline-mixed/render/'.$mode)->assertOk()->json();
        $this->assertNotEmpty($fixture['submitScripts']);
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $before = $this->databaseRows();
        // Obtain a real validation response for the DOM's correction/retry flow.
        $fixture['invalidResponse'] = $this->post('/grid-inline-mixed/items/1', [
            '_token' => 'test-token', '_method' => 'PUT', '_edit_inline' => true, 'title' => 'x',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('title.0')->json();
        $this->assertSame($before, $this->databaseRows());
        $result = $this->widget($fixture, $jquery);
        $this->assertSame($before, $this->databaseRows(), 'DOM inspection/cancellation cannot persist anything');
        $responses = [];
        foreach ($result['requests'] as $request) {
            $case = $fixture['cases'][$request['case']];
            $value = $request['invalid'] ? $case['invalid'] : $case['saved'];
            $this->assertSame($case['url'], $request['url']);
            $this->assertSame('POST', $request['type']);
            $this->assertSame([
                '_token' => 'test-token', '_method' => 'PUT', '_edit_inline' => true, $case['name'] => $value,
            ], $request['data']);
            parse_str($request['query'], $values);
            $this->assertSame($value, data_get($values, $case['field']));
            $expected = $this->databaseRows();
            $response = $this->post($request['url'], $values, $headers);
            if ($request['invalid']) {
                $response->assertStatus(422)->assertJsonValidationErrors($case['field'].'.0');
                $this->assertSame($fixture['invalidResponse'], $response->json());
            } else {
                $response->assertOk()->assertJson(['status' => true]);
                data_set($expected[$case['id'] - 1], $case['field'], $case['saved']);
                $responses[$request['case']] = $response->json();
            }
            // Every untouched field and other row must survive each Form update.
            $this->assertSame($expected, $this->databaseRows());
        }
        $this->assertCount(count($fixture['cases']), $responses);
        $completed = $this->widget($fixture + ['responses' => $responses], $jquery);
        $this->assertSame($result['requests'], $completed['requests']);
        $this->assertCount(count($fixture['cases']), $completed['completed']);
        $reloaded = $this->get('/grid-inline-mixed/render/'.$mode)->assertOk()->json();
        $inspection = $this->widget($reloaded + ['inspectOnly' => true], $jquery);
        $this->assertCount(count($fixture['cases']), $inspection['inspected']);
    }
}

class GridInlineMixedItem extends Model
{
    protected $table = 'grid_inline_mixed_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['tags' => 'array', 'flags' => 'array', 'details' => 'array'];
}
