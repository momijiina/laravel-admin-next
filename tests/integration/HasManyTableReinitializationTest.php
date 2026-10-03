<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\NestedForm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class HasManyTableReinitializationTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('h', 32)));
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
        Schema::create('table_reinit_parents', function ($table) {
            $table->increments('id');
        });
        Schema::create('table_reinit_children', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->unsignedInteger('other_parent_id')->nullable();
            $table->json('items');
            $table->string('probe')->nullable();
        });
        $this->app['router']->put('table-reinit/{type}/{id}', function ($type, $id) {
            return $this->form($type)->update($id);
        })->middleware('web');
    }

    public static function collectionTypes(): iterable
    {
        foreach (['list', 'keyValue'] as $type) {
            yield $type => [$type, false];
            yield $type.' customized nested name' => [$type, true];
        }
    }

    #[DataProvider('collectionTypes')]
    public function test_table_parent_reinitialization_keeps_handlers_names_and_saved_children(string $type, bool $customized): void
    {
        $parent = TableReinitParent::create([]);
        $parent->children()->create(['items' => ['first' => 'alpha', 'second' => '0']]);
        $parent->children()->create(['items' => ['third' => 'beta', 'fourth' => 'delta']]);
        $parent->others()->create(['items' => ['other' => 'unchanged']]);
        $initialIds = $parent->children()->pluck('id')->all();
        $otherId = $parent->others()->firstOrFail()->id;
        $fixture = $this->renderFixture($type, $parent->id, $customized);
        $fixture['customized'] = $customized;
        $fixture['type'] = $type === 'list' ? 'list' : 'key-value';
        $fixture['initialKeys'] = [
            'children' => array_map('strval', $initialIds),
            'others' => [(string) $otherId],
        ];
        $result = $this->runDomAssertions($fixture);

        foreach ($result['snapshots'] as $snapshot) {
            parse_str($snapshot['query'], $parsed);
            foreach ($snapshot['parents'] as $expected) {
                $actual = $this->atPath($parsed, $expected['path']);
                $this->assertSame($expected['keys'], array_map('strval', array_keys($actual)));
            }
            foreach ($snapshot['collections'] as $expected) {
                $this->assertSame($expected['value'], $this->atPath($parsed, $expected['path']));
            }
            if (!$snapshot['persist']) {
                continue;
            }

            // Submit exactly the browser-style successful-control query, parsed by
            // PHP, through Laravel's real HTTP kernel and Form::update pipeline.
            // Reset only the synthetic children inserted by the other jQuery run.
            $parent->children()->whereNotIn('id', $initialIds)->delete();
            $parent->others()->where('id', '!=', $otherId)->delete();
            $this->put('/table-reinit/'.$type.'/'.$parent->id, $parsed)
                ->assertRedirect()->assertSessionHasNoErrors();
            $saved = $parent->children()->orderBy('id')->get();
            $this->assertCount(5, $saved, 'Both originals and all three independently populated new children persist.');
            $this->assertSame($initialIds, $saved->take(2)->pluck('id')->all(), 'Existing primary keys are unchanged.');
            foreach (array_values($parsed['children']) as $index => $input) {
                $expected = $type === 'list' ? $input['items']['values']
                    : array_combine($input['items']['keys'], $input['items']['values']);
                $this->assertSame($expected, $saved[$index]->items);
                if ($index >= 2) {
                    $this->assertSame('quoted "< & value', $saved[$index]->probe);
                }
            }
            $others = $parent->others()->orderBy('id')->get();
            $this->assertCount(2, $others, 'The second HasMany has its own allocation and insertion.');
            $this->assertSame($otherId, $others[0]->id);
            foreach (array_values($parsed['others']) as $index => $input) {
                $expected = $type === 'list' ? $input['items']['values']
                    : array_combine($input['items']['keys'], $input['items']['values']);
                $this->assertSame($expected, $others[$index]->items);
            }
        }
        $this->assertSame($customized ? 0 : 2, count(array_filter($result['snapshots'], fn ($snapshot) => $snapshot['persist'])),
            'Both real jQuery versions contribute an HTTP/SQLite round trip.');
    }

    private function form(string $type, bool $customized = false): Form
    {
        $form = new Form(new TableReinitParent());
        foreach (['children', 'others'] as $relation) {
            $form->hasMany($relation, function ($nested) use ($type, $relation, $customized) {
                $field = $nested->$type('items');
                if ($customized) {
                    // Public child-field naming API, with a nested pending key
                    // and selector/HTML punctuation. Canonical ID/removal fields
                    // retain their production-generated HasMany envelope.
                    $field->setElementName('groups[new_40][part:one\'s & "quote"]['.$relation.']['.$nested->getKey().'][items]');
                }
                $probe = $nested->text('probe')->default('quoted "< & value');
                if ($nested->getKey() === 'new_'.NestedForm::DEFAULT_KEY_NAME) {
                    // A generic custom child initializer legitimately captures
                    // HasMany's index variable, just as built-in widgets can.
                    $prefix = json_encode($relation.'[new_', JSON_THROW_ON_ERROR);
                    $probe->setScript('var probe = document.getElementsByName('.$prefix.' + index + "][probe]")[0];'
                        .'probe.setAttribute("data-probe-index", String(index));'
                        .'probe.setAttribute("data-probe-type", typeof index);'
                        .'if (typeof index === "number") { probe.setAttribute("data-probe-next", index + 1); }'
                        .'probe.setAttribute("data-probe-calls", Number(probe.getAttribute("data-probe-calls") || 0) + 1);');
                }
            })->useTable();
        }

        return $form;
    }

    private function renderFixture(string $type, int $parentId, bool $customized): array
    {
        $scripts = Admin::$script;
        $styles = Admin::$style;
        Admin::$script = Admin::$style = [];
        try {
            $form = $this->form($type, $customized)->edit($parentId);
            $html = '';
            foreach ($form->fields() as $field) {
                $html .= (string) $field->render();
            }

            return [
                'html' => $html,
                // Keep the production ready callback. Evaluating only the strings
                // would hide the closure reset that originally reused new_1.
                'renderedScript' => $this->app['view']->make('admin::partials.script', [
                    'script' => array_values(array_unique(Admin::$script)),
                ])->render(),
            ];
        } finally {
            Admin::$script = $scripts;
            Admin::$style = $styles;
        }
    }

    private function atPath(array $input, array $path)
    {
        foreach ($path as $key) {
            $this->assertArrayHasKey($key, $input);
            $input = $input[$key];
        }

        return $input;
    }

    private function runDomAssertions(array $fixture): array
    {
        $path = tempnam(sys_get_temp_dir(), 'admin-hasmany-table-');
        $this->assertNotFalse($path);
        try {
            file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
            $process = new Process(['node', __DIR__.'/javascript/hasmany-table-reinitialization.cjs', $path]);
            $process->setTimeout(60);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['2.1.4', '3.7.1'], $result['jquery']);
            $this->assertGreaterThan(0, $result['assertions']);
            $this->addToAssertionCount($result['assertions']);

            return $result;
        } finally {
            if (is_string($path) && file_exists($path)) {
                unlink($path);
            }
        }
    }
}

class TableReinitParent extends Model
{
    protected $table = 'table_reinit_parents';
    protected $guarded = [];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(TableReinitChild::class, 'parent_id');
    }

    public function others()
    {
        return $this->hasMany(TableReinitChild::class, 'other_parent_id');
    }
}

class TableReinitChild extends Model
{
    protected $table = 'table_reinit_children';
    protected $guarded = [];
    protected $casts = ['items' => 'array'];
    public $timestamps = false;
}
