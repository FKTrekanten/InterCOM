<?php

declare(strict_types=1);

namespace FKT\Component\Intercom\Administrator\Domain;

final class EmailContent
{
    public static function placeholders(string $text): string
    {
        $parts = preg_split('/(\{FIRSTNAME\[std:(?:Medlem|Member)\]\})/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException('COM_INTERCOM_INVALID_MESSAGE', 422);
        }
        return implode('', array_map(static function ($part): string {
            if (in_array($part, ['{FIRSTNAME[std:Medlem]}', '{FIRSTNAME[std:Member]}'], true)) {
                return $part;
            }
            do {
                $previous = $part;
                $part = preg_replace('/\{[^{}]*\}/u', '', $part);
            } while ($part !== $previous);
            return str_replace(['{', '}'], '', $part);
        }, $parts));
    }

    public static function sanitise(string $html): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $target = new \DOMDocument('1.0', 'UTF-8');
        $root = $target->createElement('div');
        $target->appendChild($root);
        $walk = function (\DOMNode $node, \DOMNode $parent) use (&$walk, $target): void {
            if ($node instanceof \DOMText) {
                $parent->appendChild($target->createTextNode(self::placeholders($node->nodeValue)));
                return;
            }
            if (!$node instanceof \DOMElement) {
                return;
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'img', 'video', 'audio', 'template', 'noscript'], true)) {
                return;
            }
            $allowed = ['p', 'div', 'span', 'br', 'h1', 'h2', 'h3', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'a'];
            $element = $parent;
            if (in_array($tag, $allowed, true)) {
                $element = $target->createElement($tag);
                if ($tag === 'a') {
                    $url = trim($node->getAttribute('href'));
                    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
                    if (
                        in_array($scheme, ['http', 'https', 'mailto'], true) && !preg_match('/[\x00-\x20{}\\\\]/', $url)
                        && ($scheme === 'mailto' || parse_url($url, PHP_URL_HOST))
                    ) {
                        $element->setAttribute('href', $url);
                    }
                }
                if (preg_match('/(?:^|;)\s*text-align\s*:\s*(left|center|right|justify)\s*(?:;|$)/i', $node->getAttribute('style'), $match)) {
                    $element->setAttribute('style', 'text-align:' . strtolower($match[1]));
                }
                $parent->appendChild($element);
            }
            foreach (iterator_to_array($node->childNodes) as $child) {
                $walk($child, $element);
            }
        };
        foreach (iterator_to_array($document->getElementsByTagName('body')->item(0)->childNodes) as $node) {
            $walk($node, $root);
        }
        $clean = '';
        foreach ($root->childNodes as $child) {
            $clean .= $target->saveHTML($child);
        }
        return trim($clean);
    }

    public static function text(string $html): string
    {
        $html = preg_replace('/<(?:br\s*\/?|\/p|\/div|\/h[123]|\/li|\/blockquote)>/i', "\n", $html);
        $html = preg_replace_callback('/<a href="([^"]+)"[^>]*>(.*?)<\/a>/is', static fn ($match) => strip_tags($match[2]) . ' (' . $match[1] . ')', $html);
        return trim(preg_replace('/\n{3,}/', "\n\n", html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
