<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Access\Rules;
use Joomla\CMS\Language\Text;

final class CommunicationPermissions
{
    private const ACTIONS = ['intercom.type.compose', 'intercom.type.send'];

    public function __construct(private readonly Store $store)
    {
    }

    public function normalize(array $input): array
    {
        $groupIds = array_map('intval', array_column($this->store->rows('SELECT id FROM #__usergroups'), 'id'));
        $rules = [];
        foreach ($input as $action => $groups) {
            if (!in_array($action, self::ACTIONS, true) || !is_array($groups)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_RULES', 422);
            }
            $rules[$action] = [];
            foreach ($groups as $group => $value) {
                if (
                    !ctype_digit((string) $group) || !in_array((int) $group, $groupIds, true)
                    || !in_array($value, ['', '0', '1', 0, 1, false, true], true)
                ) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_RULES', 422);
                }
                // Joomla's rules form filter omits Inherited; Rules itself casts '' to Denied.
                if ($value !== '') {
                    $rules[$action][(int) $group] = (bool) $value;
                }
            }
        }
        return $rules;
    }

    public function preview(array $input): array
    {
        $id = (int) ($input['id'] ?? 0);
        $record = $id ? $this->store->row("SELECT asset_id,revision FROM #__intercom_types WHERE id=$id") : null;
        if ($id && (!$record || (int) $record['revision'] !== (int) ($input['revision'] ?? 0))) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        $asset = $record ? $this->store->row('SELECT parent_id FROM #__assets WHERE id=' . (int) $record['asset_id']) : null;
        $parentId = $record ? (int) ($asset['parent_id'] ?? 0) : (int) ($this->store->row("SELECT id FROM #__assets WHERE name='com_intercom'")['id'] ?? 0);
        if (!$parentId) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
        $local = new Rules($this->normalize((array) ($input['rules'] ?? [])));
        // Copy the cached rules: an unsaved preview must never affect subsequent ACL checks.
        $parent = new Rules((string) Access::getAssetRules($parentId, true, true));
        $effective = new Rules((string) $parent);
        $effective->merge($local);
        $groups = $this->store->rows('SELECT id,lft,rgt FROM #__usergroups ORDER BY lft');
        $result = [];
        foreach ($groups as $group) {
            $groupId = (int) $group['id'];
            $path = array_map('intval', array_column(array_filter($groups, static fn ($ancestor) =>
                (int) $ancestor['lft'] <= (int) $group['lft'] && (int) $ancestor['rgt'] >= (int) $group['rgt']), 'id'));
            $parentPath = array_values(array_diff($path, [$groupId]));
            $superUser = Access::checkGroup($groupId, 'core.admin');
            foreach (self::ACTIONS as $action) {
                $setting = $local->allow($action, $groupId);
                $allowed = $effective->allow($action, $path) === true;
                $locked = $parent->allow($action, $path) === false || $effective->allow($action, $parentPath) === false;
                if ($superUser) {
                    $allowed = true;
                    $locked = true;
                    $label = 'JLIB_RULES_ALLOWED_ADMIN';
                } elseif ($locked) {
                    $allowed = false;
                    $label = 'JLIB_RULES_NOT_ALLOWED_LOCKED';
                } elseif ($setting !== null) {
                    $label = $setting ? 'JLIB_RULES_ALLOWED' : 'JLIB_RULES_NOT_ALLOWED';
                } else {
                    $label = $allowed ? 'JLIB_RULES_ALLOWED_INHERITED' : 'JLIB_RULES_NOT_ALLOWED_INHERITED';
                }
                $result['jform_rules_' . $action . '_' . $groupId] = [
                    'class' => 'badge ' . ($allowed ? 'bg-success' : 'bg-danger'),
                    'text' => Text::_($label), 'locked' => $locked,
                ];
            }
        }
        return $result;
    }
}
