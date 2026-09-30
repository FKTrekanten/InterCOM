<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;
use FKT\Component\Intercom\Administrator\Domain\DeliveryGateway;
use FKT\Component\Intercom\Administrator\Domain\Message;
use FKT\Component\Intercom\Administrator\Table\CommunicationTable;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Access\Rules;

final class Catalog
{
    public function __construct(public readonly Store $store, public readonly array $config)
    {
    }

    private function lockConfiguration(): void
    {
        $this->store->row("SELECT provider FROM #__intercom_connections WHERE provider='cleverreach' FOR UPDATE");
        $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
        $current = json_decode($row['params'] ?? '{}', true) ?: [];
        if (
            ($current['mode'] ?? 'fake') !== ($this->config['mode'] ?? 'fake')
            || (int) ($current['group_id'] ?? 0) !== (int) ($this->config['group_id'] ?? 0)
        ) {
            throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
        }
    }

    public function context(): int
    {
        return ($this->config['mode'] ?? 'fake') === 'fake' ? 0 : (int) ($this->config['group_id'] ?? 0);
    }

    public function languages(): array
    {
        $rows = $this->store->rows('SELECT lang_code,title FROM #__languages ORDER BY ordering');
        $languages = ['da-DK' => 'Dansk', 'en-GB' => 'English'];
        foreach ($rows as $row) {
            $languages[$row['lang_code']] = $row['title'];
        }
        return $languages;
    }

    public function defaultLanguage(): string
    {
        return (string) (\Joomla\CMS\Component\ComponentHelper::getParams('com_languages')->get('site', 'en-GB'));
    }

    public function types(bool $published = true): array
    {
        $rows = $this->store->rows('SELECT * FROM #__intercom_types' . ($published ? ' WHERE state=1' : '') . ' ORDER BY ordering,id');
        $result = [];
        foreach ($rows as $row) {
            $result[$row['type_key']] = $this->definition($row);
        }
        return $result;
    }

    public function definition(array $row): array
    {
        $translations = [];
        foreach ($this->store->rows('SELECT * FROM #__intercom_type_translations WHERE type_id=' . (int) $row['id'] . ' ORDER BY language') as $translation) {
            unset($translation['type_id']);
            $translations[$translation['language']] = $translation;
        }
        return ['id' => (int) $row['id'], 'key' => $row['type_key'], 'suppression' => $row['suppression'],
            'category_id' => (int) $row['category_id'], 'require_group' => (bool) $row['require_group'],
            'state' => (int) $row['state'], 'revision' => (int) $row['revision'], 'translations' => $translations,
            'fallback' => $this->defaultLanguage()];
    }

