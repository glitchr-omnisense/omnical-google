<?php

namespace Omnical\Google;

use Omnical\Auth\AccessTokenInterface;
use Omnical\CalendarEventsInterface;
use Omnical\Capabilities;
use Omnical\Exception\AuthenticationException;
use Omnical\Exception\InvalidArgumentException;
use Omnical\Exception\ProviderException;
use Omnical\Exception\UsageLimitReachedException;
use Omnical\FreeBusyInterface;
use Omnical\Http\Data;
use Omnical\Http\JsonClient;
use Omnical\Http\RawClient;
use Omnical\Model\Attendee;
use Omnical\Model\AttendeeRole;
use Omnical\Model\BusySlot;
use Omnical\Model\Calendar;
use Omnical\Model\Event;
use Omnical\Model\EventQuery;
use Omnical\Model\EventStatus;
use Omnical\Model\FreeBusy;
use Omnical\Model\FreeBusyQuery;
use Omnical\Model\FreeBusyStatus;
use Omnical\Model\ParticipationStatus;
use Omnical\Model\Recurrence;
use Omnical\Model\Transparency;
use Omnisense\Contracts\Policy\Backoff;
use Omnisense\Contracts\Policy\DeclaresUsagePolicyInterface;
use Omnisense\Contracts\Policy\UsagePolicy;

/**
 * Google Calendar, API v3 (https://developers.google.com/workspace/calendar/api/v3/reference),
 * for one user, over OAuth 2.0: the user's calendars (calendarList.list),
 * their events in a span - each occurrence (singleEvents=true) or the
 * series - (events.list), one event (events.get), written (events.insert,
 * events.update with If-Match on the etag, events.delete), and the busy
 * time of calendars, the user's or a colleague's by address
 * (freebusy.query).
 *
 * Every request carries the user's bearer token; a 401 refreshes it once
 * and asks again. A 403 rateLimitExceeded or userRateLimitExceeded (Google's
 * way of saying 429 too) is a UsageLimitReachedException; given the
 * provider's Backoff, it holds the next calls for HOLD seconds (no
 * Retry-After comes with it). Its usage policy
 * is policy.json, beside this class (POLICY).
 */
final class GoogleProvider implements CalendarEventsInterface, FreeBusyInterface, DeclaresUsagePolicyInterface
{
    public const BASE_URI = 'https://www.googleapis.com/calendar/v3';

    /** Google's usage policy, as its quota page and terms read on the day it says (provenance.verifiedAt). */
    public const POLICY = __DIR__.'/policy.json';

    private const PAGE = 2500;

    /** The wait after a 403 rate limit: Google sends no Retry-After, and its limits are per minute. */
    public const HOLD = 60;

    private static ?UsagePolicy $policy = null;

    public function __construct(
        private readonly JsonClient $json,
        private readonly RawClient $raw,
        private readonly AccessTokenInterface $token,
        private readonly string $baseUri = self::BASE_URI,
        private readonly string $sendUpdates = 'none',
        private readonly ?Backoff $backoff = null,
    ) {
    }

    public function getName(): string
    {
        return 'google';
    }

    public function getTitle(): string
    {
        return 'Google Calendar';
    }

    public function usagePolicy(): UsagePolicy
    {
        return self::$policy ??= UsagePolicy::fromFile(self::POLICY);
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(calendarEvents: true, write: true, expand: true, attendees: true, freeBusy: true, people: true, policy: $this->usagePolicy());
    }

    public function calendars(): array
    {
        $calendars = [];
        $page = null;
        do {
            $data = $this->get('/users/me/calendarList', ['maxResults' => 250, 'pageToken' => $page]);
            foreach ($data?->list('items') ?? [] as $item) {
                $id = $item->string('id');
                if (null === $id || true === $item->bool('deleted')) {
                    continue;
                }
                $calendars[] = new Calendar(
                    $id,
                    $item->string('summaryOverride') ?? $item->string('summary') ?? $id,
                    'google',
                    self::zone($item->string('timeZone')),
                    $item->string('description'),
                    $item->string('backgroundColor'),
                    \in_array($item->string('accessRole'), ['reader', 'freeBusyReader'], true),
                    true === $item->bool('primary'),
                    'owner' === $item->string('accessRole') && true === $item->bool('primary') ? $id : null,
                    $item->string('etag'),
                );
            }
            $page = $data?->string('nextPageToken');
        } while (null !== $page);
        usort($calendars, static fn (Calendar $a, Calendar $b) => $b->primary <=> $a->primary);

        return $calendars;
    }

