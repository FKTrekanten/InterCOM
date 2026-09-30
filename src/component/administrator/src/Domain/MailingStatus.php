<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class MailingStatus
{
    public static function status(array $mailing, int $id, int $groupId, ?int $now = null): string
    {
        if ((string) ($mailing['id'] ?? '') !== (string) $id) {
            return 'mismatch';
        }
        // Only a static, single-list mailing has a terminal completion state.
        // Campaigns and dynamic mailings can continue to use their audience.
        if (($mailing['is_mailing'] ?? null) !== true || ($mailing['is_campaign'] ?? null) !== false || ($mailing['is_dynamic'] ?? null) !== false) {
            return 'unknown';
        }
        $groups = $mailing['mailing_groups']['group_ids'] ?? null;
        if (!is_array($groups) || count($groups) !== 1 || (string) reset($groups) !== (string) $groupId) {
            return 'mismatch';
        }
        $started = $mailing['started'] ?? null;
        $finished = $mailing['finished'] ?? null;
        foreach ([$started, $finished] as $timestamp) {
            if (!is_int($timestamp) && !(is_string($timestamp) && ctype_digit($timestamp))) {
                return 'unknown';
            }
        }
        if ((int) $finished === 0) {
            return 'waiting';
        }
        return (int) $started > 0 && (int) $finished >= (int) $started && (int) $finished <= ($now ?? time()) ? 'completed' : 'unknown';
    }
}
