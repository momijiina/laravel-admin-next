<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Orchestra\Testbench\TestCase;
use Symfony\Component\Process\Process;

class TagsSeparatorsTest extends TestCase
{
    private ?array $separators = null;
    private array $previousScript = [];

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
        $app['config']->set('session.driver', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/tag-separators/create', fn () => $this->renderForm());
            $router->get('/tag-separators/{id}/edit', fn ($id) => $this->renderForm($id));
            $router->post('/tag-separators', fn () => $this->form()->store());
            $router->put('/tag-separators/{id}', fn ($id) => $this->form()->update($id));
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScript = Admin::$script;
        Schema::create('tags_separator_records', function ($table) {
            $table->increments('id');
            $table->text('tags')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Admin::$script = $this->previousScript;
        parent::tearDown();
    }

    private function form(): Form
    {
        $form = new Form(new TagsSeparatorRecord());
        $form->setAction('/tag-separators');
        $form->tools(function ($tools) {
            $tools->disableList();
            $tools->disableDelete();
            $tools->disableView();
        });
        $field = $form->tags('tags');
        if ($this->separators !== null) {
            $field->separators($this->separators);
        }
        return $form;
    }

    private function renderForm($id = null)
    {
        $previous = Admin::$script;
        try {
            Admin::$script = [];
            $form = $this->form();
            return response()->json([
                'html' => $id === null ? $form->render() : $form->edit($id)->render(),
                'scriptHtml' => Admin::script()->render(),
            ]);
        } finally {
            Admin::$script = $previous;
        }
    }

    private function widget(array $fixture, array $actions = []): array
    {
        $process = new Process(['node', __DIR__.'/javascript/tags-separators.cjs']);
        $process->setTimeout(120);
        $process->setInput(json_encode($fixture + $actions, JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function separatorCases(): array
    {
        return [
            'default' => [null, ';'],
            'empty retains defaults' => [[], ';'],
            'ordinary custom' => [['|'], '|'],
            'closing bracket' => [[']'], ']'],
            'opening bracket' => [['['], '['],
            'backslash' => [['\\'], '\\'],
            'caret' => [['^'], '^'],
            'literal hyphen between letters' => [['a', '-', 'z'], '-'],
            'slash' => [['/'], '/'],
            'quotes' => [["'", '"'], '"'],
            'line feed' => [["\n"], "\n"],
            'carriage return' => [["\r"], "\r"],
            'unicode line separators' => [["\u{2028}", "\u{2029}"], "\u{2028}"],
            'regex punctuation' => [['.', '*', '+', '?', '$', '|', '(', ')', '{', '}'], '$'],
            'unicode punctuation' => [['、', '；'], '、'],
        ];
    }

    #[DataProvider('separatorCases')]
    public function test_literal_separator_initialization_selection_and_form_round_trips(?array $separators, string $separator): void
    {
        $this->separators = $separators;
        $fixture = $this->get('/tag-separators/create')->assertOk()->json();
        $created = $this->widget($fixture, ['term' => 'berry'.$separator]);
        $this->assertNull($created['plain'], 'Ordinary text is not itself a separator');
        $this->assertSame(['id' => 'berry', 'text' => 'berry'], $created['delimited']);
        $this->assertSame(['berry'], $created['selected']);
        $this->assertSame($separators ?: [',', ';', '，', '；', ' '], $created['separators']);
        parse_str($created['query'], $input);
        $this->assertSame(['berry', ''], $input['tags']);
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $this->post('/tag-separators', $input, $headers)->assertOk()->assertJson(['status' => true]);
        $record = TagsSeparatorRecord::latest('id')->firstOrFail();
        $this->assertSame('berry', $record->tags);

        $edited = $this->widget($this->get('/tag-separators/'.$record->id.'/edit')->assertOk()->json(), [
            'term' => 'kiwi'.$separator, 'clear' => true,
        ]);
        $this->assertSame(['berry'], $edited['initial']);
        $this->assertSame(['kiwi'], $edited['selected']);
        parse_str($edited['query'], $input);
        $this->put('/tag-separators/'.$record->id, $input, $headers)->assertOk()->assertJson(['status' => true]);
        $this->assertSame('kiwi', $record->fresh()->tags);
        $reopened = $this->widget($this->get('/tag-separators/'.$record->id.'/edit')->assertOk()->json());
        $this->assertSame(['kiwi'], $reopened['initial']);
        $this->assertSame(['kiwi'], $reopened['selected']);
    }

    public function test_callback_preserves_internal_separators_and_removes_only_trailing_literal_characters(): void
    {
        $this->separators = [']', '^', '-', '\\'];
        $result = $this->widget($this->get('/tag-separators/create')->assertOk()->json(), [
            'probes' => ['berry', 'berry^inside', 'berry]^--\\', '  berry^^  ', '0]'],
        ]);
        $this->assertSame([
            null,
            ['id' => 'berry^inside', 'text' => 'berry^inside'],
            ['id' => 'berry', 'text' => 'berry'],
            ['id' => 'berry', 'text' => 'berry'],
            ['id' => '0', 'text' => '0'],
        ], $result['probes']);
        $this->assertSame([], $result['selected']);
    }
}

class TagsSeparatorRecord extends Model
{
    protected $table = 'tags_separator_records';
    protected $guarded = [];
    public $timestamps = false;
}
