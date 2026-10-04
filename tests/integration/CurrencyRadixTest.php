<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class CurrencyRadixTest extends TestCase
{
    private array $hooks = [];

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    private function field(Form $form)
    {
        $kind = request('kind', 'currency');
        $options = request('options', []);
        if (request('preset') === 'space') {
            $options = ['radixPoint' => ',', 'groupSeparator' => ' '];
        }
        if (request('preset') === 'affixes') {
            $options = ['radixPoint' => ',', 'groupSeparator' => '.', 'prefix' => 'EUR ', 'suffix' => ' euro'];
        }
        $field = $form->{$kind}('amount')->options($options);
        if (request('mode') === 'readonly') {
            $field->readonly();
        } elseif (request('mode') === 'disabled') {
            $field->disable();
        }
        if (request('validate')) {
            $field->rules('numeric');
        }
        return $field;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('currency_radix_records', function ($table) {
            $table->increments('id');
            $table->decimal('amount', 12, 2)->nullable();
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('currency-radix/{id}/edit', function ($id) {
            $form = new Form(new CurrencyRadixRecord());
            $field = $this->field($form);
            $field->fill(CurrencyRadixRecord::findOrFail($id)->toArray());
            return [
                'html' => '<form method="post">'.$field->render().'<button type="submit">Save</button></form>',
                'script' => $field->getScript(),
            ];
        })->middleware('web');
        $save = function ($id = null) {
            $form = new Form(new CurrencyRadixRecord());
            $this->field($form);
            $form->submitted(function ($form) {
                $this->hooks['submitted'] = request('amount');
            });
            $form->saving(function ($form) {
                $this->hooks['saving'] = $form->input('amount');
                if (request('rewrite') === 'string') {
                    $form->amount = '-9876.54';
                } elseif (request('rewrite') === 'float') {
                    $form->amount = -9876.54;
                }
            });
            return $id === null ? $form->store() : $form->update($id);
        };
        $this->app['router']->post('currency-radix', $save)->middleware('web');
        $this->app['router']->put('currency-radix/{id}', $save)->middleware('web');
    }

    public static function jqueryVersions(): iterable
    {
        yield 'shipped jQuery 2.1.4' => ['shipped'];
        yield 'modern jQuery 3.7.1' => ['modern'];
    }

    private function runWidget(string $jquery, array $fixtures): array
    {
        $process = new Process(['node', __DIR__.'/javascript/currency-radix.cjs']);
        $process->setTimeout(60);
        $process->setInput(json_encode([
            'assets' => __DIR__.'/../../resources/assets', 'jquery' => $jquery, 'fixtures' => $fixtures,
        ], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $output['logs']);
        $this->assertCount(count($fixtures), $output['results']);
        return $output['results'];
    }

    private function fixture($value, array $query = [], array $actions = []): array
    {
        $record = CurrencyRadixRecord::create(['amount' => $value]);
        $url = '/currency-radix/'.$record->id;
        $queryString = '?'.http_build_query($query);
        $fixture = $this->get($url.'/edit'.$queryString)->assertOk()->json();
        return [$record, $url.$queryString, $fixture + $actions];
    }

    private function assertSaved(array $payload, $record, string $url, float $expected): void
    {
        $raw = $payload['amount'] === '' ? null : $payload['amount'];
        $this->hooks = [];
        $this->put($url, $payload, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJson(['status' => true]);
        $this->assertSame($expected, (float) $record->fresh()->getRawOriginal('amount'));
        $this->assertSame(['submitted' => $raw, 'saving' => $raw], $this->hooks);
        $query = str_contains($url, '?') ? substr($url, strpos($url, '?')) : '';
        $this->hooks = [];
        $this->post('/currency-radix'.$query, $payload, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJson(['status' => true]);
        $this->assertSame($expected, (float) CurrencyRadixRecord::latest('id')->first()->getRawOriginal('amount'));
        $this->assertSame(['submitted' => $raw, 'saving' => $raw], $this->hooks);
    }

    #[DataProvider('jqueryVersions')]
    public function test_actual_unmasked_values_round_trip_through_create_update_and_raw_hooks(string $jquery): void
    {
        $cases = [];
        $fixtures = [];
        foreach ([
            'default' => [[], '.', ',', '', ''],
            'comma inherited grouping' => [['radixPoint' => ','], ',', '.', '', ''],
            'comma explicit grouping' => [['radixPoint' => ',', 'groupSeparator' => '.'], ',', '.', '', ''],
            'comma space grouping' => [['radixPoint' => ',', 'groupSeparator' => ' '], ',', ' ', '', ''],
            'comma affixes' => [['radixPoint' => ',', 'groupSeparator' => '.', 'prefix' => 'EUR ', 'suffix' => ' euro'], ',', '.', 'EUR ', ' euro'],
        ] as $mode => [$options, $radix, $group, $prefix, $suffix]) {
            foreach (['1234.56', '-1234.56', '0.00', '0.56', '-0.56'] as $value) {
                foreach ([false, true] as $edit) {
                    $expected = $edit ? '-9876.54' : $value;
                    $actions = $edit ? ['edit' => str_replace('.', $radix, $expected)] : [];
                    [$record, $url, $fixture] = $this->fixture($value, $mode === 'comma affixes' ? ['preset' => 'affixes'] : ($mode === 'comma space grouping' ? ['preset' => 'space'] : ['options' => $options]), $actions);
                    $fixtures[] = $fixture;
                    $display = function ($number) use ($radix, $group, $prefix, $suffix) {
                        return $prefix.((float) $number < 0 ? '-' : '').number_format(abs((float) $number), 2, $radix, $group).$suffix;
                    };
                    $cases[] = [$record, $url, $expected, $display($value), $display($expected), $radix, $group, $mode];
                }
            }
        }
        foreach ($this->runWidget($jquery, $fixtures) as $index => $result) {
            [$record, $url, $expected, $initial, $display, $radix, $group, $mode] = $cases[$index];
            $this->assertSame($initial, $result['initial'], $mode);
            $this->assertSame($display, $result['display'], $mode);
            $this->assertSame(['radixPoint' => $radix, 'groupSeparator' => $group, 'removeMaskOnSubmit' => true], $result['options']);
            $payload = ['amount' => str_replace('.', $radix, $expected)];
            $this->assertSame($payload, $result['native'], $mode);
            $this->assertSame($payload['amount'], $result['unmasked']);
            parse_str($result['serialized'], $serialized);
            $this->assertSame($payload, $serialized);
            $this->assertSaved($payload, $record, $url, (float) $expected);
        }
    }

    #[DataProvider('jqueryVersions')]
    public function test_empty_zero_readonly_disabled_and_decimal_controls(string $jquery): void
    {
        $cases = [
            ['before' => null, 'query' => ['options' => ['radixPoint' => ',']], 'display' => '', 'payload' => '', 'stored' => 0.0],
            ['before' => 1234.56, 'query' => ['options' => ['radixPoint' => ',']], 'edit' => '', 'display' => '', 'payload' => '', 'stored' => 0.0],
            ['before' => 0, 'query' => ['options' => ['radixPoint' => ','], 'mode' => 'readonly'], 'display' => '0,00', 'payload' => '0,00', 'stored' => 0.0],
            ['before' => -1234.56, 'query' => ['options' => ['radixPoint' => ','], 'mode' => 'readonly'], 'display' => '-1.234,56', 'payload' => '-1234,56', 'stored' => -1234.56],
            ['before' => -1234.56, 'query' => ['options' => ['radixPoint' => ','], 'mode' => 'disabled'], 'display' => '-1.234,56', 'stored' => -1234.56],
            ['before' => 1234.56, 'query' => ['kind' => 'decimal'], 'display' => '1234.56', 'payload' => '1234.56', 'stored' => 1234.56],
            ['before' => -0.56, 'query' => ['kind' => 'decimal'], 'display' => '-0.56', 'payload' => '-0.56', 'stored' => -0.56],
        ];
        $fixtures = [];
        $records = [];
        foreach ($cases as $case) {
            [$record, $url, $fixture] = $this->fixture($case['before'], $case['query'], isset($case['edit']) ? ['edit' => $case['edit']] : []);
            $fixtures[] = $fixture;
            $records[] = [$record, $url];
        }
        foreach ($this->runWidget($jquery, $fixtures) as $index => $result) {
            $case = $cases[$index];
            [$record, $url] = $records[$index];
            $mode = $case['query']['mode'] ?? '';
            $this->assertSame($case['display'], $result['display']);
            $this->assertSame($mode === 'readonly', $result['readonly']);
            $this->assertSame($mode === 'disabled', $result['disabled']);
            $payload = isset($case['payload']) ? ['amount' => $case['payload']] : [];
            $this->assertSame($payload, $result['native']);
            parse_str($result['serialized'], $serialized);
            $this->assertSame($payload, $serialized);
            if ($mode === 'disabled') {
                $this->put($url, $payload, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertJson(['status' => true]);
                $this->assertSame($case['stored'], (float) $record->fresh()->getRawOriginal('amount'));
            } else {
                $this->assertSaved($payload, $record, $url, $case['stored']);
            }
        }
    }

    #[DataProvider('jqueryVersions')]
    public function test_numeric_validation_still_rejects_localized_payload_before_hooks_and_prepare(string $jquery): void
    {
        [$record, $url, $fixture] = $this->fixture(1234.56, ['options' => ['radixPoint' => ','], 'validate' => 1], ['edit' => '-9876,54']);
        $result = $this->runWidget($jquery, [$fixture])[0];
        $this->assertSame(['amount' => '-9876,54'], $result['native']);
        $this->hooks = [];
        $this->from('/currency-radix/'.$record->id.'/edit')->put($url, $result['native'])
            ->assertRedirect()->assertSessionHasErrors('amount');
        $this->assertSame([], $this->hooks);
        $this->post('/currency-radix?'.http_build_query(['options' => ['radixPoint' => ','], 'validate' => 1]), $result['native'])
            ->assertRedirect()->assertSessionHasErrors('amount');
        $this->assertSame([], $this->hooks);
        $this->assertSame(1, CurrencyRadixRecord::count());
        $this->assertSame(1234.56, (float) $record->fresh()->getRawOriginal('amount'));
        $this->assertSame('-9876,54', $this->app['session']->getOldInput('amount'));
        $redisplay = $this->get('/currency-radix/'.$record->id.'/edit?'.http_build_query(['options' => ['radixPoint' => ',']]))->assertOk()->json();
        $this->assertSame('-9.876,54', $this->runWidget($jquery, [$redisplay])[0]['initial']);
        $this->app['session']->flush();
        // Canonical values still pass the same real numeric rule.
        $this->assertSaved(['amount' => '-9876.54'], $record, $url, -9876.54);
    }

    #[DataProvider('jqueryVersions')]
    public function test_saving_hook_can_supply_canonical_string_or_float_without_losing_decimal_point(string $jquery): void
    {
        foreach (['string', 'float'] as $rewrite) {
            [$record, $url, $fixture] = $this->fixture(1234.56, ['options' => ['radixPoint' => ','], 'rewrite' => $rewrite]);
            $result = $this->runWidget($jquery, [$fixture])[0];
            $this->assertSame(['amount' => '1234,56'], $result['native']);
            $this->assertSaved($result['native'], $record, $url, -9876.54);
        }
    }

    public function test_prepare_only_normalizes_declared_string_radix_and_keeps_float_cast_semantics(): void
    {
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            foreach ([null, '', false, true, 0, 12, -12, 12.34, -0.56, [], [1]] as $value) {
                $this->assertSame((float) $value, (new Currency('amount'))->options(['radixPoint' => ','])->prepare($value));
            }
            foreach ([
                [',', '1234,56', 1234.56], [',', '-0,56', -0.56], [',', '0,00', 0.0],
                [',', '1234.56', 1234.56], [',', '-0.56', -0.56], [',', '1.234,56', 1.234],
                [',', 'EUR 1234,56', 0.0], [',', '1,23,45', 1.23],
                [':', '1234:56', 1234.56], [':', '-0:56', -0.56],
                ['.', '1234.56', 1234.56], ['.', '1234,56', 1234.0],
            ] as [$radix, $value, $expected]) {
                $this->assertSame($expected, (new Currency('amount'))->options(['radixPoint' => $radix])->prepare($value));
            }
            // Invalid/nonliteral widget options are not coerced or executed server-side.
            foreach ([null, '', false, true, 0, 1, [], [','], new \stdClass(), function () { return ','; }, 'function(){return ",";}'] as $radix) {
                $this->assertSame(1234.0, (new Currency('amount'))->options(['radixPoint' => $radix])->prepare('1234,56'));
            }
            foreach ([null, '', '1234.56', '-0.56', '0.00', '1234,56'] as $value) {
                $this->assertSame((float) $value, (new Currency('amount'))->prepare($value));
            }
        } finally {
            restore_error_handler();
        }
    }
}

class CurrencyRadixRecord extends Model
{
    protected $table = 'currency_radix_records';
    protected $guarded = [];
    public $timestamps = false;
}
