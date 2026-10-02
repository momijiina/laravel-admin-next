<?php

namespace Encore\Admin\Middleware;

use Encore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Encore\Admin\Facades\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LogOperation
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, \Closure $next)
    {
        if ($this->shouldLogOperation($request)) {
            $log = [
                'user_id' => Admin::user()->id,
                'path'    => substr($request->path(), 0, 255),
                'method'  => $request->method(),
                'ip'      => $request->getClientIp(),
                'input'   => json_encode($this->redactInput($request->input())),
            ];

            try {
                OperationLogModel::create($log);
            } catch (\Exception $exception) {
                // pass
            }
        }

        return $next($request);
    }

    /**
     * Redact sensitive field names at every array depth without changing input.
     *
     * Configured names extend the defaults, including for published configs.
     *
     * @param array $input
     *
     * @return array
     */
    protected function redactInput(array $input)
    {
        $additionalFields = config('admin.operation_log.redact_fields', []);
        $additionalFields = is_array($additionalFields)
            ? array_filter($additionalFields, 'is_string')
            : [];

        $fields = array_merge([
            'password', 'password_confirmation', 'current_password',
            'new_password', 'new_password_confirmation', '_token', 'token',
            'access_token', 'refresh_token', 'remember_token', 'api_token',
            'api_key', 'secret', 'client_secret', 'authorization',
        ], $additionalFields);

        $fields = array_map('strtolower', $fields);

        foreach ($input as $key => $value) {
            if (in_array(strtolower((string) $key), $fields, true)) {
                $input[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $input[$key] = $this->redactInput($value);
            }
        }

        return $input;
    }

    /**
     * @param Request $request
     *
     * @return bool
     */
    protected function shouldLogOperation(Request $request)
    {
        return config('admin.operation_log.enable')
            && !$this->inExceptArray($request)
            && $this->inAllowedMethods($request->method())
            && Admin::user();
    }

    /**
     * Whether requests using this method are allowed to be logged.
     *
     * @param string $method
     *
     * @return bool
     */
    protected function inAllowedMethods($method)
    {
        $allowedMethods = collect(config('admin.operation_log.allowed_methods'))->filter();

        if ($allowedMethods->isEmpty()) {
            return true;
        }

        return $allowedMethods->map(function ($method) {
            return strtoupper($method);
        })->contains($method);
    }

    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return bool
     */
    protected function inExceptArray($request)
    {
        foreach (config('admin.operation_log.except') as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            $methods = [];

            if (Str::contains($except, ':')) {
                list($methods, $except) = explode(':', $except);
                $methods = explode(',', $methods);
            }

            $methods = array_map('strtoupper', $methods);

            if ($request->is($except) &&
                (empty($methods) || in_array($request->method(), $methods))) {
                return true;
            }
        }

        return false;
    }
}
