<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class CollectionFieldStatesTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('s', 32)));
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
        Schema::create('collection_state_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->json('items');
            $table->json('settings')->nullable();
        });
        Schema::create('collection_state_children', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
            $table->json('items');
            $table->string('sibling')->default('before');
        });
        $this->app['router']->put('collection-state/{type}/{state}/{id}', function ($type, $state, $id) {
            $form = new Form(new CollectionStateRecord());
            $form->text('name')->rules('required');
            $this->configure($form->$type('items'), $state);
            return $form->update($id);
        })->middleware('web');
        $this->app['router']->put('collection-state-nested/{type}/{shape}/{state}/{id}', function ($type, $shape, $state, $id) {
            $form = new Form(new CollectionStateRecord());
            $form->text('name');
            $configure = function ($nested) use ($type, $state) {
                $this->configure($nested->$type('items'), $state);
                $nested->text('sibling');
            };
            if ($shape === 'embeds') {
                $form->embeds('settings', $configure);
            } else {
                $form->hasMany('children', $configure)->mode($shape);
            }
            return $form->update($id);
        })->middleware('web');
    }

    private function configure($field, string $state): void
    {
        if ($state === 'disabled' || $state === 'both') {
            $field->disable();
        }
        if ($state === 'readonly' || $state === 'both') {
            $field->readonly();
        }
        if ($state === 'readonly-alias') {
            $field->readOnly();
        }
        foreach (['disabled', 'readonly'] as $attribute) {
            foreach (['false' => false, 'null' => null, 'empty' => ''] as $suffix => $value) {
                if ($state === $attribute.'-'.$suffix) {
                    $field->attribute($attribute, $value);
                }
                if ($state === $attribute.'-array-'.$suffix) {
                    $field->attribute([$attribute => $value]);
                }
            }
        }
        if ($state === 'removed') {
            $field->disable()->readonly()->removeAttribute('disabled')->removeAttribute('readonly');
        }
    }

    private function metadata(string $type, string $state, string $base = 'items'): array
    {
        return [
            'type' => $type, 'base' => $base,
            // disable() remains unsupported for these collection views.
            'disabled' => false,
            'readonly' => str_starts_with($state, 'readonly') || $state === 'both',
        ];
    }

    private function renderFixture(callable $configure): array
    {
        $scripts = Admin::$script;
        $styles = Admin::$style;
        Admin::$script = Admin::$style = [];
        try {
            $form = new Form(new CollectionStateRecord());
            $configure($form);
            $html = '';
            foreach ($form->fields() as $field) {
                $html .= (string) $field->render();
            }
            return ['html' => $html, 'scripts' => array_values(array_unique(Admin::$script))];
        } finally {
            Admin::$script = $scripts;
            Admin::$style = $styles;
        }
    }

    private function runDom(array $fixtures): array
    {
        $path = tempnam(sys_get_temp_dir(), 'collection-states-');
        $this->assertNotFalse($path);
        try {
            file_put_contents($path, json_encode($fixtures, JSON_THROW_ON_ERROR));
            $process = new Process(['node', __DIR__.'/javascript/collection-field-states.cjs', $path]);
            $process->setTimeout(120);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['2.1.4', '3.7.1'], $result['jquery']);
            $this->addToAssertionCount($result['assertions']);
            return $result['snapshots'];
        } finally {
            unlink($path);
        }
    }

    public function test_attribute_presence_locks_controls_and_preserves_submission_semantics(): void
    {
        $fixtures = [];
        foreach (['list', 'keyValue'] as $type) {
            foreach (['normal', 'disabled', 'readonly', 'both', 'disabled-false', 'disabled-null', 'disabled-empty',
                'readonly-false', 'readonly-null', 'readonly-empty', 'readonly-array-false', 'readonly-array-null',
                'readonly-array-empty', 'readonly-alias', 'removed'] as $state) {
                foreach (['empty' => [], 'populated' => ['first' => 'alpha', 'zero' => '0', 'blank' => '']] as $label => $value) {
                    $stored = $type === 'list' ? array_values($value) : $value;
                    $fixture = $this->renderFixture(function (Form $form) use ($type, $state, $stored) {
                        $this->configure($form->$type('items')->value($stored), $state);
                        $form->$type('neighbor')->value(['safe' => 'neighbor']);
                    });
                    $fixture += ['label' => "$type/$state/$label", 'type' => $type, 'state' => $state, 'stored' => $stored,
                        'roots' => [$this->metadata($type, $state), $this->metadata($type, 'normal', 'neighbor')]];
                    $fixtures[] = $fixture;
                }
            }
        }
        foreach ($this->runDom($fixtures) as $snapshot) {
            $fixture = $fixtures[$snapshot['fixture']];
            $record = CollectionStateRecord::create(['name' => 'before', 'items' => $fixture['stored']]);
            foreach ($snapshot['queries'] as $scenario => $query) {
                $record->refresh()->update(['name' => 'before', 'items' => $fixture['stored']]);
                parse_str($query, $payload);
                $this->assertArrayHasKey('items', $payload, $fixture['label']);
                $payload['name'] = 'after';
                $response = $this->put('/collection-state/'.$fixture['type'].'/'.$fixture['state'].'/'.$record->id, $payload);
                $this->assertLessThan(400, $response->getStatusCode(), $fixture['label'].' '.$scenario);
                $this->assertSame('after', $record->fresh()->name);
                // HTTP middleware normalizes blank row text to null; zero remains a string.
                $expected = $scenario === 'original' ? array_map(
                    static fn ($value) => $value === '' ? null : $value, $fixture['stored']
                ) : [];
                $this->assertSame($expected, $record->fresh()->items, $fixture['label'].' '.$scenario);
            }
        }
    }

    public function test_re_render_recomputes_removed_attribute_state(): void
    {
        $fixtures = [];
        foreach (['list', 'keyValue'] as $type) {
            $fixture = $this->renderFixture(function (Form $form) use ($type) {
                $field = $form->$type('items')->value(['first' => '0'])->disable()->readonly();
                $locked = (string) $field->render();
                $this->assertStringContainsString('data-collection-locked', $locked);
                $field->removeAttribute('disabled')->removeAttribute('readonly');
            });
            $fixtures[] = $fixture + ['label' => $type.'/rerender', 'shape' => 'embeds',
                'roots' => [$this->metadata($type, 'normal')]];
        }
        $this->runDom($fixtures);
    }

    public function test_readonly_nested_submission_preserves_originals_with_sibling_updates(): void
    {
        $fixtures = [];
        foreach (['list', 'keyValue'] as $type) {
            foreach (['embeds', 'default', 'tab', 'table'] as $shape) {
                $state = 'readonly';
                $stored = $type === 'list' ? ['alpha', '0'] : ['first' => 'alpha', 'zero' => '0'];
                $record = CollectionStateRecord::create(['name' => 'before', 'items' => [],
                    'settings' => ['items' => $stored, 'sibling' => 'before']]);
                $child = $record->children()->create(['items' => $stored, 'sibling' => 'before']);
                $fixture = $this->renderFixture(function (Form $form) use ($type, $shape, $state, $record) {
                    $configure = function ($nested) use ($type, $state) {
                        $this->configure($nested->$type('items'), $state);
                        $nested->text('sibling');
                    };
                    if ($shape === 'embeds') {
                        $form->embeds('settings', $configure);
                    } else {
                        $form->hasMany('children', $configure)->mode($shape);
                    }
                    $form->edit($record->id);
                });
                $base = $shape === 'embeds' ? 'settings[items]' : 'children['.$child->id.'][items]';
                $fixtures[] = $fixture + ['label' => "$type/$shape/$state/http", 'shape' => 'http',
                    'captureOriginal' => true, 'roots' => [$this->metadata($type, $state, $base)],
                    'record' => $record->id, 'child' => $child->id, 'type' => $type, 'state' => $state,
                    'nestedShape' => $shape, 'stored' => $stored, 'collectionScripts' => []];
            }
        }
        foreach ($this->runDom($fixtures) as $snapshot) {
            $fixture = $fixtures[$snapshot['fixture']];
            foreach ([false, true] as $updateSibling) {
                $record = CollectionStateRecord::findOrFail($fixture['record']);
                $child = CollectionStateChild::findOrFail($fixture['child']);
                $original = ['items' => $fixture['stored'], 'sibling' => 'before'];
                $record->update(['name' => 'before', 'settings' => $original]);
                $child->update(['items' => $fixture['stored'], 'sibling' => 'before']);
                parse_str($snapshot['query'], $payload);
                $payload['name'] = 'after';
                if ($updateSibling) {
                    if ($fixture['nestedShape'] === 'embeds') {
                        $payload['settings']['sibling'] = 'after';
                    } else {
                        $payload['children'][$child->id]['sibling'] = 'after';
                    }
                }
                $response = $this->put('/collection-state-nested/'.$fixture['type'].'/'.$fixture['nestedShape'].'/'.$fixture['state'].'/'.$record->id, $payload);
                $this->assertLessThan(400, $response->getStatusCode(), $fixture['label']);
                $this->assertSame('after', $record->fresh()->name);
                if ($fixture['nestedShape'] === 'embeds') {
                    // Submit the rendered sibling too: this verifies a bounded readonly
                    // round trip, not a new embedded deep-merge contract.
                    $expected = $original;
                    if ($updateSibling) {
                        $expected['sibling'] = 'after';
                    }
                    $this->assertSame($expected, $record->fresh()->settings, $fixture['label']);
                } else {
                    $this->assertSame($fixture['stored'], $child->fresh()->items, $fixture['label']);
                    $this->assertSame($updateSibling ? 'after' : 'before', $child->fresh()->sibling);
                }
            }
        }
    }

    public function test_embedded_and_hasmany_children_keep_local_state_on_template_insertion(): void
    {
        $fixtures = [];
        foreach (['list', 'keyValue'] as $type) {
            foreach (['embeds', 'default', 'tab', 'table'] as $shape) {
                $state = 'readonly';
                $roots = [];
                $fixture = $this->renderFixture(function (Form $form) use ($type, $shape, $state, &$roots) {
                    if ($shape === 'embeds') {
                        foreach (['locked' => $state, 'open' => 'normal'] as $name => $mode) {
                            $form->embeds($name, function ($nested) use ($type, $mode) {
                                $this->configure($nested->$type('items')->value(['first' => '0']), $mode);
                            });
                            $roots[] = $this->metadata($type, $mode, $name.'[items]');
                        }
                    } else {
                        $parent = CollectionStateRecord::create(['name' => 'before', 'items' => []]);
                        $child = $parent->children()->create(['items' => ['first' => '0']]);
                        $form->hasMany('children', function ($nested) use ($type, $state) {
                            $this->configure($nested->$type('items'), $state);
                            $nested->$type('open')->value(['first' => 'neighbor']);
                        })->mode($shape);
                        $form->edit($parent->id);
                        $roots[] = $this->metadata($type, $state, 'children['.$child->id.'][items]');
                        $roots[] = $this->metadata($type, 'normal', 'children['.$child->id.'][open]');
                    }
                });
                $collectionScripts = $this->renderFixture(function (Form $form) use ($type) {
                    $form->$type('items');
                })['scripts'];
                $fixtures[] = $fixture + ['label' => "$type/$shape/$state", 'shape' => $shape,
                    'roots' => $roots, 'collectionScripts' => $collectionScripts];
            }
        }
        $this->runDom($fixtures);
    }
}

class CollectionStateRecord extends Model
{
    protected $table = 'collection_state_records';
    protected $guarded = [];
    protected $casts = ['items' => 'array', 'settings' => 'array'];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(CollectionStateChild::class, 'parent_id');
    }
}

class CollectionStateChild extends Model
{
    protected $table = 'collection_state_children';
    protected $guarded = [];
    protected $casts = ['items' => 'array'];
    public $timestamps = false;
}
