const assert = require('node:assert/strict');
const path = require('node:path');

module.exports = async function restrictedUserSmoke({browser, base, artifacts, readDatabase}) {
  const suffix = Date.now().toString(36);
  const fixture = readDatabase(String.raw`
    $users = config('admin.database.users_model');
    $roles = config('admin.database.roles_model');
    $permissions = config('admin.database.permissions_model');
    $menus = config('admin.database.menu_model');
    $suffix = $argv[1];
    $fixture = (new $users())->getConnection()->transaction(function () use ($users, $roles, $permissions, $menus, $suffix) {
      $role = $roles::create(['name' => 'Restricted role '.$suffix, 'slug' => 'restricted-'.$suffix]);
      $read = $permissions::create([
        'name' => 'Read roles '.$suffix, 'slug' => 'read-roles-'.$suffix,
        'http_method' => ['GET'], 'http_path' => "/\n/auth/roles",
      ]);
      $denied = $permissions::create([
        'name' => 'Denied permission '.$suffix, 'slug' => 'denied-'.$suffix,
        'http_method' => ['GET'], 'http_path' => '/auth/users',
      ]);
      $role->permissions()->attach($read->getKey());
      $user = $users::create([
        'username' => 'restricted-'.$suffix, 'name' => 'Restricted user '.$suffix,
        'password' => Illuminate\Support\Facades\Hash::make('restricted-smoke-password'),
      ]);
      $user->roles()->attach($role->getKey());
      $adminRole = $roles::where('slug', 'administrator')->sole();
      $uri = 'auth/roles?id='.$role->getKey();
      $makeMenu = function ($title, $roleId, $permission, $parent = 0) use ($menus, $suffix, $uri) {
        $menu = $menus::create([
          'parent_id' => $parent, 'order' => 100, 'title' => $title.' '.$suffix,
          'icon' => 'fa-list', 'uri' => $uri, 'permission' => $permission,
        ]);
        $menu->roles()->attach($roleId);
        return $menu;
      };
      $allowed = $makeMenu('Allowed roles', $role->getKey(), $read->slug);
      $blocked = [
        $makeMenu('Hidden role leaf', $adminRole->getKey(), $read->slug),
        $makeMenu('Hidden permission leaf', $role->getKey(), $denied->slug),
      ];
      $roleParent = $makeMenu('Hidden role parent', $adminRole->getKey(), $read->slug);
      $permissionParent = $makeMenu('Hidden permission parent', $role->getKey(), $denied->slug);
      $blocked[] = $roleParent;
      $blocked[] = $permissionParent;
      // Each child is independently allowed; a denied ancestor must still hide it.
      $blocked[] = $makeMenu('Child of hidden role', $role->getKey(), $read->slug, $roleParent->getKey());
      $blocked[] = $makeMenu('Child of hidden permission', $role->getKey(), $read->slug, $permissionParent->getKey());
      return [
        'userId' => $user->getKey(), 'username' => $user->username, 'userName' => $user->name,
        'roleId' => $role->getKey(), 'roleName' => $role->name, 'roleSlug' => $role->slug,
        'permissionSlug' => $read->slug, 'allowedTitle' => $allowed->title, 'allowedUri' => $uri,
        'blockedTitles' => array_map(fn ($menu) => $menu->title, $blocked),
        'seededTitles' => $menus::whereIn('id', [2, 3, 4, 5, 6, 7])->orderBy('id')->pluck('title'),
        'seededParentRoles' => $menus::findOrFail(2)->roles()->pluck('slug'),
        'denial' => trans('admin.deny'),
        'checks' => [config('admin.check_route_permission'), config('admin.check_menu_roles'), config('admin.enable_menu_search')],
      ];
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
  `, suffix);
  assert.deepEqual(fixture.checks, [true, true, true], 'Use the normal route, menu role and search configuration');
  assert.deepEqual(fixture.seededTitles, ['Admin', 'Users', 'Roles', 'Permission', 'Menu', 'Operation log']);
  assert.deepEqual(fixture.seededParentRoles, ['administrator'], 'The seeded Admin parent is restricted to administrators');

  function identityState() {
    return readDatabase(String.raw`
      $model = config('admin.database.users_model');
      $user = $model::findOrFail($argv[1]);
      echo json_encode([
        'administrator' => $user->isAdministrator(),
        'roles' => $user->roles()->pluck('slug'),
        'directPermissions' => $user->permissions()->pluck('slug'),
        'permissions' => $user->allPermissions()->map(fn ($permission) => [
          'slug' => $permission->slug, 'methods' => $permission->http_method, 'path' => $permission->http_path,
        ])->values(),
      ], JSON_THROW_ON_ERROR);
    `, fixture.userId);
  }
  const expectedIdentity = {
    administrator: false, roles: [fixture.roleSlug], directPermissions: [],
    permissions: [{slug: fixture.permissionSlug, methods: ['GET'], path: '/\n/auth/roles'}],
  };
  assert.deepEqual(identityState(), expectedIdentity, 'The fixture has only the intended GET permission and no administrator role');

  const context = await browser.newContext();
  let page;
  try {
    assert.deepEqual(await context.cookies(), [], 'Restricted login starts without the administrator session');
    page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.setDefaultNavigationTimeout(15000);
    const failures = [];
    const expectedDenials = new Map();
    page.on('pageerror', error => failures.push('JavaScript: ' + error.message));
    page.on('response', response => {
      if (response.status() < 400) return;
      const key = response.request().method() + ' ' + response.url();
      if (expectedDenials.get(key) === response.status()) {
        expectedDenials.delete(key);
      } else {
        failures.push(response.status() + ' ' + key);
      }
    });
    page.on('requestfailed', request => failures.push(request.failure().errorText + ' ' + request.url()));

    async function visit(route) {
      const response = await page.goto(base + route);
      assert.equal(response.status(), 200, 'Restricted GET ' + route);
      await page.waitForLoadState('networkidle');
      return response;
    }

    async function pjaxClick(link, pathname) {
      await page.evaluate(() => {
        document.querySelector('#pjax-container .content').dataset.restrictedSmokeOldDom = 'true';
        window.restrictedSmokePjaxEnded = false;
        $(document).off('pjax:end.restrictedSmoke').one('pjax:end.restrictedSmoke', () => { window.restrictedSmokePjaxEnded = true; });
      });
      const [response] = await Promise.all([
        page.waitForResponse(response => new URL(response.url()).pathname === pathname && response.request().headers()['x-pjax'] === 'true'),
        link.click(),
      ]);
      assert.equal(response.status(), 200, 'Existing PJAX response contract for ' + pathname);
      await page.waitForFunction(() => window.restrictedSmokePjaxEnded && !document.querySelector('[data-restricted-smoke-old-dom]'));
      await page.waitForLoadState('networkidle');
      return response;
    }

    async function checkIdentity() {
      assert.equal((await page.locator('.user-panel .info > p').textContent()).trim(), fixture.userName);
      assert.equal((await page.locator('.user-menu > a > span').textContent()).trim(), fixture.userName);
    }

    async function checkMenus(selector) {
      const titles = (await page.locator(selector).allTextContents()).map(title => title.trim());
      assert.equal(titles.filter(title => title === fixture.allowedTitle).length, 1, 'Allowed menu appears in ' + selector);
      for (const title of [...fixture.seededTitles, ...fixture.blockedTitles]) {
        assert.equal(titles.includes(title), false, 'Restricted menu is absent from ' + selector + ': ' + title);
      }
    }

    async function checkRolesPage() {
      assert.equal(new URL(page.url()).pathname, '/admin/auth/roles');
      const row = page.locator('.grid-table tbody tr').filter({hasText: fixture.roleSlug});
      await row.waitFor();
      assert.ok((await row.textContent()).includes(fixture.roleName), 'Allowed roles page renders the known fixture row');
      await checkIdentity();
    }

    await visit('/admin');
    assert.equal(new URL(page.url()).pathname, '/admin/auth/login');
    assert.ok(await page.locator('input[name="_token"]').inputValue());
    await page.locator('input[name="username"]').fill(fixture.username);
    await page.locator('input[name="password"]').fill('restricted-smoke-password');
    const [dashboard] = await Promise.all([
      page.waitForResponse(response => response.url() === base + '/admin' && response.request().isNavigationRequest()),
      page.locator('button[type="submit"]').click(),
    ]);
    assert.equal(dashboard.status(), 200, 'Restricted browser login reaches the allowed dashboard');
    await page.locator('.content-header h1').filter({hasText: 'Dashboard'}).waitFor();
    await page.waitForLoadState('networkidle');
    await checkIdentity();
    await checkMenus('.sidebar-menu a');
    if (await page.locator('body').evaluate(body => body.classList.contains('sidebar-collapse'))) {
      await page.locator('.sidebar-toggle').click();
    }
    const search = page.locator('.sidebar-form .autocomplete');
    await search.waitFor({state: 'visible'});
    await search.fill('Users');
    await search.press('End');
    const usersResult = page.locator('.sidebar-form .dropdown-menu a').filter({hasText: /^Users$/});
    assert.equal(await usersResult.isVisible(), false, 'Searching Users must not reveal a child of the restricted Admin parent');
    await checkMenus('.sidebar-form .dropdown-menu a');
    console.log('PASS restricted sidebar and search hide denied leaves and descendants of denied parents');

    await search.fill(fixture.allowedTitle);
    await search.press('End');
    const allowedSearch = page.locator('.sidebar-form .dropdown-menu a').filter({hasText: fixture.allowedTitle});
    await allowedSearch.waitFor({state: 'visible'});
    assert.equal(await allowedSearch.getAttribute('href'), base + '/admin/' + fixture.allowedUri);
    await pjaxClick(allowedSearch, '/admin/auth/roles');
    await checkRolesPage();
    console.log('PASS restricted user searches and opens the allowed roles grid through PJAX');

    const deniedUsers = await visit('/admin/auth/users');
    assert.equal(new URL(page.url()).pathname, '/admin/auth/users');
    assert.match(await deniedUsers.text(), /<html[\s>]/i, 'Direct denial keeps the existing full HTML response');
    await page.locator('#pjax-container .alert-danger').filter({hasText: fixture.denial}).waitFor();
    assert.equal(await page.locator('.grid-table').count(), 0, 'Denied users route does not render its grid');
    await checkIdentity();
    await checkMenus('.sidebar-menu a');
    await checkMenus('.sidebar-form .dropdown-menu a');

    await visit('/admin/' + fixture.allowedUri);
    await checkRolesPage();
    const deniedCreate = await pjaxClick(page.locator('.grid-create-btn a'), '/admin/auth/roles/create');
    assert.doesNotMatch(await deniedCreate.text(), /<html[\s>]/i, 'Create denial is the actual PJAX fragment');
    await page.locator('#pjax-container .alert-danger').filter({hasText: fixture.denial}).waitFor();
    assert.equal(await page.locator('input[name="slug"]').count(), 0, 'Denied Create click does not render a role form');
    assert.equal(await page.locator('.grid-table').count(), 0, 'PJAX denial replaces the previous roles grid');
    console.log('PASS restricted direct users navigation and grid Create click render permission denials');

    function roleState() {
      return readDatabase(String.raw`
        $model = config('admin.database.roles_model');
        $db = (new $model())->getConnection();
        $state = [];
        foreach ([
          'roles_table' => ['id'], 'permissions_table' => ['id'],
          'role_users_table' => ['role_id', 'user_id'],
          'role_permissions_table' => ['role_id', 'permission_id'],
          'user_permissions_table' => ['user_id', 'permission_id'],
          'role_menu_table' => ['role_id', 'menu_id'],
        ] as $table => $columns) {
          $query = $db->table(config('admin.database.'.$table));
          foreach ($columns as $column) $query->orderBy($column);
          $state[$table] = $query->get();
        }
        echo json_encode($state, JSON_THROW_ON_ERROR);
      `);
    }
    const beforeWrites = roleState();
    for (const attempt of [
      {method: 'POST', route: '/admin/auth/roles', fields: {name: 'Forbidden role ' + suffix, slug: 'forbidden-' + suffix}},
      {method: 'PUT', route: '/admin/auth/roles/' + fixture.roleId, fields: {name: 'Forbidden edit ' + suffix, slug: fixture.roleSlug}},
      {method: 'DELETE', route: '/admin/auth/roles/' + fixture.roleId, fields: {}},
    ]) {
      const key = attempt.method + ' ' + base + attempt.route;
      expectedDenials.set(key, 403);
      const result = await page.evaluate(async ({method, route, fields}) => {
        const token = document.querySelector('meta[name="csrf-token"]').content;
        const response = await fetch(route, {
          method, credentials: 'same-origin',
          headers: {'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json'},
          body: new URLSearchParams({_token: token, ...fields}),
        });
        return {status: response.status, redirected: response.redirected, body: await response.json()};
      }, attempt);
      assert.equal(result.status, 403, 'Restricted AJAX ' + attempt.method + ' is forbidden with a valid CSRF token');
      assert.equal(result.redirected, false);
      assert.equal(result.body.message, fixture.denial);
      assert.equal(expectedDenials.has(key), false, 'Observe only the expected 403 response for ' + attempt.method);
      assert.deepEqual(roleState(), beforeWrites, 'Denied ' + attempt.method + ' preserves roles, permissions and related pivots');
    }
    assert.deepEqual(identityState(), expectedIdentity, 'The restricted account keeps exactly its original grants');
    assert.deepEqual(failures, [], 'No unexpected restricted-user JavaScript or page/asset failures');
    console.log('PASS restricted AJAX role writes and deletion return 403 without changing role or permission state');
  } catch (error) {
    if (page) await page.screenshot({path: path.join(artifacts, 'restricted-user-failure.png'), fullPage: true}).catch(() => {});
    throw error;
  } finally {
    await context.close();
  }
};
