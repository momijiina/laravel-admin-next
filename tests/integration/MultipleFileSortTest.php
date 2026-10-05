<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Admin;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\MultipleFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;

class MultipleFileSortTest extends TestCase
{
    private array $packageState = [];
    private ?string $uploadRoot = null;
    private array $uploadFiles = [];
    private array $savingInputs = [];
    private array $validatorInputs = [];

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
            $router->get('/multiple-file-sort/{mode}/{id}/edit', function ($mode, $id) {
                $scripts = Admin::$script;
                try {
                    Admin::$script = [];
                    $html = $this->form($mode)->edit($id)->render();

                    return response()->json(['html' => $html, 'scriptHtml' => Admin::script()->render()]);
                } finally {
                    Admin::$script = $scripts;
                }
            });
            $router->put('/multiple-file-sort/{mode}/{id}', function ($mode, $id) {
                return $this->form($mode)->update($id);
            });
        });
    }

    protected function setUp(): void
    {
        foreach ([Admin::class => ['script', 'html', 'style'], Form::class => ['snakeAttributes']] as $class => $names) {
            foreach ($names as $name) {
                $property = new \ReflectionProperty($class, $name);
                $this->packageState[] = [$property, $property->getValue()];
            }
        }
        try {
            parent::setUp();
            $this->withoutExceptionHandling();
            $this->uploadRoot = sys_get_temp_dir().'/admin-multiple-file-sort-'.bin2hex(random_bytes(8));
            mkdir($this->uploadRoot, 0700, true);
            config([
                'filesystems.disks.sort_test' => ['driver' => 'local', 'root' => $this->uploadRoot, 'throw' => true],
                'admin.upload.disk' => 'sort_test',
                'admin.upload.directory.file' => 'uploads',
            ]);
            Schema::create('multiple_file_sort_records', function ($table) {
                $table->increments('id');
                $table->string('title');
                $table->text('documents');
                $table->text('alternates');
            });
            foreach (['first.txt', 'second.txt', 'other-first.txt', 'other-second.txt'] as $file) {
                Storage::disk('sort_test')->put($file, 'Original '.$file."\0\xff");
            }
            MultipleFileSortRecord::create([
                'title' => 'Before', 'documents' => ['first.txt', 'second.txt'],
                'alternates' => ['other-first.txt', 'other-second.txt'],
            ]);
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
            foreach ($this->uploadFiles as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if ($this->uploadRoot !== null) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->uploadRoot);
            }
        } finally {
            foreach ($this->packageState as [$property, $value]) {
                $property->setValue(null, $value);
            }
        }
    }

    private function form(string $mode): Form
    {
        $form = new Form(new MultipleFileSortRecord());
        $form->text('title')->rules('required');
        $field = $form->multipleFile('documents')->sortable();
        if ($mode !== 'plain') {
            $field->rules($mode === 'image' ? 'image' : 'file');
        }
        if ($mode === 'rule-callback') {
            $field->rules([function ($attribute, $file, $fail) {
                if ($file->getClientOriginalName() === 'blocked.txt') {
                    $fail('This filename is blocked.');
                }
            }]);
        }
        if ($mode === 'two-fields') {
            $form->multipleFile('alternates')->sortable()->rules('file');
        }
        if ($mode === 'other-image') {
            $form->multipleFile('alternates')->rules('image');
        }
        if (str_starts_with($mode, 'custom')) {
            $field->rules('image');
            $seen = &$this->validatorInputs;
            $field->validator(function ($input) use (&$seen, $mode) {
                $seen[] = $input;
                if ($mode === 'custom-reject') {
                    return validator(['decision' => 'rejected'], ['decision' => 'in:accepted']);
                }
                $files = $input['documents'];

                return validator(is_array($files) ? ['documents' => $files] : [], ['documents.*' => 'file']);
            });
        }
        $form->saving(function (Form $form) {
            $this->savingInputs[] = $form->input('documents');
        });

        return $form;
    }

    private function fixture(string $mode): array
    {
        $fixture = $this->get('/multiple-file-sort/'.$mode.'/1/edit')->assertOk()->json();
        $crawler = new Crawler($fixture['html']);
        $this->assertCount(1, $crawler->filter('input[type=file][name="documents[]"]'));
        $this->assertCount(1, $crawler->filter('input[type=hidden][name="_file_sort_[documents]"]'));
        $fixture['root'] = realpath(__DIR__.'/../..');

        return $fixture;
    }

    private function widgetPayload(string $mode, ?array $order = [1, 0], array $uploads = []): array
    {
        $fixture = $this->fixture($mode) + compact('order', 'uploads');
        $process = new Process(['node', __DIR__.'/javascript/multiple-file-sort.cjs']);
        $process->setInput(json_encode($fixture, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($order === null ? '' : implode(',', $order), $result['sortValue']);
        $this->assertSame(array_column($uploads, 'name'), array_column($result['pluginFiles'], 'name'));
        $payload = [];
        foreach ($result['entries'] as [$name, $value]) {
            if (is_array($value)) {
                $this->assertSame('documents[]', $name);
                $path = tempnam(sys_get_temp_dir(), 'admin-sort-upload-');
                $this->uploadFiles[] = $path;
                file_put_contents($path, base64_decode($value['bytes']));
                // Use real MIME detection, not the fake factory's filename-derived MIME.
                $payload['documents'][] = new UploadedFile($path, $value['name'], null, UPLOAD_ERR_OK, true);
            } else {
                parse_str(urlencode($name).'='.urlencode($value), $entry);
                $payload = array_merge_recursive($payload, $entry);
            }
        }

        return $payload;
    }

    private function save(string $mode, array $payload)
    {
        return $this->put('/multiple-file-sort/'.$mode.'/1', $payload + ['title' => 'After'], [
            'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
        ]);
    }

    private function assertOriginalBytes(): void
    {
        foreach (['first.txt', 'second.txt', 'other-first.txt', 'other-second.txt'] as $file) {
            $this->assertSame('Original '.$file."\0\xff", Storage::disk('sort_test')->get($file));
        }
    }

    public static function optionalRules(): array
    {
        return [['plain'], ['file'], ['image']];
    }

    #[DataProvider('optionalRules')]
    public function test_shipped_sort_only_payload_saves_with_optional_rules(string $mode): void
    {
        $this->save($mode, $this->widgetPayload($mode))->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['second.txt', 'first.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame(['1,0'], $this->savingInputs, 'Sort-only saving hooks retain the existing order-string contract.');
        $this->assertOriginalBytes();
    }

    public static function uploadModes(): array
    {
        return [['plain'], ['file']];
    }

    #[DataProvider('uploadModes')]
    public function test_shipped_sort_and_upload_payload_appends_all_files_in_selection_order(string $mode): void
    {
        $uploads = [
            ['name' => 'new.txt', 'type' => 'text/plain', 'bytes' => base64_encode("First new bytes\0\xff")],
            ['name' => 'next.txt', 'type' => 'text/plain', 'bytes' => base64_encode("Second new bytes\0\xfe")],
        ];
        $payload = $this->widgetPayload($mode, [1, 0], $uploads);
        $this->save($mode, $payload)->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['second.txt', 'first.txt', 'uploads/new.txt', 'uploads/next.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        foreach ($uploads as $upload) {
            $this->assertSame(base64_decode($upload['bytes']), Storage::disk('sort_test')->get('uploads/'.$upload['name']));
        }
        $this->assertCount(2, $this->savingInputs[0]);
        $this->assertContainsOnlyInstancesOf(UploadedFile::class, $this->savingInputs[0]);
        $this->assertSame(['new.txt', 'next.txt'], array_map(fn ($file) => $file->getClientOriginalName(), $this->savingInputs[0]));
        $this->assertOriginalBytes();
    }

    public function test_empty_sort_marker_leaves_unchanged_and_upload_only_saves_in_original_order(): void
    {
        $this->save('file', $this->widgetPayload('file', null))->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['first.txt', 'second.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->save('file', $this->widgetPayload('file', null, [
            ['name' => 'new.txt', 'type' => 'text/plain', 'bytes' => base64_encode('Upload only')],
        ]))->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['first.txt', 'second.txt', 'uploads/new.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame('Upload only', Storage::disk('sort_test')->get('uploads/new.txt'));
        $this->assertOriginalBytes();
    }

    public function test_subsequent_render_uses_saved_order_for_next_sort(): void
    {
        $this->save('file', $this->widgetPayload('file', [1, 0], [
            ['name' => 'new.txt', 'type' => 'text/plain', 'bytes' => base64_encode('New bytes')],
        ]))->assertOk()->assertJson(['status' => true]);
        $this->save('file', $this->widgetPayload('file', [2, 0, 1]))->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['uploads/new.txt', 'second.txt', 'first.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame('New bytes', Storage::disk('sort_test')->get('uploads/new.txt'));
        $this->assertOriginalBytes();
    }

    public function test_upload_without_any_sort_metadata_keeps_existing_append_behavior(): void
    {
        $this->save('file', ['documents' => [UploadedFile::fake()->createWithContent('new.txt', 'New bytes')]])
            ->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['first.txt', 'second.txt', 'uploads/new.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame('New bytes', Storage::disk('sort_test')->get('uploads/new.txt'));
        $this->assertOriginalBytes();
    }

    public function test_rule_callback_rejects_combined_upload_before_any_storage(): void
    {
        $this->save('rule-callback', [
            'documents' => [UploadedFile::fake()->createWithContent('blocked.txt', 'Blocked bytes')],
            '_file_sort_' => ['documents' => '1,0'],
        ])->assertRedirect()->assertSessionHasErrors('documents0');
        $this->assertSame(['first.txt', 'second.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame([], $this->savingInputs);
        $this->assertSame([], Storage::disk('sort_test')->files('uploads'));
        $this->assertOriginalBytes();
    }

    public function test_invalid_new_file_is_rejected_when_sorting_then_valid_retry_succeeds(): void
    {
        $this->save('file', ['documents' => ['not an uploaded file'], '_file_sort_' => ['documents' => '1,0']])
            ->assertRedirect()->assertSessionHasErrors('documents0');
        $this->assertSame(['first.txt', 'second.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame([], $this->savingInputs);
        $this->assertSame([], Storage::disk('sort_test')->files('uploads'));
        $this->save('file', ['documents' => [UploadedFile::fake()->createWithContent('retry.txt', 'Retry bytes')],
            '_file_sort_' => ['documents' => '1,0']])->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['second.txt', 'first.txt', 'uploads/retry.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame('Retry bytes', Storage::disk('sort_test')->get('uploads/retry.txt'));
        $this->assertOriginalBytes();
    }

    public function test_image_rule_rejects_real_non_image_upload_even_when_sorting(): void
    {
        $this->save('image', $this->widgetPayload('image', [1, 0], [
            ['name' => 'bad.png', 'type' => 'image/png', 'bytes' => base64_encode('Not image bytes')],
        ]))->assertRedirect()->assertSessionHasErrors('documents0');
        $this->assertSame(['first.txt', 'second.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame([], $this->savingInputs);
        $this->assertSame([], Storage::disk('sort_test')->files('uploads'));
        $this->assertOriginalBytes();
    }

    public function test_image_rule_accepts_real_image_with_sort_and_retains_its_bytes(): void
    {
        $bytes = file_get_contents(__DIR__.'/../assets/test.jpg');
        $this->save('image', $this->widgetPayload('image', [1, 0], [
            ['name' => 'new.jpg', 'type' => 'image/jpeg', 'bytes' => base64_encode($bytes)],
        ]))->assertOk()->assertJson(['status' => true]);
        $this->assertSame(['second.txt', 'first.txt', 'uploads/new.jpg'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame($bytes, Storage::disk('sort_test')->get('uploads/new.jpg'));
        $this->assertOriginalBytes();
    }

    public function test_other_fields_are_still_validated_during_sort_only_save(): void
    {
        $this->save('other-image', ['title' => '', '_file_sort_' => ['documents' => '1,0'],
            'alternates' => [UploadedFile::fake()->createWithContent('bad.txt', 'Not an image')]])
            ->assertRedirect()->assertSessionHasErrors(['title', 'alternates0']);
        $record = MultipleFileSortRecord::findOrFail(1);
        $this->assertSame('Before', $record->title);
        $this->assertSame(['first.txt', 'second.txt'], $record->documents);
        $this->assertSame([], $this->savingInputs);
        $this->assertSame([], Storage::disk('sort_test')->files('uploads'));
        $this->assertOriginalBytes();
    }

    public function test_multiple_fields_keep_independent_sort_and_upload_inputs(): void
    {
        $this->save('two-fields', [
            '_file_sort_' => ['documents' => '1,0', 'alternates' => '0,1'],
            'documents' => [UploadedFile::fake()->createWithContent('new.txt', 'New bytes')],
            'alternates' => [UploadedFile::fake()->createWithContent('other.txt', 'Other bytes')],
        ])->assertOk()->assertJson(['status' => true]);
        $record = MultipleFileSortRecord::findOrFail(1);
        $this->assertSame(['second.txt', 'first.txt', 'uploads/new.txt'], $record->documents);
        $this->assertSame(['other-first.txt', 'other-second.txt', 'uploads/other.txt'], $record->alternates);
        $this->assertSame('New bytes', Storage::disk('sort_test')->get('uploads/new.txt'));
        $this->assertSame('Other bytes', Storage::disk('sort_test')->get('uploads/other.txt'));
        $this->assertOriginalBytes();
    }

    public function test_custom_validator_precedes_builtin_rules_and_sees_sort_only_string_or_upload_array(): void
    {
        $this->save('custom', ['_file_sort_' => ['documents' => '1,0']])->assertOk()->assertJson(['status' => true]);
        $this->assertSame('1,0', $this->validatorInputs[0]['documents']);
        $this->assertSame(['1,0'], $this->savingInputs);
        $this->save('custom', ['_file_sort_' => ['documents' => '1,0'],
            'documents' => [UploadedFile::fake()->createWithContent('custom.txt', 'Custom bytes')]])
            ->assertOk()->assertJson(['status' => true]);
        $this->assertCount(1, $this->validatorInputs[1]['documents']);
        $this->assertInstanceOf(UploadedFile::class, $this->validatorInputs[1]['documents'][0]);
        $this->assertSame('1,0', $this->validatorInputs[1]['_file_sort_']['documents']);
        $this->assertSame($this->validatorInputs[1]['documents'], $this->savingInputs[1]);
        $this->assertSame(['first.txt', 'second.txt', 'uploads/custom.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertSame('Custom bytes', Storage::disk('sort_test')->get('uploads/custom.txt'));
        $this->assertOriginalBytes();
    }

    public function test_custom_validator_rejection_is_not_bypassed_by_sort_flag(): void
    {
        $this->save('custom-reject', ['_file_sort_' => ['documents' => '1,0']])
            ->assertRedirect()->assertSessionHasErrors('decision');
        $this->assertCount(1, $this->validatorInputs);
        $this->assertSame('1,0', $this->validatorInputs[0]['documents']);
        $this->assertSame([], $this->savingInputs);
        $this->assertSame(['first.txt', 'second.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
    }

    public function test_custom_validator_rejects_combined_upload_before_any_storage(): void
    {
        $this->save('custom-reject', ['_file_sort_' => ['documents' => '1,0'],
            'documents' => [UploadedFile::fake()->createWithContent('blocked.txt', 'Blocked bytes')]])
            ->assertRedirect()->assertSessionHasErrors('decision');
        $this->assertCount(1, $this->validatorInputs);
        $this->assertInstanceOf(UploadedFile::class, $this->validatorInputs[0]['documents'][0]);
        $this->assertSame([], $this->savingInputs);
        $this->assertSame([], Storage::disk('sort_test')->files('uploads'));
        $this->assertSame(['first.txt', 'second.txt'], MultipleFileSortRecord::findOrFail(1)->documents);
        $this->assertOriginalBytes();
    }

    public static function unrelatedSortMarkers(): array
    {
        return [[['alternates' => '1,0']], [['documents' => '0,1']], [['documents' => '']]];
    }

    #[DataProvider('unrelatedSortMarkers')]
    public function test_only_matching_same_field_string_is_normalized_for_builtin_validation(array $sorts): void
    {
        $field = (new MultipleFile('documents'))->rules('file');
        $this->expectException(\TypeError::class);
        $field->getValidator(['documents' => '1,0', '_file_sort_' => $sorts]);
    }
}

class MultipleFileSortRecord extends Model
{
    protected $table = 'multiple_file_sort_records';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['documents' => 'array', 'alternates' => 'array'];
}
