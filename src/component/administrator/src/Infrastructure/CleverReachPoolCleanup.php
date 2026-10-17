<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use FKT\Component\Intercom\Administrator\Domain\PoolCleanupGateway;

final class CleverReachPoolCleanup implements PoolCleanupGateway
{
    public function __construct(private CleverReachGateway $gateway)
    {
    }

    public function account(): string
    {
        return $this->gateway->retirementAccount();
    }

    public function filters(int $group): array
    {
        $this->gateway->assertRetirementList($group);
        return $this->gateway->filters($group);
    }

    public function assertFilterAbsent(int $group, int $filter): void
    {
        try {
            $this->gateway->filter($group, $filter);
        } catch (\RuntimeException $error) {
            if ($error->getCode() === 404) {
                return;
            }
            throw $error;
        }
        throw new \RuntimeException('COM_INTERCOM_CLEANUP_EXISTS', 409);
    }

    public function assertMailingAbsent(int $mailing): void
    {
        try {
            $this->gateway->mailing($mailing);
        } catch (\RuntimeException $error) {
            if ($error->getCode() !== 404) {
                throw $error;
            }
            foreach (['draft', 'waiting', 'running', 'automation', 'finished'] as $state) {
                if ($this->gateway->retirementCatalogueContains($mailing, $state)) {
                    throw new \RuntimeException('COM_INTERCOM_CLEANUP_EXISTS', 409);
                }
            }
            return;
        }
        throw new \RuntimeException('COM_INTERCOM_CLEANUP_EXISTS', 409);
    }
}
