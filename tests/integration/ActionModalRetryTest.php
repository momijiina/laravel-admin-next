<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Actions\Action;
use Encore\Admin\Actions\BatchAction;
use Encore\Admin\Actions\GridAction;
use Encore\Admin\Actions\RowAction;
use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Controllers\HandleController;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class ActionModalRetryTest extends TestCase
{
    private array $previousHtml;
    private array $previousScripts;
    private const ACTIONS = [
        'action' => [ModalRetryAction::class, ModalRetryOtherAction::class],
        'row' => [ModalRetryRowAction::class, ModalRetryOtherRowAction::class],
        'batch' => [ModalRetryBatchAction::class, ModalRetryOtherBatchAction::class],
    ];

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
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->post('/action-modal-retry', function (Request $request) {
            if ($request->input('title') === 'HTTP failure') {
                return response()->json(['message' => 'Temporary failure'], 500);
            }

            return app(HandleController::class)->handleAction($request);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousHtml = Admin::$html;
        $this->previousScripts = Admin::$script;
        Schema::create('action_modal_retry_records', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->integer('writes')->default(0);
        });
        ModalRetryRecord::insert([['title' => 'Original first'], ['title' => 'Original second']]);
        $this->app['request']->setLaravelSession($this->app['session']->driver());
    }

    protected function tearDown(): void
    {
        Admin::$html = $this->previousHtml;
        Admin::$script = $this->previousScripts;
        parent::tearDown();
    }

    public static function scenarios(): iterable
    {
        foreach (['action', 'row', 'batch'] as $type) {
            foreach (['shipped', 'modern'] as $jquery) {
                foreach ([false, true] as $confirm) {
                    $modes = ['http-error', 'network-error', 'status-false', 'success'];
                    if ($confirm) {
                        $modes = array_merge($modes, ['cancel', 'escape', 'pending-escape-error', 'pending-backdrop-error', 'pending-escape-success', 'pending-backdrop-success']);
                    }
                    foreach ($modes as $mode) {
                        yield "$type / $jquery / ".($confirm ? 'confirm' : 'direct')." / $mode" => [$type, $jquery, $confirm, $mode];
                    }
                }
            }
        }
    }

    #[DataProvider('scenarios')]
    public function test_failed_and_cancelled_action_forms_can_retry_without_resetting_other_forms(string $type, string $jquery, bool $confirm, string $mode): void
    {
        Admin::$html = Admin::$script = [];
        $html = '';
        $actions = [];
        foreach (self::ACTIONS[$type] as $index => $class) {
            $action = (new $class())->configure($index + 1, $index === 0 && $confirm);
            if ($action instanceof GridAction) {
                $action->setGrid(new Grid(new ModalRetryRecord()));
            }
            if ($action instanceof RowAction) {
                $action->setRow(ModalRetryRecord::findOrFail($index + 1));
            }
            $actions[] = $action;
            $html .= $action->render();
        }
        $fixture = [
            'html' => $html.implode('', Admin::$html),
            'scriptHtml' => Admin::script()->render(),
            'type' => $type, 'jquery' => $jquery, 'confirm' => $confirm, 'mode' => $mode,
        ];
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $payload = $actions[0]->parameters() + ['_action' => str_replace('\\', '_', get_class($actions[0])), '_key' => '1'];
        foreach (['http-error' => 'HTTP failure', 'status-false' => 'x', 'success' => 'Corrected title'] as $response => $title) {
            $fixture['responses'][$response] = $this->post('/action-modal-retry', $payload + ['title' => $title], $headers)
                ->assertStatus($response === 'http-error' ? 500 : 200)->json();
        }
        $this->assertFalse($fixture['responses']['status-false']['status']);
        $this->assertTrue($fixture['responses']['success']['status']);
        $this->assertSame(1, ModalRetryRecord::find(1)->writes);
        ModalRetryRecord::find(1)->update(['title' => 'Original first', 'writes' => 0]);

        $process = new Process(['node', __DIR__.'/javascript/action-modal-retry.cjs']);
        $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $dismissedSuccess = str_starts_with($mode, 'pending-') && str_ends_with($mode, '-success');
        $this->assertSame($dismissedSuccess ? 1 : 2, $result['redirects']);
        $this->assertSame(0, $result['pending']);
        $expectedRequests = in_array($mode, ['http-error', 'network-error', 'status-false']) ? 4
            : (str_starts_with($mode, 'pending-') && !$dismissedSuccess ? 3 : 2);
        $this->assertCount($expectedRequests, $result['requests']);
        // Replay the actual emitted FormData in completion order through the
        // production controller and assert persistence after every response.
        foreach ($result['completed'] as $completion) {
            $request = $result['requests'][$completion['index']];
            $this->assertSame('/action-modal-retry', $request['url']);
            $this->assertSame('POST', $request['method']);
            $data = array_column($request['data'], 1, 0);
            $this->assertSame('synthetic-token', $data['_token']);
            $this->assertSame($type === 'action', !array_key_exists('_model', $data));
            if ($type !== 'action') {
                $this->assertSame($data['record'], $data['_key']);
                $this->assertSame(str_replace('\\', '_', ModalRetryRecord::class), $data['_model']);
            }
            $before = ModalRetryRecord::orderBy('id')->get()->toArray();
            if ($completion['response'] === 'network-error') {
                // No server receipt is asserted for an interrupted transport.
                $this->assertSame($before, ModalRetryRecord::orderBy('id')->get()->toArray());
                continue;
            }
            $response = $this->post($request['url'], $data, $headers)
                ->assertStatus($completion['response'] === 'http-error' ? 500 : 200);
            $this->assertSame($fixture['responses'][$completion['response']], $response->json());
            if ($completion['response'] === 'success') {
                $expected = $before;
                $key = (int) $data['record'] - 1;
                $expected[$key]['title'] = $data['title'];
                ++$expected[$key]['writes'];
                $this->assertSame($expected, ModalRetryRecord::orderBy('id')->get()->toArray());
            } else {
                $this->assertSame($before, ModalRetryRecord::orderBy('id')->get()->toArray());
            }
        }
        $this->assertSame(['Corrected title', 'Other pending title'], ModalRetryRecord::orderBy('id')->pluck('title')->all());
        $this->assertSame([1, 1], ModalRetryRecord::orderBy('id')->pluck('writes')->all());
    }
}

