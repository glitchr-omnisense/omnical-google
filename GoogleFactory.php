<?php

namespace Omnical\Google;

use Omnical\Auth\AccessTokenInterface;
use Omnical\Auth\EnvSecrets;
use Omnical\Auth\InMemoryTokenStore;
use Omnical\Auth\OAuth2Client;
use Omnical\Auth\RefreshingAccessToken;
use Omnical\Auth\SecretsInterface;
use Omnical\Auth\StaticAccessToken;
use Omnical\Auth\TokenStoreInterface;
use Omnical\Config;
use Omnical\Exception\InvalidConfigException;
use Omnical\Http\JsonClient;
use Omnical\Http\RawClient;
use Omnical\ProviderFactory;
use Omnical\ProviderInterface;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "google": Google Calendar (API v3) for one user who opened their calendars
 * to the site through OAuth 2.0. Options:
 *
 * - client_id: the OAuth client's id (Google Cloud console, "Web application");
 * - client_secret: the REFERENCE of its secret ("GOOGLE_CLIENT_SECRET": an
 *   environment variable by default, the factory's secrets otherwise), never
 *   the secret itself;
 * - token: the key the user's token is kept under in the token store
 *   ("user:42:google"), refreshed there when it expires;
 * - or access_token: the reference of a token minted elsewhere, sent as it
 *   is (it cannot be refreshed);
 * - base_uri (https://www.googleapis.com/calendar/v3), send_updates (none,
 *   all, externalOnly: whom Google e-mails on a write; none by default),
 *   timeout (seconds); policy, usage.
 *
 * The consent step is the site's: oauth() gives the OAuth client (its
 * authorizationUrl() with access_type=offline and prompt=consent, so that
 * Google hands a refresh token; exchange() of the code; the token saved in
 * the store under the key the "token" option names).
 */
final class GoogleFactory extends ProviderFactory
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Every calendar, read and written. */
    public const SCOPE_CALENDAR = 'https://www.googleapis.com/auth/calendar';
    /** Every calendar, read only. */
    public const SCOPE_CALENDAR_READONLY = 'https://www.googleapis.com/auth/calendar.readonly';
    /** The events of every calendar, read and written (not the calendars' settings). */
    public const SCOPE_EVENTS = 'https://www.googleapis.com/auth/calendar.events';
    /** The events of every calendar, read only. */
    public const SCOPE_EVENTS_READONLY = 'https://www.googleapis.com/auth/calendar.events.readonly';
    /** Free/busy only. */
    public const SCOPE_FREEBUSY = 'https://www.googleapis.com/auth/calendar.freebusy';
    /** The list of the user's calendars, read only. */
    public const SCOPE_CALENDARLIST_READONLY = 'https://www.googleapis.com/auth/calendar.calendarlist.readonly';

    /** What the consent step sends besides the scopes: a refresh token, asked again on every consent. */
    public const OFFLINE = ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'];

    private readonly TokenStoreInterface $tokens;

    private readonly SecretsInterface $secrets;

    /** @param ?\Closure(): int $now the clock the token's expiry is read with (time() by default) */
    public function __construct(
        private readonly ?HttpClientInterface $http = null,
        ?TokenStoreInterface $tokens = null,
        ?SecretsInterface $secrets = null,
        private readonly ?\Closure $now = null,
    ) {
        $this->tokens = $tokens ?? new InMemoryTokenStore();
        $this->secrets = $secrets ?? new EnvSecrets();
    }

    /**
     * Google's OAuth 2.0 client for the consent step and the refreshes.
     *
     * @param ?string $clientSecret the reference of the client secret
     */
    public static function oauth(HttpClientInterface $http, string $clientId, SecretsInterface $secrets, ?string $clientSecret, ?Backoff $backoff = null, ?\Closure $now = null): OAuth2Client
    {
        return new OAuth2Client($http, 'Google', self::AUTHORIZE_URL, self::TOKEN_URL, $clientId, $secrets, $clientSecret, $backoff, $now);
    }

    /**
     * The OAuth client of these options (client_id, client_secret), over the
     * factory's HTTP client and secrets.
     *
     * @param array<string, mixed> $options
     */
    public function oauthClient(array $options): OAuth2Client
    {
        $c = $this->createConfig($options);

        return self::oauth($this->client(), $c->string('client_id') ?? throw new InvalidConfigException('The "google" provider\'s OAuth client needs its client_id.'), $this->secrets, $c->string('client_secret'), null, $this->now);
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnical.factory_name' => 'google',
            'omnical.factory_title' => 'Google Calendar',
            'omnical.required_options' => [],
            'base_uri' => GoogleProvider::BASE_URI,
            'client_id' => null,
            'client_secret' => null,
            'token' => null,
            'access_token' => null,
            'send_updates' => 'none',
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $base = rtrim((string) $c->string('base_uri'), '/');
        if (!preg_match('~^https?://[^/\s]+~', $base)) {
            throw new InvalidConfigException(\sprintf('The "google" provider needs a base_uri such as %s, not "%s".', GoogleProvider::BASE_URI, $base));
        }
        $updates = (string) $c->string('send_updates');
        if (!\in_array($updates, ['none', 'all', 'externalOnly'], true)) {
            throw new InvalidConfigException(\sprintf('The "google" provider\'s send_updates is none, all or externalOnly, not "%s".', $updates));
        }
        $http = $this->client();
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }
        $backoff = $c->backoff();

        return new GoogleProvider(
            new JsonClient($http, 'Google Calendar', ['Accept' => 'application/json'], $backoff),
            new RawClient($http, 'Google Calendar', [], $backoff),
            $this->token($c, $http),
            $base,
            $updates,
            $backoff,
        );
    }

    private function token(Config $c, HttpClientInterface $http): AccessTokenInterface
    {
        if (null !== $reference = $c->string('access_token')) {
            return new StaticAccessToken($this->secrets, $reference, 'Google Calendar');
        }
        $client = $c->string('client_id');
        $key = $c->string('token');
        if (null === $client || null === $key) {
            throw new InvalidConfigException('The "google" provider needs client_id, client_secret (a secret\'s reference) and token (the key of the user\'s token in the store), or access_token (a token\'s reference).');
        }

        return new RefreshingAccessToken(self::oauth($http, $client, $this->secrets, $c->string('client_secret'), null, $this->now), $this->tokens, $key, 60, $this->now);
    }

    private function client(): HttpClientInterface
    {
        return $this->http ?? HttpClient::create(['timeout' => 20]);
    }
}
