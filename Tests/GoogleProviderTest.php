<?php

namespace Omnical\Google\Tests;

use Omnical\Auth\ArraySecrets;
use Omnical\Auth\InMemoryTokenStore;
use Omnical\Auth\OAuthToken;
use Omnical\Exception\AuthenticationException;
use Omnical\Exception\ConflictException;
use Omnical\Exception\InvalidConfigException;
use Omnical\Exception\OmnicalException;
use Omnical\Exception\UsageLimitReachedException;
use Omnical\Google\GoogleFactory;
use Omnical\Google\GoogleProvider;
use Omnical\Model\Attendee;
use Omnical\Model\AttendeeRole;
use Omnical\Model\Calendar;
use Omnical\Model\Event;
use Omnical\Model\EventQuery;
use Omnical\Model\FreeBusyQuery;
use Omnical\Model\ParticipationStatus;
use Omnical\Model\Transparency;
use Omnical\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Google Calendar's answers, written from the API v3 reference (the
 * calendarList, events and freeBusy resources, the token endpoint's answer)
 * as it read on 2026-11-10 - not recorded live: no call to Google is made.
 * Every request's method, address, query, headers and body is asserted.
 */
final class GoogleProviderTest extends TestCase
{
    private const API = 'https://www.googleapis.com/calendar/v3';
    private const NOW = 1800000000;
    private const BEARER = 'Bearer ya29.valid-access-token';

    public function testTheCalendarsOverTwoPagesThePrimaryFirst(): void
    {
        $http = RecordedHttpClient::routes([
            '~/users/me/calendarList\?maxResults=250&pageToken=page-2$~' => [200, '@'.__DIR__.'/Fixtures/calendar-list-2.json'],
            '~/users/me/calendarList\?maxResults=250$~' => [200, '@'.__DIR__.'/Fixtures/calendar-list-1.json'],
        ]);
        $calendars = self::provider($http)->calendars();

        self::assertSame(['anna.martin@example.org', 'fr.french#holiday@group.v.calendar.google.com', 'c_0a1b2c3d4e@group.calendar.google.com'], array_map(static fn (Calendar $c) => $c->id, $calendars), 'the primary first, the deleted one left out');
        self::assertSame([true, 'anna.martin@example.org', false, '#9A9CFF'], [$calendars[0]->primary, $calendars[0]->owner, $calendars[0]->readOnly, $calendars[0]->color]);
        self::assertSame(['Jours fériés en France', true, '#16A765', 'Europe/Paris'], [$calendars[1]->name, $calendars[1]->readOnly, $calendars[1]->color, $calendars[1]->timeZone]);
        self::assertSame(['Cabinet (partagé)', false, 'Les rendez-vous du cabinet', '"1731234567000001"'], [$calendars[2]->name, $calendars[2]->readOnly, $calendars[2]->description, $calendars[2]->version]);
        self::assertCount(2, $http->requests);
        self::assertSame('GET', $http->requests[0]['method']);
        self::assertSame(self::API.'/users/me/calendarList', $http->requests[0]['url']);
        self::assertSame(['maxResults' => 250, 'pageToken' => 'page-2'], $http->requests[1]['options']['query'] ?? null);
        self::assertSame(self::BEARER, $http->header('Authorization'));
    }

