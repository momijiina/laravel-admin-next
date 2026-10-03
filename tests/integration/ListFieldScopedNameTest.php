<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;

class ListFieldScopedNameTest extends TestCase
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
        Schema::create('scoped_list_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->json('items')->nullable();
            $table->json('settings')->nullable();
        });
        Schema::create('scoped_list_children', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
            $table->json('items')->nullable();
        });
        $this->app['router']->match(['GET', 'PUT'], 'scoped-list/{type}/{id}', function ($type, $id) {
            $form = new Form(new ScopedListRecord());
            $form->text('name')->rules('required');
            if ($type === 'embedded') {
                $form->embeds('settings', function ($form) {
                    $form->text('sibling');
                    $form->list('items');
                });
            } elseif ($type === 'nested') {
                $form->hasMany('children', function ($form) {
                    $form->list('items');
                });
            } else {
                $form->list('items');
            }
            return request()->isMethod('GET') ? $form->edit($id)->render() : $form->update($id);
        })->middleware('web');
    }

    // Read successful controls in DOM order, excluding inert templates, then use
    // PHP's actual bracket-name parser. This does not execute browser JavaScript.
    private function inputFromHtml($html): array
    {
        $pairs = [];
        foreach ((new Crawler($html))->filter('input[name]') as $input) {
            for ($parent = $input->parentNode; $parent; $parent = $parent->parentNode) {
                if ($parent->nodeName === 'template') {
                    continue 2;
                }
            }
            if (!$input->hasAttribute('disabled')) {
                $pairs[] = urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
            }
        }
        parse_str(implode('&', $pairs), $parsed);
        return $parsed;
    }

    public function test_top_level_and_embedded_lists_round_trip_rendered_names(): void
    {
        $values = ['first', '0', '<tag> & "quoted"'];
        foreach (['top', 'embedded'] as $type) {
            $record = ScopedListRecord::create([
                'name' => 'before', 'items' => $values,
                'settings' => ['sibling' => 'keep', 'items' => $values],
            ]);
            $url = '/scoped-list/'.$type.'/'.$record->id;
            $html = $this->get($url)->assertOk()->getContent();
            $name = $type === 'top' ? 'items[values][]' : 'settings[items][values][]';
            $crawler = new Crawler($html);
            $this->assertSame(3, $crawler->filter('tbody input[name="'.$name.'"]')->count());
            $this->assertSame(1, $crawler->filter('template input[name="'.$name.'"]')->count());
            $input = $this->inputFromHtml($html);
            if ($type === 'embedded') {
                $this->assertArrayNotHasKey('items', $input);
                $this->assertSame($values, $input['settings']['items']['values']);
                $input['settings']['sibling'] = 'changed sibling';
                $input['settings']['items']['values'][0] = 'changed list';
            } else {
                $this->assertSame($values, $input['items']['values']);
                $input['items']['values'][0] = 'changed list';
            }
            $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $expected = ['changed list', '0', '<tag> & "quoted"'];
            $this->assertSame($expected, $type === 'top' ? $record->fresh()->items : $record->fresh()->settings['items']);
            if ($type === 'embedded') {
                $this->assertSame('changed sibling', $record->fresh()->settings['sibling']);
                $this->assertSame($values, $record->fresh()->items);
            }
        }
    }

    public function test_has_many_records_keep_their_own_existing_and_template_names(): void
    {
        $record = ScopedListRecord::create(['name' => 'parent']);
        $first = $record->children()->create(['items' => ['first', '0']]);
        $second = $record->children()->create(['items' => ['second']]);
        $url = '/scoped-list/nested/'.$record->id;
        $html = $this->get($url)->assertOk()->getContent();
        $crawler = new Crawler($html);
        foreach ([$first, $second] as $child) {
            $name = 'children['.$child->id.'][items][values][]';
            $this->assertSame(count($child->items), $crawler->filter('tbody input[name="'.$name.'"]')->count());
            $this->assertSame(1, $crawler->filter('template input[name="'.$name.'"]')->count());
        }
        $input = $this->inputFromHtml($html);
        $this->assertArrayNotHasKey('items', $input);
        $this->assertSame(['first', '0'], $input['children'][$first->id]['items']['values']);
        $this->assertSame(['second'], $input['children'][$second->id]['items']['values']);
        $input['children'][$first->id]['items']['values'] = ['updated', '0'];
        $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['updated', '0'], $first->fresh()->items);
        $this->assertSame(['second'], $second->fresh()->items);
    }

    public function test_unrelated_validation_failure_redisplays_each_scoped_submitted_list(): void
    {
        foreach (['top', 'embedded', 'nested'] as $type) {
            session()->flush();
            $record = ScopedListRecord::create([
                'name' => 'before', 'items' => ['stored'],
                'settings' => ['sibling' => 'keep', 'items' => ['stored']],
            ]);
            $first = $record->children()->create(['items' => ['first stored']]);
            $second = $record->children()->create(['items' => ['second stored']]);
            $url = '/scoped-list/'.$type.'/'.$record->id;
            $input = $this->inputFromHtml($this->get($url)->assertOk()->getContent());
            $input['name'] = '';
            if ($type === 'nested') {
                $input['children'][$first->id]['items']['values'] = ['first submitted', '0'];
                $input['children'][$second->id]['items']['values'] = ['second submitted'];
            } elseif ($type === 'embedded') {
                $input['settings']['items']['values'] = ['submitted', '0'];
            } else {
                $input['items']['values'] = ['submitted', '0'];
            }
            $this->from($url)->put($url, $input)->assertRedirect($url)->assertSessionHasErrors('name');
            $redisplayed = $this->inputFromHtml($this->get($url)->assertOk()->getContent());
            $key = $type === 'nested' ? 'children' : ($type === 'embedded' ? 'settings' : 'items');
            $this->assertSame($input[$key], $redisplayed[$key]);
            $this->assertSame('before', $record->fresh()->name);
            $this->assertSame(['stored'], $record->fresh()->items);
            $this->assertSame(['stored'], $record->fresh()->settings['items']);
            $this->assertSame(['first stored'], $first->fresh()->items);
            $this->assertSame(['second stored'], $second->fresh()->items);
        }
    }

    public function test_scoped_names_values_and_error_messages_are_escaped(): void
    {
        $name = 'settings["items<>]';
        $key = 'settings.items';
        request()->setLaravelSession(session()->driver());
        session()->flashInput([
            'settings' => ['items' => ['values' => ['<submitted> & "quoted"']]],
            'items' => ['values' => ['wrong unscoped value']],
        ]);
        $errors = new ViewErrorBag();
        $errors->put('default', new MessageBag([
            $key.'.values' => ['<collection error>'],
            $key.'.values.0' => ['<item error>'],
            'items.values' => ['wrong unscoped error'],
        ]));
        $this->app['view']->share('errors', $errors);
        $field = (new Form(new ScopedListRecord()))->list('items')
            ->setElementName($name)->setErrorKey($key)->value(['stored']);
        $html = $field->render();
        $crawler = new Crawler($html);
        $this->assertSame(2, $crawler->filter('input')->count());
        foreach ($crawler->filter('input') as $input) {
            $this->assertSame($name.'[values][]', $input->getAttribute('name'));
            $this->assertFalse($input->hasAttribute('items'));
        }
        $this->assertSame('<submitted> & "quoted"', $crawler->filter('tbody input')->attr('value'));
        $this->assertStringContainsString('&lt;collection error&gt;', $html);
        $this->assertStringContainsString('&lt;item error&gt;', $html);
        $this->assertStringNotContainsString('wrong unscoped', $html);
        $this->assertSame(0, $crawler->filter('submitted, collection, item')->count());
    }
}

class ScopedListRecord extends Model
{
    protected $table = 'scoped_list_records';
    protected $guarded = [];
    protected $casts = ['items' => 'array', 'settings' => 'array'];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(ScopedListChild::class, 'parent_id');
    }
}

class ScopedListChild extends Model
{
    protected $table = 'scoped_list_children';
    protected $guarded = [];
    protected $casts = ['items' => 'array'];
    public $timestamps = false;
}
