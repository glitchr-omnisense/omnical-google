# omnical/google

Google Calendar (API v3) for omnical: `calendar-events` and `free-busy`, over OAuth 2.0.

## In plain PHP

```php
use Omnical\Auth\InMemoryTokenStore;
use Omnical\Google\GoogleFactory;
use Omnical\Model\EventQuery;
use Omnical\Model\FreeBusyQuery;
use Omnical\Registry;
use Symfony\Component\HttpClient\HttpClient;

$tokens = new InMemoryTokenStore();               // the site's own store in production
$factory = new GoogleFactory(HttpClient::create(), $tokens);
$options = ['client_id' => getenv('GOOGLE_CLIENT_ID'), 'client_secret' => 'GOOGLE_CLIENT_SECRET', 'token' => 'user:42:google'];

$registry = new Registry([$factory], ['agenda' => ['factory' => 'google', 'options' => $options]]);
$calendar = $registry->calendarEvents('agenda', 'user-42');
foreach ($calendar->events(new EventQuery('primary', new DateTimeImmutable('monday'), new DateTimeImmutable('next monday'))) as $event) {
    echo $event->start->format('D H:i'), ' ', $event->title, "\n";
}
$busy = $registry->freeBusy('agenda', 'user-42')->freeBusy(new FreeBusyQuery(['primary'], $from, $to));
```

The token under `user:42:google` comes from the consent step (README: "The consent step").

## Errors

- `AuthenticationException`: the token is refused twice, or the refresh token is revoked or
  expired: send the user through the consent again.
- `ConflictException`: `update()` or `delete()` with an etag that changed since it was read.
- `UsageLimitReachedException`: the policy's limits (as the site tightened them), a 429, or
  Google's 403 rate limits.
- `ProviderException`: any other error status, the path only in its message, no token.

## Testing a site that uses it

`Omnical\Testing\RecordedHttpClient` answers Google's routes from files; the package's own tests
(`Tests/GoogleProviderTest.php`) show every request and answer.
