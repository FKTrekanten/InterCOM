<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

// Provider release has not been attempted; retain the lease, allow another test.
final class ReleaseBlocked extends \RuntimeException
{
}
