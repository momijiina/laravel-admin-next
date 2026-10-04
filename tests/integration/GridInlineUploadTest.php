<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class GridInlineUploadTest extends TestCase
{
    private array $packageState = [];
    private ?string $uploadRoot = null;
    private const MODELS = [
        'primary' => GridInlineUploadItem::class,
        'secondary' => GridInlineUploadOtherItem::class,
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function defineRoutes($router)
    {
        $router->middleware('web')->group(function ($router) {
            $router->get('/grid-inline-upload/render/{mode}', function ($mode) {
                $previousScript = Admin::$script;
                $previousHtml = Admin::$html;
                try {
                    Admin::$script = Admin::$html = [];
                    Admin::script('window.inlineUploadBefore = true;');
                    $html = '';
                    foreach ($this->grids($mode) as $resource) {
                        $model = self::MODELS[$resource];
                        $grid = new Grid(new $model());
                        $grid->setResource('/grid-inline-upload/'.$resource);
                        $grid->model()->orderBy('id');
                        if ($mode === 'single') {
                            $grid->model()->where('id', 1);
                        }
                        $grid->column('id');
                        foreach ($this->fields($mode, request()->boolean('multiple')) as $field) {
                            $column = $grid->column($field);
                            if (request()->boolean('multiple')) {
                                $column->display(function ($files) {
                                    return count($files).' files';
                                })->uplaodMany();
                            } else {
                                $column->upload();
                            }
                        }
                        $grid->disableActions()->disableRowSelector()->disableCreateButton()->disableExport()->disableFilter();
                        $html .= $grid->render();
                    }
                    Admin::script('window.inlineUploadAfter = true;');

                    return response()->json([
                        'html' => $html.Admin::html()->render(),
                        'scriptHtml' => Admin::script()->render(),
                    ]);
                } finally {
                    Admin::$script = $previousScript;
                    Admin::$html = $previousHtml;
                }
            });
            $router->put('/grid-inline-upload/{resource}/{id}', function ($resource, $id) {
                $model = self::MODELS[$resource];
                $form = new Form(new $model());
                $form->file('document');
                $form->file('alternate');
                $form->multipleFile('documents');
                $form->multipleFile('alternates');

                return $form->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        foreach ([
            Admin::class => ['script', 'html', 'style'],
            Form::class => ['snakeAttributes'],
            Grid::class => ['snakeAttributes'],
            Grid\Column::class => ['htmlAttributes', 'rowAttributes', 'model', 'originalGridModels'],
        ] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            $this->withoutExceptionHandling();
            $this->uploadRoot = sys_get_temp_dir().'/admin-grid-inline-upload-'.bin2hex(random_bytes(8));
            mkdir($this->uploadRoot.'/uploads', 0777, true);
            config([
                'filesystems.disks.inline_upload_test' => [
                    'driver' => 'local', 'root' => $this->uploadRoot, 'throw' => true,
                ],
                'admin.upload.disk' => 'inline_upload_test',
                'admin.upload.directory.file' => 'uploads',
            ]);
            foreach (self::MODELS as $resource => $model) {
                Schema::create((new $model())->getTable(), function ($table) {
                    $table->increments('id');
                    $table->string('document');
                    $table->string('alternate');
                    $table->text('documents');
                    $table->text('alternates');
                });
                foreach ([1, 2] as $id) {
                    $values = ['id' => $id];
                    foreach (['document', 'alternate'] as $field) {
                        $values[$field] = $resource.'-'.$id.'-'.$field.'.txt';
                        Storage::disk('inline_upload_test')->put($values[$field], 'Original '.$values[$field]."\0\xff");
                    }
                    foreach (['documents', 'alternates'] as $field) {
                        $values[$field] = [];
                        foreach ([0, 1] as $position) {
                            $path = $resource.'-'.$id.'-'.$field.'-'.$position.'.txt';
                            $values[$field][] = $path;
                            Storage::disk('inline_upload_test')->put($path, 'Original '.$path."\0\xff");
                        }
                    }
                    $model::create($values);
                }
            }
        } catch (\Throwable $exception) {
            $this->cleanUp();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->cleanUp();
        }
    }

    private function cleanUp(): void
    {
        try {
            if ($this->uploadRoot !== null) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->uploadRoot);
            }
        } finally {
            foreach ($this->packageState as [$property, $value]) {
                $property->setValue(null, $value);
            }
        }
    }

    private function grids(string $mode): array
    {
        return match ($mode) {
            'single', 'two rows' => ['primary'],
            'same resource' => ['primary', 'primary'],
            'different resources' => ['primary', 'secondary'],
        };
    }

    private function fields(string $mode, bool $multiple): array
    {
        $fields = $multiple ? ['documents', 'alternates'] : ['document', 'alternate'];

        return $mode === 'single' ? [$fields[0]] : $fields;
    }

    private function cells(string $mode, int $fileCount = 0): array
    {
        $cells = [];
        foreach ($this->grids($mode) as $resource) {
            $model = self::MODELS[$resource];
            foreach ($mode === 'single' ? [1] : [1, 2] as $id) {
                $item = $model::findOrFail($id);
                foreach ($this->fields($mode, $fileCount > 0) as $field) {
                    $cell = [
                        'resource' => $resource, 'key' => $id, 'field' => $field,
                        'url' => '/grid-inline-upload/'.$resource.'/'.$id,
                        'label' => $fileCount > 0 ? count($item->$field).' files' : $item->$field,
                        'file' => [
                            'name' => 'replacement-'.count($cells).'.txt',
                            'type' => 'application/octet-stream',
                            'bytes' => base64_encode('Selected '.$resource.' '.$id.' '.$field.' '.count($cells)."\0\xff\n"),
                        ],
                    ];
                    if ($fileCount > 0) {
                        unset($cell['file']);
                        $cell['files'] = [];
                        for ($position = 0; $position < $fileCount; $position++) {
                            $cell['files'][] = [
                                'name' => 'selected-'.count($cells).'-'.$position.'.txt',
                                'type' => 'application/octet-stream',
                                'bytes' => base64_encode('Selected '.$resource.' '.$id.' '.$field.' '.count($cells).' '.$position."\0\xff\n"),
                            ];
                        }
                    }
                    $cells[] = $cell;
                }
            }
        }

        return $cells;
    }

    private function storedState(): array
    {
        $state = [];
        foreach (self::MODELS as $resource => $model) {
            foreach ($model::orderBy('id')->get() as $item) {
                foreach (['document', 'alternate', 'documents', 'alternates'] as $field) {
                    $read = fn ($path) => [
                        'path' => $path, 'bytes' => base64_encode(Storage::disk('inline_upload_test')->get($path)),
                    ];
                    $state[$resource][$item->id][$field] = is_array($item->$field)
                        ? array_map($read, $item->$field) : $read($item->$field);
                }
            }
        }

        return $state;
    }

    private function widget(array $fixture): array
    {
        $process = new Process(['node', __DIR__.'/javascript/grid-inline-upload.cjs']);
        $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function scenarios(): array
    {
        $cases = [];
        foreach (['shipped', 'modern'] as $jquery) {
            foreach (['single', 'two rows', 'same resource', 'different resources'] as $mode) {
                $cases[$jquery.' '.$mode] = [$jquery, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('scenarios')]
    public function test_rendered_single_file_cells_keep_clicks_and_http_updates_isolated(string $jquery, string $mode): void
    {
        $this->assertUploads($jquery, $mode);
    }

    public static function multipleScenarios(): array
    {
        $cases = [];
        foreach (self::scenarios() as $name => [$jquery, $mode]) {
            foreach ([1, 2, 5] as $count) {
                $cases[$name.' '.$count.' files'] = [$jquery, $mode, $count];
            }
        }

        return $cases;
    }

    #[DataProvider('multipleScenarios')]
    public function test_native_file_lists_append_every_file_in_order_and_preserve_other_cells(string $jquery, string $mode, int $fileCount): void
    {
        $this->assertUploads($jquery, $mode, $fileCount);
    }

    private function assertUploads(string $jquery, string $mode, int $fileCount = 0): void
    {
        $multiple = $fileCount > 0;
        $url = '/grid-inline-upload/render/'.rawurlencode($mode).'?multiple='.($multiple ? '1' : '0');
        $cells = $this->cells($mode, $fileCount);
        $original = $this->storedState();
        $fixture = $this->get($url)->assertOk()->json() + compact('jquery', 'cells', 'multiple');
        $result = $this->widget($fixture);
        $this->assertCount(count($cells), $result['requests']);
        $this->assertSame(0, $result['reloads']);
        $this->assertSame($original, $this->storedState(), 'DOM clicks, cancellations and captured requests cannot save files');
        $responses = [];
        foreach ($result['requests'] as $index => $request) {
            $cell = $cells[$index];
            $files = $cell['files'] ?? [$cell['file']];
            $this->assertSame($cell['url'], $request['url']);
            $this->assertSame('POST', $request['type']);
            $this->assertFalse($request['processData']);
            $this->assertFalse($request['contentType']);
            $this->assertSame('multipart/form-data', $request['enctype']);
            $this->assertSame([
                ...array_map(fn ($file) => [
                    $cell['field'].($multiple ? '[]' : ''),
                    $file + ['size' => strlen(base64_decode($file['bytes']))],
                ], $files),
                ['_token', 'test-token'], ['_method', 'PUT'],
            ], $request['entries']);
            $payload = [];
            // Reconstruct only the entries actually emitted by native FormData,
            // including the captured file bytes, for the real HTTP/Form update.
            foreach ($request['entries'] as [$name, $value]) {
                $value = is_array($value)
                    ? UploadedFile::fake()->createWithContent($value['name'], base64_decode($value['bytes']))
                    : $value;
                if (str_ends_with($name, '[]')) {
                    $payload[substr($name, 0, -2)][] = $value;
                } else {
                    $payload[$name] = $value;
                }
            }
            $before = $this->storedState();
            $response = $this->post($request['url'], $payload, [
                'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
            ])->assertOk()->assertJsonPath('status', true);
            $responses[] = $response->json();
            $expected = $before;
            $saved = array_map(fn ($file) => [
                'path' => 'uploads/'.$file['name'], 'bytes' => $file['bytes'],
            ], $files);
            $old = $before[$cell['resource']][$cell['key']][$cell['field']];
            $expected[$cell['resource']][$cell['key']][$cell['field']] = $multiple
                ? array_merge($old, $saved) : $saved[0];
            $this->assertSame($expected, $this->storedState(), 'Every other row, field and resource must retain its path and exact bytes');
            if (!$multiple) {
                $this->assertFalse(Storage::disk('inline_upload_test')->exists($old['path']),
                    'Successful single-file replacement still removes only its own former file');
            }
        }
        // Deliver genuine HTTP response bodies to the same production callbacks.
        $completed = $this->widget($fixture + compact('responses'));
        $this->assertSame($result['requests'], $completed['requests']);
        $this->assertSame(count($cells), $completed['reloads']);
        $this->assertSame(array_column($responses, 'message'), $completed['notices']);
        $reloaded = $this->widget($this->get($url)->assertOk()->json() + [
            'jquery' => $jquery, 'cells' => $this->cells($mode, $fileCount),
            'multiple' => $multiple, 'inspectOnly' => true,
        ]);
        $this->assertSame([], $reloaded['requests']);
        $this->assertSame([], array_values(array_intersect($result['inputIds'], $reloaded['inputIds'])));
    }
}

class GridInlineUploadItem extends Model
{
    protected $table = 'grid_inline_upload_items';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['documents' => 'array', 'alternates' => 'array'];
}

class GridInlineUploadOtherItem extends GridInlineUploadItem
{
    protected $table = 'grid_inline_upload_other_items';
}