    public function events(EventQuery $query): array
    {
        $events = [];
        $page = null;
        do {
            $data = $this->get('/calendars/'.rawurlencode($query->calendarId).'/events', [
                'timeMin' => $query->from->format(\DATE_RFC3339),
                'timeMax' => $query->to->format(\DATE_RFC3339),
                'singleEvents' => $query->expand ? 'true' : 'false',
                'orderBy' => $query->expand ? 'startTime' : null,
                'maxResults' => min(self::PAGE, $query->limit - \count($events)),
                'pageToken' => $page,
                'q' => $query->search,
                'timeZone' => $query->timeZone,
            ]);
            foreach ($data?->list('items') ?? [] as $item) {
                $event = self::read($item, $query->calendarId, $data?->string('timeZone'));
                if (null !== $event && \count($events) < $query->limit) {
                    $events[] = $event;
                }
            }
            $page = $data?->string('nextPageToken');
        } while (null !== $page && \count($events) < $query->limit);
        if (!$query->expand) {
            usort($events, static fn (Event $a, Event $b) => $a->start <=> $b->start);
        }

        return $events;
    }

    public function event(string $calendarId, string $eventId): ?Event
    {
        $data = $this->get(self::path($calendarId, $eventId));

        return null === $data ? null : self::read($data, $calendarId);
    }

    public function create(Event $event): Event
    {
        $calendar = $event->calendarId ?? throw new InvalidArgumentException('An event is created in a calendar: its calendarId.');
        $data = $this->send('POST', '/calendars/'.rawurlencode($calendar).'/events?sendUpdates='.$this->sendUpdates, self::resource($event));

        return $this->written($data, $calendar, 'POST');
    }

    public function update(Event $event): Event
    {
        $calendar = $event->calendarId ?? throw new InvalidArgumentException('An event is updated in its calendar: its calendarId.');
        $id = $event->id ?? throw new InvalidArgumentException('An event is updated by its id.');
        $data = $this->send('PUT', self::path($calendar, $id).'?sendUpdates='.$this->sendUpdates, self::resource($event), null === $event->etag ? [] : ['If-Match' => $event->etag]);

        return $this->written($data, $calendar, 'PUT');
    }

    public function delete(string $calendarId, string $eventId, ?string $etag = null): void
    {
        $this->authorized(fn (array $auth) => $this->raw->request('DELETE', $this->baseUri.self::path($calendarId, $eventId).'?sendUpdates='.$this->sendUpdates, [], $auth + (null === $etag ? [] : ['If-Match' => $etag]), [404, 410]));
    }

    public function freeBusy(FreeBusyQuery $query): array
    {
        $body = [
            'timeMin' => $query->from->format(\DATE_RFC3339),
            'timeMax' => $query->to->format(\DATE_RFC3339),
            'items' => array_map(static fn (string $id) => ['id' => $id], $query->calendars),
        ] + (null === $query->timeZone ? [] : ['timeZone' => $query->timeZone]);
        $calendars = $this->send('POST', '/freeBusy', $body)?->data('calendars');
        $answers = [];
        foreach ($query->calendars as $id) {
            $one = $calendars?->data($id) ?? Data::of([]);
            $slots = [];
            foreach ($one->list('busy') as $busy) {
                $start = self::moment($busy->string('start'));
                $end = self::moment($busy->string('end'));
                if (null !== $start && null !== $end) {
                    $slots[] = new BusySlot($start, $end, FreeBusyStatus::Busy);
                }
            }
            $errors = $one->strings('errors', 'reason');
            $answers[] = new FreeBusy($id, $slots, $errors[0] ?? (null === ($calendars?->get($id)) ? 'notFound' : null), 'google');
        }

        return $answers;
    }

    /**
     * An event as Google's resource holds it (events.insert, events.update).
     *
     * @return array<string, mixed>
     */
    public static function resource(Event $event): array
    {
        $zone = $event->timeZone ?? (null !== $event->recurrence ? 'UTC' : null);
        $when = static fn (\DateTimeImmutable $moment) => $event->allDay
            ? ['date' => $moment->format('Y-m-d')]
            : ['dateTime' => $moment->format(\DATE_RFC3339)] + (null === $zone ? [] : ['timeZone' => $zone]);
        $resource = [
            'summary' => $event->title,
            'start' => $when($event->start),
            'end' => $when($event->end),
            'status' => $event->status->value,
            'transparency' => $event->transparency->value,
        ];
        if (null !== $event->description) {
            $resource['description'] = $event->description;
        }
        if (null !== $event->location) {
            $resource['location'] = $event->location;
        }
        if (null !== $event->recurrence) {
            $resource['recurrence'] = $event->recurrence->toLines($zone, $event->allDay);
        }
        if ([] !== $event->attendees) {
            $resource['attendees'] = array_map(static fn (Attendee $a) => array_filter([
                'email' => $a->email,
                'displayName' => $a->name,
                'optional' => AttendeeRole::Optional === $a->role ? true : null,
                'resource' => $a->resource ? true : null,
                'responseStatus' => match ($a->status) {
                    ParticipationStatus::Accepted => 'accepted',
                    ParticipationStatus::Declined => 'declined',
                    ParticipationStatus::Tentative => 'tentative',
                    default => 'needsAction',
                },
            ], static fn (mixed $v) => null !== $v), $event->attendees);
        }

        return $resource;
    }

