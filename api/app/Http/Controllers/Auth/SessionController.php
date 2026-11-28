<?php

namespace App\Http\Controllers\Auth;

use App\Auth\OwnerAccounts;
use App\Auth\SessionMark;
use App\Auth\TenantUserProvider;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetRequestLocale;
use App\Http\Resources\SessionResource;
use App\Models\User;
use App\Support\Http\ProblemException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SessionController extends Controller
{
    private const LOGIN_FIELDS = ['email', 'password'];

    public function __construct(private readonly TenantContext $tenant) {}

    public function store(Request $request): JsonResponse
    {
        // Without an Origin or Referer of the web app there is no session and no CSRF check.
        if (! $request->hasSession()) {
            throw new ProblemException('forbidden', 403);
        }

        $credentials = $this->validateLogin($request);

        /** @var TenantUserProvider $provider */
        $provider = Auth::createUserProvider('users');
        $user = $provider->retrieveByCredentials(['email' => OwnerAccounts::normalizeEmail($credentials['email'])]);

        if (! $provider->passwordMatches($user, $credentials['password'])) {
            throw new ProblemException('invalid_credentials', 422);
        }

        /** @var User $user */
        $this->ensureRememberToken($user);

        $guard = $this->guard();

        // Signing in over another session ends that session first.
        if ($request->session()->has($guard->getName())) {
            $guard->logoutCurrentDevice();
            $request->session()->invalidate();
        }

        // Regenerates the session id and destroys the old row. Never "remember me".
        $guard->login($user, false);
        SessionMark::put($request->session(), $user);
        SetRequestLocale::apply($user->locale);

        return $this->tenant->run($user->id, fn () => SessionResource::respond($user));
    }

    public function show(Request $request): JsonResponse
    {
        return SessionResource::respond($request->user());
    }

    public function destroy(Request $request): Response
    {
        // logout() would rotate remember_token and sign out every other device.
        $this->guard()->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    private function guard(): StatefulGuard
    {
        return Auth::guard('web');
    }

    /** @return array{email: string, password: string} */
    private function validateLogin(Request $request): array
    {
        $rules = [
            'email' => ['bail', 'required', 'string', 'email', 'max:254'],
            'password' => ['bail', 'required', 'string', 'max:200'],
        ];

        // The contract refuses unknown fields.
        foreach (array_diff(array_keys($request->post()), self::LOGIN_FIELDS) as $unknown) {
            $rules[$unknown] = ['prohibited'];
        }

        $validated = Validator::make($request->post(), $rules)->validate();

        return ['email' => $validated['email'], 'password' => $validated['password']];
    }

    /** Only fills a missing token: changing an existing one would sign out other devices. */
    private function ensureRememberToken(User $user): void
    {
        if ((string) $user->getRememberToken() !== '') {
            return;
        }

        $this->tenant->run($user->id, function () use ($user): void {
            User::query()->whereKey($user->id)->whereNull('remember_token')
                ->update(['remember_token' => Str::random(60)]);
            $user->refresh();
        });
    }
}
