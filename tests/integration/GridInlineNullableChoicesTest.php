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
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class GridInlineNullableChoicesTest extends TestCase
{
    private array $packageState = [];
    private const OPTIONS = [0 => 'Zero', 1 => 'One', '001' => 'Leading zeros', 'alpha' => 'Alpha'];

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
            $router->get('/grid-inline-nullable/{mode}/{id}', function ($mode, $id) {
                $previousScript = Admin::$script;
                $previousHtml = Admin::$html;
                try {
                    Admin::$script = Admin::$html = [];
                    Admin::script('window.inlineBefore = true;');
                    $grid = new Grid(new GridInlineNullableItem());
                    $grid->model()->orderBy('id');
                    if ($id !== 'all') {
                        $grid->model()->where('id', $id);
                    }
                    $grid->setResource('/grid-inline-nullable/'.$mode);
                    $grid->column('id');
                    $column = $grid->column('choice');
                    if (str_ends_with($mode, '-callback')) {
                        $editor = substr($mode, 0, -9);
                        $options = self::OPTIONS;
                        $column->display(function ($value, $column) use ($editor, $options) {
                            $prefix = $this->label_prefix;

                            return $column->{$editor}(array_map(static fn ($label) => $prefix.$label, $options));
                        });
                    } else {
                        $column->{$mode}(self::OPTIONS);
                    }
                    $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();
                    $html = $grid->render();
                    Admin::script('window.inlineAfter = true;');

                    return response()->json([
                        'html' => $html.Admin::html()->render(),
                        'scriptHtml' => Admin::script()->render(),
                    ]);
                } finally {
                    Admin::$script = $previousScript;
                    Admin::$html = $previousHtml;
                }
            });
            $router->put('/grid-inline-nullable/{mode}/{id}', function ($mode, $id) {
                $form = new Form(new GridInlineNullableItem());
                $editor = str_replace('-callback', '', $mode);
                $form->{$editor}('choice')->options(self::OPTIONS);

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
            $this->withoutExceptionHandling();
            Schema::create('grid_inline_nullable_items', function ($table) {
                $table->increments('id');
                $table->text('choice')->nullable();
                $table->string('label_prefix')->default('');
            });
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

    public static function editors(): array
    {
        return ['select' => ['select'], 'radio' => ['radio']];
    }

    #[DataProvider('editors')]
    public function test_direct_null_label_is_blank_even_with_an_empty_string_option(string $editor): void
    {
        foreach ([[], self::OPTIONS, ['' => 'Empty key'] + self::OPTIONS] as $options) {
            $crawler = $this->directDisplayer($editor, null, $options);
            $this->assertSame('', $crawler->filter('.ie-display')->text());
            $this->assertSame('', $crawler->filter('[data-toggle="popover"]')->attr('data-value'));
            $this->assertSame('', $crawler->filter('[data-toggle="popover"]')->attr('data-original'));
        }
    }

    #[DataProvider('editors')]
    public function test_nonnull_option_keys_and_escaped_labels_keep_existing_lookup(string $editor): void
    {
        $options = [0 => 'Zero <b>escaped</b>', 1 => 'One', '001' => 'Leading zeros', 'alpha' => 'Alpha', '' => 'Empty key'];
        foreach ([[0, $options[0]], ['0', $options[0]], [1, 'One'], ['1', 'One'], ['001', 'Leading zeros'], ['alpha', 'Alpha'], ['', 'Empty key'], ['missing', '']] as [$stored, $label]) {
            $crawler = $this->directDisplayer($editor, $stored, $options);
            $this->assertSame($label, $crawler->filter('.ie-display')->text());
            $this->assertCount(0, $crawler->filter('.ie-display b'));
            $this->assertSame((string) $stored, $crawler->filter('[data-toggle="popover"]')->attr('data-value'));
        }
        // Literal dotted IDs and empty options keep their existing lookup behavior.
        $this->assertSame('Dotted', $this->directDisplayer($editor, 'literal.id', ['literal.id' => 'Dotted'])->filter('.ie-display')->text());
        $this->assertSame('', $this->directDisplayer($editor, 'missing', [])->filter('.ie-display')->text());
    }

    private function directDisplayer(string $editor, $stored, array $options): Crawler
    {
        $grid = new Grid(new GridInlineNullableItem());
        $grid->setResource('/grid-inline-nullable/'.$editor);
        $row = new GridInlineNullableItem(['id' => 1, 'choice' => $stored]);
        $class = $editor === 'select' ? Grid\Displayers\Select::class : Grid\Displayers\Radio::class;
        $displayer = new $class($stored, $grid, $grid->column('choice'), $row);

        return new Crawler($displayer->display($options));
    }

    public static function gridModes(): array
    {
        return ['select' => ['select'], 'radio' => ['radio'], 'dynamic select' => ['select-callback'], 'dynamic radio' => ['radio-callback']];
    }

    #[DataProvider('gridModes')]
    public function test_nullable_row_does_not_hide_the_grid_or_other_rows(string $mode): void
    {
        $dynamic = str_ends_with($mode, '-callback');
        $items = [];
        foreach ([null, '0', '1', '001', 'alpha'] as $index => $value) {
            $items[] = GridInlineNullableItem::create(['choice' => $value, 'label_prefix' => 'Row '.$index.': '])->fresh();
        }
        $response = $this->get('/grid-inline-nullable/'.$mode.'/all')->assertOk();
        $crawler = new Crawler($response->json('html'));
        $this->assertCount(1, $crawler->filter('table.grid-table'));
        $this->assertCount(count($items), $crawler->filter('.ie-display'));
        foreach ($items as $index => $item) {
            $expected = $item->choice === null ? '' : ($dynamic ? $item->label_prefix : '').self::OPTIONS[$item->choice];
            $this->assertSame($expected, $crawler->filter('.ie-display')->eq($index)->text());
            $this->assertSame($item->choice, $item->fresh()->choice);
        }
    }

    private function widget(array $fixture, string $jquery): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-inline-nullable-choices.cjs']);
        $process->setInput(json_encode($fixture + ['jquery' => $jquery], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function initialChoice(?string $stored, string $editor): ?string
    {
        // jQuery parses canonical numeric attributes; the existing loose JS
        // comparison also matches '001' to numeric 1. Select opens the last
        // match; the existing Radio flow retains the first checked input.
        if ($stored === '1' && $editor === 'select') {
            return '001';
        }

        return $stored ?? ($editor === 'select' ? '0' : null);
    }

    public static function savedChoices(): array
    {
        $cases = [];
        foreach (['select', 'radio', 'select-callback', 'radio-callback'] as $mode) {
            foreach (['NULL to zero' => [null, '0'], 'NULL to string' => [null, 'alpha'], 'zero to one' => ['0', '1'], 'one to zero' => ['1', '0'], 'string to zero' => ['alpha', '0']] as $label => [$stored, $choose]) {
                $cases[$mode.' '.$label] = [$mode, $stored, $choose];
            }
        }

        return $cases;
    }

    #[DataProvider('savedChoices')]
    public function test_popover_cancel_explicit_choice_and_http_save_preserve_values(string $mode, ?string $stored, string $choose): void
    {
        foreach (['shipped', 'modern'] as $jquery) {
            $prefix = str_ends_with($mode, '-callback') ? 'This row: ' : '';
            $item = GridInlineNullableItem::create(['choice' => $stored, 'label_prefix' => $prefix])->fresh();
            $this->assertSame($stored, $item->choice);
            $url = '/grid-inline-nullable/'.$mode.'/'.$item->id;
            $label = $stored === null ? '' : $prefix.self::OPTIONS[$stored];
            $savedLabel = $prefix.self::OPTIONS[$choose];
            // The old popover defaults are intentionally unchanged: Select opens
            // its first option for NULL; Radio opens without a checked choice.
            $editor = str_replace('-callback', '', $mode);
            $initial = $this->initialChoice($stored, $editor);
            $fixture = $this->get($url)->assertOk()->json() + [
                'mode' => $editor, 'stored' => $stored, 'initial' => $initial,
                'label' => $label, 'choose' => $choose, 'savedLabel' => $savedLabel, 'url' => $url,
            ];
            $result = $this->widget($fixture, $jquery);
            $this->assertSame($stored, $item->fresh()->choice);
            $this->assertSame($initial, $result['initial']);
            $this->assertSame($url, $result['request']['url']);
            $this->assertSame('POST', $result['request']['type']);
            $this->assertSame([
                '_token' => 'test-token', '_method' => 'PUT', '_edit_inline' => true, 'choice' => $choose,
            ], $result['request']['data']);
            parse_str($result['request']['query'], $values);
            $this->assertSame($choose, $values['choice']);
            $response = $this->post($url, $values, [
                'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
            ])->assertOk()->assertJson(['status' => true]);
            $this->assertSame($choose, $item->fresh()->choice);
            $this->assertSame($choose, $item->fresh()->getRawOriginal('choice'));
            $completed = $this->widget($fixture + ['response' => $response->json()], $jquery);
            $this->assertSame($result['request'], $completed['request']);
            $this->assertSame($choose, $completed['reopened']);
            $this->assertSame($savedLabel, $completed['label']);
            $reloaded = $this->get($url)->assertOk()->json() + [
                'mode' => $editor, 'stored' => $choose, 'initial' => $this->initialChoice($choose, $editor), 'label' => $savedLabel,
                'url' => $url, 'inspectOnly' => true,
            ];
            $this->assertSame($this->initialChoice($choose, $editor), $this->widget($reloaded, $jquery)['initial']);
        }
    }
}

class GridInlineNullableItem extends Model
{
    protected $table = 'grid_inline_nullable_items';
    public $timestamps = false;
    protected $guarded = [];
}
