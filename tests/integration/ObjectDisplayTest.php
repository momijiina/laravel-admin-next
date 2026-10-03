<?php

namespace LaravelAdminNext\Integration;

use Composer\Autoload\ClassLoader;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Column;
use Encore\Admin\Grid\Displayers\AbstractDisplayer;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\Process\Process;

require_once __DIR__.'/fixtures/object_display.php';

class ObjectDisplayTest extends ObjectDisplayFixture
{
    private $savedDefinitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedDefinitions = (new \ReflectionProperty(Column::class, 'defined'))->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Column::class, 'defined'))->setValue(null, $this->savedDefinitions);
        parent::tearDown();
    }

    private function fill($input, array $callbacks = [])
    {
        $model = new ObjectDisplayRecord(['id' => 42]);
        Column::setOriginalGridModels(new Collection([$model]));
        $column = new Column('value', 'Value');
        foreach ($callbacks as $callback) {
            $column->display($callback);
        }
        return [$column->fill([['value' => $input]])[0]['value'], $column];
    }

    public function test_scalar_null_outputs_and_original_identity_with_ordered_model_bound_callbacks(): void
    {
        $input = (object) ['label' => self::TEXT, 'nested' => (object) ['value' => 0]];
        $snapshot = serialize($input);
        foreach ([null, false, true, 0, 7, 1.25, '', 'done'] as $expected) {
            $trace = [];
            [$actual, $column] = $this->fill($input, [
                function ($value, $column) use (&$trace, $input) {
                    $trace[] = [$value === $input, $column->getOriginal() === $input, $this->id];
                    return $value;
                },
                function ($value) use (&$trace, $input, $expected) {
                    $trace[] = $value === $input;
                    return $expected;
                },
            ]);
            $this->assertSame($expected, $actual);
            $this->assertSame([[true, true, 42], true], $trace);
            $this->assertSame($input, $column->getOriginal());
            $this->assertSame($snapshot, serialize($input));
        }
    }

    public function test_structured_and_resource_results_are_rejected_before_row_output(): void
    {
        $input = (object) ['label' => self::TEXT];
        $resource = fopen('php://memory', 'r+');
        $renderable = new class implements \Illuminate\Contracts\Support\Renderable {
            public function render() { throw new \LogicException('Must not render'); }
        };
        $htmlable = new class implements \Illuminate\Contracts\Support\Htmlable {
            public function toHtml() { throw new \LogicException('Must not render'); }
        };
        $jsonable = new class implements \Illuminate\Contracts\Support\Jsonable {
            public function toJson($options = 0) { throw new \LogicException('Must not render'); }
        };
        try {
            foreach ([$input, (object) ['label' => self::TEXT], [], ['nested' => $input], new ObjectDisplayString(), $renderable, $htmlable, $jsonable, $resource] as $result) {
                try {
                    $this->fill($input, [function () use ($result) { return $result; }]);
                    $this->fail('Non-scalar object display output must be rejected');
                } catch (\UnexpectedValueException $exception) {
                    $this->assertSame('Display callback for object-valued column [value] must return a scalar or null.', $exception->getMessage());
                }
            }
        } finally {
            fclose($resource);
        }
    }

    public function test_callback_exception_propagates_unchanged(): void
    {
        $expected = new \LogicException('Explicit callback failure');
        try {
            $this->fill((object) ['label' => 'x'], [function () use ($expected) { throw $expected; }]);
            $this->fail('Callback exception must propagate');
        } catch (\LogicException $actual) {
            $this->assertSame($expected, $actual);
        }
    }

    public function test_no_callback_and_array_object_roots_still_fail_before_callbacks(): void
    {
        foreach ([(object) ['label' => 'x'], [(object) ['label' => 'x']], ['nested' => ['value' => (object) ['label' => 'x']]]] as $input) {
            $called = false;
            $callbacks = is_array($input) ? [function () use (&$called) { $called = true; return 'safe'; }] : [];
            try {
                $this->fill($input, $callbacks);
                $this->fail('Unsupported input must still fail');
            } catch (\TypeError $exception) {
                $this->assertStringContainsString('htmlentities', $exception->getMessage());
                $this->assertFalse($called);
            }
        }
    }

    public function test_existing_strings_stringables_and_arrays_keep_encoding_and_callback_order(): void
    {
        $stringable = new ObjectDisplayString();
        $subclass = new ObjectDisplayStringObject();
        foreach ([self::TEXT, $stringable, $subclass, ['nested' => [self::TEXT]]] as $input) {
            ObjectDisplayString::$trace = [];
            [$encoded] = $this->fill($input);
            $this->assertSame(is_array($input) ? ['nested' => [htmlentities(self::TEXT)]] : htmlentities(self::TEXT), $encoded);
            ObjectDisplayString::$trace = [];
            [$actual] = $this->fill($input, [function ($value) { ObjectDisplayString::$trace[] = 'callback'; return $value; }]);
            $this->assertSame($input, $actual);
            $this->assertSame(is_object($input) ? ['stringify', 'callback'] : ['callback'], ObjectDisplayString::$trace);
            // Existing callback-result contracts remain unchanged for supported inputs.
            [$actual] = $this->fill($input, [function () use ($stringable) { return $stringable; }]);
            $this->assertSame($stringable, $actual);
        }
    }

    public function test_defined_closure_replaces_existing_callbacks_and_class_displayer_is_scalar_checked(): void
    {
        Column::define('value', function ($value) { return $this->id.':'.$value->label; });
        [$actual] = $this->fill((object) ['label' => 'defined'], [function () { throw new \LogicException('Replaced callback'); }]);
        $this->assertSame('42:defined', $actual);
        foreach ([ObjectScalarDisplayer::class, ObjectIdentityDisplayer::class] as $class) {
            Column::define('value', $class);
            Column::setOriginalGridModels(new Collection([new ObjectDisplayRecord(['id' => 42])]));
            $column = new Column('value', 'Value');
            $column->setGrid(new Grid(new ObjectDisplayRecord()));
            try {
                $actual = $column->fill([['value' => (object) ['label' => 'class']]])[0]['value'];
                $this->assertSame(ObjectScalarDisplayer::class, $class);
                $this->assertSame('42:class', $actual);
            } catch (\UnexpectedValueException $exception) {
                $this->assertSame(ObjectIdentityDisplayer::class, $class);
            }
        }
    }

    public function test_real_grid_renders_explicitly_escaped_native_object_text(): void
    {
        $grid = $this->makeGrid();
        $grid->model()->usePaginate(true);
        $grid->getColumns()->get(1)->display(function ($json) {
            return '<span class="object-label">'.htmlspecialchars(json_decode($json)->label, ENT_QUOTES, 'UTF-8').'</span>';
        });
        $html = $grid->render();
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        $labels = $crawler->filter('span.object-label');
        $this->assertCount(5, $labels);
        $this->assertSame(self::TEXT, $labels->first()->text(null, false));
        $this->assertCount(0, $labels->filter('em'));
        $this->assertStringContainsString('&lt;em&gt;', $html);
        $this->assertStringNotContainsString('<em>quoted', $html);
        $this->assertSame(self::TEXT, ObjectDisplayRecord::firstOrFail()->payload->label);
    }

    private function export($scenario): Process
    {
        $vendor = array_key_first(ClassLoader::getRegisteredLoaders());
        $source = dirname((new \ReflectionClass(Column::class))->getFileName(), 2).'/';
        $process = new Process([PHP_BINARY, __DIR__.'/fixtures/export_objects.php', $vendor.'/autoload.php', $source, $scenario]);
        $process->setTimeout(30);
        $process->run();
        return $process;
    }

    public function test_actual_streamed_csv_parse_back_with_scopes_and_custom_columns(): void
    {
        foreach (['display', 'defined', 'filtered', 'page', 'selected', 'custom'] as $scenario) {
            $process = $this->export($scenario);
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());
            $output = $process->getOutput();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $output);
            $handle = fopen('php://memory', 'r+');
            fwrite($handle, substr($output, 3));
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) { $rows[] = $row; }
            fclose($handle);
            $this->assertSame($scenario === 'custom' ? ['ID', 'Custom payload'] : ['ID', 'Payload', 'Text'], array_shift($rows));
            $ids = ['filtered' => ['3'], 'page' => ['3', '4'], 'selected' => ['2', '4']][$scenario] ?? ['1', '2', '3', '4', '5'];
            $this->assertSame($ids, array_column($rows, 0));
            foreach ($rows as $row) {
                if ($scenario === 'custom') {
                    $this->assertSame(self::TEXT, $row[1]);
                } else {
                    $value = json_decode($row[1], true, 512, JSON_THROW_ON_ERROR);
                    $this->assertSame(['label' => self::TEXT, 'nested' => ['zero' => 0, 'null' => null], 'list' => [['label' => 'nested & <b>text</b>']]], $value);
                    $this->assertSame(htmlentities(self::TEXT), $row[2]);
                }
            }
        }
    }

    public function test_scalar_null_results_survive_real_csv_output(): void
    {
        foreach (['null' => '', 'false' => '', 'true' => '1', 'zero' => '0', 'float' => '1.25', 'empty' => ''] as $mode => $expected) {
            $process = $this->export('scalar-'.$mode);
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());
            $handle = fopen('php://memory', 'r+');
            fwrite($handle, substr($process->getOutput(), 3));
            rewind($handle);
            fgetcsv($handle, null, ',', '"', '\\');
            for ($id = 1; $id <= 5; $id++) {
                $row = fgetcsv($handle, null, ',', '"', '\\');
                $this->assertSame((string) $id, $row[0]);
                $this->assertSame($expected, $row[1]);
            }
            $this->assertFalse(fgetcsv($handle, null, ',', '"', '\\'));
            fclose($handle);
        }
    }

    public function test_real_grid_rejects_raw_object_callback_result(): void
    {
        $grid = $this->makeGrid('identity');
        $grid->model()->usePaginate(true);
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Display callback for object-valued column [payload] must return a scalar or null.');
        $grid->build();
    }

    public function test_exporter_only_callback_list_root_and_identity_are_not_enabled(): void
    {
        foreach (['none', 'export-only', 'list', 'identity'] as $scenario) {
            $process = $this->export($scenario);
            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString($scenario === 'identity' ? 'UnexpectedValueException' : 'TypeError', $process->getErrorOutput());
            $this->assertSame("\xEF\xBB\xBF", $process->getOutput());
        }
    }
}

class ObjectDisplayString implements \Stringable
{
    public static $trace = [];
    public function __toString(): string { self::$trace[] = 'stringify'; return ObjectDisplayFixture::TEXT; }
}

class ObjectDisplayStringObject extends \stdClass implements \Stringable
{
    public function __toString(): string { ObjectDisplayString::$trace[] = 'stringify'; return ObjectDisplayFixture::TEXT; }
}

class ObjectScalarDisplayer extends AbstractDisplayer
{
    public function display() { return $this->row->id.':'.$this->value->label; }
}

class ObjectIdentityDisplayer extends AbstractDisplayer
{
    public function display() { return $this->value; }
}
