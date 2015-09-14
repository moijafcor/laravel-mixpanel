<?php namespace GeneaLabs\LaravelMixpanel\Listeners;

use GeneaLabs\LaravelMixpanel\LaravelMixpanel;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as CurrentRequest;

class LaravelMixpanelEventHandler
{
    protected $guard;
    protected $mixPanel;
    protected $request;

    /**
     *
     */
    public function __construct(Guard $guard, LaravelMixpanel $mixPanel)
    {
        $this->guard = $guard;
        $this->mixPanel = $mixPanel;
    }

    /**
     * @param array $event
     */
    public function onUserLoginAttempt(array $event)
    {
        $email = (array_key_exists('email', $event) ? $event['email'] : '');
        $password = (array_key_exists('password', $event) ? $event['password'] : '');

        $user = App::make(config('auth.model'))->where('email', $email)->first();

        if ($user
            && ! $this->guard->getProvider()->validateCredentials($user, ['email' => $email, 'password' => $password])
        ) {
            $this->mixPanel->identify($user->id);
            $this->mixPanel->track('Session', ['Status' => 'Login Failed']);
        }
    }

    /**
     * @param Model $user
     */
    public function onUserLogin(Model $user)
    {
        if ($user->name) {
            $nameParts = explode(' ', $user->name);
            array_filter($nameParts);
            $lastName = array_pop($nameParts);
            $firstName = implode(' ', $nameParts);
            $user->first_name = $firstName;
            $user->last_name = $lastName;
        }

        $data = [
            '$first_name' => $user->first_name,
            '$last_name' => $user->last_name,
            '$email' => $user->email,
            '$created' => $user->created_at->format('Y-m-d\Th:i:s'),
        ];
        array_filter($data);

        $this->mixPanel->identify($user->id);
        $request = App::make(Request::class);
        $this->mixPanel->people->set($user->id, $data, $request->ip());
        $this->mixPanel->track('Session', ['Status' => 'Logged In']);
    }

    /**
     * @param Model $user
     */
    public function onUserLogout(Model $user = null)
    {
        if ($user) {
            $this->mixPanel->identify($user->id);
        }

        $this->mixPanel->track('Session', ['Status' => 'Logged Out']);
    }

    public function onViewLoad($route)
    {
        $data = [];
        $routeAction = $route->getAction();
        $request = App::make(Request::class);

        if (Auth::check()) {
            $this->mixPanel->identify(Auth::user()->id);
            $this->mixPanel->people->set(Auth::user()->id, [], $request->ip());
        }

        if (is_array($routeAction) && array_key_exists('as', $routeAction)) {
            $data['Route'] = $routeAction['as'];
        }

        $data['Url'] = $request->getUri();
        $data['Referrer'] = $request->header('referer');

        $this->mixPanel->track('Page View', $data);
    }

    /**
     * @param Dispatcher $events
     */
    public function subscribe(Dispatcher $events)
    {
        $events->listen('auth.attempt', 'GeneaLabs\LaravelMixpanel\Listeners\LaravelMixpanelEventHandler@onUserLoginAttempt');
        $events->listen('auth.login', 'GeneaLabs\LaravelMixpanel\Listeners\LaravelMixpanelEventHandler@onUserLogin');
        $events->listen('auth.logout', 'GeneaLabs\LaravelMixpanel\Listeners\LaravelMixpanelEventHandler@onUserLogout');
        $events->listen('router.matched', 'GeneaLabs\LaravelMixpanel\Listeners\LaravelMixpanelEventHandler@onViewLoad');
    }
}
