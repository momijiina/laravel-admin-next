<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Displayers\Copyable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class GridCopyableTextItem extends Model
{
    protected $table = 'grid_copyable_text_items';
    public $timestamps = false;
    protected $guarded = [];
}

/** Supply the original boundary without changing the production displayer. */
class GridCopyableOriginalColumn extends Grid\Column
{
    public function __construct($original)
    {
        parent::__construct('value', 'Value');
        $this->original = $original;
    }
}

class GridCopyableTextTest extends TestCase
{
    private array $packageState = [];

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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->get('/grid-copyable-text', function () {
            $grid = new Grid(new GridCopyableTextItem());
            $grid->model()->orderBy('id');
            $grid->column('id');
            $grid->column('value')->display(function ($value) {
                return '<strong class="copyable-visible">Display: '.e($value ?? '').'</strong>';
            })->copyable();
            $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();

            return ['html' => $grid->render(), 'scripts' => (string) Admin::script()];
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'deferredScript'],
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
            foreach (['script', 'deferredScript'] as $name) {
                (new \ReflectionProperty(Admin::class, $name))->setValue(null, []);
            }
            $this->withoutExceptionHandling();
            Schema::create('grid_copyable_text_items', function (Blueprint $table) {
                $table->increments('id');
                $table->text('value')->nullable();
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

    public static function jqueryVersions(): iterable
    {
        yield 'shipped jQuery' => ['shipped'];
        yield 'modern jQuery' => ['modern'];
    }

    public static function literalStrings(): iterable
    {
        $cases = [
            'ordinary' => 'INV-A17',
            'Unicode' => '注文 café 🌿 é',
            'empty' => '',
            'zero' => '0',
            'leading zero' => '00123',
            'numeric-looking' => '1.00',
            'large integer' => '9007199254740993',
            'double quotes' => 'Invoice "A-17"',
            'single quotes' => "Customer's order",
            'ampersand' => 'ACME & Sons',
            'literal entity' => 'ACME &amp; Sons',
            'numeric entities' => '&#13; &#10; &#34; &quot;',
            'literal null' => 'null',
            'literal boolean' => 'false',
            'JSON array' => '[1,2]',
            'JSON object' => '{"invoice":"A-17","paid":false}',
            'literal markup' => '<b>Invoice</b> & "quoted"',
            'spaces and tab' => "  A\tB  ",
            'LF' => "First\nSecond",
            'leading trailing LF' => "\n\nFirst\nSecond\n",
            'only LF' => "\n",
            'CRLF' => "\r\nFirst\r\nSecond\r\n",
            'CR' => "\rFirst\rSecond\r",
            'mixed newlines' => "A\r\nB\rC\nD",
        ];
        foreach (self::jqueryVersions() as [$jquery]) {
            foreach ($cases as $label => $value) {
                yield $jquery.' '.$label => [$value, $jquery];
            }
        }
    }

    #[DataProvider('literalStrings')]
    public function test_http_grid_selects_the_exact_original_literal_string(string $value, string $jquery): void
    {
        $item = GridCopyableTextItem::create(['value' => $value]);
        $this->assertSame($value, $item->fresh()->value);
        $payload = $this->get('/grid-copyable-text')->assertOk()->json();
        $observed = $this->observe($payload, $jquery, [0]);
        $this->assertControls($observed, [$value], true);
        $this->assertCopies($observed, [$value]);
        $this->assertSame($value, DB::table('grid_copyable_text_items')->where('id', $item->id)->value('value'));
    }

    #[DataProvider('jqueryVersions')]
    public function test_sql_null_remains_empty_rather_than_the_literal_string_null(string $jquery): void
    {
        $item = GridCopyableTextItem::create(['value' => null]);
        $payload = $this->get('/grid-copyable-text')->assertOk()->json();
        $observed = $this->observe($payload, $jquery, [0]);
        $this->assertControls($observed, [''], true);
        $this->assertCopies($observed, ['']);
        $this->assertNull($item->fresh()->value);
    }

    #[DataProvider('jqueryVersions')]
    public function test_repeated_and_multiple_controls_keep_their_own_originals_and_cleanup(string $jquery): void
    {
        $values = ['First "A"', "Second\r\nline", 'null'];
        foreach ($values as $value) {
            GridCopyableTextItem::create(['value' => $value]);
        }
        $payload = $this->get('/grid-copyable-text')->assertOk()->json();
        // The last command returns false; existing tooltip behavior is unchanged.
        $observed = $this->observe($payload, $jquery, [0, 1, 0, 2, 1], false);
        $this->assertControls($observed, $values, true);
        $this->assertCopies($observed, [$values[0], $values[1], $values[0], $values[2], $values[1]]);
        $this->assertFalse($observed['copies'][4]['commandResult']);
        $this->assertSame($values, DB::table('grid_copyable_text_items')->orderBy('id')->pluck('value')->all());
    }

    public static function scalarOriginals(): iterable
    {
        foreach (self::jqueryVersions() as [$jquery]) {
            foreach (['null' => [null, ''], 'false' => [false, ''], 'true' => [true, '1'],
                'zero' => [0, '0'], 'negative' => [-17, '-17'], 'decimal' => [1.5, '1.5']] as $label => [$value, $text]) {
                yield $jquery.' '.$label => [$value, $text, $jquery];
            }
        }
    }

    #[DataProvider('scalarOriginals')]
    public function test_existing_nonstring_scalar_conversion_is_unchanged($value, string $text, string $jquery): void
    {
        $payload = $this->directDisplayer($value);
        $observed = $this->observe($payload, $jquery, [0]);
        $this->assertControls($observed, [$text], false);
        $this->assertCopies($observed, [$text]);
    }

    #[DataProvider('jqueryVersions')]
    public function test_existing_stringable_original_keeps_ordinary_string_conversion(string $jquery): void
    {
        $value = new class {
            public function __toString(): string
            {
                return 'Stringable original';
            }
        };
        $observed = $this->observe($this->directDisplayer($value), $jquery, [0]);
        $this->assertControls($observed, ['Stringable original'], false);
        $this->assertCopies($observed, ['Stringable original']);
    }

    public function test_arrays_are_not_silently_promoted_to_a_supported_json_input(): void
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Array to string conversion');
        $this->directDisplayer(['invoice' => 'A-17']);
    }

    public function test_nonstringable_objects_remain_unsupported(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('could not be converted to string');
        $this->directDisplayer(new \stdClass());
    }

    private function directDisplayer($original): array
    {
        $grid = new Grid(new GridCopyableTextItem());
        $column = new GridCopyableOriginalColumn($original);
        $display = new Copyable('Rendered value', $grid, $column, new \stdClass());
        $html = '<table id="'.$grid->tableID.'"><tr><td>'.$display->display().'</td></tr></table>';

        return ['html' => $html, 'scripts' => (string) Admin::script()];
    }

    private function observe(array $payload, string $jquery, array $clicks, bool $lastResult = true): array
    {
        $payload += ['jquery' => $jquery, 'clicks' => $clicks, 'lastResult' => $lastResult];
        $process = new Process(['node', __DIR__.'/javascript/grid-copyable-text.cjs'], __DIR__);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->mustRun();
        $this->assertSame('', trim($process->getErrorOutput()));
        $observed = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $observed['errors']);
        $this->assertSame($jquery === 'shipped' ? '2.1.4' : '3.7.1', $observed['jqueryVersion']);
        $this->assertSame(1, $observed['emittedScriptCount']);
        $this->assertSame(0, $observed['outsideCopyCalls']);

        return $observed;
    }

    private function assertControls(array $observed, array $values, bool $formatted): void
    {
        $this->assertCount(count($values), $observed['controls']);
        foreach ($observed['controls'] as $index => $control) {
            $this->assertSame(json_encode($values[$index], JSON_INVALID_UTF8_SUBSTITUTE), $control['attribute']);
            $this->assertSame('grid-column-copyable text-muted', $control['class']);
            $this->assertSame('Copied!', $control['title']);
            $this->assertSame('bottom', $control['placement']);
            $this->assertSame('javascript:void(0);', $control['href']);
            $this->assertSame(1, $control['copyIcons']);
            $this->assertSame($formatted ? 'STRONG' : null, $control['displayTag']);
            $expected = $formatted ? 'Display: '.str_replace(["\r\n", "\r"], "\n", $values[$index]) : 'Rendered value';
            $this->assertSame($expected, $control['displayText']);
        }
    }

    private function assertCopies(array $observed, array $values): void
    {
        $this->assertCount(count($values), $observed['copies']);
        $this->assertCount(count($values), $observed['afterClicks']);
        foreach ($observed['copies'] as $index => $copy) {
            $this->assertSame($values[$index], $copy['selectedText']);
            $this->assertSame($values[$index], $copy['elementText']);
            $this->assertTrue($copy['selectionInsideTemporaryElement']);
            $this->assertSame('true', $copy['editable']);
            $this->assertSame('fixed', $copy['position']);
            $this->assertSame('-9999px', $copy['left']);
            $this->assertSame('pre', $copy['whiteSpace']);
            $this->assertSame(0, $observed['afterClicks'][$index]['remainingTemporaryElements']);
            $this->assertSame(0, $observed['afterClicks'][$index]['remainingRanges']);
            $this->assertTrue($observed['afterClicks'][$index]['displayUnchanged']);
            $this->assertTrue($observed['afterClicks'][$index]['tooltipShown']);
        }
    }
}
