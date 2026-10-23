<?php

namespace Encore\Admin\Middleware;

use Encore\Admin\Auth\Permission as Checker;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;

class Permission
{
    /**
     * @var string
     */
    protected $middlewarePrefix = 'admin.permission:';

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     * @param array                    $args
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, ...$args)
    {
        if (config('admin.check_route_permission') === false) {
            return $next($request);
        }

        if (!Admin::user() || $this->shouldPassThrough($request)) {
            return $next($request);
        }

        if ($this->checkRoutePermission($request)) {
            return $next($request);
        }

        if (!Admin::user()->allPermissions()->first(function ($permission) use ($request) {
            return $permission->shouldPassThrough($request);
        })) {
            Checker::error();
        }

        return $next($request);
    }

    /**
     * Check every explicit permission rule before the default path permissions.
     *
     * @param Request $request
     *
     * @return bool
     */
    public function checkRoutePermission(Request $request)
    {
        $router = app('router');
        $permissionMiddleware = $router->getMiddleware()[rtrim($this->middlewarePrefix, ':')] ?? static::class;
        $hasExplicitPermission = false;

        foreach ($router->gatherRouteMiddleware($request->route()) as $middleware) {
            if (!is_string($middleware) || strpos($middleware, ':') === false) {
                continue;
            }

            [$class, $parameters] = explode(':', $middleware, 2);

            if ($class !== $permissionMiddleware && !is_a($class, self::class, true)) {
                continue;
            }

            $args = explode(',', $parameters);
            $method = array_shift($args);

            if (!method_exists(Checker::class, $method)) {
                throw new \InvalidArgumentException("Invalid permission method [$method].");
            }

            call_user_func_array([Checker::class, $method], [$args]);
            $hasExplicitPermission = true;
        }

        return $hasExplicitPermission;
    }

    /**
     * Determine if the request has a URI that should pass through verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function shouldPassThrough($request)
    {
        // 下面这些路由不验证权限
        $excepts = array_merge(config('admin.auth.excepts', []), [
            'auth/login',
            'auth/logout',
            '_handle_action_',
            '_handle_form_',
            '_handle_selectable_',
            '_handle_renderable_',
        ]);

        return collect($excepts)
            ->map('admin_base_path')
            ->contains(function ($except) use ($request) {
                if ($except !== '/') {
                    $except = trim($except, '/');
                }

                return $request->is($except);
            });
    }
}
