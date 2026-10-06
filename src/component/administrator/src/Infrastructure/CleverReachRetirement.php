<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use FKT\Component\Intercom\Administrator\Domain\RetirementGateway;

// Manual retirement only: the published mailing API has no DELETE contract.
// Absence must be combined with the administrator's permanent-removal attestation.
final class CleverReachRetirement implements RetirementGateway
{
    public function __construct(private CleverReachGateway $gateway)
    {
    }

    public function account(): string
    {
        return $this->gateway->retirementAccount();
    }

    private function scope(int $group, int $filter): void
    {
        $this->gateway->assertRetirementScope($group, $filter);
    }

    public function assertDraft(int $mailing, int $group, int $filter): void
    {
        $this->scope($group, $filter);
        $remote = $this->gateway->mailing($mailing);
        if (
            (string) ($remote['id'] ?? '') !== (string) $mailing
            || ($remote['is_mailing'] ?? null) !== true || ($remote['is_campaign'] ?? null) !== false
            || ($remote['is_dynamic'] ?? null) !== false || ($remote['is_splittest'] ?? null) !== false
            || array_map('strval', $remote['mailing_groups']['group_ids'] ?? []) !== [(string) $group]
        ) {
            throw new \RuntimeException('COM_INTERCOM_ABANDON_UNSAFE', 409);
        }
        foreach (['started', 'finished', 'ready', 'send_on'] as $key) {
            if (!in_array($remote[$key] ?? null, [0, '0'], true)) {
                throw new \RuntimeException('COM_INTERCOM_ABANDON_UNSAFE', 409);
            }
        }
        if (!$this->gateway->retirementCatalogueContains($mailing, 'draft')) {
            throw new \RuntimeException('COM_INTERCOM_ABANDON_UNSAFE', 409);
        }
    }

    public function assertAbsent(int $mailing, int $group, int $filter): void
    {
        $this->scope($group, $filter);
        try {
            $this->gateway->mailing($mailing);
        } catch (\RuntimeException $error) {
            if ($error->getCode() !== 404) {
                throw $error;
            }
            // Establish successful catalogue access too; an arbitrary 404 cannot
            // stand in for correct account/read scopes or queued-mailing evidence.
            foreach (['draft', 'waiting', 'running', 'automation', 'finished'] as $state) {
                if ($this->gateway->retirementCatalogueContains($mailing, $state)) {
                    throw new \RuntimeException('COM_INTERCOM_ABANDON_UNVERIFIED', 409);
                }
            }
            return;
        }
        throw new \RuntimeException('COM_INTERCOM_ABANDON_EXISTS', 409);
    }
}