trait ModalRetryForm
{
    public $name = 'Retry action';
    private int $fixtureKey = 1;
    private bool $confirmation = false;

    public function configure(int $key, bool $confirmation)
    {
        $this->fixtureKey = $key;
        $this->confirmation = $confirmation;
        $this->selector = '.modal-retry-'.$key;

        return $this;
    }

    public function form($row = null)
    {
        $this->text('title', 'Title')->rules('required|min:3')->value('Initial title');
        if ($this->confirmation) {
            $this->confirm('Apply the change?');
        }
    }

    public function getHandleRoute()
    {
        return '/action-modal-retry';
    }

    public function parameters()
    {
        return parent::parameters() + ['record' => $this->fixtureKey];
    }

    public function html()
    {
        return '<button class="'.$this->getElementClass().'">Open</button>';
    }

    protected function saveRecords($records, Request $request)
    {
        foreach ($records as $record) {
            $record->update(['title' => $request->input('title'), 'writes' => $record->writes + 1]);
        }

        return $this->response()->success('Saved')->redirect('/saved');
    }
}

class ModalRetryAction extends Action
{
    use ModalRetryForm;

    public function handle(Request $request)
    {
        return $this->saveRecords([ModalRetryRecord::findOrFail($request->input('record'))], $request);
    }
}
class ModalRetryOtherAction extends ModalRetryAction
{
}

class ModalRetryRowAction extends RowAction
{
    use ModalRetryForm;

    public function handle($model, Request $request)
    {
        return $this->saveRecords([$model], $request);
    }
}
class ModalRetryOtherRowAction extends ModalRetryRowAction
{
}

class ModalRetryBatchAction extends BatchAction
{
    use ModalRetryForm;

    public function handle($models, Request $request)
    {
        return $this->saveRecords($models, $request);
    }
}
class ModalRetryOtherBatchAction extends ModalRetryBatchAction
{
}

class ModalRetryRecord extends Model
{
    protected $table = 'action_modal_retry_records';
    protected $guarded = [];
    public $timestamps = false;
}
