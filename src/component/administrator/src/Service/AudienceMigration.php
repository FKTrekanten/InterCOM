<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class AudienceMigration
{
    public const DISCIPLINES = ['group.epee' => 'discipline.epee', 'group.foil' => 'discipline.foil', 'group.sabre' => 'discipline.sabre'];

    public static function message(array $message): array
    {
        $tags = [];
        $disciplines = $message['disciplines'] ?? [];
        foreach ($message['tags'] ?? [] as $tag) {
            if (isset(self::DISCIPLINES[$tag])) {
                $disciplines[] = self::DISCIPLINES[$tag];
            } else {
                $tags[] = $tag;
            }
        }
        $message['tags'] = $tags;
        $message['disciplines'] = array_values(array_unique($disciplines));
        foreach (self::DISCIPLINES as $old => $new) {
            if (isset($message['tag_labels'][$old])) {
                $message['tag_labels'][$new] = $message['tag_labels'][$old];
                unset($message['tag_labels'][$old]);
            }
        }
        return $message;
    }

    public static function run(Store $store): void
    {
        $store->transaction(function () use ($store): void {
            $store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
            $row = $store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
            $params = json_decode($row['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
            if (($params['member_audience_version'] ?? 0) >= 1) {
                return;
            }
            foreach (self::DISCIPLINES as $old => $new) {
                foreach ($store->rows('SELECT * FROM #__intercom_tags WHERE tag=' . $store->q($old) . ' FOR UPDATE') as $tag) {
                    $list = (int) $tag['list_id'];
                    $existing = $store->row("SELECT labels FROM #__intercom_tags WHERE list_id=$list AND tag=" . $store->q($new) . ' FOR UPDATE');
                    $labels = array_merge(
                        json_decode($existing['labels'] ?? '{}', true, 32, JSON_THROW_ON_ERROR) ?: [],
                        json_decode($tag['labels'] ?? '{}', true, 32, JSON_THROW_ON_ERROR) ?: []
                    );
                    $store->execute("INSERT INTO #__intercom_tags (list_id,tag,enabled,available,ordering,labels) VALUES ($list," . $store->q($new)
                        . ',' . (int) $tag['enabled'] . ',' . (int) $tag['available'] . ',' . (int) $tag['ordering'] . ',' . $store->q(json_encode($labels, JSON_THROW_ON_ERROR))
                        . ') ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),available=VALUES(available),ordering=VALUES(ordering),labels=VALUES(labels)');
                }
                $store->execute('DELETE FROM #__intercom_tags WHERE tag=' . $store->q($old));
            }
            $rules = json_decode($params['audience_rules'] ?? '[]', true, 32, JSON_THROW_ON_ERROR);
            foreach ($rules as &$rule) {
                $rule['tags'] = array_values(array_unique(array_map(static fn ($tag) => self::DISCIPLINES[$tag] ?? $tag, $rule['tags'] ?? [])));
            }
            unset($rule);
            $params['audience_rules'] = json_encode($rules, JSON_THROW_ON_ERROR);
            // Frozen revisions, history and provider operations keep their original evidence.
            foreach ($store->rows("SELECT id,content,state FROM #__intercom_drafts WHERE state IN ('draft','tested','deleted','cancelled') AND content!='{}' FOR UPDATE") as $draft) {
                $before = json_decode($draft['content'], true, 64, JSON_THROW_ON_ERROR);
                $message = self::message($before);
                $changed = ($before['tags'] ?? []) !== $message['tags'] || ($before['disciplines'] ?? []) !== $message['disciplines'];
                if (!$changed && empty($message['age_from']) && empty($message['age_to']) && empty($message['gender']) && empty($message['disciplines'])) {
                    continue;
                }
                $id = (int) $draft['id'];
                $state = $draft['state'] === 'tested' ? 'draft' : $draft['state'];
                $store->execute('UPDATE #__intercom_drafts SET content=' . $store->q(json_encode($message, JSON_THROW_ON_ERROR))
                    . ',state=' . $store->q($state) . ',revision=revision+1,tested_revision=NULL,tested_fingerprint=NULL,tested_audience=NULL,tested_count=NULL,'
                    . "audience_fingerprint=NULL,audience_rules=NULL,estimate_count=NULL,estimate_checked=NULL,updated_at=UTC_TIMESTAMP() WHERE id=$id");
                $store->audit(0, 'audience.migrated', $id);
            }
            $store->execute('UPDATE #__intercom_catalogues SET revision=revision+1');
            $params['member_audience_version'] = 1;
            $store->execute('UPDATE #__extensions SET params=' . $store->q(json_encode($params, JSON_THROW_ON_ERROR)) . " WHERE element='com_intercom' AND type='component'");
            $store->audit(0, 'audience.migration_completed');
        });
    }
}