    /**
     * A Google event resource read into an Event; null for a cancelled one,
     * or one without its times. An all-day event's dates are read in the
     * calendar's zone ($calendarZone, the list's timeZone) when the event
     * names none.
     */
    public static function read(Data $item, string $calendarId, ?string $calendarZone = null): ?Event
    {
        $id = $item->string('id');
        $status = EventStatus::read($item->string('status'));
        $start = $item->data('start');
        $end = $item->data('end');
        $zone = self::zone($start->string('timeZone')) ?? self::zone($calendarZone);
        $allDay = null !== $start->string('date');
        $first = self::when($start, $zone);
        $last = self::when($end, $zone);
        if (null === $id || EventStatus::Cancelled === $status || null === $first || null === $last) {
            return null;
        }
        $attendees = [];
        foreach ($item->list('attendees') as $one) {
            $email = $one->string('email');
            if (null !== $email) {
                $attendees[] = new Attendee($email, $one->string('displayName'), ParticipationStatus::read($one->string('responseStatus')), true === $one->bool('optional') ? AttendeeRole::Optional : AttendeeRole::Required, true === $one->bool('organizer'), true === $one->bool('resource'), true === $one->bool('self'));
            }
        }
        $organizer = $item->data('organizer');
        $organizerEmail = $organizer->string('email');

        return new Event(
            $item->string('summary') ?? '',
            $first,
            $last,
            $allDay,
            $id,
            $calendarId,
            $zone,
            $item->string('description'),
            $item->string('location'),
            $status,
            'transparent' === $item->string('transparency') ? Transparency::Transparent : Transparency::Opaque,
            null === $organizerEmail ? null : new Attendee($organizerEmail, $organizer->string('displayName'), ParticipationStatus::Accepted, AttendeeRole::Chair, true, false, true === $organizer->bool('self')),
            $attendees,
            Recurrence::fromLines($item->strings('recurrence'), null === $zone ? null : new \DateTimeZone($zone)),
            $item->string('recurringEventId'),
            self::when($item->data('originalStartTime'), $zone),
            $item->string('iCalUID'),
            $item->string('htmlLink'),
            $item->string('etag'),
            self::moment($item->string('updated')),
            'google',
        );
    }

    private function written(?Data $data, string $calendarId, string $method): Event
    {
        return (null === $data ? null : self::read($data, $calendarId)) ?? throw ProviderException::answered('Google Calendar', $method.' /calendars/…/events', 200, 'The answer holds no event.');
    }

    /** @param array<string, string|int|float|bool|null> $query */
    private function get(string $path, array $query = []): ?Data
    {
        return $this->authorized(fn (array $auth) => $this->json->get($this->baseUri.$path, $query, $auth));
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $body, array $headers = []): ?Data
    {
        return $this->authorized(fn (array $auth) => $this->json->request($method, $this->baseUri.$path, ['json' => $body], $auth + $headers));
    }

    /**
     * A call with the user's bearer token: refreshed once and asked again
     * on a 401; a 403 rate limit read as the limit it is.
     *
     * @template T
     *
     * @param \Closure(array<string, string>): T $call
     *
     * @return T
     */
    private function authorized(\Closure $call): mixed
    {
        try {
            try {
                return $call(['Authorization' => 'Bearer '.$this->token->token()]);
            } catch (AuthenticationException) {
                return $call(['Authorization' => 'Bearer '.$this->token->refresh()]);
            }
        } catch (ProviderException $e) {
            if (403 === $e->status() && preg_match('~\b(rateLimitExceeded|userRateLimitExceeded)\b~', $e->getMessage(), $m)) {
                $reason = \sprintf('Google Calendar answered 403 %s: slow down (Google asks for an exponential backoff)', $m[1]);
                // Given the provider's Backoff, the next calls wait too, as after a 429's Retry-After.
                throw null !== $this->backoff ? $this->backoff->hold(self::HOLD, $reason) : new UsageLimitReachedException($reason.'.', null, 'google', null, $e);
            }
            throw $e;
        }
    }

    private static function path(string $calendarId, string $eventId): string
    {
        return '/calendars/'.rawurlencode($calendarId).'/events/'.rawurlencode($eventId);
    }

    /** An IANA zone PHP knows; null otherwise. */
    private static function zone(?string $name): ?string
    {
        if (null === $name) {
            return null;
        }
        try {
            new \DateTimeZone($name);

            return $name;
        } catch (\Exception) {
            return null;
        }
    }

    /** A start, an end or an original start: a date (in the event's zone) or a dateTime (its offset kept, then the event's zone). */
    private static function when(Data $time, ?string $zone): ?\DateTimeImmutable
    {
        $name = self::zone($time->string('timeZone')) ?? $zone;
        $in = new \DateTimeZone($name ?? 'UTC');
        $date = $time->string('date');
        if (null !== $date && preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
            return \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $in) ?: null;
        }
        $moment = self::moment($time->string('dateTime'));

        return null === $moment || null === $name ? $moment : $moment->setTimezone($in);
    }

    /** An RFC 3339 moment, its offset kept. */
    private static function moment(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || !preg_match('~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}~', $value)) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
