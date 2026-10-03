<?php

use Encore\Admin\Auth\Database\Administrator;
use Encore\Admin\Auth\Database\Menu;

class MenuTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->be(Administrator::first(), 'admin');
    }

    public function testMenuIndex()
    {
        // Check rendered menu labels, not matching text in the sidebar or scripts.
        $this->visit('admin/auth/menu')
            ->seeInElement('.content-header h1', 'Menu')
            ->seeInElement('.breadcrumb', 'Auth')
            ->seeInElement('.dd-item[data-id="1"] > .dd-handle > strong', 'Dashboard')
            ->seeInElement('.dd-item[data-id="2"] > .dd-handle > strong', 'Admin')
            ->seeInElement('.dd-item[data-id="3"] > .dd-handle > strong', 'Users')
            ->seeInElement('.dd-item[data-id="4"] > .dd-handle > strong', 'Roles')
            ->seeInElement('.dd-item[data-id="5"] > .dd-handle > strong', 'Permission')
            ->seeInElement('.dd-item[data-id="6"] > .dd-handle > strong', 'Menu')
            ->seeInElement('.dd-item[data-id="7"] > .dd-handle > strong', 'Operation log');
    }

    public function testAddMenu()
    {
        $item = ['parent_id' => '0', 'title' => 'Test', 'uri' => 'test'];

        $this->visit('admin/auth/menu')
            ->seePageIs('admin/auth/menu')
            ->see('Menu')
            ->submitForm('Submit', $item)
            ->seePageIs('admin/auth/menu')
            ->seeInDatabase(config('admin.database.menu_table'), $item)
            ->assertEquals(8, Menu::count());

//        $this->expectException(\Laravel\BrowserKitTesting\HttpException::class);
//
//        $this->visit('admin')
//            ->see('Test')
//            ->click('Test');
    }

    public function testDeleteMenu()
    {
        $this->delete('admin/auth/menu/8')
            ->assertEquals(7, Menu::count());
    }

    public function testEditMenu()
    {
        $this->visit('admin/auth/menu/1/edit')
            ->see('Menu')
            ->submitForm('Submit', ['title' => 'blablabla'])
            ->seePageIs('admin/auth/menu')
            ->seeInDatabase(config('admin.database.menu_table'), ['title' => 'blablabla'])
            ->assertEquals(7, Menu::count());
    }

    public function testShowPage()
    {
        $this->visit('admin/auth/menu/1/edit')
            ->seePageIs('admin/auth/menu/1/edit');
    }

    public function testEditMenuParent()
    {
        $this->expectException(\Laravel\BrowserKitTesting\HttpException::class);

        $this->visit('admin/auth/menu/5/edit')
            ->see('Menu')
            ->submitForm('Submit', ['parent_id' => 5]);
    }
}
