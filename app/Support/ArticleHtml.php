<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * An article's body is HTML from the admin panel's editor, and the website prints it as it is. So it
 * only ever gets stored after clean() keeps just the formatting the editor offers: no scripts, event
 * attributes, styles or javascript: links, even from a staff account that was broken into.
 */
class ArticleHtml
{
    /** Longest body accepted, in bytes. The sanitizer would silently cut anything over its own limit. */
    public const MAX_LENGTH = 200000;

    public static function clean(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->withMaxInputLength(self::MAX_LENGTH + 1)
            ->allowElement('a', ['href', 'title'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->allowElement('img', ['src', 'alt', 'width', 'height'])
            ->allowElement('figure', ['class'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('th', ['colspan', 'rowspan']);

        foreach (['p', 'br', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'u', 's', 'blockquote', 'ul', 'ol', 'li', 'hr', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'code', 'pre'] as $element) {
            $config = $config->allowElement($element);
        }

        return (new HtmlSanitizer($config))->sanitize($html);
    }

    /**
     * Whether there is anything to read or see, not just empty paragraphs.
     */
    public static function hasContent(string $html): bool
    {
        return str_contains($html, '<img') || trim(html_entity_decode(strip_tags($html)), " \t\n\r\0\x0B\u{A0}") !== '';
    }

    /**
     * The old plain text format: blank lines between blocks, "## " for headings, "- " for lists.
     */
    public static function fromPlainText(string $text): string
    {
        $escape = fn (string $value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $blocks = preg_split('/\n\s*\n/', str_replace("\r\n", "\n", trim($text))) ?: [];

        return implode('', array_map(function (string $block) use ($escape): string {
            $block = trim($block);
            if (str_starts_with($block, '## ')) {
                return '<h2>'.$escape(substr($block, 3)).'</h2>';
            }

            $lines = explode("\n", $block);
            if (array_filter($lines, fn (string $line) => str_starts_with($line, '- ')) === $lines) {
                return '<ul>'.implode('', array_map(fn (string $line) => '<li>'.$escape(substr($line, 2)).'</li>', $lines)).'</ul>';
            }

            return '<p>'.implode('<br>', array_map($escape, $lines)).'</p>';
        }, array_filter($blocks, fn (string $block) => trim($block) !== '')));
    }
}
