<?php

/**
 * Standalone middleware regression; no Composer dependencies or database.
 * Run: php tests/compatibility/operation_log_redaction.php
 */
namespace Illuminate\Http {
    class Request
    {
        private $input;

        public function __construct(array $input) { $this->input = $input; }
        public function input() { return $this->input; }
        public function path() { return 'admin/auth/users/1'; }
        public function method() { return 'PUT'; }
        public function getClientIp() { return '127.0.0.1'; }
    }
}

namespace Encore\Admin\Facades {
    class Admin
    {
        public static function user() { return (object) ['id' => 7]; }
    }
}

namespace Encore\Admin\Auth\Database {
    class OperationLog
    {
        public static $logs = [];
        public static $fail = false;

        public static function create($log)
        {
            if (self::$fail) {
                throw new \RuntimeException('Synthetic database failure');
            }
            self::$logs[] = $log;
        }
    }
}

namespace {
    error_reporting(E_ALL);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });

    $configuration = [];
    function config($key, $default = null)
    {
        global $configuration;
        return array_key_exists($key, $configuration) ? $configuration[$key] : $default;
    }

    require __DIR__.'/../../src/Middleware/LogOperation.php';

    class LogOperationProbe extends \Encore\Admin\Middleware\LogOperation
    {
        public $enabled = true;
        protected function shouldLogOperation(\Illuminate\Http\Request $request)
        {
            // Route/method/auth decisions are outside this focused regression.
            return $this->enabled;
        }
    }

    function expect($condition, $message)
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    function capture(array $input, array $settings = [])
    {
        global $configuration;
        $configuration = $settings;
        \Encore\Admin\Auth\Database\OperationLog::$logs = [];
        $request = new \Illuminate\Http\Request($input);
        $called = 0;
        $result = (new LogOperationProbe())->handle($request, function ($received) use ($request, $input, &$called) {
            ++$called;
            expect($received === $request, 'The original request must reach the next middleware.');
            expect($received->input() === $input, 'Redaction must not change downstream request input.');
            return 'synthetic-response';
        });
        expect($result === 'synthetic-response' && $called === 1, 'The response and continuation must be preserved.');
        expect($request->input() === $input, 'Redaction must not mutate request input.');
        $logs = \Encore\Admin\Auth\Database\OperationLog::$logs;
        expect(count($logs) === 1, 'Exactly one operation log should be created.');
        expect($logs[0]['user_id'] === 7 && $logs[0]['path'] === 'admin/auth/users/1'
            && $logs[0]['method'] === 'PUT' && $logs[0]['ip'] === '127.0.0.1', 'Log metadata must remain intact.');
        return json_decode($logs[0]['input'], true);
    }

    $sensitive = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'new_password_confirmation', '_token', 'token', 'access_token',
        'refresh_token', 'remember_token', 'api_token', 'api_key', 'secret',
        'client_secret', 'authorization',
    ];
    $input = [];
    foreach ($sensitive as $field) {
        $input[$field] = 'synthetic-sensitive-placeholder';
        $input[strtoupper($field)] = 'synthetic-sensitive-placeholder';
    }
    $redacted = capture($input);
    foreach ($input as $field => $value) {
        expect($redacted[$field] === '[REDACTED]', 'Sensitive field was persisted: '.$field);
    }

    $ordinary = ['name' => 'Example', 'zero' => 0, 'false' => false, 'empty' => '', 'null' => null,
        'unicode' => '日本語', 'password_hint' => 'unchanged', 'token_count' => 3];
    $nested = $ordinary + ['users' => [
        ['password' => ['nested' => 'synthetic-sensitive-placeholder'], 'name' => 'First'],
        ['profile' => ['Password_Confirmation' => null, 'api_key' => false], 'name' => 'Second'],
    ]];
    $expected = $ordinary + ['users' => [
        ['password' => '[REDACTED]', 'name' => 'First'],
        ['profile' => ['Password_Confirmation' => '[REDACTED]', 'api_key' => '[REDACTED]'], 'name' => 'Second'],
    ]];
    expect(capture($nested) === $expected, 'Nested arrays, sensitive values of any type, and ordinary values must be handled.');
    expect(capture([]) === [], 'Empty input must remain empty.');
    expect(capture($ordinary) === $ordinary, 'Ordinary input must be unchanged.');

    $custom = ['pin' => 'synthetic-pin', 'items' => [['PIN' => 'synthetic-pin']], 'password' => 'synthetic-password'];
    $expected = ['pin' => '[REDACTED]', 'items' => [['PIN' => '[REDACTED]']], 'password' => '[REDACTED]'];
    expect(capture($custom, ['admin.operation_log.redact_fields' => ['PiN']]) === $expected,
        'Configured field names must be additive, case-insensitive and recursive.');
    expect(capture(['password' => 'synthetic-password'], ['admin.operation_log.redact_fields' => []])
        === ['password' => '[REDACTED]'], 'Empty configuration must retain secure defaults.');

    foreach ([null, false, 42, 'pin', new \stdClass()] as $invalidConfiguration) {
        expect(capture(['password' => 'synthetic-password'], [
            'admin.operation_log.redact_fields' => $invalidConfiguration,
        ]) === ['password' => '[REDACTED]'], 'Malformed configuration must preserve secure defaults and request handling.');
    }
    expect(capture($custom, ['admin.operation_log.redact_fields' => [
        null, false, 42, ['nested-invalid'], new \stdClass(), 'PiN',
    ]]) === $expected, 'Non-string configured entries must be ignored while valid names still apply.');

    $request = new \Illuminate\Http\Request(['password' => 'synthetic-password']);
    $middleware = new LogOperationProbe();
    $middleware->enabled = false;
    \Encore\Admin\Auth\Database\OperationLog::$logs = [];
    expect($middleware->handle($request, function () { return 'skipped'; }) === 'skipped'
        && \Encore\Admin\Auth\Database\OperationLog::$logs === [], 'Excluded requests must remain unlogged.');

    $middleware->enabled = true;
    \Encore\Admin\Auth\Database\OperationLog::$fail = true;
    expect($middleware->handle($request, function () { return 'continued'; }) === 'continued',
        'A database exception must still allow the request to continue.');
    \Encore\Admin\Auth\Database\OperationLog::$fail = false;

    try {
        $middleware->handle($request, function () { throw new \RuntimeException('Synthetic downstream failure'); });
        throw new \LogicException('Downstream exceptions must propagate.');
    } catch (\RuntimeException $exception) {
        expect($exception->getMessage() === 'Synthetic downstream failure', 'Unexpected downstream exception.');
    }
    $logs = \Encore\Admin\Auth\Database\OperationLog::$logs;
    expect(json_decode($logs[0]['input'], true) === ['password' => '[REDACTED]'],
        'Input must already be redacted when a downstream request fails.');

    echo "PASS: operation-log redaction, nested/custom/default fields, metadata, request preservation and continuation.\n";
}
