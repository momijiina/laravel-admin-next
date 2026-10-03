<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Tags;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class TagsTest extends TestCase
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
        Schema::create('tag_records', function ($table) {
            $table->increments('id');
            $table->string('tags')->nullable();
        });
        $this->app['router']->get('tags-test', function () {
            $form = new Form(new TagsRecord());
            return $form->tags('tags')->render();
        })->middleware('web');
        $this->app['router']->post('tags-test/{id?}', function ($id = null) {
            // The real HTTP kernel must normalize the view's hidden empty value.
            $posted = request()->input('tags');
            $this->assertNull(end($posted));
            $form = new Form(new TagsRecord());
            $form->tags('tags');
            return $id === null ? $form->store() : $form->update($id);
        })->middleware('web');
    }

    public function test_hidden_empty_input_round_trips_through_real_http_and_form_storage(): void
    {
        $this->withoutExceptionHandling();
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $this->get('/tags-test')->assertOk()
                ->assertSee('<input type="hidden" name="tags[]"', false);
            // A browser sends selected options first, then the hidden empty input.
            foreach ([[''], ['red', ''], ['0', 'red', 'red', '']] as $posted) {
                $expected = implode(',', array_slice($posted, 0, -1));
                $this->post('/tags-test', ['tags' => $posted], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertOk()->assertJson(['status' => true]);
                $record = TagsRecord::latest('id')->firstOrFail();
                $this->assertSame($expected, $record->tags);
                $this->post('/tags-test/'.$record->id, ['tags' => ['blue', '0', '']], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                    ->assertOk()->assertJson(['status' => true]);
                $this->assertSame('blue,0', $record->fresh()->tags);
                $this->post('/tags-test/'.$record->id, ['tags' => ['']], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
                    ->assertOk()->assertJson(['status' => true]);
                $this->assertSame('', $record->fresh()->tags);
            }
        } finally {
            restore_error_handler();
        }
    }

    public function test_ordinary_nullable_and_comma_storage_fill_remains_unchanged(): void
    {
        $field = new Tags('tags');
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ([null, ''] as $stored) {
                $field->fill(['tags' => $stored]);
                $this->assertSame([], $field->value());
            }
            $field->fill(['tags' => '0,red,red']);
            $this->assertSame(['0', 'red', 'red'], $field->value());
        } finally {
            restore_error_handler();
        }
    }

    public function test_real_field_preserves_sparse_keys_and_plucked_saving_values(): void
    {
        $field = new Tags('tags');
        $input = ['absent' => null, 'blank' => '', 'false' => false,
            'zero' => 0, 'string-zero' => '0', 8 => 'red', 13 => 'red', 'space' => ' '];
        $expected = ['zero' => 0, 'string-zero' => '0', 8 => 'red', 13 => 'red', 'space' => ' '];
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            $this->assertSame($expected, $field->prepare($input));
            $this->assertSame([1 => 'red'], $field->prepare([null, 'red']));
            $this->assertSame('red,blue', $field->separators(['|'])->prepare(['red', 'blue', null]));
            $field->pluck('name', 'id')->saving(function ($value) use ($expected) {
                $this->assertSame($expected, $value);
                return 'saved';
            });
            $this->assertSame('saved', $field->prepare($input));
        } finally {
            restore_error_handler();
        }
    }
}

class TagsRecord extends Model
{
    protected $table = 'tag_records';
    protected $guarded = [];
    public $timestamps = false;
}
