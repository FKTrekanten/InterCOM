<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Table;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;

final class CommunicationTable extends Table
{
    public function __construct(DatabaseInterface $db)
    {
        parent::__construct('#__intercom_types', 'id', $db);
        $this->_trackAssets = false;
    }

    public function store($updateNulls = false)
    {
        if (!parent::store($updateNulls)) {
            throw new \RuntimeException('COM_INTERCOM_ERROR');
        }
        $asset = new TransactionalAssetTable($this->getDatabase(), $this->getDispatcher());
        $asset->lockTree();
        $asset->loadByName($this->_getAssetName());
        $parent = $this->_getAssetParentId();
        if (empty($asset->id) || (int) $asset->parent_id !== $parent) {
            $asset->setLocation($parent, 'last-child');
        }
        $asset->parent_id = $parent;
        $asset->name = $this->_getAssetName();
        $asset->title = mb_substr($this->_getAssetTitle(), 0, 100);
        $asset->rules = (string) $this->getRules();
        if (!$asset->check() || !$asset->store()) {
            throw new \RuntimeException('COM_INTERCOM_ERROR');
        }
        $this->asset_id = (int) $asset->id;
        $row = (object) ['id' => (int) $this->id, 'asset_id' => $this->asset_id];
        $this->getDatabase()->updateObject('#__intercom_types', $row, 'id');
        return true;
    }

    public function delete($pk = null)
    {
        if (!$this->load($pk)) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        $asset = new TransactionalAssetTable($this->getDatabase(), $this->getDispatcher());
        $asset->lockTree();
        if (!$asset->loadByName($this->_getAssetName()) || !$asset->delete() || !parent::delete($pk)) {
            throw new \RuntimeException('COM_INTERCOM_ERROR');
        }
        return true;
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Native Joomla Table override.
    protected function _getAssetName()
    {
        return 'com_intercom.communication.' . (int) $this->id;
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Native Joomla Table override.
    protected function _getAssetTitle()
    {
        return $this->type_key;
    }

    // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- Native Joomla Table override.
    protected function _getAssetParentId(?Table $table = null, $id = null)
    {
        return (int) $this->getDatabase()->setQuery("SELECT id FROM #__assets WHERE name='com_intercom'")->loadResult();
    }
}
