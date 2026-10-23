<?php

namespace Encore\Admin\Controllers;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form;
use Encore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @var string
     */
    protected $loginView = 'admin::login';

    /**
     * Show the login page.
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function getLogin()
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        return view($this->loginView);
    }

    /**
     * Handle a login request.
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function postLogin(Request $request)
    {
        try {
            $this->loginValidator($request->all())->validate();
        } catch (ValidationException $exception) {
            if ($exception->response === null && !$request->expectsJson()) {
                $exception->response = redirect($exception->redirectTo ?? url()->previous())
                    ->withInput($this->loginOldInput($request))
                    ->withErrors($exception->validator, $exception->errorBag);
            }

            throw $exception;
        }

        $throttleKey = config('admin.auth.login_throttle.enabled', true) ? $this->loginThrottleKey($request) : null;
        $maxAttempts = config('admin.auth.login_throttle.max_attempts', 5);
        $maxAttempts = is_int($maxAttempts) && $maxAttempts > 0 ? $maxAttempts : 5;

        if ($throttleKey !== null && RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            return back()->withInput($this->loginOldInput($request))->withErrors([
                $this->username() => $this->getLoginThrottleMessage(RateLimiter::availableIn($throttleKey)),
            ]);
        }

        $credentials = $request->only([$this->username(), 'password']);
        // Preserve Request::get() precedence and values without its deprecation.
        $remember = $request->attributes->get('remember', $request);
        if ($remember === $request) {
            if ($request->query->has('remember')) {
                $remember = $request->query->all()['remember'];
            } elseif ($request->request->has('remember')) {
                $remember = $request->request->all()['remember'];
            } else {
                $remember = false;
            }
        }

        if ($this->guard()->attempt($credentials, $remember)) {
            if ($throttleKey !== null) {
                RateLimiter::clear($throttleKey);
            }

            return $this->sendLoginResponse($request);
        }

        if ($throttleKey !== null) {
            $decaySeconds = config('admin.auth.login_throttle.decay_seconds', 60);
            $decaySeconds = is_int($decaySeconds) && $decaySeconds > 0 ? $decaySeconds : 60;
            RateLimiter::hit($throttleKey, $decaySeconds);
        }

        return back()->withInput($this->loginOldInput($request))->withErrors([
            $this->username() => $this->getFailedLoginMessage(),
        ]);
    }

    /**
     * Keep only the identity and checkbox state when redisplaying the login form.
     *
     * @param Request $request
     *
     * @return array
     */
    protected function loginOldInput(Request $request)
    {
        $input = is_string($request->input($this->username())) ? $request->only($this->username()) : [];

        if ($request->has('remember')) {
            $input['remember'] = (bool) $request->input('remember');
        }

        return $input;
    }

    /**
     * Separate guard identities, usernames and IPs without putting them in cache keys.
     *
     * @param Request $request
     *
     * @return string
     */
    protected function loginThrottleKey(Request $request)
    {
        $guard = $this->guard();
        $guardName = method_exists($guard, 'getName') ? $guard->getName() : (config('admin.auth.guard') ?: 'admin');

        return 'admin-login:'.hash('sha256', serialize([
            get_class($guard), $guardName, $this->username(), Str::lower($request->input($this->username())), $request->ip(),
        ]));
    }

    /**
     * @param int $seconds
     *
     * @return string
     */
    protected function getLoginThrottleMessage($seconds)
    {
        return Lang::has('auth.throttle')
            ? trans('auth.throttle', ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)])
            : "Too many login attempts. Please try again in {$seconds} seconds.";
    }

    /**
     * Get a validator for an incoming login request.
     *
     * @param array $data
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function loginValidator(array $data)
    {
        return Validator::make($data, [
            $this->username()   => 'required|string',
            'password'          => 'required|string',
        ]);
    }

    /**
     * User logout.
     *
     * @return Redirect
     */
    public function getLogout(Request $request)
    {
        $this->guard()->logout();

        $request->session()->invalidate();

        return redirect(config('admin.route.prefix'));
    }

    /**
     * User setting page.
     *
     * @param Content $content
     *
     * @return Content
     */
    public function getSetting(Content $content)
    {
        $form = $this->settingForm();
        $form->tools(
            function (Form\Tools $tools) {
                $tools->disableList();
                $tools->disableDelete();
                $tools->disableView();
            }
        );

        return $content
            ->title(trans('admin.user_setting'))
            ->body($form->edit(Admin::user()->id));
    }

    /**
     * Update user setting.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function putSetting()
    {
        return $this->settingForm()->update(Admin::user()->id);
    }

    /**
     * Model-form for user setting.
     *
     * @return Form
     */
    protected function settingForm()
    {
        $class = config('admin.database.users_model');

        $form = new Form(new $class());

        $form->display('username', trans('admin.username'));
        $form->text('name', trans('admin.name'))->rules('required');
        $form->image('avatar', trans('admin.avatar'));
        $form->password('password', trans('admin.password'))
            ->rules('nullable|string|confirmed')
            ->attribute('value', '');
        $form->password('password_confirmation', trans('admin.password_confirmation'))
            ->rules('nullable|string|same:password')
            ->attribute('value', '');

        $form->setAction(admin_url('auth/setting'));

        $form->ignore(['password_confirmation']);

        $form->saving(function (Form $form) {
            if ($form->password === null || $form->password === '') {
                $form->password = $form->model()->password;
            } else {
                $form->password = Hash::make($form->password);
            }
        });

        $form->saved(function () {
            admin_toastr(trans('admin.update_succeeded'));

            return redirect(admin_url('auth/setting'));
        });

        return $form;
    }

    /**
     * @return string|\Symfony\Component\Translation\TranslatorInterface
     */
    protected function getFailedLoginMessage()
    {
        return Lang::has('auth.failed')
            ? trans('auth.failed')
            : 'These credentials do not match our records.';
    }

    /**
     * Get the post login redirect path.
     *
     * @return string
     */
    protected function redirectPath()
    {
        if (method_exists($this, 'redirectTo')) {
            return $this->redirectTo();
        }

        return property_exists($this, 'redirectTo') ? $this->redirectTo : config('admin.route.prefix');
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function sendLoginResponse(Request $request)
    {
        admin_toastr(trans('admin.login_successful'));

        $request->session()->regenerate();

        return redirect()->intended($this->redirectPath());
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    protected function username()
    {
        return 'username';
    }

    /**
     * Get the guard to be used during authentication.
     *
     * @return \Illuminate\Contracts\Auth\StatefulGuard
     */
    protected function guard()
    {
        return Admin::guard();
    }
}
