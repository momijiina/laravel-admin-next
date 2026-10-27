const assert = require('node:assert/strict');
const fs = require('node:fs');
const net = require('node:net');
const path = require('node:path');
const {execFileSync, spawn} = require('node:child_process');
const {setTimeout: delay} = require('node:timers/promises');
const {chromium} = require('playwright');

const app = fs.realpathSync(process.env.FRESH_APP_PATH || '');
const metadata = JSON.parse(fs.readFileSync(path.join(app, '.fresh-app-smoke.json'), 'utf8'));
assert.equal(metadata.package_path, fs.realpathSync(path.join(__dirname, '../..')), 'Use an application created by provision.php for this checkout');
const artifacts = path.resolve(process.env.FRESH_APP_ARTIFACTS || path.join(__dirname, 'artifacts'));
fs.mkdirSync(artifacts, {recursive: true});
fs.copyFileSync(path.join(app, 'composer.lock'), path.join(artifacts, 'composer.lock'));
fs.writeFileSync(path.join(artifacts, 'skeleton.json'), JSON.stringify(metadata, null, 2) + '\n');

function readDatabase(code, ...args) {
  return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', ['-r', String.raw`
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  ` + code, ...args.map(String)], {cwd: app, encoding: 'utf8'}));
}

async function availablePort() {
  const socket = net.createServer();
  await new Promise((resolve, reject) => {
    socket.once('error', reject);
    socket.listen(0, '127.0.0.1', resolve);
  });
  const port = socket.address().port;
  await new Promise(resolve => socket.close(resolve));
  return port;
}

async function main() {
  const port = await availablePort();
  const base = 'http://127.0.0.1:' + port;
  const log = fs.openSync(path.join(artifacts, 'server.log'), 'w');
  const server = spawn(process.env.PHP_BINARY || 'php', [
    '-S', '127.0.0.1:' + port,
    path.join(app, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
  ], {cwd: path.join(app, 'public'), stdio: ['ignore', log, log]});
  fs.closeSync(log);
  let serverError;
  let serverClosed = false;
  const closed = new Promise(resolve => server.once('close', () => { serverClosed = true; resolve(); }));
  server.once('error', error => { serverError = error; });
  const stopServer = () => { if (!serverClosed && server.pid) server.kill('SIGTERM'); };
  process.once('exit', stopServer);
  let browser;
  let page;
  try {
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
      if (serverError) throw serverError;
      assert.equal(server.exitCode, null, 'PHP server must keep running');
      try {
        const response = await fetch(base + '/up', {signal: AbortSignal.timeout(1000)});
        if (response.status === 200) { ready = true; break; }
      } catch (_) { /* Wait only for this newly spawned loopback server. */ }
      await delay(100);
    }
    assert.ok(ready, 'PHP server should become ready');
    browser = await chromium.launch({
      headless: true,
      ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? {executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH} : {}),
    });
    const context = await browser.newContext();
    page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.setDefaultNavigationTimeout(15000);
    const failures = [];
    page.on('pageerror', error => failures.push('JavaScript: ' + error.message));
    page.on('response', response => {
      if (response.status() >= 400) failures.push(response.status() + ' ' + response.url());
    });
    page.on('requestfailed', request => {
      if (request.failure().errorText !== 'net::ERR_ABORTED') failures.push(request.failure().errorText + ' ' + request.url());
    });

    async function visit(route) {
      const response = await page.goto(base + route);
      assert.equal(response.status(), 200, 'GET ' + route);
      await page.waitForLoadState('networkidle');
      return response;
    }

    async function markMenuRefresh() {
      await page.evaluate(() => {
        document.querySelector('.dd').dataset.menuSmokeOldDom = 'true';
        window.menuSmokePjaxEnded = false;
        $(document).off('pjax:end.menuSmoke').one('pjax:end.menuSmoke', () => { window.menuSmokePjaxEnded = true; });
      });
    }

    await visit('/admin');
    assert.equal(new URL(page.url()).pathname, '/admin/auth/login');
    assert.ok(await page.locator('input[name="_token"]').inputValue(), 'Login form includes CSRF token');
    const rejected = await context.request.post(base + '/admin/auth/login', {
      form: {username: 'admin', password: 'admin'}, maxRedirects: 0,
    });
    assert.equal(rejected.status(), 419, 'Real web middleware rejects a missing CSRF token');
    console.log('PASS guest redirect and missing-CSRF rejection');

    await page.locator('input[name="username"]').fill('admin');
    await page.locator('input[name="password"]').fill('admin');
    const [dashboard] = await Promise.all([
      page.waitForResponse(response => response.url() === base + '/admin' && response.request().isNavigationRequest()),
      page.locator('button[type="submit"]').click(),
    ]);
    assert.equal(dashboard.status(), 200, 'Dashboard response after login');
    await page.waitForURL(base + '/admin');
    await page.locator('.content-header h1').filter({hasText: 'Dashboard'}).waitFor();
    await page.locator('.toast-success').filter({hasText: 'Login successful'}).waitFor();
    assert.ok((await context.cookies()).some(cookie => cookie.httpOnly), 'Browser has an HTTP-only session cookie');
    console.log('PASS browser login and success toast after session serialization');

    for (const route of ['/admin/auth/users', '/admin/auth/roles', '/admin/auth/permissions', '/admin/auth/menu', '/admin/auth/logs', '/admin/auth/setting']) {
      await visit(route);
      assert.equal(new URL(page.url()).pathname, route);
      console.log('PASS ' + route);
    }

    // The default upload disk is intentionally not configured. Visiting settings
    // flashes its normal configuration alert; the next request must render it.
    await visit('/admin/auth/roles');
    await page.locator('.alert').filter({hasText: 'Disk [admin] not configured'}).waitFor();
    console.log('PASS serialized configuration alert');

    await page.locator('a[href="' + base + '/admin/auth/roles/create"]').click();
    await page.locator('input[name="slug"]').waitFor();
    const suffix = Date.now().toString(36);
    const roleName = 'Browser smoke ' + suffix;
    await page.locator('input[name="slug"]').fill('browser-smoke-' + suffix);
    await page.locator('input[name="name"]').fill(roleName);
    await page.locator('.box-footer button[type="submit"]').click();
    const row = page.locator('tr').filter({hasText: roleName});
    await row.waitFor();
    await page.locator('.toast-success').filter({hasText: 'Save succeeded'}).waitFor();
    console.log('PASS role creation through the generated form and PJAX');

    const edit = row.locator('a[href$="/edit"]');
    const editUrl = await edit.getAttribute('href');
    await row.locator('.grid-dropdown-actions .dropdown-toggle').click();
    await edit.click();
    await page.waitForURL(editUrl);
    await page.locator('input[name="name"]').waitFor();
    assert.equal(await page.locator('input[name="name"]').inputValue(), roleName);
    await page.locator('input[name="name"]').fill(roleName + ' updated');
    await page.locator('.box-footer button[type="submit"]').click();
    await page.locator('tr').filter({hasText: roleName + ' updated'}).waitFor();
    await visit('/admin/auth/roles');
    await page.locator('tr').filter({hasText: roleName + ' updated'}).waitFor();
    console.log('PASS role edit and persistence after a full page load');

    await visit('/admin/auth/menu');
    const menuToken = await page.locator('form input[name="_token"]').first().inputValue();
    const emptyMenu = {title: 'Menu N ' + suffix, uri: ''};
    const menus = [
      {title: 'Menu R ' + suffix + ' &amp;<svg/onload=window.mt=1>', uri: 'auth/roles?label=" onmouseover="window.mu=1 &amp;<b>'},
      {title: 'Menu E ' + suffix + ' &amp;<svg/onload=window.mt=1>', uri: 'https://example.invalid/" onmouseover="window.mu=1 &amp;<b>'},
      emptyMenu,
    ];
    const menuIcon = "fa-bars' onmouseover='window.mi=1";
    async function createMenu(menu) {
      const created = await context.request.post(base + '/admin/auth/menu', {
        form: {_token: menuToken, parent_id: '0', icon: menuIcon, ...menu},
        maxRedirects: 0,
      });
      assert.equal(created.status(), 302, 'Save menu with browser cookies and real CSRF token');
      assert.equal(new URL(created.headers().location, base).pathname, '/admin/auth/menu');
      return readDatabase(String.raw`
        $model = config('admin.database.menu_model');
        echo json_encode($model::where('title', $argv[1])->sole()->getKey(), JSON_THROW_ON_ERROR);
      `, menu.title);
    }
    for (const menu of menus) {
      assert.ok([...menu.title].length <= 50 && menuIcon.length <= 50 && menu.uri.length <= 255, 'Fixture fits the shipped schema');
      await createMenu(menu);
    }
    const savedUri = readDatabase(String.raw`
      $model = config('admin.database.menu_model');
      echo json_encode($model::where('title', $argv[1])->sole()->getRawOriginal('uri'), JSON_THROW_ON_ERROR);
    `, emptyMenu.title);
    assert.equal(savedUri, null, 'Web middleware stores the empty URI as database NULL');

    async function checkLiteralMenus() {
      for (const menu of menus) {
        const title = page.locator('.dd-handle > strong').filter({hasText: menu.title});
        assert.equal(await title.count(), 1, 'Saved menu title stays literal');
        assert.equal(await title.textContent(), menu.title);
        assert.equal(await title.locator('*').count(), 0, 'Title does not create SVG or other elements');
        const branch = title.locator('..');
        const icon = branch.locator(':scope > i');
        assert.equal(await icon.getAttribute('class'), 'fa ' + menuIcon);
        assert.equal(await icon.getAttribute('onmouseover'), null);
        const link = branch.locator(':scope > a.dd-nodrag');
        const expectedUri = menu.uri.startsWith('https://') ? menu.uri : base + '/admin' + (menu.uri ? '/' + menu.uri : '');
        assert.equal(await link.getAttribute('href'), expectedUri);
        assert.equal(await link.textContent(), expectedUri);
        assert.equal(await link.locator('*').count(), 0);
        assert.equal(await link.getAttribute('onmouseover'), null);
        await icon.dispatchEvent('mouseover');
        await link.dispatchEvent('mouseover');
      }
      assert.deepEqual(await page.evaluate(() => [window.mt || 0, window.mi || 0, window.mu || 0]), [0, 0, 0], 'Stored menu values do not create event handlers');
    }

    await visit('/admin/auth/menu');
    await checkLiteralMenus();
    for (const selector of ['.sidebar-menu > li > a', '.sidebar-form .dropdown-menu a']) {
      const rootLink = page.locator(selector).filter({hasText: emptyMenu.title});
      assert.equal(await rootLink.count(), 1, 'NULL URI menu appears in ' + selector);
      assert.equal(await rootLink.getAttribute('href'), base + '/admin', 'NULL URI links to the admin root');
    }
    const defaultParent = page.locator('.dd-item[data-id="2"]');
    assert.equal(await defaultParent.locator(':scope > .dd-handle > i').getAttribute('class'), 'fa fa-tasks');
    assert.equal(await defaultParent.locator(':scope > .dd-handle > a.dd-nodrag').count(), 0);
    assert.equal(await page.locator('.dd-item[data-id="4"] > .dd-handle > a.dd-nodrag').getAttribute('href'), base + '/admin/auth/roles');
    await defaultParent.locator(':scope > button[data-action="collapse"]').click();
    assert.equal(await defaultParent.locator(':scope > ol.dd-list').isVisible(), false);
    await defaultParent.locator(':scope > button[data-action="expand"]').click();
    assert.equal(await defaultParent.locator(':scope > ol.dd-list').isVisible(), true);
    await markMenuRefresh();
    const [menuRefresh] = await Promise.all([
      page.waitForResponse(response => new URL(response.url()).pathname === '/admin/auth/menu' && response.request().headers()['x-pjax'] === 'true'),
      page.locator('a[class*="tree-"][class*="-refresh"]').click(),
    ]);
    assert.equal(menuRefresh.status(), 200);
    await page.waitForFunction(() => window.menuSmokePjaxEnded && !document.querySelector('.dd[data-menu-smoke-old-dom]'));
    await page.waitForLoadState('networkidle');
    await checkLiteralMenus();
    console.log('PASS literal menu values and NULL URI root links after full page and PJAX loads');

    const roleId = Number(new URL(editUrl).pathname.split('/').at(-2));
    assert.ok(Number.isInteger(roleId) && roleId > 0, 'Use the role created by this browser run');
    const subtreeIds = [];
    for (let depth = 0; depth < 4; depth++) {
      subtreeIds.push(await createMenu({
        parent_id: subtreeIds.at(-1) || '0', title: 'Delete ' + depth + ' ' + suffix,
        icon: 'fa-bars', uri: 'auth/roles', 'roles[]': roleId,
      }));
    }
    const controlId = await createMenu({
      title: 'Keep ' + suffix, icon: 'fa-bars', uri: 'auth/roles', 'roles[]': roleId,
    });
    function deletionState() {
      return readDatabase(String.raw`
        $ids = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
        $menu = config('admin.database.menu_model');
        $role = config('admin.database.roles_model');
        $db = (new $menu())->getConnection();
        echo json_encode([
          'menus' => $menu::whereKey($ids)->orderBy('id')->pluck('id'),
          'pivots' => $db->table(config('admin.database.role_menu_table'))->whereIn('menu_id', $ids)
            ->orderBy('menu_id')->get(['menu_id', 'role_id']),
          'role' => $role::whereKey($argv[2])->exists(),
        ], JSON_THROW_ON_ERROR);
      `, JSON.stringify([...subtreeIds, controlId]), roleId);
    }
    const expectedState = ids => ({menus: ids, pivots: ids.map(id => ({menu_id: id, role_id: roleId})), role: true});
    assert.deepEqual(deletionState(), expectedState([...subtreeIds, controlId]), 'Every fixture has its role pivot before deletion');
    await visit('/admin/auth/menu');
    for (const id of subtreeIds) assert.equal(await page.locator('.dd-item[data-id="' + id + '"]').count(), 1);
    const deleteUrl = base + '/admin/auth/menu/' + subtreeIds[0];
    const refusalMessage = 'Deletion refused ' + suffix;
    const refuseDelete = route => {
      const request = route.request();
      if (request.method() === 'POST' && new URLSearchParams(request.postData()).get('_method') === 'delete') {
        return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({status: false, message: refusalMessage})});
      }
      return route.continue();
    };
    let refusedRefreshes = 0;
    const countRefusedRefresh = request => {
      if (new URL(request.url()).pathname === '/admin/auth/menu' && request.headers()['x-pjax'] === 'true') refusedRefreshes++;
    };
    await page.route(deleteUrl, refuseDelete);
    page.on('request', countRefusedRefresh);
    await page.evaluate(() => toastr.remove());
    await markMenuRefresh();
    await page.locator('.tree_branch_delete[data-id="' + subtreeIds[0] + '"]').click();
    await page.locator('.swal2-confirm').click();
    await page.locator('.swal2-title').filter({hasText: refusalMessage}).waitFor();
    await page.locator('.swal2-error').waitFor();
    await page.waitForLoadState('networkidle');
    assert.equal(refusedRefreshes, 0, 'Rejected deletion does not request a PJAX refresh');
    assert.equal(await page.locator('.toast-success').count(), 0, 'Rejected deletion does not show a success toast');
    assert.equal(await page.locator('.dd[data-menu-smoke-old-dom]').count(), 1, 'Rejected deletion preserves the current tree DOM');
    assert.deepEqual(deletionState(), expectedState([...subtreeIds, controlId]), 'Simulated rejection leaves all menu rows and pivots unchanged');
    await page.locator('.swal2-confirm').click();
    await page.unroute(deleteUrl, refuseDelete);
    page.off('request', countRefusedRefresh);
    console.log('PASS simulated deletion rejection shows an error without a success toast or PJAX refresh');

    await page.locator('.tree_branch_delete[data-id="' + subtreeIds[0] + '"]').click();
    await markMenuRefresh();
    const [deleted, deletionRefresh] = await Promise.all([
      page.waitForResponse(response => response.url() === deleteUrl && response.request().method() === 'POST'),
      page.waitForResponse(response => new URL(response.url()).pathname === '/admin/auth/menu' && response.request().headers()['x-pjax'] === 'true'),
      page.locator('.swal2-confirm').click(),
    ]);
    assert.equal(new URLSearchParams(deleted.request().postData()).get('_method'), 'delete');
    assert.equal(deleted.status(), 200);
    assert.equal((await deleted.json()).status, true, 'Tree deletion reports success');
    assert.equal(deletionRefresh.status(), 200);
    await page.waitForFunction(() => window.menuSmokePjaxEnded && !document.querySelector('.dd[data-menu-smoke-old-dom]'));
    await page.waitForLoadState('networkidle');
    assert.deepEqual(deletionState(), expectedState([controlId]), 'Delete every descendant and pivot while preserving the unrelated menu and role');
    for (const id of subtreeIds) assert.equal(await page.locator('.dd-item[data-id="' + id + '"]').count(), 0);
    assert.equal(await page.locator('.dd-item[data-id="' + controlId + '"]').count(), 1);
    await page.locator('.swal2-success').waitFor();
    await page.locator('.swal2-confirm').click();
    console.log('PASS four-level menu deletion, descendant/pivot cleanup and unrelated record preservation after PJAX');

    await page.locator('.user-menu > a').click();
    await Promise.all([
      page.waitForURL(base + '/admin/auth/login'),
      page.locator('a[href$="/auth/logout"]').click(),
    ]);
    await visit('/admin/auth/roles');
    assert.equal(new URL(page.url()).pathname, '/admin/auth/login');
    console.log('PASS logout and protected route redirect');
    assert.deepEqual(failures, [], 'No JavaScript errors or failed page/asset requests');
    console.log('PASS browser smoke on official skeleton ' + metadata.skeleton_tag);
  } catch (error) {
    if (page) await page.screenshot({path: path.join(artifacts, 'failure.png'), fullPage: true}).catch(() => {});
    const appLog = path.join(app, 'storage/logs/laravel.log');
    if (fs.existsSync(appLog)) fs.copyFileSync(appLog, path.join(artifacts, 'laravel.log'));
    throw error;
  } finally {
    try {
      if (browser) await browser.close();
    } finally {
      stopServer();
      await Promise.race([closed, delay(3000, undefined, {ref: false})]);
      if (!serverClosed && server.pid) {
        server.kill('SIGKILL');
        await closed;
      }
      process.removeListener('exit', stopServer);
    }
  }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
