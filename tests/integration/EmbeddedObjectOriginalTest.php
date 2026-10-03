<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\AdminTablesSeeder;
use Encore\Admin\Controllers\AuthController;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Form\EmbeddedForm;
use Encore\Admin\Form\Field;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class EmbeddedObjectOriginalTest extends TestCase
{
    private $generatedDirectory;

    protected function getPackageProviders($app)
    {
        return [AdminServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['Admin' => Admin::class];
    }

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('admin', require __DIR__.'/../../config/admin.php');
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('g', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('admin.auth.controller', AuthController::class);
        $app['config']->set('admin.bootstrap', __DIR__.'/fixtures/bootstrap.php');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('hashing.bcrypt.rounds', 4);
    }

    protected function defineRoutes($router)
    {
        Admin::routes();
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__.'/../../database/migrations/2016_01_04_173148_create_admin_tables.php';
        (new \CreateAdminTables())->up();
        $this->seed(AdminTablesSeeder::class);
        $this->withoutExceptionHandling();
        Schema::create('embedded_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->json('settings')->nullable();
            $table->timestamps();
        });
        $this->generatedDirectory = sys_get_temp_dir().'/admin-embedded-original-'.bin2hex(random_bytes(8));
        mkdir($this->generatedDirectory, 0700, true);
        $this->app->getNamespace();
        $this->app->useAppPath($this->generatedDirectory);
        config(['admin.directory' => $this->generatedDirectory.'/Admin']);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->generatedDirectory) {
                (new Filesystem())->deleteDirectory($this->generatedDirectory);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function resource($model)
    {
        $namespace = 'App\\EmbeddedOriginal'.bin2hex(random_bytes(8));
        config(['admin.route.namespace' => $namespace]);
        $this->assertSame(0, Artisan::call('admin:make', ['name' => 'EmbeddedController', '--model' => $model]), Artisan::output());
        $path = app_path(str_replace('\\', '/', substr($namespace, 4)).'/EmbeddedController.php');
        $source = file_get_contents($path);
        $this->assertStringContainsString("\$form->textarea('settings'", $source);
        $replacement = <<<'CODE'
$form->embeds('settings', function ($embedded) {
            $embedded->text('theme');
            $embedded->embeds('nested', function ($nested) {
                $nested->text('label');
                $nested->multipleSelect('choices')->options(['a' => 'A', 'b' => 'B']);
            });
        });
CODE;
        $source = preg_replace('/\$form->textarea\(\x27settings\x27[^;]+;/', $replacement, $source);
        file_put_contents($path, $source);
        require $path;
        $slug = 'embedded-'.bin2hex(random_bytes(6));
        $this->app['router']->group(['prefix' => 'admin', 'middleware' => ['web', 'admin']], function ($router) use ($slug, $namespace) {
            $router->resource($slug, $namespace.'\\EmbeddedController');
        });
        $this->actingAs(Administrator::firstOrFail(), 'admin');

        return '/admin/'.$slug;
    }

    public function test_native_object_and_array_casts_create_edit_and_update_configured_fields(): void
    {
        foreach ([EmbeddedArrayRecord::class, EmbeddedObjectRecord::class] as $model) {
            $url = $this->resource($model);
            $input = ['theme' => 'blue', 'nested' => ['label' => 'inner', 'choices' => ['a', 'b']]];
            $this->get($url.'/create')->assertOk()->assertSee('name="settings[theme]"', false);
            $this->post($url, ['name' => 'created', 'settings' => $input])->assertRedirect($url);
            $record = $model::latest('id')->firstOrFail();
            $this->assertSame(json_encode($input), $record->getRawOriginal('settings'));
            $this->get($url.'/'.$record->id.'/edit')->assertOk()
                ->assertSee('value="blue"', false)->assertSee('value="inner"', false);
            // Unchanged save, then an update to configured nested and list fields.
            $this->put($url.'/'.$record->id, ['name' => 'created', 'settings' => $input])->assertRedirect($url);
            $this->assertSame(json_encode($input), $record->fresh()->getRawOriginal('settings'));
            $input['nested'] = ['label' => 'changed', 'choices' => ['b']];
            $this->put($url.'/'.$record->id, ['name' => 'created', 'settings' => $input])->assertRedirect($url);
            $this->assertSame(json_encode($input), $record->fresh()->getRawOriginal('settings'));
            $this->get($url.'/'.$record->id.'/edit')->assertOk()->assertSee('value="changed"', false);
        }
    }

    public function test_empty_and_populated_native_originals_keep_existing_replacement_semantics(): void
    {
        foreach ([EmbeddedArrayRecord::class, EmbeddedObjectRecord::class] as $model) {
            $url = $this->resource($model);
            foreach ([null, [], (object) [], (object) [
                'theme' => 'before', 'extra' => (object) [], 'list' => [1, true, null],
                'nested' => (object) ['label' => 'old', 'choices' => ['a'], 'unrepresented' => 'old'],
            ]] as $original) {
                $record = $model::create(['name' => 'existing', 'settings' => $original]);
                $this->get($url.'/'.$record->id.'/edit')->assertOk()->assertSee('name="settings[nested][label]"', false);
                $input = ['theme' => 'after', 'nested' => ['label' => 'new', 'choices' => ['b']]];
                $this->put($url.'/'.$record->id, ['name' => 'existing', 'settings' => $input])->assertRedirect($url);
                // Embeds replaces with submitted configured values; it does not merge unseen siblings.
                $this->assertSame(json_encode($input), $record->fresh()->getRawOriginal('settings'));
            }
        }
    }

    public function test_original_lookup_is_shallow_and_does_not_mutate_metadata_or_submission(): void
    {
        $nested = (object) ['list' => [1, true, null], 'empty' => (object) []];
        foreach ([['theme' => ['theme' => $nested]], (object) ['theme' => ['theme' => $nested]]] as $original) {
            $form = new EmbeddedForm('settings');
            $field = new EmbeddedOriginalObserver('theme');
            $form->pushField($field)->setOriginal($original);
            $input = ['theme' => '{"raw":"textarea input"}'];
            $this->assertSame($input, $form->prepare($input));
            $this->assertSame($nested, $field->observed);
            $property = new \ReflectionProperty(EmbeddedForm::class, 'original');
            $this->assertSame($original, $property->getValue($form));
        }
        // Preserve the existing array-original callback/prepare contract, including null values.
        foreach ([['theme' => null], ['theme' => 'old'], [], null, (object) [], (object) ['theme' => null]] as $original) {
            $form = new EmbeddedForm('settings');
            $field = new EmbeddedOriginalObserver('theme');
            $form->pushField($field)->setOriginal($original);
            $this->assertSame(['theme' => 'new'], $form->prepare(['theme' => 'new']));
            $this->assertNull($field->observed);
            $this->assertSame(array_key_exists('theme', (array) $original) ? 1 : 0, $field->originalAssignments);
        }
    }

    public function test_unsupported_original_types_still_fail_instead_of_being_silently_coerced(): void
    {
        foreach ([42, true, new \ArrayObject(['theme' => 'old']), new EmbeddedUnsupportedOriginal(), '42', 'null', '{invalid'] as $original) {
            $form = new EmbeddedForm('settings');
            $form->setOriginal($original);
            try {
                $form->prepare(['theme' => 'new']);
                $this->fail('Unsupported original unexpectedly accepted');
            } catch (\TypeError $error) {
                $this->assertStringContainsString('array_key_exists()', $error->getMessage());
            }
        }
        // Existing JSON-string originals continue to decode, without decoding submitted strings.
        $form = new EmbeddedForm('settings');
        $this->assertSame(['theme' => '{invalid'], $form->setOriginal('{"theme":"old"}')->prepare(['theme' => '{invalid']));
    }
}

class EmbeddedArrayRecord extends Model
{
    protected $table = 'embedded_records';
    protected $guarded = [];
    protected $casts = ['settings' => 'array'];
}

class EmbeddedObjectRecord extends EmbeddedArrayRecord
{
    protected $casts = ['settings' => 'object'];
}

class EmbeddedOriginalObserver extends Field
{
    public $observed;
    public $originalAssignments = 0;

    public function setOriginal($data)
    {
        ++$this->originalAssignments;
        parent::setOriginal($data);
    }

    public function prepare($value)
    {
        $this->observed = $this->original();

        return $value;
    }
}

class EmbeddedUnsupportedOriginal
{
    public $theme = 'old';
}
