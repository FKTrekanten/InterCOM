<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Table;

use Joomla\CMS\Table\Asset;

/** Native Joomla asset-tree operations with InnoDB locks retained by the caller's transaction. */
final class TransactionalAssetTable extends Asset
{
    public function lockTree(): void
    {
        $this->_lock();
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Native Joomla Table override.
    protected function _lock()
    {
        // Joomla's LOCK TABLES implicitly commits MariaDB/MySQL transactions. Lock the
        // entire asset tree with row locks instead, preserving atomic record/audit saves.
        $this->getDatabase()->setQuery('SELECT id FROM #__assets ORDER BY id FOR UPDATE')->loadColumn();
        $this->_locked = true;
        return true;
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Native Joomla Table override.
    protected function _unlock()
    {
        $this->_locked = false;
        return true;
    }
}
