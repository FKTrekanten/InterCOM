<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Service;

use FKT\Component\Intercom\Administrator\Domain\EmailContent;
use FKT\Component\Intercom\Administrator\Domain\Message;

final class TestDelivery
{
    public function __construct(private \Closure $send)
    {
    }

    public static function payloads(array $snapshot): array
    {
        $message = $snapshot['message'];
        $result = [];
        foreach (['da' => 'da-DK', 'en' => 'en-GB'] as $lang => $locale) {
            $note = $lang === 'da' ? 'TEST via hjemmesidens mailtjeneste. Eksempel på medlemsdata; frameldings- og weblink er ikke aktive. Kontroller begge sprog før afsendelse.' : 'TEST through the site mail service. Example member details; unsubscribe and online-view links are inactive. Check both languages before sending.';
            $html = preg_replace('/<body[^>]*>/i', '$0<div style="padding:16px;background:#f4f7fa;color:#172534;font:12px Arial,sans-serif">' . $note . '</div>', $snapshot[$lang], 1);
            preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $body);
            $prefix = Message::translation($message['definition'] ?? [], $locale)['subject_prefix'];
            $result[] = ['language' => $lang, 'subject' => '[InterCOM test / ' . strtoupper($lang) . '] ' . ($prefix ? '[' . $prefix . '] ' : '') . $message['subject_' . $lang],
                'html' => $html, 'text' => EmailContent::text($body[1] ?? ''), 'sender' => $message['sender']];
        }
        return $result;
    }

    public function deliver(array $snapshot, string $address): void
    {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_EMAIL', 422);
        }
        try {
            foreach (self::payloads($snapshot) as $payload) {
                if (($this->send)($address, $payload) !== true) {
                    throw new \RuntimeException('COM_INTERCOM_TEST_DELIVERY_ERROR');
                }
            }
        } catch (\Throwable) {
            // Do not expose SMTP details, credentials or the recipient address.
            throw new \RuntimeException('COM_INTERCOM_TEST_DELIVERY_ERROR');
        }
    }
}
