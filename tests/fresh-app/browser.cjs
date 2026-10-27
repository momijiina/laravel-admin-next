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
    for (const menu of menus) {
      assert.ok([...menu.title].length <= 50 && menuIcon.length <= 50 && menu.uri.length <= 255, 'Fixture fits the shipped schema');
      const created = await context.request.post(base + '/admin/auth/menu', {
        form: {_token: menuToken, parent_id: '0', title: menu.title, icon: menuIcon, uri: menu.uri},
        maxRedirects: 0,
      });
      assert.equal(created.status(), 302, 'Save menu with browser cookies and real CSRF token');
      assert.equal(new URL(created.headers().location, base).pathname, '/admin/auth/menu');
    }
    const savedUri = execFileSync(process.env.PHP_BINARY || 'php', ['-r', String.raw`
      require 'vendor/autoload.php';
      $app = require 'bootstrap/app.php';
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      $model = config('admin.database.menu_model');
      echo json_encode($model::where('title', $argv[1])->sole()->getRawOriginal('uri'), JSON_THROW_ON_ERROR);
    `, emptyMenu.title], {cwd: app, encoding: 'utf8'});
    assert.equal(JSON.parse(savedUri), null, 'Web middleware stores the empty URI as database NULL');

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
    await page.evaluate(() => {
      document.querySelector('.dd').dataset.menuSmokeOldDom = 'true';
      window.menuSmokePjaxEnded = false;
      $(document).one('pjax:end.menuSmoke', () => { window.menuSmokePjaxEnded = true; });
    });
    const [menuRefresh] = await Promise.all([
      page.waitForResponse(response => new URL(response.url()).pathname === '/admin/auth/menu' && response.request().headers()['x-pjax'] === 'true'),
      page.locator('a[class*="tree-"][class*="-refresh"]').click(),
    ]);
    assert.equal(menuRefresh.status(), 200);
    await page.waitForFunction(() => window.menuSmokePjaxEnded && !document.querySelector('.dd[data-menu-smoke-old-dom]'));
    await page.waitForLoadState('networkidle');
    await checkLiteralMenus();
    console.log('PASS literal menu values and NULL URI root links after full page and PJAX loads');

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
