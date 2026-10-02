<?php

namespace LaravelAdminNext\Integration;

use Encore\Admin\Actions\Interactor\Form;
use Encore\Admin\AdminServiceProvider;
use Encore\Admin\Auth\Database\Administrator;
use Orchestra\Testbench\TestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Exercises both production Crawler consumers without replacing package classes. */
class DomCrawlerCompatibilityTest extends TestCase
{
    private $page;
    private $status = 200;

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
        $app['config']->set('session.driver', 'array');
    }

    protected function defineRoutes($router)
    {
        $router->get('/crawler', function () {
            return response($this->page, $this->status)->header('X-Original', 'kept');
        })->middleware(['web', 'admin.pjax']);
        $router->get('/crawler-redirect', function () {
            return redirect('/destination');
        })->middleware(['web', 'admin.pjax']);
    }

    private function authenticate(): void
    {
        // A real session guard and real model; these parser tests do not need a DB.
        $this->actingAs(new Administrator(['id' => 1, 'username' => 'parser-test']), 'admin');
    }

    private function pjax($container = '#content')
    {
        return $this->get('/crawler?next=1', ['X-PJAX' => 'true', 'X-PJAX-CONTAINER' => $container]);
    }

    public function test_pjax_preserves_unicode_entities_nested_markup_and_response_metadata(): void
    {
        $this->authenticate();
        $this->status = 201;
        $this->page = '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            .'<title>Café &amp; 日本 &#169;</title></head><body><aside>outside</aside>'
            .'<div id="content"><p>日本 café 😀 &#169; &#x1F600; &amp; &lt; &quot;</p>'
            .'<input value="A &amp; B"></div></body></html>';
        $this->pjax()->assertStatus(201)->assertHeader('X-Original', 'kept')
            ->assertHeader('X-PJAX-URL', '/crawler?next=1')
            ->assertContent('<title>Café &amp; 日本 ©</title>'
                .'<p>日本 café 😀 © 😀 &amp; &lt; "</p><input value="A &amp; B">');
    }

    public function test_pjax_uses_declared_legacy_charset(): void
    {
        $this->authenticate();
        $this->page = '<html><head><meta charset="ISO-8859-1"><title>Caf'.chr(233)
            .'</title></head><body><div id="content">Caf'.chr(233).'</div></body></html>';
        $this->pjax()->assertOk()->assertContent('<title>Café</title>Café');
    }

    public function test_pjax_preserves_table_serialization_and_uses_first_selector_match(): void
    {
        $this->authenticate();
        $this->page = '<html><head><title>Table</title></head><body>'
            .'<div class="content"><table><tr><td>one</td></tr></table></div>'
            .'<div class="content">second</div></body></html>';
        $this->pjax('.content')->assertOk()->assertContent('<title>Table</title><table><tr><td>one</td></tr></table>');
    }

    public function test_pjax_empty_container_still_returns_title(): void
    {
        $this->authenticate();
        $this->page = '<html><head><title>Empty</title></head><body><div id="content"></div></body></html>';
        $this->pjax()->assertOk()->assertContent('<title>Empty</title>')->assertHeader('X-PJAX-URL', '/crawler?next=1');
    }

    public function test_pjax_missing_title_container_and_invalid_selector_keep_original_response(): void
    {
        $this->authenticate();
        foreach ([
            ['<div id="content">no title</div>', '#content'],
            ['<html><head><title>T</title></head><body>no container</body></html>', '#content'],
            ['<html><head><title>T</title></head><body><div id="content">body</div></body></html>', '['],
        ] as [$html, $selector]) {
            $this->page = $html;
            $this->pjax($selector)->assertOk()->assertContent($html)->assertHeaderMissing('X-PJAX-URL');
        }
    }

    public function test_non_pjax_guest_and_redirect_responses_bypass_parsing(): void
    {
        $this->page = '<html><head><title>T</title></head><body><div id="content">body</div></body></html>';
        $this->pjax()->assertOk()->assertContent($this->page)->assertHeaderMissing('X-PJAX-URL');
        $this->authenticate();
        $this->get('/crawler')->assertOk()->assertContent($this->page)->assertHeaderMissing('X-PJAX-URL');
        $this->get('/crawler-redirect', ['X-PJAX' => 'true', 'X-PJAX-CONTAINER' => '#content'])
            ->assertRedirect('/destination')->assertHeaderMissing('X-PJAX-URL');
    }

    public function test_action_form_mutates_only_first_match_and_preserves_fragments(): void
    {
        $action = new ActionForCrawlerCompatibility();
        $form = new Form($action);
        $modal = $form->getModalId();
        $cases = [
            ['<div><input class="target" value="Café &amp; 日本" modal="old"><input class="target"><span>Other &lt;x&gt;</span></div>', '.target',
                '<div><input class="target" value="Café &amp; 日本" modal="'.$modal.'"><input class="target"><span>Other &lt;x&gt;</span></div>'],
            ['<select><option>A &amp; B</option></select>', 'select', '<select modal="'.$modal.'"><option>A &amp; B</option></select>'],
            ['<textarea>café &amp; 日本</textarea>', 'textarea', '<textarea modal="'.$modal.'">café &amp; 日本</textarea>'],
            ['<div><table><tr><td>x</td></tr></table></div>', 'table', '<div><table modal="'.$modal.'"><tr><td>x</td></tr></table></div>'],
        ];
        foreach ($cases as [$input, $selector, $expected]) {
            $output = $form->addElementAttr($input, $selector);
            $this->assertSame($expected, $output);
            $crawler = new Crawler($output);
            $this->assertSame($modal, $crawler->filter($selector)->first()->attr('modal'));
            $this->assertCount(1, $crawler->filter('[modal]'));
        }
    }

    public function test_action_form_missing_selector_retains_existing_error(): void
    {
        $form = new Form(new ActionForCrawlerCompatibility());
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('setAttribute');
        $form->addElementAttr('<input name="present">', '.absent');
    }
}

/** Concrete consumer action, not a replacement for any production class. */
class ActionForCrawlerCompatibility extends \Encore\Admin\Actions\Action
{
}
