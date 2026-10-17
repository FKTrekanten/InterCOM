<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Infrastructure\Store;

final class Mailbox
{
    public const VIEWS = [
        'drafts' => ['draft', 'tested'],
        'sent' => ['scheduled', 'submitted', 'completed'],
        'attention' => ['testing', 'estimating', 'releasing', 'uncertain', 'abandoning'],
        'closed' => ['cancelled', 'abandoned', 'deleted', 'closed_unverified'],
    ];

    public function __construct(private Store $store, private int $owner)
    {
    }

    public function page(string $view, string $search = '', int $page = 1, string $state = ''): array
    {
        $view = isset(self::VIEWS[$view]) ? $view : 'drafts';
        $states = self::VIEWS[$view];
        if (in_array($state, $states, true)) {
            $states = [$state];
        }
        $where = 'd.owner_id=' . $this->owner . ' AND d.state IN (' . implode(',', array_map($this->store->q(...), $states)) . ')';
        $search = mb_substr(trim($search), 0, 100);
        if ($search !== '') {
            // INSTR treats %, _ and backslashes as literal search characters.
            $term = $this->store->q(mb_strtolower($search));
            $where .= " AND (d.id=" . (int) ltrim($search, '#') . " OR INSTR(LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.content,'$.subject_da')),'')),$term)>0 OR INSTR(LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.content,'$.subject_en')),'')),$term)>0)";
        }
        $total = (int) ($this->store->row("SELECT COUNT(*) n FROM #__intercom_drafts d WHERE $where")['n'] ?? 0);
        $page = max(1, min($page, max(1, (int) ceil($total / 10))));
        $offset = ($page - 1) * 10;
        $rows = $this->store->rows("SELECT d.*,h.requested_at,h.scheduled_at,h.finished_at FROM #__intercom_drafts d LEFT JOIN #__intercom_history h ON h.draft_id=d.id WHERE $where ORDER BY d.updated_at DESC,d.id DESC LIMIT 10 OFFSET $offset");
        $counts = array_fill_keys(array_keys(self::VIEWS), 0);
        foreach ($this->store->rows('SELECT state,COUNT(*) n FROM #__intercom_drafts WHERE owner_id=' . $this->owner . ' GROUP BY state') as $row) {
            foreach (self::VIEWS as $key => $values) {
                if (in_array($row['state'], $values, true)) {
                    $counts[$key] += (int) $row['n'];
                }
            }
        }
        return compact('view', 'rows', 'total', 'page', 'counts');
    }
}
