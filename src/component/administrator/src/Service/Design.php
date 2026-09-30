<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\EmailDesign;
use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Design
{
    public function __construct(private Store $store)
    {
    }

    public function snapshot(): array
    {
        $row = $this->store->row('SELECT * FROM #__intercom_design WHERE id=1');
        return ['revision' => (int) ($row['revision'] ?? 1), 'settings' => EmailDesign::validate(json_decode($row['configuration'] ?? '{}', true, 32, JSON_THROW_ON_ERROR))];
    }

    public function save(array $input, int $revision, int $actor, bool $reset = false): void
    {
        $settings = EmailDesign::validate($reset ? EmailDesign::defaults() : $input);
        $this->store->transaction(function () use ($settings, $revision, $actor, $reset): void {
            // Same lock order as workflow/configuration changes.
            $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $row = $this->store->row('SELECT * FROM #__intercom_design WHERE id=1 FOR UPDATE');
            if (!$row || (int) $row['revision'] !== $revision) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $before = $this->snapshot();
            $this->store->execute('UPDATE #__intercom_design SET configuration=' . $this->store->q(json_encode($settings, JSON_THROW_ON_ERROR)) . ',revision=revision+1 WHERE id=1');
            $this->store->execute("UPDATE #__intercom_drafts SET state='draft',tested_revision=NULL,tested_fingerprint=NULL WHERE state='tested'");
            $this->store->audit($actor, $reset ? 'design.reset' : 'design.saved', 0, ['before' => $before, 'after' => $this->snapshot()]);
        });
    }
}