    public function saveType(array $input, int $actor): int
    {
        $id = (int) ($input['id'] ?? 0);
        $key = (string) ($input['type_key'] ?? '');
        $word = trim((string) ($input['suppression'] ?? ''));
        if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $key) || !preg_match('/^[^,\x00-\x20{}]{1,128}$/uD', $word)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_TYPE', 422);
        }
        $translations = [];
        foreach ($this->languages() as $language => $label) {
            $translation = (array) ($input['translations'][$language] ?? []);
            $clean = [];
            foreach (['name' => 255, 'description' => 2000, 'subject_prefix' => 80, 'heading' => 255] as $field => $limit) {
                $value = trim((string) ($translation[$field] ?? ''));
                if (mb_strlen($value) > $limit || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f{}]/u', $value)) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_TYPE', 422);
                }
                $clean[$field] = $value;
            }
            $translations[$language] = $clean;
        }
        if (empty($translations[$this->defaultLanguage()]['name'])) {
            throw new \RuntimeException('COM_INTERCOM_TRANSLATION_REQUIRED', 422);
        }
        $rules = (array) ($input['rules'] ?? []);
        foreach ($rules as $action => $groups) {
            if (!in_array($action, ['intercom.type.compose', 'intercom.type.send'], true) || !is_array($groups)) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_RULES', 422);
            }
            foreach ($groups as $group => $value) {
                if (
                    !ctype_digit((string) $group) || !in_array((string) $value, ['', '0', '1'], true)
                    || !$this->store->row('SELECT id FROM #__usergroups WHERE id=' . (int) $group)
                ) {
                    throw new \RuntimeException('COM_INTERCOM_INVALID_RULES', 422);
                }
            }
        }
        return $this->store->transaction(function () use ($id, $key, $word, $input, $translations, $rules, $actor): int {
            $this->lockConfiguration();
            $before = $id ? $this->store->row("SELECT * FROM #__intercom_types WHERE id=$id FOR UPDATE") : null;
            if ($id && (!$before || (int) $before['revision'] !== (int) ($input['revision'] ?? 0) || $before['type_key'] !== $key)) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            if ($this->store->row('SELECT id FROM #__intercom_types WHERE suppression=' . $this->store->q($word) . " AND id!=$id")) {
                throw new \RuntimeException('COM_INTERCOM_DUPLICATE_SUPPRESSION', 422);
            }
            if (!$id && $this->store->row('SELECT id FROM #__intercom_types WHERE type_key=' . $this->store->q($key))) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_TYPE', 422);
            }
            $previous = $before ? $this->definition($before) : null;
            $oldRules = $before ? $this->store->row('SELECT rules FROM #__assets WHERE id=' . (int) $before['asset_id']) : null;
            $table = new CommunicationTable($this->store->db);
            $table->bind(['id' => $id, 'asset_id' => (int) ($before['asset_id'] ?? 0), 'type_key' => $key,
                'suppression' => $word, 'category_id' => max(0, (int) ($input['category_id'] ?? 0)),
                'state' => in_array((int) ($input['state'] ?? 0), [-2, 0, 1], true) ? (int) ($input['state'] ?? 0) : 0,
                'require_group' => !empty($input['require_group']) ? 1 : 0, 'ordering' => (int) ($input['ordering'] ?? 0),
                'used' => (int) ($before['used'] ?? 0), 'revision' => (int) ($before['revision'] ?? 0) + 1]);
            $table->setRules(new Rules($rules));
            $table->store();
            $id = (int) $table->id;
            $this->store->execute("DELETE FROM #__intercom_type_translations WHERE type_id=$id");
            foreach ($translations as $language => $translation) {
                $row = (object) array_merge(['type_id' => $id, 'language' => $language], $translation);
                $this->store->db->insertObject('#__intercom_type_translations', $row);
            }
            $this->store->execute("UPDATE #__intercom_drafts SET state='draft',tested_revision=NULL,tested_fingerprint=NULL WHERE state='tested' AND JSON_UNQUOTE(JSON_EXTRACT(content,'$.type'))=" . $this->store->q($key));
            $this->store->audit(
                $actor,
                $before ? 'communication.updated' : 'communication.created',
                0,
                ['before' => $previous, 'after' => $this->definition($this->store->row("SELECT * FROM #__intercom_types WHERE id=$id")),
                'permissions_before' => json_decode(
                    $oldRules['rules'] ?? '{}',
                    true
                ),
                'permissions_after' => $rules]
            );
            Access::clearStatics();
            return $id;
        });
    }

    public function deleteType(int $id, int $revision, int $actor): void
    {
        $this->store->transaction(function () use ($id, $revision, $actor): void {
            $this->lockConfiguration();
            $row = $this->store->row("SELECT * FROM #__intercom_types WHERE id=$id FOR UPDATE");
            if (!$row || (int) $row['revision'] !== $revision) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $key = $this->store->q($row['type_key']);
            if (
                (int) $row['used'] === 1 || $this->store->row("SELECT id FROM #__intercom_drafts WHERE JSON_UNQUOTE(JSON_EXTRACT(content,'$.type'))=$key LIMIT 1")
                || $this->store->row("SELECT draft_id FROM #__intercom_revisions WHERE JSON_UNQUOTE(JSON_EXTRACT(content,'$.type'))=$key LIMIT 1")
            ) {
                // Used group identities remain available even after message retention expires.
                throw new \RuntimeException('COM_INTERCOM_TYPE_IN_USE', 409);
            }
            $definition = $this->definition($row);
            $table = new CommunicationTable($this->store->db);
            $table->delete($id);
            $this->store->execute("DELETE FROM #__intercom_type_translations WHERE type_id=$id");
            $this->store->audit($actor, 'communication.deleted', 0, ['definition' => $definition]);
            Access::clearStatics();
        });
    }

    public function refreshTags(DeliveryGateway $gateway, int $actor): void
    {
        try {
            $tags = array_values(array_unique(array_merge($gateway->tags('group'), $gateway->tags('membership'))));
            foreach ($tags as $tag) {
                if (!is_string($tag) || !preg_match('/^(group|membership)\.[^,{}\x00-\x1f]{1,180}$/uD', $tag)) {
                    throw new \RuntimeException('COM_INTERCOM_PROVIDER_ERROR');
                }
            }
            $this->store->transaction(function () use ($tags, $actor): void {
                $this->lockConfiguration();
                $list = $this->context();
                $this->store->execute("INSERT IGNORE INTO #__intercom_catalogues (list_id) VALUES ($list)");
                $this->store->row("SELECT revision FROM #__intercom_catalogues WHERE list_id=$list FOR UPDATE");
                $this->store->execute("UPDATE #__intercom_tags SET available=0 WHERE list_id=$list");
                foreach ($tags as $tag) {
                    $this->store->execute("INSERT INTO #__intercom_tags (list_id,tag) VALUES ($list," . $this->store->q($tag) . ') ON DUPLICATE KEY UPDATE available=1');
                }
                $this->store->execute("UPDATE #__intercom_catalogues SET revision=revision+1,refreshed_at=UTC_TIMESTAMP() WHERE list_id=$list");
                $this->store->audit($actor, 'tags.refreshed', 0, ['list_id' => $list, 'count' => count($tags)]);
            });
        } catch (\Throwable $e) {
            $this->store->audit($actor, 'tags.refresh_failed', 0, ['list_id' => $this->context()]);
            throw $e;
        }
    }

    public function tags(bool $enabled = true): array
    {
        return $this->store->rows('SELECT * FROM #__intercom_tags WHERE list_id=' . $this->context()
            . ($enabled ? ' AND enabled=1 AND available=1' : '') . ' ORDER BY ordering,tag');
    }

    public function label(string $tag, string $language): string
    {
        $row = $this->store->row('SELECT tag,labels FROM #__intercom_tags WHERE list_id=' . $this->context() . ' AND tag=' . $this->store->q($tag));
        return \FKT\Component\Intercom\Administrator\Domain\TagLabel::display($row ?: ['tag' => $tag], $language);
    }

    public function tagLabels(array $message): array
    {
        $labels = [];
        foreach ($message['tags'] ?? [] as $tag) {
            foreach ($this->languages() as $language => $name) {
                $labels[$tag][$language] = $this->label($tag, $language);
            }
        }
        return $labels;
    }

    public function saveTags(array $enabled, array $ordering, int $revision, int $actor, array $labels = []): void
    {
        $this->store->transaction(function () use ($enabled, $ordering, $revision, $actor, $labels): void {
            $this->lockConfiguration();
                $list = $this->context();
            $meta = $this->store->row("SELECT * FROM #__intercom_catalogues WHERE list_id=$list FOR UPDATE");
            if (!$meta || (int) $meta['revision'] !== $revision) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $all = $this->tags(false);
            $available = array_column(array_filter($all, static fn ($row) => (int) $row['available'] === 1), 'tag');
            if (array_diff($enabled, $available) || array_diff(array_keys($labels), array_column($all, 'tag'))) {
                throw new \RuntimeException('COM_INTERCOM_SCOPE_DENIED', 403);
            }
            foreach ($all as $row) {
                $value = (int) in_array($row['tag'], $enabled, true);
                $position = (int) ($ordering[$row['tag']] ?? $row['ordering']);
                $names = array_key_exists($row['tag'], $labels) ? \FKT\Component\Intercom\Administrator\Domain\TagLabel::validate((array) $labels[$row['tag']], $this->languages()) : json_decode($row['labels'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
                $names = $this->store->q(json_encode($names, JSON_THROW_ON_ERROR));
                $this->store->execute("UPDATE #__intercom_tags SET enabled=$value,ordering=$position,labels=$names WHERE list_id=$list AND tag=" . $this->store->q($row['tag']));
            }
            $this->store->execute("UPDATE #__intercom_catalogues SET revision=revision+1 WHERE list_id=$list");
            $this->store->audit($actor, 'tags.visibility_saved', 0, ['list_id' => $list, 'before' => $all, 'enabled' => $enabled, 'after' => $this->tags(false)]);
        });
    }

    public function assertTags(array $message): void
    {
        $allowed = array_column($this->tags(), 'tag');
        if (array_diff(array_merge($message['tags'], $message['memberships']), $allowed)) {
            throw new \RuntimeException('COM_INTERCOM_SCOPE_DENIED', 403);
        }
    }

    public function snapshot(array $message): array
    {
        $this->lockConfiguration();
        $key = $this->store->q($message['type']);
        $row = $this->store->row("SELECT * FROM #__intercom_types WHERE type_key=$key AND state=1");
        if (!$row) {
            throw new \RuntimeException('COM_INTERCOM_DENIED', 403);
        }
        $this->assertTags($message);
        return $this->definition($row);
    }

    public function footer(): array
    {
        $settings = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
        return \FKT\Component\Intercom\Administrator\Domain\Footer::validate(json_decode($settings['params'] ?? '{}', true, 64, JSON_THROW_ON_ERROR));
    }

    public function fingerprint(array $message): string
    {
        $settings = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component'");
        $current = json_decode($settings['params'] ?? '{}', true) ?: [];
        $meta = $this->store->row('SELECT revision FROM #__intercom_catalogues WHERE list_id=' . $this->context());
        return hash('sha256', json_encode([$this->snapshot($message), $meta['revision'] ?? 0,
            array_intersect_key($current, array_flip(['mode', 'group_id', 'sender_name', 'sender_email', 'unsubscribe_form_id', 'board_archive_email'])),
            \FKT\Component\Intercom\Administrator\Domain\Footer::validate($current),
            Message::templateVersion(), (new Design($this->store))->snapshot()], JSON_THROW_ON_ERROR));
    }

    public function saveScopes(array $input, int $actor, string $revision): void
    {
        $allowed = array_column($this->tags(), 'tag');
        $rules = [];
        foreach ($input as $group => $rule) {
            $id = (int) $group;
            $tags = array_values(array_unique((array) ($rule['tags'] ?? [])));
            if (
                !$this->store->row("SELECT id FROM #__usergroups WHERE id=$id") || array_diff($tags, $allowed)
                || array_filter($tags, static fn ($tag) => !str_starts_with($tag, 'group.'))
            ) {
                throw new \RuntimeException('COM_INTERCOM_INVALID_RULES', 422);
            }
            if ($tags || !empty($rule['all'])) {
                $rules[] = ['group' => $id, 'all' => !empty($rule['all']), 'tags' => $tags];
            }
        }
        $this->store->transaction(function () use ($rules, $actor, $revision): void {
            $this->lockConfiguration();
            $row = $this->store->row("SELECT params FROM #__extensions WHERE element='com_intercom' AND type='component' FOR UPDATE");
            $params = json_decode($row['params'] ?? '{}', true) ?: [];
            $before = json_decode($params['audience_rules'] ?? '[]', true) ?: [];
            if (!hash_equals(hash('sha256', json_encode($before)), $revision)) {
                throw new \RuntimeException('COM_INTERCOM_CONFLICT', 409);
            }
            $params['audience_rules'] = json_encode($rules, JSON_THROW_ON_ERROR);
            $this->store->execute('UPDATE #__extensions SET params=' . $this->store->q(json_encode($params, JSON_THROW_ON_ERROR)) . " WHERE element='com_intercom' AND type='component'");
            $this->store->audit($actor, 'audience.permissions_saved', 0, ['before' => $before, 'after' => $rules]);
        });
    }
}
