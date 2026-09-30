<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Infrastructure;

use Joomla\Database\DatabaseInterface;

final class Store
{
    private int $transactionDepth = 0;
    public function __construct(public readonly DatabaseInterface $db)
    {
    }

    public function q(string $value): string
    {
        return $this->db->quote($value);
    }

    public function execute(string $sql): void
    {
        $this->db->setQuery($sql)->execute();
    }

    public function row(string $sql): ?array
    {
        return $this->db->setQuery($sql)->loadAssoc() ?: null;
    }

    public function rows(string $sql): array
    {
        return $this->db->setQuery($sql)->loadAssocList();
    }

    public function begin(): void
    {
        if ($this->transactionDepth !== 0) {
            throw new \LogicException('Transaction already open');
        }
        $this->db->transactionStart();
        $this->transactionDepth = 1;
    }

    public function commit(): void
    {
        $this->db->transactionCommit();
        $this->transactionDepth = 0;
    }

    public function rollback(): void
    {
        if ($this->transactionDepth !== 0) {
            $this->db->transactionRollback();
            $this->transactionDepth = 0;
        }
    }

    public function transaction(callable $operation): mixed
    {
        if ($this->transactionDepth > 0) {
            return $operation();
        }
        $this->begin();
        try {
            $result = $operation();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function audit(int $actor, string $event, int $draftId = 0, array $context = []): void
    {
        // Callers pass explicit metadata, never raw input, secrets or provider responses.
        $record = (object) ['actor_id' => $actor, 'event' => $event, 'draft_id' => $draftId,
            'context' => json_encode($context, JSON_THROW_ON_ERROR), 'created_at' => gmdate('Y-m-d H:i:s')];
        $this->db->insertObject('#__intercom_audit', $record);
    }

    public function draft(int $id, int $owner, bool $lock = false, bool $includeDeleted = false): array
    {
        $row = $this->row('SELECT * FROM #__intercom_drafts WHERE id=' . $id . ' AND owner_id=' . $owner
            . ($includeDeleted ? '' : " AND state!='deleted'") . ($lock ? ' FOR UPDATE' : ''));
        if (!$row) {
            throw new \RuntimeException('COM_INTERCOM_DRAFT_NOT_FOUND', 404);
        }
        return $row;
    }
}
