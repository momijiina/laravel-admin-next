<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Exporter;
use Encore\Admin\Grid\Exporters\AbstractExporter;
use Encore\Admin\Grid\Exporters\CsvExporter;
use Illuminate\Database\Eloquent\Model;
use Orchestra\Testbench\TestCase;

class ExporterDriverTest extends TestCase
{
    private $originalDrivers;
    private $originalExporter;

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDrivers = $this->state('drivers')->getValue();
        $this->originalExporter = $this->state('exporter')->getValue();
        $this->resetResolver();
    }

    protected function tearDown(): void
    {
        $this->state('drivers')->setValue(null, $this->originalDrivers);
        $this->state('exporter')->setValue(null, $this->originalExporter);
        parent::tearDown();
    }

    public function test_null_and_empty_names_share_default_and_registered_driver_semantics(): void
    {
        $this->strict(function () {
            foreach ([null, ''] as $name) {
                foreach ([[], ['' => ExporterDriverCustom::class], ['' => CsvExporter::class]] as $drivers) {
                    $this->resetResolver($drivers);
                    $grid = $this->grid();
                    $resolved = (new Exporter($grid))->resolve($name);
                    $this->assertSame($drivers[''] ?? CsvExporter::class, get_class($resolved));
                    $this->assertSame($grid, $this->attachedGrid($resolved));
                    $this->assertSame($resolved, $this->state('exporter')->getValue());
                }
            }
        });
    }

    public function test_named_and_class_names_require_registration_and_unknown_names_fall_back(): void
    {
        $this->strict(function () {
            foreach ([
                ['custom', ['custom' => ExporterDriverCustom::class], ExporterDriverCustom::class],
                ['missing', ['custom' => ExporterDriverCustom::class], CsvExporter::class],
                [ExporterDriverCustom::class, [], CsvExporter::class],
                [ExporterDriverCustom::class, [ExporterDriverCustom::class => ExporterDriverOther::class], ExporterDriverOther::class],
            ] as [$name, $drivers, $expected]) {
                $this->resetResolver();
                foreach ($drivers as $key => $driver) {
                    Exporter::extend($key, $driver);
                }
                $grid = $this->grid();
                $resolved = (new Exporter($grid))->resolve($name);
                $this->assertSame($expected, get_class($resolved));
                $this->assertSame($grid, $this->attachedGrid($resolved));
                $this->assertSame($resolved, $this->state('exporter')->getValue());
            }
        });
    }

    public function test_boolean_integer_string_and_integral_float_keys_keep_array_key_equivalence(): void
    {
        $this->strict(function () {
            foreach ([[false, 0], [0, 0], ['0', 0], [0.0, 0], [true, 1], [1, 1], ['1', 1], [1.0, 1]] as [$name, $key]) {
                // The empty key is deliberately different from integer zero.
                $this->resetResolver(['' => ExporterDriverOther::class, $key => ExporterDriverCustom::class]);
                $grid = $this->grid();
                $resolved = (new Exporter($grid))->resolve($name);
                $this->assertSame(ExporterDriverCustom::class, get_class($resolved));
                $this->assertSame($grid, $this->attachedGrid($resolved));
            }
        });
    }

    public function test_exporter_object_is_attached_without_replacing_cache(): void
    {
        $this->strict(function () {
            foreach ([null, new ExporterDriverOther($this->grid())] as $cached) {
                $this->resetResolver(['custom' => ExporterDriverOther::class], $cached);
                $grid = $this->grid();
                $object = new ExporterDriverCustom($this->grid());
                $resolved = (new Exporter($grid))->resolve($object);
                $this->assertSame($object, $resolved);
                $this->assertSame($grid, $this->attachedGrid($resolved));
                $this->assertSame($cached, $this->state('exporter')->getValue());
            }
        });
    }

    public function test_cached_exporter_precedes_names_and_invalid_keys_without_reattaching_grid(): void
    {
        $this->strict(function () {
            $originalGrid = $this->grid();
            $cached = (new Exporter($originalGrid))->resolve(null);
            Exporter::extend('', ExporterDriverCustom::class);
            Exporter::extend('custom', ExporterDriverCustom::class);
            $resolver = new Exporter($this->grid());
            foreach ([null, '', 'custom', ExporterDriverCustom::class, false, 0, '0', true, 1.0, 1.5, [], new \stdClass()] as $name) {
                $this->assertSame($cached, $resolver->resolve($name));
                $this->assertSame($originalGrid, $this->attachedGrid($cached));
                $this->assertSame($cached, $this->state('exporter')->getValue());
            }
        });
    }

    public function test_invalid_non_exporter_keys_keep_native_type_errors(): void
    {
        foreach ([[], ['custom'], new \stdClass(), new ExporterDriverStringable()] as $name) {
            $this->resetResolver(['custom' => ExporterDriverCustom::class]);
            $error = $this->failure(function () use ($name) { return (new Exporter($this->grid()))->resolve($name); });
            $this->assertSame(\TypeError::class, get_class($error));
            $this->assertStringContainsString('array_key_exists()', $error->getMessage());
            $this->assertNull($this->state('exporter')->getValue());
        }
    }

    public function test_fractional_float_still_reports_lossy_key_conversion(): void
    {
        $this->resetResolver([1 => ExporterDriverCustom::class]);
        $error = $this->failure(function () { return (new Exporter($this->grid()))->resolve(1.5); });
        $this->assertSame(\ErrorException::class, get_class($error));
        $this->assertSame(E_DEPRECATED, $error->getSeverity());
        $this->assertStringContainsString('loses precision', $error->getMessage());
        $this->assertNull($this->state('exporter')->getValue());
    }

    public function test_null_registry_values_are_present_and_fail_instead_of_falling_back(): void
    {
        foreach ([null, '', 'custom', false, 0, '0', true, 1.0] as $name) {
            $this->resetResolver(['' => null, 'custom' => null, 0 => null, 1 => null]);
            $error = $this->failure(function () use ($name) { return (new Exporter($this->grid()))->resolve($name); });
            $this->assertSame(\Error::class, get_class($error));
            $this->assertSame('Class name must be a valid object or a string', $error->getMessage());
            $this->assertNull($this->state('exporter')->getValue());
        }
    }

    public function test_registered_missing_classes_and_invalid_constructors_still_fail(): void
    {
        foreach ([null, '', 'custom'] as $name) {
            foreach ([['MissingExporterDriverFixture', \Error::class], [\DateTime::class, \TypeError::class]] as [$driver, $expected]) {
                $this->resetResolver(['' => $driver, 'custom' => $driver]);
                $error = $this->failure(function () use ($name) { return (new Exporter($this->grid()))->resolve($name); });
                $this->assertSame($expected, get_class($error));
                $this->assertStringContainsString($driver === \DateTime::class ? 'DateTime::__construct()' : 'MissingExporterDriverFixture', $error->getMessage());
                $this->assertNull($this->state('exporter')->getValue());
            }
        }
    }

    private function grid(): Grid
    {
        return new Grid(new ExporterDriverRecord());
    }

    private function state(string $property): \ReflectionProperty
    {
        return new \ReflectionProperty(Exporter::class, $property);
    }

    private function resetResolver(array $drivers = [], $exporter = null): void
    {
        $this->state('drivers')->setValue(null, $drivers);
        $this->state('exporter')->setValue(null, $exporter);
    }

    private function attachedGrid(AbstractExporter $exporter): Grid
    {
        return (new \ReflectionProperty(AbstractExporter::class, 'grid'))->getValue($exporter);
    }

    private function failure(callable $callback): \Throwable
    {
        try {
            $this->strict($callback);
        } catch (\Throwable $error) {
            return $error;
        }
        $this->fail('Expected the invalid driver to retain its native failure.');
    }

    private function strict(callable $callback)
    {
        $previous = error_reporting(E_ALL);
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return $callback();
        } finally {
            restore_error_handler();
            error_reporting($previous);
        }
    }
}

class ExporterDriverRecord extends Model
{
    protected $table = 'exporter_driver_records';
}

class ExporterDriverCustom extends CsvExporter {}
class ExporterDriverOther extends CsvExporter {}
class ExporterDriverStringable
{
    public function __toString(): string
    {
        return 'custom';
    }
}
