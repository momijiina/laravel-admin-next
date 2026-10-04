<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class NumberFieldStateTest extends TestCase
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
        Schema::create('number_field_state_records', function ($table) {
            $table->increments('id');
            $table->bigInteger('quantity');
            $table->string('name');
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('number-field-states/{id}/edit', function ($id) {
            $form = new Form(new NumberFieldStateRecord());
            $field = $form->number('quantity');
            $this->configure($field, request('state', 'enabled'));
            $field->fill(NumberFieldStateRecord::findOrFail($id)->toArray());
            $html = $this->wrap((string) $field->render(), request('layout', 'plain'));
            return [
                'html' => '<form method="post">'.$html.'<input name="name" value="after"><button class="save" type="submit">Save</button></form>',
                'script' => $field->getScript(),
            ];
        })->middleware('web');
        $this->app['router']->put('number-field-states/{id}', function ($id) {
            $form = new Form(new NumberFieldStateRecord());
            $field = $form->number('quantity')->rules('nullable|integer');
            $this->configure($field, request('state', 'enabled'));
            $form->text('name')->rules('required');
            return $form->update($id);
        })->middleware('web');
    }

    private function configure($field, string $state): void
    {
        if ($state === 'readonly' || $state === 'both' || $state === 'removed') {
            $field->readonly();
        }
        if ($state === 'disabled' || $state === 'both' || $state === 'removed') {
            $field->disable();
        }
        foreach (['readonly', 'disabled'] as $attribute) {
            foreach (['false' => false, 'zero' => 0, 'null' => null, 'empty' => '', 'literal-false' => 'false'] as $suffix => $value) {
                if ($state === $attribute.'-'.$suffix) {
                    $field->attribute($attribute, $value);
                }
                if ($state === $attribute.'-array-'.$suffix) {
                    $field->attribute([$attribute => $value]);
                }
            }
        }
        if ($state === 'removed') {
            $field->removeAttribute(['readonly', 'disabled']);
        }
        foreach (['min', 'max'] as $attribute) {
            if (request()->has($attribute)) {
                $field->{$attribute}(request($attribute));
            }
        }
    }

    private function wrap(string $html, string $layout): string
    {
        switch ($layout) {
            case 'fieldset-enabled':
                return '<fieldset data-state-fieldset="outer"><legend>Outer</legend>'.$html.'</fieldset>';
            case 'fieldset-disabled':
                return '<fieldset data-state-fieldset="outer" disabled><legend>Outer</legend>'.$html.'</fieldset>';
            case 'fieldset-no-legend':
                return '<fieldset data-state-fieldset="outer" disabled>'.$html.'</fieldset>';
            case 'fieldset-nested-legend':
                return '<fieldset data-state-fieldset="outer" disabled><div><legend>'.$html.'</legend></div><legend>Direct legend</legend></fieldset>';
            case 'fieldset-first-legend':
                return '<fieldset data-state-fieldset="outer" disabled><legend>'.$html.'</legend></fieldset>';
            case 'fieldset-first-legend-after-sibling':
                return '<fieldset data-state-fieldset="outer" disabled><div>Before legend</div><legend>'.$html.'</legend></fieldset>';
            case 'fieldset-second-legend':
                return '<fieldset data-state-fieldset="outer" disabled><legend>First</legend><legend>'.$html.'</legend></fieldset>';
            case 'nested-enabled':
                return '<fieldset data-state-fieldset="outer" disabled><legend>Outer</legend><fieldset data-state-fieldset="inner"><legend>Inner</legend>'.$html.'</fieldset></fieldset>';
            case 'nested-inner-legend':
                return '<fieldset data-state-fieldset="outer" disabled><legend>Outer</legend><fieldset data-state-fieldset="inner" disabled><legend>'.$html.'</legend></fieldset></fieldset>';
            case 'nested-outer-legend':
                return '<fieldset data-state-fieldset="outer" disabled><legend><fieldset data-state-fieldset="inner" disabled><legend>Inner</legend>'.$html.'</fieldset></legend></fieldset>';
            case 'nested-both-legends':
                return '<fieldset data-state-fieldset="outer" disabled><legend><fieldset data-state-fieldset="inner" disabled><legend>'.$html.'</legend></fieldset></legend></fieldset>';
            case 'nested-both-disabled':
                return '<fieldset data-state-fieldset="outer" disabled><legend>Outer</legend><fieldset data-state-fieldset="inner" disabled><legend>Inner</legend>'.$html.'</fieldset></fieldset>';
            default:
                return $html;
        }
    }

    public static function jqueryVersions(): iterable
    {
        yield 'shipped jQuery 2.1.4' => ['shipped'];
        yield 'modern jQuery 3.7.1' => ['modern'];
    }

    private function runCases(string $jquery, array $cases): void
    {
        $fixtures = [];
        $records = [];
        foreach ($cases as $index => $case) {
            $this->app['session']->flush();
            $record = NumberFieldStateRecord::create(['quantity' => $case['before'], 'name' => 'before']);
            $options = ['state' => $case['state'] ?? 'enabled', 'layout' => $case['layout'] ?? 'plain'] + ($case['attributes'] ?? []);
            $fixture = $this->get('/number-field-states/'.$record->id.'/edit?'.http_build_query($options))->assertOk()->json();
            $readonly = str_starts_with($options['state'], 'readonly') || $options['state'] === 'both';
            $disabled = str_starts_with($options['state'], 'disabled') || $options['state'] === 'both';
            $fixtures[] = $fixture + [
                'label' => $jquery.' '.$index.' '.json_encode($options),
                'before' => $case['before'],
                'state' => ['readonly' => $readonly, 'disabled' => $disabled,
                    'effectiveDisabled' => $disabled || ($case['fieldsetDisabled'] ?? false)],
                'steps' => $case['steps'],
            ];
            $records[] = $record;
        }
        $root = __DIR__.'/../../resources/assets';
        $process = new Process(['node', __DIR__.'/javascript/number-field-states.cjs']);
        $process->setInput(json_encode([
            'fixtures' => $fixtures,
            'jquery' => $jquery === 'modern' ? 'modern' : $root.'/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js',
            'widget' => $root.'/number-input/bootstrap-number-input.js',
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(90);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($jquery === 'modern' ? '3.7.1' : '2.1.4', $results['jquery']);
        $this->assertCount(count($cases), $results['fixtures']);
        $this->addToAssertionCount($results['assertions']);
        foreach ($results['fixtures'] as $index => $snapshots) {
            $case = $cases[$index];
            $this->assertCount(count($case['steps']) + 1, $snapshots);
            foreach ($snapshots as $snapshot) {
                $this->app['session']->flush();
                $record = $records[$index];
                // Each snapshot is an independent edit/save round trip from the original record.
                $record->refresh()->update(['quantity' => $case['before'], 'name' => 'before']);
                parse_str($snapshot['query'], $payload);
                $label = $fixtures[$index]['label'].' '.$snapshot['action'];
                if ($snapshot['effectiveDisabled']) {
                    $this->assertArrayNotHasKey('quantity', $payload, $label);
                } else {
                    $this->assertSame($snapshot['value'], $payload['quantity'], $label);
                }
                $this->assertSame('after', $payload['name'], $label);
                $url = '/number-field-states/'.$record->id.'?'.http_build_query(['state' => $case['state'] ?? 'enabled'] + ($case['attributes'] ?? []));
                $this->put($url, $payload, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJson(['status' => true]);
                $this->assertSame('after', $record->fresh()->name, $label);
                $this->assertSame($snapshot['effectiveDisabled'] ? $case['before'] : $snapshot['value'],
                    (string) $record->fresh()->getRawOriginal('quantity'), $label);
            }
        }
    }

    #[DataProvider('jqueryVersions')]
    public function test_boolean_attribute_presence_blocks_every_widget_handler_without_normalizing(string $jquery): void
    {
        $cases = [];
        $states = ['readonly', 'disabled', 'both'];
        foreach (['readonly', 'disabled'] as $attribute) {
            foreach (['false', 'zero', 'null', 'empty', 'literal-false'] as $value) {
                $states[] = $attribute.'-'.$value;
                $states[] = $attribute.'-array-'.$value;
            }
        }
        foreach ($states as $state) {
            foreach (['42', '-42', '9007199254740993'] as $before) {
                $cases[] = compact('state', 'before') + ['attributes' => ['min' => '-10', 'max' => '10'],
                    'steps' => [['action' => 'blocked-events', 'value' => $before]]];
            }
        }
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_disabled_fieldsets_respect_first_legend_and_all_ancestors(string $jquery): void
    {
        $cases = [];
        foreach (['fieldset-disabled', 'fieldset-no-legend', 'fieldset-nested-legend', 'fieldset-second-legend', 'nested-enabled', 'nested-inner-legend', 'nested-outer-legend', 'nested-both-disabled'] as $layout) {
            $cases[] = ['before' => '42', 'layout' => $layout, 'fieldsetDisabled' => true,
                'attributes' => ['max' => '10'], 'steps' => [['action' => 'blocked-events', 'value' => '42']]];
        }
        foreach (['fieldset-enabled', 'fieldset-first-legend', 'fieldset-first-legend-after-sibling', 'nested-both-legends'] as $layout) {
            $cases[] = ['before' => '9007199254740993', 'layout' => $layout,
                'steps' => [['action' => 'up', 'value' => '9007199254740994'], ['action' => 'down', 'value' => '9007199254740993']]];
        }
        // A fieldset legend exemption does not remove the input's own state.
        foreach (['readonly', 'disabled'] as $state) {
            $cases[] = ['before' => '42', 'state' => $state, 'layout' => 'fieldset-first-legend',
                'attributes' => ['max' => '10'], 'steps' => [['action' => 'blocked-events', 'value' => '42']]];
        }
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_live_input_properties_lock_and_unlock_without_reinitialization(string $jquery): void
    {
        $cases = [];
        foreach (['readonly', 'disabled'] as $property) {
            $locked = [$property => true, 'effectiveDisabled' => $property === 'disabled'];
            $unlocked = [$property => false, 'effectiveDisabled' => false];
            $cases[] = ['before' => '9007199254740993', 'steps' => [
                ['action' => 'up', 'value' => '9007199254740994'],
                ['action' => $property.'-on', 'value' => '9007199254740994', 'state' => $locked],
                ['action' => 'blocked-events', 'value' => '9007199254740994'],
                ['action' => $property.'-off', 'value' => '9007199254740994', 'state' => $unlocked],
                ['action' => 'up', 'value' => '9007199254740995'],
                ['action' => 'down', 'value' => '9007199254740994'],
            ]];
            $cases[] = ['before' => '42', 'state' => $property, 'attributes' => ['max' => '10'], 'steps' => [
                ['action' => 'blocked-events', 'value' => '42'],
                ['action' => $property.'-off', 'value' => '42', 'state' => $unlocked],
                ['action' => 'keyup', 'value' => '10'],
                ['action' => 'down', 'value' => '9'],
            ]];
        }
        $cases[] = ['before' => '42', 'state' => 'both', 'attributes' => ['max' => '10'], 'steps' => [
            ['action' => 'readonly-off', 'value' => '42', 'state' => ['readonly' => false]],
            ['action' => 'blocked-events', 'value' => '42'],
            ['action' => 'readonly-on', 'value' => '42', 'state' => ['readonly' => true]],
            ['action' => 'disabled-off', 'value' => '42', 'state' => ['disabled' => false, 'effectiveDisabled' => false]],
            ['action' => 'blocked-events', 'value' => '42'],
            ['action' => 'readonly-off', 'value' => '42', 'state' => ['readonly' => false]],
            ['action' => 'blur', 'value' => '10'],
        ]];
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_live_fieldset_properties_recompute_inherited_state(string $jquery): void
    {
        $this->runCases($jquery, [
            ['before' => '42', 'layout' => 'fieldset-enabled', 'steps' => [
                ['action' => 'up', 'value' => '43'],
                ['action' => 'outer-on', 'value' => '43', 'state' => ['effectiveDisabled' => true]],
                ['action' => 'blocked-events', 'value' => '43'],
                ['action' => 'outer-off', 'value' => '43', 'state' => ['effectiveDisabled' => false]],
                ['action' => 'up', 'value' => '44'],
            ]],
            ['before' => '42', 'layout' => 'nested-both-disabled', 'fieldsetDisabled' => true, 'steps' => [
                ['action' => 'blocked-events', 'value' => '42'],
                ['action' => 'outer-off', 'value' => '42'],
                ['action' => 'blocked-events', 'value' => '42'],
                ['action' => 'inner-off', 'value' => '42', 'state' => ['effectiveDisabled' => false]],
                ['action' => 'up', 'value' => '43'],
                ['action' => 'outer-on', 'value' => '43', 'state' => ['effectiveDisabled' => true]],
                ['action' => 'blocked-events', 'value' => '43'],
            ]],
            ['before' => '42', 'layout' => 'fieldset-first-legend', 'steps' => [
                ['action' => 'up', 'value' => '43'],
                ['action' => 'outer-off', 'value' => '43'],
                ['action' => 'up', 'value' => '44'],
                ['action' => 'outer-on', 'value' => '44'],
                ['action' => 'up', 'value' => '45'],
            ]],
        ]);
    }

    #[DataProvider('jqueryVersions')]
    public function test_enabled_and_removed_states_keep_integer_controls_working(string $jquery): void
    {
        $cases = [];
        foreach (['enabled', 'removed'] as $state) {
            $cases[] = ['before' => '42', 'state' => $state, 'attributes' => ['min' => '-10', 'max' => '10'], 'steps' => [
                ['action' => 'keydown', 'value' => '42'],
                ['action' => 'keyup', 'value' => '10'],
                ['action' => 'down', 'value' => '9'],
                ['action' => 'up', 'value' => '10'],
                ['action' => 'blur', 'value' => '10'],
            ]];
            $cases[] = ['before' => '-9007199254740993', 'state' => $state, 'steps' => [
                ['action' => 'down', 'value' => '-9007199254740994'],
                ['action' => 'up', 'value' => '-9007199254740993'],
                ['action' => 'keyup', 'value' => '-9007199254740993'],
                ['action' => 'blur', 'value' => '-9007199254740993'],
            ]];
        }
        $this->runCases($jquery, $cases);
    }
}

class NumberFieldStateRecord extends Model
{
    protected $table = 'number_field_state_records';
    protected $guarded = [];
    public $timestamps = false;
}
