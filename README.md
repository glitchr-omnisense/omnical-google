# omnical/google

Google Calendar for [omnisense/omnical](https://github.com/glitchr-omnisense/omnical), under any
PHP host: a user's calendars, their events read and written, and busy time, through the
[Calendar API v3](https://developers.google.com/workspace/calendar/api/v3/reference) over OAuth 2.0.

```
composer require omnical/google
```

It keeps two of the family's contracts: `calendar-events` (calendars, events in a span, one event,
create, update, delete) and `free-busy` (the user's calendars, or colleagues' by address).

## Setting it up in Google Cloud

1. Create a project, enable the **Google Calendar API**.
2. Configure the **OAuth consent screen**: the app's name, its home page and privacy policy, the
   scopes it asks for. Calendar scopes are sensitive: an external app needs Google's verification
   (a verified domain, a justification per scope, a demo video). Until it is verified it is capped
   at 100 new users, and while the app is in Testing status its refresh tokens last 7 days.
3. Create an **OAuth client ID** of type "Web application" with the site's redirect URI.
4. Keep the client secret where the site keeps secrets (an environment variable,
   `GOOGLE_CLIENT_SECRET`, or the host's vault): the options name it, they never hold it.

Ask for the narrowest scopes that serve the page (`GoogleFactory::SCOPE_*`):
`calendar.events` to read and write events, `calendar.calendarlist.readonly` for the list of
calendars, `calendar.freebusy` for busy time alone, `calendar.readonly` to read everything.

## The consent step, then the calls

```php
use Omnical\Google\GoogleFactory;
use Omnical\Auth\OAuth2Client;

$factory = new GoogleFactory($http, $tokenStore);            // the site's HTTP client and token store
$options = ['client_id' => '…apps.googleusercontent.com', 'client_secret' => 'GOOGLE_CLIENT_SECRET', 'token' => 'user:42:google'];

// 1. Send the user to Google.
$oauth = $factory->oauthClient($options);
[$verifier, $challenge] = OAuth2Client::pkce();
$url = $oauth->authorizationUrl('https://example.org/oauth/google', [GoogleFactory::SCOPE_EVENTS, GoogleFactory::SCOPE_CALENDARLIST_READONLY], $state, $challenge, GoogleFactory::OFFLINE);

// 2. Back on the redirect URI: keep the token under the user's key.
$tokenStore->save('user:42:google', $oauth->exchange($_GET['code'], 'https://example.org/oauth/google', $verifier));

// 3. Calls, through the registry (policy enforced, counted per user).
$registry = new Omnical\Registry([$factory], ['agenda' => ['factory' => 'google', 'options' => $options]]);
$events = $registry->calendarEvents('agenda', 'user-42')->events(new Omnical\Model\EventQuery('primary', $from, $to));
```

`GoogleFactory::OFFLINE` is `access_type=offline`, `prompt=consent`, `include_granted_scopes=true`:
Google hands a refresh token only with offline access, and only on a consent. A token that expires
(within 60 seconds) is refreshed before the call and saved over the old one; Google sends no new
refresh token, the old one is kept. A 401 refreshes once and asks again; a second 401, or a refused
refresh (`invalid_grant`), is an `AuthenticationException`: the user has to consent again.

## Options

| Option | |
|---|---|
| `client_id` | the OAuth client's id |
| `client_secret` | the **reference** of its secret (`GOOGLE_CLIENT_SECRET`), read from the factory's secrets (the environment by default) |
| `token` | the key of the user's token in the token store |
| `access_token` | instead of the three above: the reference of a token minted elsewhere, sent as it is, never refreshed |
| `base_uri` | `https://www.googleapis.com/calendar/v3` |
| `send_updates` | whom Google e-mails on a write: `none` (default), `all`, `externalOnly` |
| `timeout` | seconds |
| `policy`, `usage` | the site's tightening of the policy, what the site is |

The factory takes the HTTP client, the token store (in memory by default), the secrets
(`EnvSecrets` by default) and a clock.

## The calls

| Contract method | Google | Read |
|---|---|---|
| `calendars()` | `GET /users/me/calendarList` (250 a page, every page) | `summaryOverride` or `summary`; `accessRole` reader or freeBusyReader → read-only; `primary` first; `backgroundColor`; `timeZone`; deleted entries left out |
| `events()` | `GET /calendars/{id}/events`: `timeMin`, `timeMax`, `singleEvents` (= `EventQuery::$expand`), `orderBy=startTime` with it only, `maxResults` (at most 2,500, never more than what is left of the limit), `pageToken` until the limit, `q`, `timeZone` | cancelled items left out; a series sorted by start when not expanded |
| `event()` | `GET /calendars/{id}/events/{eventId}` | null on a 404 or a cancelled event |
| `create()` | `POST /calendars/{id}/events?sendUpdates=…` | the stored event: id, etag, iCalUID |
| `update()` | `PUT /calendars/{id}/events/{eventId}?sendUpdates=…`, `If-Match` on the event's etag | a 412 is a `ConflictException`; PUT rather than PATCH, as Google recommends (a PATCH costs 3 units and replaces arrays anyway) |
| `delete()` | `DELETE …/events/{eventId}?sendUpdates=…`, `If-Match` when an etag is given | 204; a 404 or 410 is nothing |
| `freeBusy()` | `POST /freeBusy` with `timeMin`, `timeMax`, `items[].id`, `timeZone` | `calendars.{id}.busy[]`; the first `errors[].reason` (`notFound`) as `FreeBusy::$error` |

Calendar ids are e-mail addresses: they are URL-encoded in every path.

Mapping: `start`/`end` hold `date` (all-day, the end exclusive, the dates read in the event's or the
calendar's zone) or `dateTime` with its offset and `timeZone` (IANA); `recurrence` lines become the
event's `Recurrence` and are written back as `RRULE`, `EXRULE`, `RDATE`, `EXDATE` lines (a
recurring event without a zone is written in UTC: Google requires one); `recurringEventId` and
`originalStartTime` mark an occurrence; `status`, `transparency`; `attendees[]` with
`responseStatus` (needsAction, accepted, declined, tentative), `optional`, `organizer`, `resource`,
`self`, `displayName`; `organizer`; `iCalUID`, `htmlLink`, `etag`, `updated`.

## Usage policy

`policy.json`, read on 2026-11-10 from the [quota page](https://developers.google.com/workspace/calendar/api/guides/quota)
and the [Google APIs Terms of Service](https://developers.google.com/terms):

- 10,000 requests a minute per project, 600 a minute per user per project (count it per user:
  `Registry::calendarEvents($name, $user)`), 1,000,000 a day per project before charges, which
  Google announced for later in 2026;
- commercial use allowed; redistribution forbidden; no attribution required, the branding rules
  apply ("for Google Calendar", the trademark notice);
- caching left unknown: the terms bound API content by its cache headers, a user's events are user
  data under the [User Data Policy](https://developers.google.com/terms/api-services-user-data-policy),
  which sets no time to live;
- storage allowed for the feature the user consented to, under Limited Use.

A 403 `rateLimitExceeded` or `userRateLimitExceeded` is a `UsageLimitReachedException` (Google says
it means the same as a 429, with no `Retry-After`: back off exponentially).

## Verified, and not

- Verified against the API reference, the OAuth 2.0 web-server guide, the quota and errors guides
  and the terms on 2026-11-10: every path, parameter and field above.
- The tests' answers are written from the reference's resource shapes, not recorded live: no call
  to Google was made (it needs a consented user). Run it against a test account before relying on
  it.
- `DELETE` answering 204: Google's page says "an empty response body"; 204 is assumed.
- PKCE from a web server: Google documents it for installed apps only; whether its web-server flow
  honours it is not verified (the challenge is optional: `authorizationUrl()` without one).
- Not done: sync tokens (`syncToken`, 410 full resync), push notifications, conference data,
  attachments, `eventType` (focus time, out of office), reminders, calendars' creation.

MIT licence.
