<?php

use Encore\Admin\Admin as AdminState;
use Encore\Admin\Auth\Database\Administrator;

require_once __DIR__.'/../FileUploadTest.php';

/** These probes exercise complete harness lifecycles in a single PHP process. */
class StaticStateIsolationTest extends \PHPUnit\Framework\TestCase
{
    public function testFileUploadScriptsDoNotContaminateTheNextMenuPage(): void
    {
        $original = new HistoricalBrowserKitPackageState();
        try {
            $this->withFixture(new FileUploadTest('testUpdateFile'), function ($fixture) {
                $fixture->testUpdateFile();
                $this->assertStringContainsString('IndexTest.php', implode("\n", AdminState::$script));
            });

            $this->withFixture(new HistoricalStateProbe('probe'), function ($fixture) {
                $this->assertStringNotContainsString('IndexTest.php', implode("\n", AdminState::$script));
                $fixture->be(Administrator::first(), 'admin');
                $fixture->visit('admin/auth/menu');
                $fixture->dontSee('IndexTest.php');
                $fixture->seeInElement('.dd-item[data-id="1"] > .dd-handle', 'Dashboard');
            });
        } finally {
            $original->restore();
        }
    }

    public function testRestoresAccumulatorsAndPreservesHostDefaultsAndCallbacks(): void
    {
        $original = new HistoricalBrowserKitPackageState();
        $bootCount = 0;
        $hostCallback = static function () use (&$bootCount) { ++$bootCount; };
        AdminState::booting($hostCallback);
        AdminState::css('host-default.css');
        AdminState::extend('host-plugin', 'HostPlugin');
        $properties = [
            AdminState::class => array_keys(get_class_vars(AdminState::class)),
            \Encore\Admin\Form::class => ['collectedAssets', 'availableFields', 'fieldAlias', 'initCallbacks', 'snakeAttributes'],
            \Encore\Admin\Grid::class => ['initCallbacks', 'snakeAttributes'],
            \Encore\Admin\Show::class => ['extendedFields', 'initCallback', 'snakeAttributes'],
            \Encore\Admin\Grid\Column::class => ['originalGridModels', 'defined', 'htmlAttributes', 'rowAttributes', 'model', 'displayers'],
            \Encore\Admin\Actions\Action::class => ['selectors'],
            \Encore\Admin\Grid\Tools\Selector::class => ['selected'],
            \Encore\Admin\Grid\Displayers\BelongsToMany::class => ['otherKey'],
            \Encore\Admin\Grid\Exporter::class => ['drivers', 'exporter'],
            \Encore\Admin\Auth\Database\Menu::class => ['branchOrder'],
            \Tests\Models\Tree::class => ['branchOrder'],
        ];
        $properties[AdminState::class][] = 'bootingCallbacks';
        $properties[AdminState::class][] = 'bootedCallbacks';
        $before = [];
        foreach ($properties as $class => $names) {
            foreach ($names as $name) {
                $property = new ReflectionProperty($class, $name);
                $before[] = [$property, $property->getValue()];
            }
        }

        try {
            $this->withFixture(new HistoricalStateProbe('probe'), function ($fixture) use ($before, &$firstAdmin, &$firstNavbar) {
                $firstAdmin = \Encore\Admin\Facades\Admin::getFacadeRoot();
                $firstNavbar = $firstAdmin->getNavbar();
                $fixture->be(Administrator::first(), 'admin');
                $fixture->visit('admin/auth/menu');
                $this->assertContains('host-default.css', AdminState::$css);
                foreach ($before as [$property]) {
                    $property->setValue(null, ['fixture-pollution']);
                }
            });
            foreach ($before as [$property, $value]) {
                $this->assertSame($value, $property->getValue(), $property->class.'::$'.$property->name);
            }
            $this->withFixture(new HistoricalStateProbe('probe'), function ($fixture) use (&$firstAdmin, &$firstNavbar) {
                $admin = \Encore\Admin\Facades\Admin::getFacadeRoot();
                $this->assertNotSame($firstAdmin, $admin);
                $this->assertNotSame($firstNavbar, $admin->getNavbar());
                $this->assertSame([], (new ReflectionProperty(AdminState::class, 'menu'))->getValue($admin));
                $fixture->be(Administrator::first(), 'admin');
                $fixture->visit('admin/auth/menu');
                $this->assertSame('HostPlugin', AdminState::$extensions['host-plugin']);
            });
            $this->assertSame(2, $bootCount);
        } finally {
            $original->restore();
        }
    }

    public function testRestoresStateWhenApplicationSetupThrows(): void
    {
        $before = AdminState::$script;
        $fixture = new HistoricalStateProbe('probe');
        $fixture->failSetup = true;
        try {
            (new ReflectionMethod(TestCase::class, 'setUp'))->invoke($fixture);
            $this->fail('The setup exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('setup failure', $exception->getMessage());
            $this->assertSame($before, AdminState::$script);
        } finally {
            (new ReflectionMethod(TestCase::class, 'tearDown'))->invoke($fixture);
        }
    }

    public function testRestoresStateWhenApplicationTeardownThrows(): void
    {
        $before = AdminState::$script;
        try {
            $this->withFixture(new HistoricalStateProbe('probe'), static function ($fixture) {
                AdminState::script('teardown-pollution');
                $fixture->failOnTeardown();
            });
            $this->fail('The teardown exception must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('teardown failure', $exception->getMessage());
            $this->assertSame($before, AdminState::$script);
        }
    }

    private function withFixture(TestCase $fixture, callable $assertions): void
    {
        try {
            (new ReflectionMethod($fixture, 'setUp'))->invoke($fixture);
            $assertions($fixture);
        } finally {
            (new ReflectionMethod($fixture, 'tearDown'))->invoke($fixture);
        }
    }
}

class HistoricalStateProbe extends TestCase
{
    public $failSetup = false;

    public function createApplication()
    {
        if ($this->failSetup) {
            AdminState::script('setup-pollution');
            throw new RuntimeException('setup failure');
        }
        return parent::createApplication();
    }

    public function failOnTeardown(): void
    {
        $this->beforeApplicationDestroyed(static function () {
            throw new RuntimeException('teardown failure');
        });
    }
}
