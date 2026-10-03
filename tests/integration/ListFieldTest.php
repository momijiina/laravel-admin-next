<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\ListField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class ListFieldTest extends TestCase
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
        Schema::create('list_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->json('items')->nullable();
        });
        $this->app['router']->match(['GET', 'POST', 'PUT'], 'list-test/{id?}', function ($id = null) {
            $form = new Form(new ListRecord());
            $form->text('name')->rules('required');
            $form->list('items')->min(2)->max(3);
            if (request()->isMethod('GET')) {
                return $id === null ? $form->render() : $form->edit($id)->render();
            }
            return $id === null ? $form->store() : $form->update($id);
        })->middleware('web');
    }

    public function test_bounds_without_item_rules_reject_invalid_writes_and_allow_endpoints(): void
    {
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $this->get('/list-test')->assertOk()->assertSee('name="items[values][]"', false);
        foreach ([[], ['one'], ['one', 'two', 'three', 'four']] as $values) {
            $this->post('/list-test', ['name' => 'invalid', 'items' => ['values' => $values]], $headers)
                ->assertOk()->assertJson(['status' => false]);
            $this->assertDatabaseCount('list_records', 0);
        }
        $this->post('/list-test', ['name' => 'before', 'items' => ['values' => ['one', 'two']]], $headers)
            ->assertOk()->assertJson(['status' => true]);
        $record = ListRecord::firstOrFail();
        $this->assertSame(['one', 'two'], $record->items);
        $this->get('/list-test/'.$record->id)->assertOk()->assertSee('value="one"', false);
        foreach ([[], ['one'], ['one', 'two', 'three', 'four']] as $values) {
            $this->put('/list-test/'.$record->id, ['name' => 'invalid', 'items' => ['values' => $values]], $headers)
                ->assertRedirect()->assertSessionHasErrors('items.values');
            $this->assertSame(['one', 'two'], $record->fresh()->items);
            $this->assertSame('before', $record->fresh()->name);
        }
        $this->put('/list-test/'.$record->id, ['name' => 'after', 'items' => ['values' => ['one', 'two', 'three']]], $headers)
            ->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['one', 'two', 'three'], $record->fresh()->items);
        $this->put('/list-test/'.$record->id, ['name' => 'omitted'], $headers)
            ->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['one', 'two', 'three'], $record->fresh()->items);
        $this->assertSame('omitted', $record->fresh()->name);
    }

    public function test_min_only_max_only_and_zero_bounds(): void
    {
        foreach ([
            [(new ListField('items'))->min(2), [], false],
            [(new ListField('items'))->min(2), ['a'], false],
            [(new ListField('items'))->min(2), ['a', 'b'], true],
            [(new ListField('items'))->max(1), [], true],
            [(new ListField('items'))->max(1), ['a'], true],
            [(new ListField('items'))->max(1), ['a', 'b'], false],
            [(new ListField('items'))->min(0)->max(0), [], true],
            [(new ListField('items'))->min(0)->max(0), ['a'], false],
        ] as [$field, $values, $passes]) {
            $validator = $field->getValidator(['items' => ['values' => $values]]);
            $this->assertNotFalse($validator);
            $this->assertSame($passes, $validator->passes());
            $this->assertFalse($field->getValidator([]));
        }
    }

    public function test_default_fast_path_and_custom_validator_precedence_are_preserved(): void
    {
        foreach ([new ListField('items'), (new ListField('items'))->min(0)] as $field) {
            foreach ([[], ['items' => ['values' => []]], ['items' => 'unvalidated']] as $input) {
                $this->assertFalse($field->getValidator($input));
            }
        }
        $field = (new ListField('items'))->min(2)->max(3)->validator(function ($input) {
            return ['custom' => $input];
        });
        $this->assertSame(['custom' => []], $field->getValidator([]));
        $this->assertSame(['custom' => ['items' => 'custom']], $field->getValidator(['items' => 'custom']));
    }

    public function test_item_rules_callbacks_method_specific_rules_and_messages_are_preserved(): void
    {
        $input = ['items' => ['values' => ['one', 'two']]];
        $field = (new ListField('items'))->min(2)->max(3)->rules('integer', ['integer' => 'An integer is needed.']);
        $validator = $field->getValidator($input);
        $this->assertFalse($validator->passes());
        $this->assertSame('An integer is needed.', $validator->errors()->first('items.values.0'));
        $this->assertTrue($field->getValidator(['items' => ['values' => [1, 2]]])->passes());
        $field = (new ListField('items'))->min(2)->rules(function () {
            return ['integer'];
        });
        $this->assertFalse($field->getValidator($input)->passes());
        $this->assertTrue($field->getValidator(['items' => ['values' => [1, 2]]])->passes());
        foreach ([[], null, false, ''] as $emptyRules) {
            $field = (new ListField('items'))->min(2)->rules(function () use ($emptyRules) {
                return $emptyRules;
            });
            $this->assertFalse($field->getValidator(['items' => ['values' => ['one']]])->passes());
            $this->assertTrue($field->getValidator($input)->passes());
        }
        $field = (new ListField('items'))->min(2)->rules('string')->creationRules('integer')->updateRules('in:one,two');
        request()->setMethod('POST');
        $this->assertFalse($field->getValidator($input)->passes());
        $this->assertTrue($field->getValidator(['items' => ['values' => [1, 2]]])->passes());
        request()->setMethod('PUT');
        $this->assertTrue($field->getValidator($input)->passes());
        $this->assertFalse($field->getValidator(['items' => ['values' => ['one', 'other']]])->passes());
        $field = (new ListField('items', ['Choices']))->min(2)->setValidationMessages('default', ['min' => ':attribute needs more entries.']);
        $this->assertSame('Choices needs more entries.', $field->getValidator(['items' => ['values' => []]])->errors()->first('items.values'));
    }
}

class ListRecord extends Model
{
    protected $table = 'list_records';
    protected $guarded = [];
    protected $casts = ['items' => 'array'];
    public $timestamps = false;
}
