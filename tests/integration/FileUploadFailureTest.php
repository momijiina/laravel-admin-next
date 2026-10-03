<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\File;
use Encore\Admin\Form\Field\Image;
use Encore\Admin\Form\Field\MultipleFile;
use Encore\Admin\Form\Field\MultipleImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Orchestra\Testbench\TestCase;

class FileUploadFailureTest extends TestCase
{
    private $uploadRoot;

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
        $this->uploadRoot = sys_get_temp_dir().'/admin-upload-failure-'.bin2hex(random_bytes(8));
        mkdir($this->uploadRoot.'/uploads', 0777, true);
        config([
            'filesystems.disks.upload_test' => ['driver' => 'local', 'root' => $this->uploadRoot, 'throw' => false],
            'admin.upload.disk' => 'upload_test',
            'admin.upload.directory.file' => 'uploads',
            'admin.upload.directory.image' => 'uploads',
        ]);
        Schema::create('upload_records', function ($table) {
            $table->increments('id');
            $table->string('document')->nullable();
        });
        $this->app['router']->post('upload-test', function () {
            $form = new Form(new UploadFailureRecord());
            $form->file('document');
            return $form->store();
        })->middleware('web');
        $this->app['router']->post('upload-test/{id}', function ($id) {
            $form = new Form(new UploadFailureRecord());
            $form->file('document');
            return $form->update($id);
        })->middleware('web');
    }

    protected function tearDown(): void
    {
        try {
            if ($this->uploadRoot) {
                (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($this->uploadRoot);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function failedUpload($extension = 'txt')
    {
        // Exceeds a local filesystem's per-component limit, without changing permissions.
        return UploadedFile::fake()->createWithContent(str_repeat('n', 260).'.'.$extension,
            $extension === 'jpg' ? file_get_contents(__DIR__.'/../assets/test.jpg') : 'replacement bytes');
    }

    private function seedOriginal()
    {
        Storage::disk('upload_test')->put('old.txt', 'original bytes');
        return UploadFailureRecord::create(['document' => 'old.txt']);
    }

    public function test_failed_http_replacement_preserves_original_bytes_and_database_path(): void
    {
        $record = $this->seedOriginal();
        $response = $this->post('/upload-test/'.$record->id, ['document' => $this->failedUpload()], [
            'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
        ]);

        $response->assertStatus(500);
        $this->assertNotSame(true, $response->json('status'));
        $this->assertSame('old.txt', $record->fresh()->document);
        $this->assertSame('original bytes', Storage::disk('upload_test')->get('old.txt'));
        $this->assertSame([], Storage::disk('upload_test')->files('uploads'));
    }

    public function test_successful_http_replacement_stores_new_path_and_deletes_original(): void
    {
        $record = $this->seedOriginal();
        $this->post('/upload-test/'.$record->id, [
            'document' => UploadedFile::fake()->createWithContent('new.txt', 'replacement bytes'),
        ], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->assertOk()->assertJson(['status' => true]);

        $this->assertSame('uploads/new.txt', $record->fresh()->document);
        $this->assertSame('replacement bytes', Storage::disk('upload_test')->get('uploads/new.txt'));
        $this->assertFalse(Storage::disk('upload_test')->exists('old.txt'));
    }

    public function test_failed_initial_http_upload_does_not_create_a_record(): void
    {
        $response = $this->post('/upload-test', ['document' => $this->failedUpload()], [
            'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json',
        ]);

        $response->assertStatus(500);
        $this->assertNotSame(true, $response->json('status'));
        $this->assertDatabaseCount('upload_records', 0);
        $this->assertSame([], Storage::disk('upload_test')->files('uploads'));
    }

    public function test_initial_upload_failure_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to store uploaded file.');
        (new File('document'))->prepare($this->failedUpload());
    }

    public function test_throwing_disk_exception_is_preserved_and_original_survives(): void
    {
        config(['filesystems.disks.upload_test.throw' => true]);
        $record = $this->seedOriginal();
        $field = new File('document');
        $field->setOriginal(['document' => $record->document]);
        try {
            $field->prepare($this->failedUpload());
            $this->fail('Expected the original Flysystem exception.');
        } catch (UnableToWriteFile $exception) {
            $this->assertSame('original bytes', Storage::disk('upload_test')->get('old.txt'));
            $this->assertSame('old.txt', $record->fresh()->document);
        }
    }

    public function test_image_and_multiple_uploads_reject_false_without_deleting_originals(): void
    {
        $this->seedOriginal();
        foreach ([Image::class, MultipleFile::class, MultipleImage::class] as $class) {
            $multiple = is_a($class, MultipleFile::class, true);
            $field = new $class('document');
            $field->setOriginal(['document' => $multiple ? ['old.txt'] : 'old.txt']);
            $field->storagePermission('private');
            if ($field instanceof Image || $field instanceof MultipleImage) {
                $field->thumbnail('small', 10, 10);
                Storage::disk('upload_test')->put('old-small.txt', 'original thumbnail bytes');
            }
            $upload = $this->failedUpload('jpg');
            try {
                $field->prepare($multiple ? [$upload] : $upload);
                $this->fail($class.' must reject failed storage.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Failed to store uploaded file.', $exception->getMessage());
                $this->assertSame('original bytes', Storage::disk('upload_test')->get('old.txt'));
                $this->assertSame([], Storage::disk('upload_test')->files('uploads'));
                if ($field instanceof Image || $field instanceof MultipleImage) {
                    $this->assertSame('original thumbnail bytes', Storage::disk('upload_test')->get('old-small.txt'));
                }
            }
        }
    }

    public function test_successful_upload_keeps_collision_naming_and_explicit_visibility(): void
    {
        $disk = Storage::disk('upload_test');
        $disk->put('uploads/new.txt', 'existing bytes');
        $field = (new File('document'))->storagePermission('private');
        $path = $field->prepare(UploadedFile::fake()->createWithContent('new.txt', 'replacement bytes'));

        $this->assertStringStartsWith('uploads/', $path);
        $this->assertNotSame('uploads/new.txt', $path);
        $this->assertSame('existing bytes', $disk->get('uploads/new.txt'));
        $this->assertSame('replacement bytes', $disk->get($path));
        $this->assertSame('private', $disk->getVisibility($path));
    }
}

class UploadFailureRecord extends Model
{
    protected $table = 'upload_records';
    protected $guarded = [];
    public $timestamps = false;
}
