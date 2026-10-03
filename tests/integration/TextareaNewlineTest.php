<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\Textarea;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class TextareaNewlineTest extends TestCase
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
        // Isolate the renderer contract from intentional application whitespace policy.
        $this->withoutMiddleware([TrimStrings::class, ConvertEmptyStringsToNull::class]);
        Schema::create('textarea_records', function ($table) {
            $table->increments('id');
            $table->text('body')->nullable();
        });
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->app['router']->get('textarea-records/{id}/edit', function ($id) {
            $form = new Form(new TextareaRecord());
            $field = $form->textarea('body');
            $field->fill(TextareaRecord::findOrFail($id)->toArray());
            return '<form method="post">'.$field->render().'</form>';
        })->middleware('web');
        $this->app['router']->put('textarea-records/{id}', function ($id) {
            $form = new Form(new TextareaRecord());
            $form->textarea('body');
            return $form->update($id);
        })->middleware('web');
    }

    public static function texts(): array
    {
        return [
            'one leading LF' => ["\nFirst line\nSecond line"],
            'two leading LFs' => ["\n\nFirst line"],
            'only one LF' => ["\n"],
            'only two LFs' => ["\n\n"],
            'internal and trailing LF' => ["First line\nSecond line\n"],
            'empty' => [''],
            'zero' => ['0'],
            'spaces' => ['  ordinary text  '],
            'only spaces' => ['   '],
            'Unicode' => ["\n日本語 café 🌿\n第二行"],
            'escaped markup' => ["\n</textarea><b id=escaped>\"&'</b>"],
        ];
    }

    private function parse(string $html): array
    {
        $process = new Process(['node', __DIR__.'/javascript/textarea-newline.cjs']);
        $process->setInput(json_encode(['html' => $html], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $result['count']);
        $this->assertFalse($result['escapedElement']);
        return $result;
    }

    #[DataProvider('texts')]
    public function test_unchanged_native_and_jquery_submissions_preserve_text($text): void
    {
        $record = TextareaRecord::create(['body' => $text]);
        $url = '/textarea-records/'.$record->id;
        $parsed = $this->parse($this->get($url.'/edit')->assertOk()->getContent());
        $this->assertSame($text, $parsed['value']);
        $this->assertSame($text, $parsed['defaultValue']);
        $this->assertSame(['body' => $text], $parsed['native']);
        $this->put($url, $parsed['native'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJson(['status' => true]);
        $this->assertSame($text, $record->fresh()->getRawOriginal('body'));

        // Shipped jQuery's serialize() uses CRLF, unlike native FormData entries.
        parse_str($parsed['jquery'], $serialized);
        $expected = str_replace("\n", "\r\n", $text);
        $this->assertSame(['body' => $expected], $serialized);
        $this->put($url, $serialized, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertJson(['status' => true]);
        $this->assertSame($expected, $record->fresh()->getRawOriginal('body'));
    }

    public function test_value_sources_old_input_precedence_and_attributes(): void
    {
        $this->app['request']->setLaravelSession($this->app['session']->driver());
        foreach (['value', 'default', 'format', 'old', 'old empty', 'old zero'] as $mode) {
            $this->app['session']->flashInput([]);
            $field = new Textarea('body');
            $field->rows(7)->placeholder('Write "text"')->attribute('data-purpose', 'multiline');
            $expected = "\nExpected 日本語";
            if ($mode === 'value') $field->value($expected);
            if ($mode === 'default') $field->default($expected);
            if ($mode === 'format') {
                $field->customFormat(fn ($value) => "\nExpected ".$value);
                $field->fill(['body' => '日本語']);
            }
            if (str_starts_with($mode, 'old')) {
                $field->value('model value')->default('default value');
                if ($mode === 'old empty') $expected = '';
                if ($mode === 'old zero') $expected = '0';
                $this->app['session']->flashInput(['body' => $expected]);
            }
            $parsed = $this->parse('<form>'.$field->render().'</form>');
            $this->assertSame($expected, $parsed['value'], $mode);
            $this->assertSame($expected, $parsed['native']['body'], $mode);
            $this->assertSame(['name' => 'body', 'rows' => '7', 'placeholder' => 'Write "text"', 'purpose' => 'multiline'], $parsed['attributes']);
        }
    }

    public function test_consumer_subclass_and_existing_array_rendering(): void
    {
        $field = (new ConsumerTextarea('body'))->value("\nSubclass value");
        $parsed = $this->parse('<form>'.$field->render().'</form>');
        $this->assertSame("\nSubclass value", $parsed['value']);
        $array = ['label' => '日本語', 'items' => [0, 'text']];
        $field = (new Textarea('body'))->value($array);
        $parsed = $this->parse('<form>'.$field->render().'</form>');
        $this->assertSame(json_encode($array, JSON_PRETTY_PRINT), $parsed['value']);
    }

    public function test_html_parser_normalizes_carriage_returns_without_server_rewriting(): void
    {
        $field = (new Textarea('body'))->value("\r\nFirst\rSecond\r\n");
        $parsed = $this->parse('<form>'.$field->render().'</form>');
        $this->assertSame("\nFirst\nSecond\n", $parsed['value']);
        $this->assertSame("\nFirst\nSecond\n", $parsed['native']['body']);
    }
}

class TextareaRecord extends Model
{
    protected $table = 'textarea_records';
    protected $guarded = [];
    public $timestamps = false;
}

class ConsumerTextarea extends Textarea
{
    protected $view = 'admin::form.textarea';
}
