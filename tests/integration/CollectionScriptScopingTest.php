<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class CollectionScriptScopingTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('t', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['view']->share('errors', new ViewErrorBag());
        Schema::create('collection_script_parents', function ($table) {
            $table->increments('id');
        });
        Schema::create('collection_script_children', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
            $table->json('items');
        });
    }

    public static function collectionScenarios(): iterable
    {
        foreach (['list', 'keyValue'] as $type) {
            foreach (['top', 'embeds', 'default', 'tab', 'table', 'punctuation'] as $shape) {
                yield $type.' '.$shape => [$type, $shape];
            }
        }
    }

    #[DataProvider('collectionScenarios')]
    public function test_collection_controls_remain_local_and_repeatable(string $type, string $shape): void
    {
        $roots = [];
        $fixture = $this->renderFixture(function (Form $form) use ($type, $shape, &$roots) {
            if (in_array($shape, ['default', 'tab', 'table'], true)) {
                $parent = CollectionScriptParent::create([]);
                foreach ([['first' => 'alpha', 'second' => '0'], ['third' => 'beta', 'fourth' => 'delta']] as $items) {
                    $child = $parent->children()->create(['items' => $items]);
                    $roots[] = $this->root($type, ['children', (string) $child->id, 'items']);
                }
                $form->hasMany('children', function ($nested) use ($type) {
                    $nested->$type('items');
                })->mode($shape);
                $form->edit($parent->id);
            } elseif ($shape === 'embeds') {
                foreach (['one', 'two'] as $column) {
                    $form->embeds($column, function ($embedded) use ($type) {
                        $embedded->$type('items')->value(['first' => 'alpha', 'second' => '0']);
                    });
                    $roots[] = $this->root($type, [$column, 'items']);
                }
            } else {
                // Both CSS selector punctuation and a JS quote must remain ordinary column data.
                $column = $shape === 'punctuation' ? "odd:items.part'name" : 'items';
                $form->$type($column)->value(['first' => 'alpha', 'second' => '0']);
                $roots[] = $this->root($type, explode('.', $column), $column);
            }
        });
        $fixture['shape'] = $shape;
        $fixture['roots'] = $roots;
        $fixture['collectionScripts'] = $this->collectionScripts([$type]);
        $this->runDomAssertions($fixture);
    }

    public function test_same_column_list_and_key_value_coexist(): void
    {
        $fixture = $this->pairedFixture('list', 'keyValue');
        $fixture['shape'] = 'mixed';
        $this->runDomAssertions($fixture);
    }

    public static function nestedTypes(): iterable
    {
        foreach (['list', 'keyValue'] as $outer) {
            foreach (['list', 'keyValue'] as $inner) {
                yield $outer.' contains '.$inner => [$outer, $inner];
            }
        }
    }

    #[DataProvider('nestedTypes')]
    public function test_nested_roots_keep_their_own_events_and_body(string $outer, string $inner): void
    {
        $fixture = $this->pairedFixture($outer, $inner);
        $fixture['shape'] = 'nested-guard';
        $this->runDomAssertions($fixture);
    }

    private function pairedFixture(string $first, string $second): array
    {
        $fixture = $this->renderFixture(function (Form $form) use ($first, $second) {
            $form->$first('items')->value(['first' => 'alpha', 'second' => '0']);
            $form->$second('items')->value(['third' => 'beta', 'fourth' => 'delta']);
        });
        $fixture['roots'] = [$this->root($first, ['items']), $this->root($second, ['items'])];
        $fixture['collectionScripts'] = $this->collectionScripts([$first, $second]);
        return $fixture;
    }

    private function root(string $type, array $path, string $column = 'items'): array
    {
        $base = array_shift($path);
        foreach ($path as $part) {
            $base .= '['.$part.']';
        }
        return ['type' => $type === 'list' ? 'list' : 'key-value', 'base' => $base, 'column' => $column];
    }

    private function renderFixture(callable $configure): array
    {
        $scripts = Admin::$script;
        $styles = Admin::$style;
        Admin::$script = Admin::$style = [];
        try {
            $form = new Form(new CollectionScriptParent());
            $configure($form);
            $html = '';
            foreach ($form->fields() as $field) {
                $html .= (string) $field->render();
            }
            // Match HasAssets::script() deduplication. These are the real emitted
            // scripts, including HasMany's captured child-template initializer.
            return ['html' => $html, 'scripts' => array_values(array_unique(Admin::$script))];
        } finally {
            Admin::$script = $scripts;
            Admin::$style = $styles;
        }
    }

    private function collectionScripts(array $types): array
    {
        return $this->renderFixture(function (Form $form) use ($types) {
            foreach (array_unique($types) as $type) {
                $form->$type('items');
            }
        })['scripts'];
    }

    private function runDomAssertions(array $fixture): void
    {
        $path = tempnam(sys_get_temp_dir(), 'admin-collection-dom-');
        $this->assertNotFalse($path);
        try {
            file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
            $process = new Process(['node', __DIR__.'/javascript/collection-scoping.cjs', $path]);
            $process->setTimeout(60);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['2.1.4', '3.7.1'], $result['jquery']);
            $this->assertGreaterThan(0, $result['assertions']);
            // Count the actual DOM assertions without adding every successful
            // comparison to PHPUnit's process output.
            $this->addToAssertionCount($result['assertions']);
            foreach ($result['serializations'] as $snapshot) {
                parse_str($snapshot['query'], $parsed);
                parse_str(urlencode($snapshot['base']).'=collection', $nestedPath);
                while (is_array($nestedPath)) {
                    $key = array_key_first($nestedPath);
                    $this->assertArrayHasKey($key, $parsed);
                    $parsed = $parsed[$key];
                    $nestedPath = $nestedPath[$key];
                }
                $this->assertSame($snapshot['expected'], $parsed, $snapshot['base']);
            }
        } finally {
            // Keep fixture data ephemeral, including when a JS assertion fails.
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }
}

class CollectionScriptParent extends Model
{
    protected $table = 'collection_script_parents';
    protected $guarded = [];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(CollectionScriptChild::class, 'parent_id');
    }
}

class CollectionScriptChild extends Model
{
    protected $table = 'collection_script_children';
    protected $guarded = [];
    protected $casts = ['items' => 'array'];
    public $timestamps = false;
}