    public function testEachOccurrenceOverTwoPagesWithItsAttendees(): void
    {
        $http = RecordedHttpClient::routes([
            '~/events\?.*pageToken=events-page-2~' => [200, '@'.__DIR__.'/Fixtures/events-2.json'],
            '~/calendars/anna\.martin%40example\.org/events\?~' => [200, '@'.__DIR__.'/Fixtures/events-1.json'],
        ]);
        $paris = new \DateTimeZone('Europe/Paris');
        $events = self::provider($http)->events(new EventQuery('anna.martin@example.org', new \DateTimeImmutable('2026-11-16', $paris), new \DateTimeImmutable('2026-11-23', $paris), search: 'Durand'));

        self::assertSame(self::API.'/calendars/anna.martin%40example.org/events', $http->requests[0]['url']);
        self::assertSame(['timeMin' => '2026-11-16T00:00:00+01:00', 'timeMax' => '2026-11-23T00:00:00+01:00', 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250, 'q' => 'Durand'], $http->requests[0]['options']['query'] ?? null);
        self::assertSame(248, self::sent($http, 1, 'query', 'maxResults'), 'the second page asks for what is left of the limit');
        self::assertCount(3, $events, 'the cancelled occurrence left out');

        [$consultation, $weekly, $closed] = $events;
        self::assertSame(['Consultation - M. Durand', '2026-11-16T09:00:00+01:00', '2026-11-16T09:45:00+01:00', 'Europe/Paris', false], [$consultation->title, $consultation->start->format(\DATE_ATOM), $consultation->end->format(\DATE_ATOM), $consultation->timeZone, $consultation->allDay]);
        self::assertSame(['7cbh8rpc10lrc0ckih9tafss99', 'anna.martin@example.org', '7cbh8rpc10lrc0ckih9tafss99@google.com', '"3456789012345000"', 'google'], [$consultation->id, $consultation->calendarId, $consultation->uid, $consultation->etag, $consultation->source]);
        self::assertSame(['12 rue des Arts, Strasbourg', 'Premier rendez-vous', '2026-11-03T10:01:02+00:00'], [$consultation->location, $consultation->description, $consultation->updated?->format(\DATE_ATOM)]);
        self::assertStringStartsWith('https://www.google.com/calendar/event?eid=', (string) $consultation->url);
        self::assertSame([
            ['anna.martin@example.org', ParticipationStatus::Accepted, AttendeeRole::Required, true, false, true],
            ['paul.durand@example.com', ParticipationStatus::Tentative, AttendeeRole::Required, false, false, false],
            ['c_188a@resource.calendar.google.com', ParticipationStatus::Accepted, AttendeeRole::Required, false, true, false],
            ['assistant@example.org', ParticipationStatus::NeedsAction, AttendeeRole::Optional, false, false, false],
        ], array_map(static fn (Attendee $a) => [$a->email, $a->status, $a->role, $a->organizer, $a->resource, $a->self], $consultation->attendees));
        self::assertSame(['anna.martin@example.org', true, true], [$consultation->organizer?->email, $consultation->organizer?->organizer, $consultation->organizer?->self]);

        self::assertTrue($weekly->isOccurrence());
        self::assertSame(['q2kfo1k4bs0n6n3p1vqgpq0kd0', '2026-11-16T14:00:00+01:00'], [$weekly->recurringEventId, $weekly->originalStart?->format(\DATE_ATOM)]);

        self::assertSame([true, '2026-11-17T00:00:00+01:00', '2026-11-19T00:00:00+01:00', 'Europe/Paris', Transparency::Transparent, false], [$closed->allDay, $closed->start->format(\DATE_ATOM), $closed->end->format(\DATE_ATOM), $closed->timeZone, $closed->transparency, $closed->busy()], 'dates in the calendar\'s zone, the end exclusive');
        self::assertSame(self::BEARER, $http->header('Authorization'));
    }

