<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Webkul\Customer\Repositories\CustomerGroupRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\SocialLogin\Repositories\CustomerSocialAccountRepository;

/**
 * "Continue with Google". The browser goes to /api/zuno/account/google, Google sends it back to
 * /api/zuno/account/google/callback, and it lands on the React site's /account page logged in.
 * Both addresses are on the shop's own domain, so the session cookie is the shop's.
 *
 * Google accounts are stored the way Bagisto's own social login stores them (customer_social_accounts).
 */
class GoogleLoginController extends Controller
{
    private const PROVIDER = 'google';

    public function __construct(
        protected CustomerRepository $customerRepository,
        protected CustomerGroupRepository $customerGroupRepository,
        protected CustomerSocialAccountRepository $socialAccountRepository,
    ) {}

    /** Whether Google sign-in has keys, so the shop knows to show the button. */
    public static function enabled(): bool
    {
        [$clientId, $secret] = self::credentials();

        return $clientId && $secret;
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! self::enabled()) {
            return $this->backToShop('Google sign-in is not set up yet.');
        }

        // Where to return afterwards: a path on the shop only, never another site
        $from = (string) $request->query('from', '/account');
        $request->session()->put('zuno.google.from', $this->safePath($from));

        return $this->provider()->scopes(['openid', 'email', 'profile'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $from = $request->session()->pull('zuno.google.from', '/account');

        if (! self::enabled()) {
            return $this->backToShop('Google sign-in is not set up yet.');
        }
        if ($request->has('error')) {
            // The shopper pressed "Cancel" on Google's screen
            return $this->backToShop(null, $from === '/account' ? '/account' : $from);
        }

        try {
            $user = $this->provider()->user();
        } catch (\Throwable $e) {
            report($e);

            return $this->backToShop('Google sign-in did not finish. Please try again.');
        }

        $customer = DB::transaction(fn () => $this->findOrCreateCustomer($user));

        if (! $customer) {
            return $this->backToShop('Your Google account has no verified email, so it cannot be used here.');
        }
        if (! $customer->status) {
            return $this->backToShop('This account has been disabled.');
        }

        auth()->guard('customer')->login($customer, remember: true);
        $request->session()->regenerate();
        Event::dispatch('customer.after.login', $customer);

        return redirect()->away(config('zuno.frontend_url').$from);
    }

    /**
     * The customer for this Google account: already linked, or the account with the same verified email
     * (so someone who signed up with a password can also use Google), or a new one.
     */
    protected function findOrCreateCustomer($user)
    {
        $linked = $this->socialAccountRepository->findOneWhere([
            'provider_name' => self::PROVIDER,
            'provider_id' => $user->getId(),
        ]);

        if ($linked?->customer) {
            return $linked->customer;
        }

        // Google confirms whether it has verified the address; an unverified one could belong to someone else
        $email = Str::lower((string) $user->getEmail());
        if (! $email || ! ($user->user['email_verified'] ?? false)) {
            return null;
        }

        $customer = $this->customerRepository->findOneByField('email', $email);

        if (! $customer) {
            $groupCode = core()->getConfigData('customer.settings.create_new_account_options.default_group') ?: 'general';

            Event::dispatch('customer.registration.before');

            $customer = $this->customerRepository->create([
                'first_name' => $user->user['given_name'] ?? Str::before((string) $user->getName(), ' ') ?: 'ZUNO',
                'last_name' => $user->user['family_name'] ?? '',
                'email' => $email,
                // No password: they sign in with Google, or set one with "Forgot password"
                'password' => null,
                'api_token' => Str::random(80),
                'is_verified' => 1, // Google has verified the email
                'status' => 1,
                'customer_group_id' => $this->customerGroupRepository->findOneWhere(['code' => $groupCode])?->id,
                'channel_id' => core()->getCurrentChannel()->id,
                'token' => Str::random(64),
                'subscribed_to_news_letter' => false,
            ]);

            Event::dispatch('customer.create.after', $customer);
            Event::dispatch('customer.registration.after', $customer);
        } elseif (! $customer->is_verified) {
            // Google just proved they own this address
            $this->customerRepository->update(['is_verified' => 1], $customer->id);
        }

        $this->socialAccountRepository->create([
            'customer_id' => $customer->id,
            'provider_name' => self::PROVIDER,
            'provider_id' => $user->getId(),
        ]);

        return $customer->refresh();
    }

    protected function provider(): GoogleProvider
    {
        [$clientId, $secret] = self::credentials();

        return Socialite::buildProvider(GoogleProvider::class, [
            'client_id' => $clientId,
            'client_secret' => $secret,
            // Google must list this exact address under "Authorized redirect URIs"
            'redirect' => self::callbackUrl(),
        ]);
    }

    public static function callbackUrl(): string
    {
        return config('zuno.frontend_url').'/api/zuno/account/google/callback';
    }

    /** Keys from the environment, else from the admin's Social Login settings. */
    protected static function credentials(): array
    {
        return [
            config('zuno.google.client_id') ?: core()->getConfigData('customer.settings.social_login.google_client_id'),
            config('zuno.google.client_secret') ?: core()->getConfigData('customer.settings.social_login.google_client_secret'),
        ];
    }

    protected function backToShop(?string $error, string $path = '/account'): RedirectResponse
    {
        $url = config('zuno.frontend_url').$path;

        return redirect()->away($error ? $url.(str_contains($url, '?') ? '&' : '?').'error='.urlencode($error) : $url);
    }

    /** Only same-site paths like "/cart"; anything else (other sites, "//evil.com") becomes /account. */
    protected function safePath(string $path): string
    {
        return preg_match('#^/(?![/\\\\])[^\s]*$#', $path) ? $path : '/account';
    }
}
