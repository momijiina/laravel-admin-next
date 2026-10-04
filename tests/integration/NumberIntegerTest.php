<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class NumberIntegerTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('n', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('number_integer_records', function ($table) {
            $table->increments('id');
            $table->bigInteger('quantity')->nullable();
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('number-integers/{id}/edit', function ($id) {
            $form = new Form(new NumberIntegerRecord());
            $field = $form->number('quantity');
            foreach (['min', 'max', 'step'] as $attribute) {
                if (request()->has($attribute)) {
                    if ($attribute === 'step') {
                        $field->attribute($attribute, request($attribute));
                    } else {
                        $field->{$attribute}(request($attribute));
                    }
                }
            }
            if (request('mode') === 'readonly') {
                $field->readonly();
            } elseif (request('mode') === 'disabled') {
                $field->disable();
            }
            $field->fill(NumberIntegerRecord::findOrFail($id)->toArray());
            return [
                'html' => '<form method="post">'.$field->render().'<button class="save" type="submit">Save</button></form>',
                'script' => $field->getScript(),
            ];
        })->middleware('web');
        $this->app['router']->put('number-integers/{id}', function ($id) {
            $form = new Form(new NumberIntegerRecord());
            $form->number('quantity')->rules('nullable|integer');
            return $form->update($id);
        })->middleware('web');
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
        foreach ($cases as $case) {
            $this->app['session']->flush();
            $record = NumberIntegerRecord::create(['quantity' => $case['before']]);
            $query = http_build_query($case['attributes'] ?? []);
            $fixture = $this->get('/number-integers/'.$record->id.'/edit?'.$query)->assertOk()->json();
            $fixtures[] = $fixture + ['actions' => $case['actions']] + (isset($case['typed']) ? ['typed' => $case['typed']] : []);
            $records[] = $record;
        }
        $root = __DIR__.'/../../resources/assets';
        $process = new Process(['node', __DIR__.'/javascript/number-integers.cjs']);
        $process->setInput(json_encode([
            'fixtures' => $fixtures,
            'jquery' => $jquery === 'modern' ? 'modern' : $root.'/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js',
            'widget' => $root.'/number-input/bootstrap-number-input.js',
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(count($cases), $results);
        foreach ($cases as $index => $case) {
            $this->app['session']->flush();
            $result = $results[$index];
            $label = $jquery.' '.json_encode($case);
            $this->assertSame((string) $case['before'], $result['initial'], $label);
            $this->assertSame($case['expected'], $result['value'], $label);
            $mode = $case['attributes']['mode'] ?? 'plain';
            $this->assertSame($mode === 'readonly', $result['readonly'], $label);
            $this->assertSame($mode === 'disabled', $result['disabled'], $label);
            if ($case['actions'] !== [] && $mode !== 'disabled') {
                $this->assertGreaterThanOrEqual(count($case['actions']), $result['changes'], $label);
            } else {
                $this->assertSame(0, $result['changes'], $label);
            }
            parse_str($result['query'], $values);
            $record = $records[$index];
            if ($mode === 'disabled') {
                $this->assertArrayNotHasKey('quantity', $values, $label);
            } else {
                $this->assertSame($case['expected'], $values['quantity'], $label);
            }
            $url = '/number-integers/'.$record->id;
            if ($case['reject'] ?? false) {
                $this->from($url.'/edit')->put($url, $values)->assertRedirect($url.'/edit')->assertSessionHasErrors('quantity');
                $this->assertSame((string) $case['before'], (string) $record->fresh()->getRawOriginal('quantity'), $label);
                $this->assertSame($case['expected'], $this->app['session']->getOldInput('quantity'), $label);
                $redisplayed = $this->get($url.'/edit')->assertOk()->json();
                $this->assertSame($case['expected'], (new Crawler($redisplayed['html']))->filter('input[name=quantity]')->attr('value'), $label);
            } else {
                $this->put($url, $values, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJson(['status' => true]);
                $stored = $case['persisted'] ?? ($mode === 'disabled' ? $case['before'] : $case['expected']);
                $this->assertSame((string) $stored, (string) $record->fresh()->getRawOriginal('quantity'), $label);
            }
        }
    }

    #[DataProvider('jqueryVersions')]
    public function test_untouched_keyup_and_focus_blur_preserve_exact_integers(string $jquery): void
    {
        $cases = [];
        foreach (['0', '42', '-42', '9007199254740991', '9007199254740993', '-9007199254740993', '9223372036854775807', '-9223372036854775808'] as $value) {
            foreach ([[], ['keyup'], ['blur'], ['keyup', 'blur', 'blur']] as $actions) {
                $cases[] = ['before' => $value, 'actions' => $actions, 'expected' => $value];
            }
        }
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_unit_buttons_carry_borrow_cross_zero_and_ignore_step_as_before(string $jquery): void
    {
        $cases = [];
        foreach ([
            ['0', '-1', '1'], ['1', '0', '2'], ['-1', '-2', '0'],
            ['9', '8', '10'], ['10', '9', '11'], ['99', '98', '100'], ['100', '99', '101'],
            ['-9', '-10', '-8'], ['-10', '-11', '-9'], ['-99', '-100', '-98'], ['-100', '-101', '-99'],
            ['9007199254740993', '9007199254740992', '9007199254740994'],
            ['-9007199254740993', '-9007199254740994', '-9007199254740992'],
            ['9223372036854775806', '9223372036854775805', '9223372036854775807'],
            ['-9223372036854775807', '-9223372036854775808', '-9223372036854775806'],
        ] as [$before, $down, $up]) {
            foreach (['down' => $down, 'up' => $up] as $action => $expected) {
                $cases[] = compact('before', 'expected') + ['actions' => [$action]];
            }
        }
        foreach (['up' => '3', 'down' => '1'] as $action => $expected) {
            $cases[] = ['before' => '2', 'actions' => [$action], 'attributes' => ['step' => '2'], 'expected' => $expected];
        }
        $cases[] = ['before' => '999', 'actions' => ['up', 'up', 'down', 'blur'], 'expected' => '1000'];
        $cases[] = ['before' => '-999', 'actions' => ['down', 'down', 'up', 'blur'], 'expected' => '-1000'];
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_integer_bounds_compare_numeric_magnitude_sign_and_zero(string $jquery): void
    {
        $cases = [];
        foreach ([
            ['2', '1', '10', '2'], ['50', '10', '100', '50'],
            ['-2', '-10', '-1', '-2'], ['-50', '-100', '-10', '-50'],
            ['0', '-10', '10', '0'], ['-1', '0', '10', '0'], ['1', '-10', '0', '0'],
            ['9', '10', '100', '10'], ['101', '10', '100', '100'],
            ['-101', '-100', '-10', '-100'], ['-9', '-100', '-10', '-10'],
            ['2', '+001', '0010', '2'], ['0', '-0', '+0', '0'],
            ['9007199254740993', '9007199254740992', '9007199254740994', '9007199254740993'],
            ['9007199254740992', '9007199254740993', '9007199254740994', '9007199254740993'],
            ['9007199254740994', '9007199254740992', '9007199254740993', '9007199254740993'],
            ['-9007199254740993', '-9007199254740994', '-9007199254740992', '-9007199254740993'],
            ['-9007199254740994', '-9007199254740993', '-9007199254740992', '-9007199254740993'],
            ['-9007199254740992', '-9007199254740994', '-9007199254740993', '-9007199254740993'],
        ] as [$before, $min, $max, $expected]) {
            foreach ([['keyup'], ['blur']] as $actions) {
                $cases[] = compact('before', 'actions', 'expected') + ['attributes' => compact('min', 'max')];
            }
        }
        foreach ([['0', '0', '10', 'down'], ['0', '-10', '0', 'up'],
            ['9007199254740993', '9007199254740993', '9007199254740994', 'down'],
            ['9007199254740994', '9007199254740993', '9007199254740994', 'up'],
            ['-9007199254740994', '-9007199254740994', '-9007199254740993', 'down'],
            ['-9007199254740993', '-9007199254740994', '-9007199254740993', 'up'],
            ['9223372036854775807', null, '9223372036854775807', 'up'],
            ['-9223372036854775808', '-9223372036854775808', null, 'down'],
        ] as [$before, $min, $max, $action]) {
            $cases[] = ['before' => $before, 'actions' => [$action, $action], 'expected' => $before, 'attributes' => array_filter(compact('min', 'max'), static function ($value) { return $value !== null; })];
        }
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_legacy_noninteger_parsing_and_integer_text_normalization(string $jquery): void
    {
        $cases = [];
        foreach ([
            ['', '0', '0', '0', '0'], ['abc', '0', '0', '0', '0'],
            ['2.5', '2', '2', '3', '1'], ['-2.5', '-2', '-2', '-1', '-3'],
            ['1e3', '1', '1', '2', '0'], ['0x10', '0', '0', '1', '-1'],
            [' 12tail', '12', '12', '13', '11'], ['0012', '0012', '12', '13', '11'],
            ['+0012', '0012', '12', '13', '11'], ['-0', '-0', '0', '1', '-1'],
        ] as [$typed, $keyup, $blur, $up, $down]) {
            foreach (compact('keyup', 'blur', 'up', 'down') as $action => $expected) {
                $cases[] = ['before' => '42', 'typed' => $typed, 'actions' => [$action], 'expected' => $expected,
                    'reject' => $expected === '0012', 'persisted' => $expected === '-0' ? '0' : $expected];
            }
        }
        // Mixed/noninteger bounds retain the old comparison fallback for each event.
        foreach (['keyup' => '10.5', 'blur' => '2', 'up' => '3', 'down' => '1.5'] as $action => $expected) {
            $cases[] = ['before' => '2', 'actions' => [$action], 'attributes' => ['min' => '1.5', 'max' => '10.5'],
                'expected' => $expected, 'reject' => strpos($expected, '.') !== false];
        }
        $this->runCases($jquery, $cases);
    }

    #[DataProvider('jqueryVersions')]
    public function test_server_integer_validation_rejects_exact_overflow_without_rewriting_it(string $jquery): void
    {
        $this->runCases($jquery, [
            ['before' => '9223372036854775807', 'actions' => ['up'], 'expected' => '9223372036854775808', 'reject' => true],
            ['before' => '-9223372036854775808', 'actions' => ['down'], 'expected' => '-9223372036854775809', 'reject' => true],
            ['before' => '42', 'typed' => str_repeat('9', 40), 'actions' => ['up', 'blur'], 'expected' => '1'.str_repeat('0', 40), 'reject' => true],
            ['before' => '42', 'typed' => '-1'.str_repeat('0', 40), 'actions' => ['up', 'blur'], 'expected' => '-'.str_repeat('9', 40), 'reject' => true],
        ]);
    }

    #[DataProvider('jqueryVersions')]
    public function test_readonly_submission_and_disabled_omission_remain_unchanged(string $jquery): void
    {
        $this->runCases($jquery, [
            ['before' => '9007199254740993', 'actions' => [], 'expected' => '9007199254740993', 'attributes' => ['mode' => 'readonly']],
            ['before' => '9007199254740993', 'actions' => ['blur'], 'expected' => '9007199254740993', 'attributes' => ['mode' => 'readonly']],
            ['before' => '9007199254740993', 'actions' => [], 'expected' => '9007199254740993', 'attributes' => ['mode' => 'disabled']],
        ]);
    }
}

class NumberIntegerRecord extends Model
{
    protected $table = 'number_integer_records';
    protected $guarded = [];
    public $timestamps = false;
}
