<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class MailStatus
{
    public static function describe(array $draft): array
    {
        $state = $draft['state'];
        $title = 'STATE_' . strtoupper($state);
        $detail = match ($state) {
            'draft' => 'STATUS_DRAFT', 'tested' => 'TESTED',
            'testing', 'estimating', 'releasing', 'abandoning' => 'STATUS_PROGRESS',
            'scheduled' => 'STATUS_SCHEDULED', 'submitted' => 'SUBMITTED',
            'completed' => 'STATUS_COMPLETED', 'uncertain' => 'STATUS_REVIEW',
            'closed_unverified' => 'STATUS_CLOSED_UNVERIFIED',
            default => 'STATUS_CLOSED',
        };
        $severity = $state === 'uncertain' ? 'error' : 'info';
        $op = $draft['test_operation'] ?? null;
        if ($op && (int) $op['revision'] === (int) $draft['revision'] && $op['outcome'] === 'failed' && $state === 'draft') {
            $title = 'STATUS_TEST_FAILED';
            $detail = match ($op['stage']) {
                'test_da', 'test_en', 'accepted_da', 'accepted_en' => 'STATUS_TEST_TRANSPORT',
                default => 'STATUS_PREPARATION_FAILED',
            };
            $severity = 'error';
        }
        if ($op && (int) $op['revision'] === (int) $draft['revision'] && $state === 'uncertain') {
            $detail = match ($op['stage']) {
                'filter_write' => 'STATUS_FILTER_REVIEW', 'mailing_write' => 'STATUS_MAILING_REVIEW', default => $detail,
            };
        }
        if (($draft['delivery_mode'] ?? '') === 'fake') {
            $detail = match ($state) {
                'tested' => 'FAKE_TESTED', 'submitted', 'completed' => 'FAKE_SUBMITTED', default => $detail,
            };
        }
        return ['status_title' => $title, 'status_detail' => $detail, 'status_severity' => $severity];
    }
}