    public function testTheSeriesKeepTheirRulesAndAreWrittenBackAsTheyCame(): void
    {
        $http = RecordedHttpClient::routes(['~/events\?~' => [200, '@'.__DIR__.'/Fixtures/series.json']]);
        $events = self::provider($http)->events(new EventQuery('primary', new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-12-31'), expand: false));

        self::assertSame(['singleEvents' => 'false'], array_intersect_key((array) self::sent($http, 0, 'query'), ['singleEvents' => 1, 'orderBy' => 1]), 'no orderBy without singleEvents');
        [$inventory, $weekly] = $events;
        self::assertSame(['Inventaire', 'Point hebdomadaire'], [$inventory->title, $weekly->title], 'sorted by start');
        self::assertSame(['FREQ=WEEKLY;BYDAY=MO;UNTIL=20261221T225959Z'], $weekly->recurrence?->rules);
        self::assertSame(['2026-11-23T14:00:00+01:00'], array_map(static fn (\DateTimeImmutable $d) => $d->format(\DATE_ATOM), $weekly->recurrence->exdates));
        self::assertSame(['RRULE:FREQ=WEEKLY;BYDAY=MO;UNTIL=20261221T225959Z', 'EXDATE;TZID=Europe/Paris:20261123T140000'], GoogleProvider::resource($weekly)['recurrence'] ?? null);
        self::assertSame(['dateTime' => '2026-11-02T14:00:00+01:00', 'timeZone' => 'Europe/Paris'], GoogleProvider::resource($weekly)['start'] ?? null);

        self::assertTrue($inventory->allDay);
        $resource = GoogleProvider::resource($inventory);
        self::assertSame([['date' => '2026-10-30'], ['date' => '2026-10-31'], ['RRULE:FREQ=YEARLY;BYMONTH=10;BYMONTHDAY=30', 'EXDATE;VALUE=DATE:20271030']], [$resource['start'] ?? null, $resource['end'] ?? null, $resource['recurrence'] ?? null]);
    }

    public function testAnEventCreatedUpdatedOnItsEtagAndDeleted(): void
    {
        $http = RecordedHttpClient::routes([
            '~^POST https://www\.googleapis\.com/calendar/v3/calendars/anna\.martin%40example\.org/events\?sendUpdates=none$~' => [200, '@'.__DIR__.'/Fixtures/event.json'],
            '~^PUT .*/events/n4c0s3r7e0d2026a\?sendUpdates=none$~' => [200, '@'.__DIR__.'/Fixtures/event.json'],
            '~^PUT .*/events/stale~' => [412, '{"error":{"code":412,"message":"Precondition Failed","errors":[{"domain":"global","reason":"conditionNotMet"}]}}'],
            '~^DELETE .*/events/n4c0s3r7e0d2026a\?sendUpdates=none$~' => [204, ''],
            '~^DELETE .*/events/gone~' => [410, '{"error":{"code":410,"message":"Resource has been deleted"}}'],
        ]);
        $provider = self::provider($http);
        $paris = new \DateTimeZone('Europe/Paris');
        $new = new Event('Consultation', new \DateTimeImmutable('2026-11-20T10:00', $paris), new \DateTimeImmutable('2026-11-20T10:30', $paris), calendarId: 'anna.martin@example.org', description: 'Suivi', attendees: [new Attendee('Paul.Durand@example.com', 'Paul Durand'), new Attendee('assistant@example.org', role: AttendeeRole::Optional)]);

        $created = $provider->create($new);
        self::assertSame(['n4c0s3r7e0d2026a', '"3456789012399000"', 'n4c0s3r7e0d2026a@google.com', 'anna.martin@example.org'], [$created->id, $created->etag, $created->uid, $created->calendarId]);
        self::assertSame([
            'summary' => 'Consultation',
            'start' => ['dateTime' => '2026-11-20T10:00:00+01:00', 'timeZone' => 'Europe/Paris'],
            'end' => ['dateTime' => '2026-11-20T10:30:00+01:00', 'timeZone' => 'Europe/Paris'],
            'status' => 'confirmed',
            'transparency' => 'opaque',
            'description' => 'Suivi',
            'attendees' => [['email' => 'paul.durand@example.com', 'displayName' => 'Paul Durand', 'responseStatus' => 'needsAction'], ['email' => 'assistant@example.org', 'optional' => true, 'responseStatus' => 'needsAction']],
        ], $http->requests[0]['options']['json'] ?? null);
        self::assertSame([self::BEARER, null], [$http->header('Authorization'), $http->header('If-Match')], 'insert has no condition');

        $provider->update($created->moved(new \DateTimeImmutable('2026-11-20T11:00', $paris), new \DateTimeImmutable('2026-11-20T11:30', $paris)));
        self::assertSame(['PUT', '"3456789012399000"', '2026-11-20T11:00:00+01:00'], [$http->requests[1]['method'], $http->header('If-Match'), self::sent($http, 1, 'json', 'start', 'dateTime')]);
        try {
            $provider->update($created->stored('stale', 'anna.martin@example.org', '"old"'));
            self::fail('A stale etag was written over.');
        } catch (ConflictException $e) {
            self::assertSame(412, $e->status());
        }

        $provider->delete('anna.martin@example.org', 'n4c0s3r7e0d2026a', '"3456789012399000"');
        self::assertSame(['DELETE', self::API.'/calendars/anna.martin%40example.org/events/n4c0s3r7e0d2026a?sendUpdates=none', '"3456789012399000"', self::BEARER], [$http->requests[3]['method'], $http->requests[3]['url'], $http->header('If-Match'), $http->header('Authorization')]);
        $provider->delete('anna.martin@example.org', 'gone');
        self::assertNull($http->header('If-Match'), 'no etag, no condition');
    }

    public function testAnEventGoneOrCancelledIsNull(): void
    {
        $http = RecordedHttpClient::routes([
            '~/events/n4c0s3r7e0d2026a$~' => [200, '@'.__DIR__.'/Fixtures/event.json'],
            '~/events/cancelled$~' => [200, '{"kind":"calendar#event","id":"cancelled","status":"cancelled"}'],
        ]);
        $provider = self::provider($http);

        self::assertSame('Consultation', $provider->event('primary', 'n4c0s3r7e0d2026a')?->title);
        self::assertNull($provider->event('primary', 'cancelled'));
        self::assertNull($provider->event('primary', 'unknown'), 'a 404');
        self::assertSame(self::API.'/calendars/primary/events/n4c0s3r7e0d2026a', $http->requests[0]['url']);
    }

    public function testBusyTimeWithACalendarGoogleCannotTell(): void
    {
        $http = RecordedHttpClient::routes(['~^POST https://www\.googleapis\.com/calendar/v3/freeBusy$~' => [200, '@'.__DIR__.'/Fixtures/freebusy.json']]);
        $from = new \DateTimeImmutable('2026-11-16T08:00:00+01:00');
        $to = new \DateTimeImmutable('2026-11-16T18:00:00+01:00');
        [$anna, $paul] = self::provider($http)->freeBusy(new FreeBusyQuery(['anna.martin@example.org', 'paul.durand@example.com'], $from, $to, 'Europe/Paris'));

        self::assertSame(['timeMin' => '2026-11-16T08:00:00+01:00', 'timeMax' => '2026-11-16T18:00:00+01:00', 'items' => [['id' => 'anna.martin@example.org'], ['id' => 'paul.durand@example.com']], 'timeZone' => 'Europe/Paris'], $http->requests[0]['options']['json'] ?? null);
        self::assertSame([['2026-11-16T08:00:00+00:00', '2026-11-16T08:45:00+00:00'], ['2026-11-16T13:00:00+00:00', '2026-11-16T13:30:00+00:00']], array_map(static fn ($s) => [$s->start->format(\DATE_ATOM), $s->end->format(\DATE_ATOM)], $anna->slots));
        self::assertNull($anna->error);
        self::assertSame(['paul.durand@example.com', [], 'notFound'], [$paul->calendar, $paul->slots, $paul->error]);
        self::assertSame(['08:00-09:00', '09:45-14:00', '14:30-18:00'], array_map(static fn ($s) => $s->start->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i').'-'.$s->end->setTimezone(new \DateTimeZone('Europe/Paris'))->format('H:i'), $anna->free($from, $to, 3600)));
    }

    public function testAnExpiredTokenIsRefreshedFirstAndSaved(): void
    {
        $store = new InMemoryTokenStore(['user:42:google' => new OAuthToken('ya29.expired', '1//refresh-token', self::NOW - 10)]);
        $http = RecordedHttpClient::routes([
            '~^POST https://oauth2\.googleapis\.com/token$~' => [200, '@'.__DIR__.'/Fixtures/token.json'],
            '~/users/me/calendarList~' => [200, '@'.__DIR__.'/Fixtures/calendar-list-2.json'],
        ]);
        self::provider($http, $store)->calendars();

        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => '1//refresh-token', 'client_id' => 'client-1.apps.googleusercontent.com', 'client_secret' => 'not-a-real-secret'], $http->requests[0]['options']['body'] ?? null);
        self::assertSame('Bearer ya29.refreshed-access-token', $http->header('Authorization'));
        self::assertSame(['ya29.refreshed-access-token', '1//refresh-token', self::NOW + 3599], [$store->get('user:42:google')?->accessToken, $store->get('user:42:google')?->refreshToken, $store->get('user:42:google')?->expiresAt], 'Google sends no new refresh token: the old one is kept');
    }

    public function testA401RefreshesOnceAndAsksAgain(): void
    {
        $lists = 0;
        $http = new RecordedHttpClient(static function (string $method, string $url) use (&$lists): array {
            if (str_contains($url, 'oauth2.googleapis.com')) {
                return [200, (string) file_get_contents(__DIR__.'/Fixtures/token.json')];
            }

            return 1 === ++$lists ? [401, '{"error":{"code":401,"message":"Request had invalid authentication credentials.","status":"UNAUTHENTICATED"}}'] : [200, (string) file_get_contents(__DIR__.'/Fixtures/calendar-list-2.json')];
        });
        self::assertCount(1, self::provider($http)->calendars());
        self::assertSame(['GET', 'POST', 'GET'], array_column($http->requests, 'method'));
        self::assertSame([self::BEARER, 'Bearer ya29.refreshed-access-token'], [$http->header('Authorization', 0), $http->header('Authorization', 2)]);
    }

    public function testA401AfterTheRefreshAsksForConsent(): void
    {
        $http = RecordedHttpClient::routes([
            '~oauth2\.googleapis\.com/token~' => [200, '@'.__DIR__.'/Fixtures/token.json'],
            '~calendarList~' => [401, '{"error":{"code":401,"message":"Invalid Credentials"}}'],
        ]);
        try {
            self::provider($http)->calendars();
            self::fail('A refused token read calendars.');
        } catch (AuthenticationException $e) {
            self::assertSame(401, $e->status());
            self::assertStringNotContainsString('ya29', $e->getMessage());
        }
        self::assertCount(3, $http->requests, 'one refresh, one more try, no more');
    }

    public function testGoogles403RateLimitIsAUsageLimit(): void
    {
        $http = RecordedHttpClient::routes(['~calendarList~' => [403, '{"error":{"code":403,"message":"Rate Limit Exceeded","errors":[{"domain":"usageLimits","reason":"userRateLimitExceeded"}]}}']]);
        try {
            self::provider($http)->calendars();
            self::fail('A rate limit was read as calendars.');
        } catch (OmnicalException $e) {
            self::assertInstanceOf(UsageLimitReachedException::class, $e);
            self::assertSame('Google Calendar answered 403 userRateLimitExceeded: slow down (Google asks for an exponential backoff).', $e->getMessage());
        }
    }

    public function testOptionsAreCheckedAndAStaticTokenIsSentAsItIs(): void
    {
        $http = RecordedHttpClient::routes(['~calendarList~' => [200, '@'.__DIR__.'/Fixtures/calendar-list-2.json']]);
        $factory = new GoogleFactory($http, null, new ArraySecrets(['GOOGLE_TOKEN' => 'ya29.minted-elsewhere']));
        $factory->create(['access_token' => 'GOOGLE_TOKEN'])->capabilities();
        $provider = $factory->create(['access_token' => 'GOOGLE_TOKEN']);
        self::assertInstanceOf(GoogleProvider::class, $provider);
        $provider->calendars();
        self::assertSame('Bearer ya29.minted-elsewhere', $http->header('Authorization'));

        $url = $factory->oauthClient(['client_id' => 'client-1'])->authorizationUrl('https://site.example/back', [GoogleFactory::SCOPE_EVENTS, GoogleFactory::SCOPE_CALENDARLIST_READONLY], 'state', null, GoogleFactory::OFFLINE);
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?response_type=code&client_id=client-1&', $url);
        self::assertStringContainsString('scope=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fcalendar.events%20https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fcalendar.calendarlist.readonly', $url);
        self::assertStringEndsWith('&access_type=offline&prompt=consent&include_granted_scopes=true', $url);

        foreach ([[[], 'needs client_id'], [['access_token' => 'X', 'send_updates' => 'everyone'], 'send_updates is none, all or externalOnly']] as [$options, $message]) {
            try {
                $factory->create($options);
                self::fail('Accepted: '.$message);
            } catch (InvalidConfigException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    /** What a request sent under an option, by path: "query", "maxResults". */
    private static function sent(RecordedHttpClient $http, int $request, string ...$path): mixed
    {
        $value = $http->requests[$request]['options'];
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    private static function provider(RecordedHttpClient $http, ?InMemoryTokenStore $store = null): GoogleProvider
    {
        $store ??= new InMemoryTokenStore(['user:42:google' => new OAuthToken('ya29.valid-access-token', '1//refresh-token', self::NOW + 3600)]);
        $provider = (new GoogleFactory($http, $store, new ArraySecrets(['GOOGLE_CLIENT_SECRET' => 'not-a-real-secret']), static fn () => self::NOW))
            ->create(['client_id' => 'client-1.apps.googleusercontent.com', 'client_secret' => 'GOOGLE_CLIENT_SECRET', 'token' => 'user:42:google']);

        return $provider instanceof GoogleProvider ? $provider : throw new \LogicException('Not a Google provider.');
    }
}
