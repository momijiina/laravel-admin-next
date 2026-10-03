<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Form;
use Encore\Admin\Form\Field\KeyValue;
use Encore\Admin\Form\Field\ListField;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;

class ExplicitEmptyCollectionsTest extends TestCase
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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('e', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('session.driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('explicit_empty_records', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->json('items')->nullable();
            $table->json('settings')->nullable();
        });
        Schema::create('explicit_empty_children', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
            $table->json('items')->nullable();
        });
        $this->app['router']->match(['GET', 'POST', 'PUT'], 'explicit-empty/{type}/{id?}', function ($type, $id = null) {
            $form = new Form(new ExplicitEmptyRecord());
            $form->text('name')->rules('required');
            $isKeyValue = str_ends_with($type, 'keyvalue');
            if (str_starts_with($type, 'embedded-')) {
                $form->embeds('settings', function ($form) use ($isKeyValue) {
                    $form->text('sibling');
                    $isKeyValue ? $form->keyValue('items') : $form->list('items');
                });
            } elseif (str_starts_with($type, 'nested-')) {
                $form->hasMany('children', function ($form) use ($isKeyValue) {
                    $isKeyValue ? $form->keyValue('items') : $form->list('items');
                });
            } elseif ($isKeyValue) {
                $form->keyValue('items')->rules('nullable|string');
            } elseif ($type === 'min-list') {
                $form->list('items')->min(1);
            } elseif ($type === 'zero-list') {
                $form->list('items')->min(0)->max(0);
            } else {
                $form->list('items')->min(0)->rules('nullable|string');
            }

            if (request()->isMethod('GET')) {
                return $id === null ? $form->render() : $form->edit($id)->render();
            }

            return $id === null ? $form->store() : $form->update($id);
        })->middleware('web');
    }

    // These helpers inspect server-rendered HTML and emulate successful text/hidden
    // controls in DOM order. Native parse_str supplies PHP's bracket-name semantics;
    // no browser, JavaScript, click handler, or client-side validation is executed.
    private function liveInputs(Crawler $crawler): array
    {
        $inputs = [];
        foreach ($crawler->filter('input[name]') as $input) {
            for ($parent = $input->parentNode; $parent; $parent = $parent->parentNode) {
                if ($parent->nodeName === 'template') {
                    continue 2;
                }
            }
            if ($input->hasAttribute('disabled')) {
                continue;
            }
            $type = strtolower($input->getAttribute('type'));
            if (in_array($type, ['submit', 'reset', 'button', 'file'], true) ||
                (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked'))) {
                continue;
            }
            $inputs[] = $input;
        }

        return $inputs;
    }

    private function inputFromHtml($html): array
    {
        $pairs = [];
        foreach ($this->liveInputs(new Crawler($html)) as $input) {
            $pairs[] = urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
        }
        parse_str(implode('&', $pairs), $parsed);

        return $parsed;
    }

    private function htmlWithoutRows($html, $name = 'items'): string
    {
        $crawler = new Crawler($html);
        foreach ($this->liveInputs($crawler) as $input) {
            if ($input->getAttribute('name') !== $name.'[values][]') {
                continue;
            }
            $row = $input;
            while ($row && $row->nodeName !== 'tr') {
                $row = $row->parentNode;
            }
            $this->assertNotNull($row, 'Every live collection value belongs to a row.');
            $row->parentNode->removeChild($row);
        }

        return $crawler->html();
    }

    private function htmlWithRow($html, $name, $value, $key = null): string
    {
        $crawler = new Crawler($html);
        $templateRow = $tbody = null;
        foreach ($crawler->filter('template input[name]') as $input) {
            if ($input->getAttribute('name') === $name.'[values][]') {
                $templateRow = $input;
                while ($templateRow->nodeName !== 'tr') {
                    $templateRow = $templateRow->parentNode;
                }
                break;
            }
        }
        foreach ($this->liveInputs($crawler) as $input) {
            if ($input->getAttribute('name') === $name.'[values]') {
                $tbody = $input->parentNode->getElementsByTagName('tbody')->item(0);
                break;
            }
        }
        $this->assertNotNull($templateRow, 'The production template supplies the replacement row.');
        $this->assertNotNull($tbody, 'The empty marker remains outside the table.');
        $row = $templateRow->cloneNode(true);
        foreach ($row->getElementsByTagName('input') as $input) {
            if ($input->getAttribute('name') === $name.'[values][]') {
                $input->setAttribute('value', $value);
            } elseif ($input->getAttribute('name') === $name.'[keys][]') {
                $input->setAttribute('value', $key);
            }
        }
        $tbody->appendChild($row);

        return $crawler->html();
    }

    private function assertEmptyDefaultsPrecedeRows($html, $name, $isKeyValue): void
    {
        $inputs = $this->liveInputs(new Crawler($html));
        foreach ($isKeyValue ? ['keys', 'values'] : ['values'] as $part) {
            $positions = [];
            foreach ($inputs as $index => $input) {
                if ($input->getAttribute('name') === $name.'['.$part.']') {
                    $positions[] = $index;
                    $this->assertSame('hidden', $input->getAttribute('type'));
                    $this->assertSame('', $input->getAttribute('value'));
                    for ($parent = $input->parentNode; $parent; $parent = $parent->parentNode) {
                        $this->assertNotSame('table', $parent->nodeName);
                    }
                    $table = $input->nextSibling;
                    while ($table && $table->nodeName !== 'table') {
                        $table = $table->nextSibling;
                    }
                    $this->assertNotNull($table, 'The scalar empty default precedes its table.');
                }
            }
            $this->assertCount(1, $positions, 'Exactly one live empty default per scoped collection part.');
            foreach ($inputs as $index => $input) {
                if ($input->getAttribute('name') === $name.'['.$part.'][]') {
                    $this->assertLessThan($index, $positions[0], 'Array controls override the preceding scalar in PHP.');
                }
            }
        }
    }

    private function rowValues($html, $name): array
    {
        $values = [];
        foreach ($this->liveInputs(new Crawler($html)) as $input) {
            if ($input->getAttribute('name') === $name.'[values][]') {
                $values[] = $input->getAttribute('value');
            }
        }

        return $values;
    }

    public function test_rendered_defaults_and_rows_follow_native_php_form_order(): void
    {
        foreach (['list' => ['first', '0'], 'keyvalue' => ['first' => 'value', 'zero' => '0']] as $type => $values) {
            $record = ExplicitEmptyRecord::create(['name' => 'before', 'items' => $values]);
            $html = $this->get('/explicit-empty/top-'.$type.'/'.$record->id)->assertOk()->getContent();
            $this->assertEmptyDefaultsPrecedeRows($html, 'items', $type === 'keyvalue');
            $input = $this->inputFromHtml($html);
            $this->assertSame(array_values($values), $input['items']['values']);
            if ($type === 'keyvalue') {
                $this->assertSame(array_keys($values), $input['items']['keys']);
            }
            $cleared = $this->htmlWithoutRows($html);
            $this->assertSame([], $this->rowValues($cleared, 'items'));
            $input = $this->inputFromHtml($cleared);
            $this->assertSame('', $input['items']['values']);
            if ($type === 'keyvalue') {
                $this->assertSame('', $input['items']['keys']);
            }
            $refilled = $this->htmlWithRow($cleared, 'items', '0', 'zero');
            $this->assertEmptyDefaultsPrecedeRows($refilled, 'items', $type === 'keyvalue');
            $input = $this->inputFromHtml($refilled);
            $this->assertSame(['0'], $input['items']['values']);
            if ($type === 'keyvalue') {
                $this->assertSame(['zero'], $input['items']['keys']);
            }
        }
    }

    public function test_empty_create_repopulate_omission_and_clear_have_distinct_http_storage(): void
    {
        foreach (['list', 'keyvalue'] as $type) {
            session()->flush();
            $url = '/explicit-empty/top-'.$type;
            $html = $this->htmlWithoutRows($this->get($url)->assertOk()->getContent());
            $input = $this->inputFromHtml($html);
            $input['name'] = 'empty';
            $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $record = ExplicitEmptyRecord::latest('id')->firstOrFail();
            $this->assertSame([], $record->items);
            $this->assertSame('[]', $record->getRawOriginal('items'));
            $url .= '/'.$record->id;
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame([], $this->rowValues($html, 'items'));
            $input = $this->inputFromHtml($this->htmlWithRow($html, 'items', '0', 'zero'));
            $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $expected = $type === 'list' ? ['0'] : ['zero' => '0'];
            $this->assertSame($expected, $record->fresh()->items);
            $this->put($url, ['name' => 'omitted'])->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($expected, $record->fresh()->items);
            $this->assertSame('omitted', $record->fresh()->name);
            $html = $this->htmlWithoutRows($this->get($url)->assertOk()->getContent());
            $this->put($url, $this->inputFromHtml($html))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame([], $record->fresh()->items);
            $this->assertSame('[]', $record->fresh()->getRawOriginal('items'));
            $this->assertSame([], $this->rowValues($this->get($url)->assertOk()->getContent(), 'items'));
        }
    }

    public function test_unrelated_error_preserves_database_and_redisplays_the_explicit_empty_submission(): void
    {
        foreach (['list' => ['stored', '0'], 'keyvalue' => ['stored' => '0']] as $type => $values) {
            session()->flush();
            $record = ExplicitEmptyRecord::create(['name' => 'before', 'items' => $values]);
            $url = '/explicit-empty/top-'.$type.'/'.$record->id;
            $input = $this->inputFromHtml($this->htmlWithoutRows($this->get($url)->assertOk()->getContent()));
            $input['name'] = '';
            $this->from($url)->put($url, $input)->assertRedirect($url)->assertSessionHasErrors('name');
            $this->assertSame($values, $record->fresh()->items);
            $this->assertSame('before', $record->fresh()->name);
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame([], $this->rowValues($html, 'items'));
            $this->assertSame('', $this->inputFromHtml($html)['items']['values']);
        }
    }

    public function test_positive_minimum_rejects_clear_preserves_database_and_redisplays_no_rows(): void
    {
        $record = ExplicitEmptyRecord::create(['name' => 'before', 'items' => ['stored']]);
        $url = '/explicit-empty/min-list/'.$record->id;
        $input = $this->inputFromHtml($this->htmlWithoutRows($this->get($url)->assertOk()->getContent()));
        $input['name'] = 'attempted change';
        $this->from($url)->put($url, $input)->assertRedirect($url)->assertSessionHasErrors('items.values');
        $this->assertSame(['stored'], $record->fresh()->items);
        $this->assertSame('before', $record->fresh()->name);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertSame([], $this->rowValues($html, 'items'));
        $this->assertSame('', $this->inputFromHtml($html)['items']['values']);
        session()->flush();
        $this->put($url, ['name' => 'omitted'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['stored'], $record->fresh()->items);
    }

    public function test_zero_minimum_and_maximum_accept_empty_markers_but_reject_repopulated_rows(): void
    {
        $url = '/explicit-empty/zero-list';
        $input = $this->inputFromHtml($this->htmlWithoutRows($this->get($url)->assertOk()->getContent()));
        $input['name'] = 'empty';
        $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
        $record = ExplicitEmptyRecord::firstOrFail();
        $this->assertSame([], $record->items);
        $url .= '/'.$record->id;
        $html = $this->get($url)->assertOk()->getContent();
        $this->put($url, $this->inputFromHtml($html))->assertRedirect()->assertSessionHasNoErrors();
        $input = $this->inputFromHtml($this->htmlWithRow($html, 'items', '0'));
        $this->from($url)->put($url, $input)->assertRedirect($url)->assertSessionHasErrors('items.values');
        $this->assertSame([], $record->fresh()->items);
        $this->assertSame(['0'], $this->rowValues($this->get($url)->assertOk()->getContent(), 'items'));
    }

    public function test_embedded_clear_uses_scoped_names_and_preserves_the_submitted_sibling(): void
    {
        foreach (['list' => ['one', '0'], 'keyvalue' => ['theme' => 'blue', 'zero' => '0']] as $type => $values) {
            $record = ExplicitEmptyRecord::create([
                'name' => 'before', 'items' => ['unrelated top-level'],
                'settings' => ['sibling' => 'keep', 'items' => $values],
            ]);
            $url = '/explicit-empty/embedded-'.$type.'/'.$record->id;
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertEmptyDefaultsPrecedeRows($html, 'settings[items]', $type === 'keyvalue');
            $input = $this->inputFromHtml($html);
            $this->assertArrayNotHasKey('items', $input);
            $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(['sibling' => 'keep', 'items' => $values], $record->fresh()->settings);
            $input = $this->inputFromHtml($this->htmlWithoutRows($html, 'settings[items]'));
            $input['settings']['sibling'] = 'changed sibling';
            $this->assertSame('', $input['settings']['items']['values']);
            $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(['sibling' => 'changed sibling', 'items' => []], $record->fresh()->settings);
            $this->assertSame(['unrelated top-level'], $record->fresh()->items);
            $this->assertSame([], $this->rowValues($this->get($url)->assertOk()->getContent(), 'settings[items]'));
        }
    }

    public function test_has_many_two_children_round_trip_then_only_first_child_is_cleared(): void
    {
        foreach (['list' => ['one', '0'], 'keyvalue' => ['theme' => 'blue', 'zero' => '0']] as $type => $values) {
            $record = ExplicitEmptyRecord::create(['name' => 'parent']);
            $first = $record->children()->create(['items' => $values]);
            $second = $record->children()->create(['items' => $values]);
            $url = '/explicit-empty/nested-'.$type.'/'.$record->id;
            $html = $this->get($url)->assertOk()->getContent();
            foreach ([$first, $second] as $child) {
                $this->assertEmptyDefaultsPrecedeRows($html, 'children['.$child->id.'][items]', $type === 'keyvalue');
            }
            $input = $this->inputFromHtml($html);
            $this->assertArrayNotHasKey('items', $input);
            $this->assertCount(2, $input['children']);
            $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($values, $first->fresh()->items);
            $this->assertSame($values, $second->fresh()->items);
            $cleared = $this->htmlWithoutRows($html, 'children['.$first->id.'][items]');
            $input = $this->inputFromHtml($cleared);
            $this->assertSame('', $input['children'][$first->id]['items']['values']);
            $this->assertSame(array_values($values), $input['children'][$second->id]['items']['values']);
            $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame([], $first->fresh()->items);
            $this->assertSame('[]', $first->fresh()->getRawOriginal('items'));
            $this->assertSame($values, $second->fresh()->items);
            $this->assertSame(2, $record->children()->count());
        }
    }

    public function test_scoped_empty_old_input_does_not_restore_stored_or_unscoped_rows(): void
    {
        foreach (['list', 'keyvalue'] as $type) {
            foreach (['embedded', 'nested'] as $scope) {
                session()->flush();
                $stored = $type === 'list' ? ['stored'] : ['stored' => 'value'];
                $record = ExplicitEmptyRecord::create([
                    'name' => 'before', 'settings' => ['sibling' => 'keep', 'items' => $stored],
                ]);
                $first = $record->children()->create(['items' => $stored]);
                $second = $record->children()->create(['items' => $stored]);
                $url = '/explicit-empty/'.$scope.'-'.$type.'/'.$record->id;
                $name = $scope === 'embedded' ? 'settings[items]' : 'children['.$first->id.'][items]';
                $html = $this->get($url)->assertOk()->getContent();
                $input = $this->inputFromHtml($this->htmlWithoutRows($html, $name));
                $input['name'] = '';
                $input['items'] = ['keys' => ['wrong unscoped'], 'values' => ['wrong unscoped']];
                if ($scope === 'embedded') {
                    $input['settings']['sibling'] = 'submitted sibling';
                } else {
                    $input['children'][$second->id]['items']['values'] = ['submitted sibling'];
                    if ($type === 'keyvalue') {
                        $input['children'][$second->id]['items']['keys'] = ['submitted key'];
                    }
                }
                $this->from($url)->put($url, $input)->assertRedirect($url)->assertSessionHasErrors('name');
                $html = $this->get($url)->assertOk()->getContent();
                $this->assertSame([], $this->rowValues($html, $name));
                $this->assertStringNotContainsString('wrong unscoped', $html);
                $redisplayed = $this->inputFromHtml($html);
                if ($scope === 'embedded') {
                    $this->assertSame('submitted sibling', $redisplayed['settings']['sibling']);
                    $this->assertSame('', $redisplayed['settings']['items']['values']);
                } else {
                    $this->assertSame('', $redisplayed['children'][$first->id]['items']['values']);
                    $this->assertSame(['submitted sibling'], $redisplayed['children'][$second->id]['items']['values']);
                    if ($type === 'keyvalue') {
                        $this->assertSame(['submitted key'], $redisplayed['children'][$second->id]['items']['keys']);
                    }
                }
                $this->assertSame('before', $record->fresh()->name);
                $this->assertSame(['sibling' => 'keep', 'items' => $stored], $record->fresh()->settings);
                $this->assertSame($stored, $first->fresh()->items);
                $this->assertSame($stored, $second->fresh()->items);
            }
        }
    }

    public function test_blank_rows_nulls_and_zero_are_not_treated_as_an_empty_collection(): void
    {
        $this->assertSame(['', null, '0'], (new ListField('items'))->prepare(['values' => ['', null, '0']]));
        $this->assertSame(['blank' => '', 'nullable' => null, 'zero' => '0'], (new KeyValue('items'))->prepare([
            'keys' => ['blank', 'nullable', 'zero'], 'values' => ['', null, '0'],
        ]));
        foreach (['list', 'keyvalue'] as $type) {
            session()->flush();
            $url = '/explicit-empty/top-'.$type;
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame([''], $this->rowValues($html, 'items'), 'The existing initial blank row is retained.');
            $input = $this->inputFromHtml($html);
            $input['name'] = 'blank row';
            $this->post($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $record = ExplicitEmptyRecord::latest('id')->firstOrFail();
            $this->assertSame($type === 'list' ? [null] : ['' => null], $record->items);
            $input = ['name' => 'three rows', 'items' => ['values' => ['', null, '0']]];
            if ($type === 'keyvalue') {
                $input['items']['keys'] = ['blank', 'nullable', 'zero'];
            }
            $url .= '/'.$record->id;
            $this->put($url, $input)->assertRedirect()->assertSessionHasNoErrors();
            $expected = $type === 'list' ? [null, null, '0'] : ['blank' => null, 'nullable' => null, 'zero' => '0'];
            $this->assertSame($expected, $record->fresh()->items);
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(['', '', '0'], $this->rowValues($html, 'items'));
            $this->put($url, $this->inputFromHtml($html))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($expected, $record->fresh()->items);
        }
    }

    public function test_key_value_duplicate_key_validation_and_invalid_redisplay_are_preserved(): void
    {
        $record = ExplicitEmptyRecord::create(['name' => 'before', 'items' => ['stored' => '0']]);
        $url = '/explicit-empty/top-keyvalue/'.$record->id;
        $input = ['name' => 'attempted', 'items' => ['keys' => ['duplicate', 'duplicate'], 'values' => ['', '0']]];
        $this->from($url)->put($url, $input)->assertRedirect($url)
            ->assertSessionHasErrors(['items.keys.0', 'items.keys.1']);
        $this->assertSame('before', $record->fresh()->name);
        $this->assertSame(['stored' => '0'], $record->fresh()->items);
        $redisplayed = $this->inputFromHtml($this->get($url)->assertOk()->getContent());
        $this->assertSame(['duplicate', 'duplicate'], $redisplayed['items']['keys']);
        $this->assertSame(['', '0'], $redisplayed['items']['values']);
    }

    public function test_only_exact_empty_parts_are_normalized_and_malformed_values_are_not_coerced(): void
    {
        foreach ([null, ''] as $empty) {
            $this->assertSame([], (new ListField('items'))->prepare(['values' => $empty]));
            $this->assertSame([], (new KeyValue('items'))->prepare(['keys' => $empty, 'values' => $empty]));
            foreach ([
                [(new ListField('items'))->min(1), false],
                [(new ListField('items'))->min(0)->max(0), true],
                [(new ListField('items'))->rules('required'), true],
            ] as [$field, $passes]) {
                $validator = $field->getValidator(['items' => ['values' => $empty]]);
                $this->assertSame($passes, $validator->passes());
                $this->assertSame([], $validator->getData()['items']['values']);
            }
        }
        foreach (['unexpected', '0', 0, false, true] as $malformed) {
            $validator = (new ListField('items'))->min(0)->max(1)->getValidator(['items' => ['values' => $malformed]]);
            $this->assertFalse($validator->passes());
            $this->assertSame($malformed, $validator->getData()['items']['values']);
            foreach ([new ListField('items'), new KeyValue('items')] as $field) {
                $error = null;
                try {
                    $field->prepare(['keys' => $malformed, 'values' => $malformed]);
                } catch (\TypeError $exception) {
                    $error = $exception;
                }
                $this->assertInstanceOf(\TypeError::class, $error, 'Malformed scalars retain the pre-existing native type failure.');
            }
        }
        $validator = (new ListField('items'))->min(1)->getValidator(['items' => ['unexpected' => 'value']]);
        $this->assertArrayNotHasKey('values', $validator->getData()['items']);
        $this->assertFalse((new ListField('items'))->min(1)->getValidator([]));
        $this->assertFalse((new KeyValue('items'))->rules('string')->getValidator([]));
    }

    public function test_custom_validators_receive_original_input_before_empty_normalization(): void
    {
        foreach ([(new ListField('items'))->min(2)->max(3), (new KeyValue('items'))->rules('required')] as $field) {
            $field->validator(function ($input) {
                return ['custom' => $input];
            });
            foreach ([
                [], ['items' => ['keys' => null, 'values' => null]],
                ['items' => ['keys' => '', 'values' => '']], ['items' => 'malformed'],
            ] as $input) {
                $this->assertSame(['custom' => $input], $field->getValidator($input));
            }
        }
    }

    public function test_empty_old_input_markers_and_arrays_render_no_rows_at_the_scoped_error_key(): void
    {
        request()->setLaravelSession(session()->driver());
        $this->app['view']->share('errors', new ViewErrorBag());
        foreach (['list', 'keyValue'] as $type) {
            foreach ([null, '', []] as $empty) {
                session()->flashInput([
                    'settings' => ['items' => ['keys' => $empty, 'values' => $empty]],
                    'items' => ['keys' => ['wrong unscoped'], 'values' => ['wrong unscoped']],
                ]);
                $field = (new Form(new ExplicitEmptyRecord()))->{$type}('items')
                    ->setElementName('settings[items]')->setErrorKey('settings.items')
                    ->value($type === 'list' ? ['stored'] : ['stored' => 'value']);
                $html = $field->render();
                $this->assertSame([], $this->rowValues($html, 'settings[items]'));
                $this->assertEmptyDefaultsPrecedeRows($html, 'settings[items]', $type === 'keyValue');
                $this->assertStringNotContainsString('wrong unscoped', $html);
                $this->assertStringNotContainsString('value="stored"', $html);
                $this->assertSame('', $this->inputFromHtml($html)['settings']['items']['values']);
            }
        }
    }

    public function test_scoped_key_value_names_old_input_and_errors_are_escaped(): void
    {
        request()->setLaravelSession(session()->driver());
        session()->flashInput([
            'settings' => ['items' => ['keys' => ['<key> & "quoted"'], 'values' => ['<value> & "quoted"']]],
            'items' => ['keys' => ['wrong unscoped'], 'values' => ['wrong unscoped']],
        ]);
        $errors = new ViewErrorBag();
        $errors->put('default', new MessageBag([
            'settings.items.keys.0' => ['<key error>'],
            'settings.items.values.0' => ['<value error>'],
            'items.keys.0' => ['wrong unscoped error'],
        ]));
        $this->app['view']->share('errors', $errors);
        $name = 'settings["items<>]';
        $field = (new Form(new ExplicitEmptyRecord()))->keyValue('items')
            ->setElementName($name)->setErrorKey('settings.items')->value(['stored' => 'value']);
        $html = $field->render();
        $crawler = new Crawler($html);
        $this->assertEmptyDefaultsPrecedeRows($html, $name, true);
        $this->assertSame(['<value> & "quoted"'], $this->rowValues($html, $name));
        $this->assertSame('<key> & "quoted"', $crawler->filter('tbody input')->first()->attr('value'));
        foreach ($crawler->filter('input[name]') as $input) {
            $this->assertContains($input->getAttribute('name'), [
                $name.'[keys]', $name.'[values]', $name.'[keys][]', $name.'[values][]',
            ]);
            $this->assertFalse($input->hasAttribute('items'));
        }
        $this->assertStringContainsString('&lt;key error&gt;', $html);
        $this->assertStringContainsString('&lt;value error&gt;', $html);
        $this->assertStringNotContainsString('wrong unscoped', $html);
        $this->assertSame(0, $crawler->filter('key, value')->count());
    }
}

class ExplicitEmptyRecord extends Model
{
    protected $table = 'explicit_empty_records';
    protected $guarded = [];
    protected $casts = ['items' => 'array', 'settings' => 'array'];
    public $timestamps = false;

    public function children()
    {
        return $this->hasMany(ExplicitEmptyChild::class, 'parent_id');
    }
}

class ExplicitEmptyChild extends Model
{
    protected $table = 'explicit_empty_children';
    protected $guarded = [];
    protected $casts = ['items' => 'array'];
    public $timestamps = false;
}
