<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Encore\Admin\Form\Field\File;
use Encore\Admin\Form\Field\Image;
use Encore\Admin\Form\Field\MultipleFile;
use Encore\Admin\Form\Field\MultipleImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class UploadPreviewTextTest extends TestCase
{
    private array $scripts;

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
        $app['config']->set('app.locale', 'en');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('admin.upload.disk', 'local');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        $this->scripts = Admin::$script;
        parent::setUp();
        $this->app['view']->share('errors', new ViewErrorBag());
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            Admin::$script = $this->scripts;
        }
    }

    public static function fieldCases(): iterable
    {
        foreach (['shipped', 'modern'] as $jquery) {
            foreach ([File::class, Image::class, MultipleFile::class, MultipleImage::class] as $class) {
                yield $jquery.' '.class_basename($class) => [$class, $jquery];
            }
        }
    }

    public static function previewTypes(): iterable
    {
        foreach (['txt' => 'text', 'html' => 'html', 'pdf' => 'pdf', 'mp3' => 'audio', 'mp4' => 'video', 'bin' => 'other'] as $extension => $type) {
            foreach ([File::class, MultipleFile::class] as $class) {
                yield class_basename($class).' '.$type => [$class, $extension, $type];
            }
        }
    }

    private function filename(string $extension = 'png'): string
    {
        return '日本語 &copy; &amp; <b data-preview-marker="name"> "double" \'single\'.'.$extension;
    }

    private function field(string $class, array $paths): Field
    {
        $field = (new $class('documents'))->removable();
        $field->value($field instanceof MultipleFile ? $paths : reset($paths));

        return $field;
    }

    private function renderField(Field $field): array
    {
        // Any production diagnostic, including modern PHP deprecations, fails.
        set_error_handler(function ($severity, $message, $file, $line) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
        try {
            return ['html' => '<form>'.$field->render().'</form>', 'script' => $field->getScript()];
        } finally {
            restore_error_handler();
        }
    }

    private function runWidget(array $fixture, string $jquery = 'shipped'): array
    {
        $process = new Process(['node', __DIR__.'/javascript/upload-preview-text.cjs']);
        $process->setTimeout(30);
        $process->setInput(json_encode($fixture + ['jquery' => $jquery], JSON_THROW_ON_ERROR));
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $result['errors']);

        return $result;
    }

    private function assertLiteralPreview(array $preview, string $path, string $url, string $type, $key, bool $downloadable = true): void
    {
        $caption = basename($path);
        $this->assertSame($caption, $preview['caption']);
        $this->assertSame($caption, $preview['title']);
        $this->assertSame(0, $preview['captionChildren']);
        $this->assertSame(0, $preview['markerNodes']);
        $this->assertSame($caption, $preview['zoomCaption']);
        $this->assertSame($caption, $preview['zoomTitle']);
        $this->assertSame(0, $preview['zoomMarkerNodes']);
        $this->assertSame($key, $preview['key']);
        $this->assertSame((string) $key, $preview['removeKey']);
        $this->assertSame($type, $preview['type']);
        $this->assertSame($type === 'other' ? null : $url, $preview['content']);
        $this->assertSame($downloadable ? $url : null, $preview['downloadUrl']);
        $this->assertSame($downloadable ? $caption : null, $preview['downloadName']);
    }

    #[DataProvider('fieldCases')]
    public function test_saved_image_captions_and_links_keep_literal_characters(string $class, string $jquery): void
    {
        $paths = [2 => 'saved/'.$this->filename(), 9 => 'saved/plain image.png'];
        $field = $this->field($class, $paths);
        $original = $field->value();
        $fixture = $this->renderField($field);
        $again = $this->renderField($field);
        $this->assertSame($fixture, $again, 'Rendering again must not add another layer of escaping.');
        $this->assertSame($original, $field->value());
        $result = $this->runWidget($again, $jquery);
        $expected = $field instanceof MultipleFile ? $paths : [0 => reset($paths)];
        $this->assertCount(count($expected), $result['previews']);
        foreach (array_values($expected) as $index => $path) {
            $url = $field->objectUrl($path);
            $this->assertStringEndsWith($path, $url, 'The public storage URL remains raw.');
            $this->assertLiteralPreview($result['previews'][$index], $path, $url, 'image', array_keys($expected)[$index]);
        }
        if (!$field instanceof MultipleFile) {
            $this->assertSame(basename(reset($paths)), $result['inputCaption']);
        }
        $this->assertSame([true, false, false, false, false], $result['defaults']);
        $this->assertSame(['documents' => '_file_del_', '_file_del_' => '', '_method' => 'PUT'], $result['deleteData']);
    }

    #[DataProvider('previewTypes')]
    public function test_non_image_previews_apply_the_widgets_own_text_boundary(string $class, string $extension, string $type): void
    {
        $path = 'saved/'.$this->filename($extension);
        $field = $this->field($class, [$path]);
        $result = $this->runWidget($this->renderField($field));
        $this->assertLiteralPreview($result['previews'][0], $path, $field->objectUrl($path), $type, 0);
    }

    public function test_absolute_urls_keep_literal_query_parameters_and_downloads_can_be_disabled(): void
    {
        $path = 'https://files.invalid/image.png?label="quoted"&copy;=literal&amp;=entity';
        $field = $this->field(Image::class, [$path])->downloadable(false);
        $this->assertSame($path, $field->objectUrl($path));
        $result = $this->runWidget($this->renderField($field));
        $this->assertLiteralPreview($result['previews'][0], $path, $path, 'image', 0, false);
    }

    public static function textOverrides(): iterable
    {
        yield 'effective text' => [['type' => 'text'], true, 'text'];
        yield 'global HTML content' => [['type' => 'text'], false, 'generic'];
        yield 'per-preview HTML content' => [['type' => 'text', 'previewAsData' => false], true, 'generic'];
        yield 'per-preview text data' => [['type' => 'text', 'previewAsData' => true], false, 'text'];
    }

    #[DataProvider('textOverrides')]
    public function test_explicit_preview_config_controls_the_effective_text_mode(array $config, bool $asData, string $type): void
    {
        $path = 'saved/'.$this->filename();
        $config += ['caption' => 'Explicit caption', 'key' => 71, 'downloadUrl' => false];
        $field = $this->field(File::class, [$path])->options([
            'initialPreviewConfig' => [$config], 'initialPreviewAsData' => $asData,
        ]);
        $result = $this->runWidget($this->renderField($field));
        $this->assertSame([$config], $result['config']);
        $this->assertSame($type, $result['previews'][0]['type']);
        $this->assertSame($field->objectUrl($path), $result['previews'][0]['content']);
        $this->assertSame(0, $result['previews'][0]['markerNodes']);
        $this->assertSame('Explicit caption', $result['previews'][0]['caption']);
    }

    public function test_explicit_multiple_file_preview_html_and_config_remain_caller_controlled(): void
    {
        $preview = '<span class="explicit-preview">Caller HTML</span>';
        $config = ['caption' => '<strong>Caller caption</strong>', 'key' => 'caller-key', 'downloadUrl' => false];
        $field = $this->field(MultipleFile::class, ['saved/default.png'])->options([
            'initialPreview' => [$preview], 'initialPreviewConfig' => [$config], 'initialPreviewAsData' => false,
        ]);
        $result = $this->runWidget($this->renderField($field));
        $this->assertSame([$preview], $result['initialPreview']);
        $this->assertSame([$config], $result['config']);
        $this->assertSame('Caller HTML', $result['previews'][0]['content']);
        $this->assertSame(1, $result['previews'][0]['captionChildren']);
        $this->assertSame('Caller caption', $result['previews'][0]['caption']);
    }

    public function test_related_paths_keep_the_related_model_keys_and_original_values(): void
    {
        $paths = [
            ['asset_key' => 41, 'path' => 'saved/'.$this->filename()],
            ['asset_key' => 73, 'path' => 'saved/ordinary.txt'],
        ];
        $field = (new MultipleFile('assets'))->pathColumn('path')->removable()
            ->setForm(new Form(new UploadPreviewParent()))->value($paths);
        $result = $this->runWidget($this->renderField($field));
        $this->assertSame($paths, $field->value());
        foreach ($paths as $index => $record) {
            $this->assertSame($field->objectUrl($record['path']), $field->objectUrl($record));
            $this->assertLiteralPreview($result['previews'][$index], $record['path'], $field->objectUrl($record), $index === 0 ? 'image' : 'text', $record['asset_key']);
        }
    }

    #[DataProvider('fieldCases')]
    public function test_empty_upload_fields_still_initialize_without_previews(string $class, string $jquery): void
    {
        $field = new $class('documents');
        $result = $this->runWidget($this->renderField($field), $jquery);
        $this->assertSame([], $result['previews']);
        $this->assertSame('', $result['inputCaption']);
        $this->assertNull($field->value());
    }
}

class UploadPreviewParent extends Model
{
    public function assets()
    {
        return $this->hasMany(UploadPreviewAsset::class, 'parent_id');
    }
}

class UploadPreviewAsset extends Model
{
    protected $primaryKey = 'asset_key';
}
