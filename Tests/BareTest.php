<?php

namespace Omnical\Google\Tests;

use Omnical\Google\GoogleProvider;
use Omnical\Testing\BareTestCase;

/** omnical/google's classes load with omnisense/omnical, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return GoogleProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
