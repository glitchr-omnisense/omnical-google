<?php

namespace Omnical\Google\Tests;

use Omnical\Auth\ArraySecrets;
use Omnical\Auth\InMemoryTokenStore;
use Omnical\Auth\OAuthToken;
use Omnical\Exception\UsageLimitReachedException;
use Omnical\Google\GoogleFactory;
use Omnical\Google\GoogleProvider;
use Omnical\Registry;
use Omnical\Testing\RecordedHttpClient;
use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\RateLimit;
use PHPUnit\Framework\TestCase;

/** Google's quotas and terms, declared, enforced through the registry per project and per user, tightened by a site, never loosened. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsGooglesQuotaPageAndTerms(): void
    {
        $policy = self::registry(self::http(), [])->policy('agenda');

        self::assertSame('google', $policy->provider);
        self::assertSame(['10000/minute/application/*', '600/minute/user/*', '1000000/day/application/*'], array_map(static fn (RateLimit $l) => $l->id(), $policy->rateLimits ?? []));
        self::assertSame([Permission::Allowed, Permission::Unknown, Permission::Allowed, Permission::Forbidden], [$policy->rights->commercial, $policy->rights->caching->permission, $policy->rights->storage, $policy->rights->redistribution]);
        self::assertFalse($policy->rights->attribution->required);
        self::assertTrue($policy->requirements->key);
        self::assertSame(['https://developers.google.com/terms', 'https://developers.google.com/workspace/calendar/api/guides/quota', '2026-11-10'], [$policy->provenance->terms, $policy->provenance->policy, $policy->provenance->verifiedAt]);
        self::assertStringContainsString('100 new users', (string) $policy->provenance->notes);
        self::assertSame('google', self::registry(self::http(), [], ['commercial' => true])->policy('agenda')->provider, 'a commercial site is welcome');
    }

    public function testEachUsersShareIsCountedApart(): void
    {
        $http = self::http();
        $registry = self::registry($http, ['rateLimits' => [['max' => 2, 'window' => 'minute', 'scope' => 'user']]]);

        $registry->calendarEvents('agenda', 'user-42')->calendars();
        $registry->calendarEvents('agenda', 'user-42')->calendars();
        $registry->calendarEvents('agenda', 'user-7')->calendars();
        try {
            $registry->calendarEvents('agenda', 'user-42')->calendars();
            self::fail('A user\'s third call of the minute was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame('2/minute/user/*', $e->limit?->id());
        }
        self::assertCount(3, $http->requests, 'the refused call reached nothing');
        self::assertSame(1, $registry->guard('agenda')->remaining('calendars', ['user' => 'user-7']));
    }

    public function testA403RateLimitHoldsTheNextCalls(): void
    {
        // Google's way of saying 429, without a Retry-After: the provider's Backoff holds it, so the next call waits too.
        $http = RecordedHttpClient::routes(['~calendarList~' => [403, '{"error":{"code":403,"errors":[{"domain":"usageLimits","reason":"rateLimitExceeded"}]}}']]);
        $registry = self::registry($http, []);
        try {
            $registry->calendarEvents('agenda', 'user-42')->calendars();
            self::fail('A rate limit was read as calendars.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(GoogleProvider::HOLD, $e->retryAfter);
            self::assertStringStartsWith('Google Calendar answered 403 rateLimitExceeded', $e->getMessage());
        }
        try {
            $registry->calendarEvents('agenda', 'user-7')->calendars();
            self::fail('The next call went out during the wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(GoogleProvider::HOLD - 1, $e->retryAfter);
        }
        self::assertCount(1, $http->requests, 'the held call reached nothing');
    }

    public function testASiteCannotRaiseGooglesShare(): void
    {
        $this->expectException(InvalidPolicyException::class);
        self::registry(self::http(), ['rateLimits' => [['max' => 1000, 'window' => 'minute', 'scope' => 'user']]])->calendarEvents('agenda');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes(['~calendarList~' => [200, '@'.__DIR__.'/Fixtures/calendar-list-2.json']]);
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $usage
     */
    private static function registry(RecordedHttpClient $http, array $policy, array $usage = []): Registry
    {
        $store = new InMemoryTokenStore(['user:42:google' => new OAuthToken('ya29.valid', 'r', null)]);

        return new Registry([new GoogleFactory($http, $store, new ArraySecrets(['GOOGLE_CLIENT_SECRET' => 'x']))], ['agenda' => [
            'factory' => 'google',
            'options' => ['client_id' => 'client-1', 'client_secret' => 'GOOGLE_CLIENT_SECRET', 'token' => 'user:42:google'],
            'policy' => $policy,
            'usage' => $usage,
        ]]);
    }
}
