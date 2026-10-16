<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class ProviderFailure extends \RuntimeException
{
    public function __construct(public readonly int $httpStatus, public readonly int $transportCode)
    {
        parent::__construct('COM_INTERCOM_PROVIDER_ERROR', $httpStatus === 404 ? 404 : 0);
    }
}
