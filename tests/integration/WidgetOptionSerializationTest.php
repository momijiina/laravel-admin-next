<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form\Field\File;
use Encore\Admin\Form\Field\Mobile;
use Encore\Admin\Form\Field\Text;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class WidgetOptionSerializationTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('w', 32)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('admin.upload.disk', 'local');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['view']->share('errors', new ViewErrorBag());
        $this->assertSame(realpath(__DIR__.'/../../src/helpers.php'), (new \ReflectionFunction('json_encode_options'))->getFileName());
    }

    public static function inputmaskCases(): iterable
    {
        foreach (['shipped', 'modern'] as $jquery) {
            foreach (['text', 'mobile'] as $field) {
                yield $jquery.' '.$field => [$jquery, $field];
            }
        }
    }

    public static function jqueryVersions(): iterable
    {
        yield 'shipped jQuery 2.1.4' => ['shipped'];
        yield 'modern jQuery 3.7.1' => ['modern'];
    }

    private function renderField(string $kind, array $options): array
    {
        // Any production diagnostic, including a NULL-to-string deprecation, fails.
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            if ($kind === 'text') {
                $field = new Text('code');
                $this->assertSame($field, $field->inputmask($options));
            } elseif ($kind === 'mobile') {
                $field = (new Mobile('code'))->options($options);
            } else {
                $field = (new File('document'))->options($options);
            }
            return ['html' => '<form>'.$field->render().'</form>', 'script' => $field->getScript()];
        } finally {
            restore_error_handler();
        }
    }

    private function runWidget(string $jquery, array $fixture, string $mode): array
    {
        $process = new Process(['node', __DIR__.'/javascript/widget-option-serialization.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode([
            'fixture' => $fixture, 'jquery' => $jquery, 'mode' => $mode,
            'assets' => __DIR__.'/../../resources/assets',
        ], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $result['logs']);
        return $result;
    }

    #[DataProvider('inputmaskCases')]
    public function test_distinct_nested_validators_drive_the_shipped_inputmask(string $jquery, string $kind): void
    {
        $fixture = $this->renderField($kind, [
            'mask' => 'XY',
            'definitions' => [
                'X' => ['validator' => 'function(chrs){return /^[A-Z]$/.test(chrs);}', 'cardinality' => 1],
                'Y' => ['validator' => 'function(chrs){return /^[0-9]$/.test(chrs);}', 'cardinality' => 1],
            ],
            'metadata' => ['literal' => '%validator%', 'reserved' => '%__laravel_admin_callback_0__%', 'unicode' => '日本語'],
        ]);
        $result = $this->runWidget($jquery, $fixture, 'callbacks');
        $this->assertSame([true, false, false, true], $result['validators']);
        $this->assertSame([
            ['input' => 'A1', 'value' => 'A1', 'native' => ['code' => 'A1'], 'complete' => true],
            ['input' => 'AB', 'value' => 'A_', 'native' => ['code' => 'A_'], 'complete' => false],
            ['input' => 'B2', 'value' => 'B2', 'native' => ['code' => 'B2'], 'complete' => true],
        ], $result['states']);
        $this->assertSame($result['control'], $result['states']);
        $this->assertSame(['literal' => '%validator%', 'reserved' => '%__laravel_admin_callback_0__%', 'unicode' => '日本語'], $result['metadata']);
    }

    #[DataProvider('inputmaskCases')]
    public function test_nullable_and_scalar_inputmask_options_retain_their_types(string $jquery, string $kind): void
    {
        $fixture = $this->renderField($kind, [
            'mask' => '99-99', 'onBeforeMask' => null, 'repeat' => 0,
            'clearIncomplete' => false, 'showMaskOnHover' => true,
            'metadata' => [null, false, 0, '0', '', '日本語', 'a/b', "a\nb", ' function() {}', 'function () {}'],
        ]);
        $result = $this->runWidget($jquery, $fixture, 'ordinary');
        $this->assertSame([null, 0, false, true], $result['options']);
        $this->assertSame([null, false, 0, '0', '', '日本語', 'a/b', "a\nb", ' function() {}', 'function () {}'], $result['metadata']);
        $this->assertSame([['input' => '1234', 'value' => '12-34', 'native' => ['code' => '12-34'], 'complete' => true]], $result['states']);
    }

    #[DataProvider('jqueryVersions')]
    public function test_file_initializer_preserves_distinct_ajax_callback_configuration(string $jquery): void
    {
        $fixture = $this->renderField('file', [
            'ajaxSettings' => ['success' => 'function(){return "upload";}', 'timeout' => 0],
            'ajaxDeleteSettings' => ['success' => 'function(){return "delete";}', 'cache' => false],
            'metadata' => ['literal' => '%success%', 'reserved' => '%__laravel_admin_callback_0__%', 'null' => null, 'unicode' => '日本語',
                'object' => (object) ['marker' => '%__laravel_admin_callback_1__%', 'literal' => 'function(){return 1;}']],
        ]);
        // Capture the real File initializer's argument; no file transfer is performed.
        $result = $this->runWidget($jquery, $fixture, 'file');
        $this->assertSame(1, $result['calls']);
        $this->assertSame(['upload', 'delete'], $result['callbacks']);
        $this->assertSame([0, false], $result['options']);
        $this->assertSame(['literal' => '%success%', 'reserved' => '%__laravel_admin_callback_0__%', 'null' => null, 'unicode' => '日本語',
            'object' => ['marker' => '%__laravel_admin_callback_1__%', 'literal' => 'function(){return 1;}']], $result['metadata']);
        $this->assertSame([true, true, false, false, false, false], $result['defaults']);
        $this->assertSame(['document' => '_file_del_', '_file_del_' => '', '_method' => 'PUT'], $result['deleteData']);
        $this->assertSame(csrf_token(), $result['token']);
        $this->assertSame(['type' => 'file', 'name' => 'document'], $result['element']);
    }
}
