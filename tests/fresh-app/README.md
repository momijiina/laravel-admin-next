# Fresh application browser smoke

This runner installs the local package in an official Laravel application with
its own freshly resolved production dependencies. It uses PHP's HTTP server and
Chromium, including real cookies, CSRF middleware, database sessions, and PJAX.
It does not use Testbench or the repository's historical root development graph.

The official skeleton archives are pinned to these commits, verified against the
upstream tags on 2026-10-24:

| Laravel | Skeleton tag | Commit |
| --- | --- | --- |
| 12 | `v12.12.2` | `945f4e5a9fd3695dc0ee512f497c650fb82cfbb8` |
| 13 | `v13.11.0` | `06d016a364a37430eec9cbc52209adce3d7667ce` |

The skeleton's framework constraint selects current compatible dependencies on
each run. Composer's normal platform and advisory checks remain enabled, and
the runner also checks installed platform requirements and audits the locked
production graph. Playwright is pinned in `package.json` and `package-lock.json`.

Use PHP 8.4 with DOM, XML, mbstring, fileinfo, PDO SQLite, Composer 2, Node 24, and
the normal Composer requirements for downloading/extracting archives:

```bash
npm ci --prefix tests/fresh-app --ignore-scripts
npm exec --prefix tests/fresh-app -- playwright install --with-deps chromium
task_fixture=$(mktemp -d)
php tests/fresh-app/provision.php 13 "$task_fixture/app"
FRESH_APP_PATH="$task_fixture/app" npm --prefix tests/fresh-app test
```

Select `12` instead of `13` to exercise Laravel 12. Provisioning refuses an
existing destination and never removes a directory. The application, its
SQLite database, and downloaded dependencies remain in that disposable directory
for inspection. The browser script accepts only a marked application produced
by this provisioner for the current checkout, starts a server bound to
`127.0.0.1` on an available port, and stops its own server on completion/failure.
It does not accept an external base URL. Set
`PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/path/to/chromium` to use an existing local
Chromium installation; CI uses Playwright's downloaded Chromium. `PHP_BINARY`
can select the PHP executable used by the HTTP server.

Provisioning runs automatic package discovery through Composer's normal
scripts, `vendor:publish`, `admin:install`, and `config:cache`, `route:cache`, and
`view:cache`. It checks the loaded provider, generated route, seed, cached state,
and official database cache/session settings. In particular, Laravel 13 keeps
the skeleton's JSON session serialization; no PHP serialization workaround is
applied. The browser then checks:

- Guest redirection, a real missing-CSRF HTTP 419, and form login with cookies.
- The login success toast after a separate request reads the session.
- Users, roles, permissions, menu, operation log, and settings pages.
- The normal missing-upload-disk alert across a request boundary.
- Role creation and editing through the generated form/PJAX, persisted values
  after a full page load, and logout followed by protected-route redirection.
- Menu creation using authenticated browser requests with a real CSRF token;
  literal title/icon/URI rendering after full and PJAX loads, plus the seeded
  parent's icon, child link and expand/collapse controls. See the
  [menu rendering scope and URL-scheme boundary](../integration/MENU_TREE_RENDERING.md).
- No JavaScript exceptions or unsuccessful page/asset responses.

The seeded `admin` / `admin` credentials and all role records are disposable local
test data. This smoke does not change the package's default credential policy.
The official skeleton does not define the package's `admin` upload disk; that
configuration alert is intentionally exercised. Uploads and image processing
are outside this runner's scope, as are external databases, extensions, other
browsers, reverse proxies, and a full application compatibility guarantee.

The dedicated workflow runs Laravel 12 and 13 on PHP 8.4. On browser failure it retains
the dependency lock, skeleton metadata, HTTP/application logs, and a screenshot
for seven days. It does not upload the environment file or SQLite database.
Provisioning failures remain visible in the workflow's command output.
Locally these artifacts default to the ignored `tests/fresh-app/artifacts/`
directory; `FRESH_APP_ARTIFACTS` selects another directory.

The initial 2026-10-24 browser run on Laravel 13.38.0 reproduced an HTTP 500 after
successful login: JSON sessions reloaded the notification `MessageBag` as an
array, while the toast view called `get()` on it. The same failure occurred with
configuration/routes/views cached and uncached. This workflow covers that
separate-request boundary, which the existing in-process fixtures did not.

After the view fix, both complete browser flows passed locally on 2026-10-24
with Laravel 12.70.1 / 13.38.0, PHP 8.4.27, Node 24.19.0, Playwright 1.62.1,
and installed Chromium 154.0.8037.57. Restoring the old toast view only inside a
disposable Laravel 13 application made this runner fail at the explicit
dashboard `500 !== 200` assertion; its failure screenshot and application log
were captured and its HTTP server stopped. GitHub subsequently reported all
63 checks successful for merged PR #127, including both fresh-application
browser jobs, as checked on 2026-10-24. That hosted result covers the PR #127
revision; later browser additions need their own CI run.

The menu-rendering extension also passed locally on both of those framework
versions and the same browser/tool versions on 2026-10-24. It waits for the real
`pjax:end` event and replacement of a marked DOM node before checking the
refreshed tree. Preloading the original `MenuController` only in the disposable
Laravel 13 server made the runner fail at its explicit literal-title assertion;
the working checkout stayed fixed and failure artifacts were retained.
