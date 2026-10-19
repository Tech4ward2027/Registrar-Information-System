<?php

namespace App\Services\Certificates;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allow-list HTML sanitizer for certificate template bodies.
 *
 * Templates are authored by admins but are rendered to PDF by the server, so
 * they are treated as untrusted markup: only a small set of layout/typography
 * elements and style properties survive; everything else (scripts, iframes,
 * forms, images, links, event handlers, class/id, comments) is removed. There
 * is no remote content of any kind — no <img>, <a>, url(), @import — so a
 * template cannot make the server fetch a URL or run anything.
 *
 * Idempotent: sanitize(sanitize(x)) === sanitize(x).
 */
class TemplateSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'div', 'span', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 'sup', 'sub', 'small',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'colgroup', 'col',
    ];

    /** Removed together with everything inside them. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'link', 'meta',
        'base', 'form', 'input', 'button', 'textarea', 'select', 'option', 'svg', 'math', 'template',
        'noscript', 'head', 'title', 'audio', 'video', 'canvas', 'img', 'picture', 'source',
    ];

    private const TAG_ATTRIBUTES = [
        'td'  => ['colspan', 'rowspan', 'align'],
        'th'  => ['colspan', 'rowspan', 'align'],
        'col' => ['span'],
        'colgroup' => ['span'],
        'p'   => ['align'],
        'div' => ['align'],
    ];

    private const ALLOWED_STYLE_PROPERTIES = [
        'text-align', 'font-size', 'font-weight', 'font-style', 'font-family', 'text-decoration',
        'text-transform', 'text-indent', 'letter-spacing', 'line-height', 'color', 'vertical-align',
        'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
        'width', 'height', 'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
        'border-collapse', 'page-break-before', 'page-break-after', 'page-break-inside',
    ];

    /** Letters, digits, whitespace and the few symbols CSS lengths/colours/font lists need. */
    private const SAFE_STYLE_VALUE = '/^[A-Za-z0-9\s.,%#\-+\']*$/';
    private const FORBIDDEN_STYLE_WORDS = '/(url|expression|import|javascript|behavior|binding|image-set|attr)/i';

    private const MAX_DEPTH = 32;

    public function sanitize(string $html): string
    {
        $html = str_replace("\0", '', $html);
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="ct-root">' . $html . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('ct-root');
        if (!$root) {
            return '';
        }

        $this->clean($root, 0, true);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function clean(DOMNode $parent, int $depth, bool $isRoot = false): void
    {
        // Snapshot: we mutate the child list while walking it.
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMElement) {
                $name = strtolower($node->nodeName);

                if ($depth >= self::MAX_DEPTH || in_array($name, self::DROP_WITH_CONTENT, true)) {
                    $parent->removeChild($node);
                    continue;
                }

                if (!in_array($name, self::ALLOWED_TAGS, true)) {
                    // Unknown but harmless wrapper (<font>, <center>, ...): keep its children.
                    $this->clean($node, $depth, false);
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                    continue;
                }

                $this->cleanAttributes($node, $name);
                $this->clean($node, $depth + 1, false);
                continue;
            }

            // Text stays; comments, CDATA, processing instructions and the rest go.
            if ($node->nodeType !== XML_TEXT_NODE) {
                $parent->removeChild($node);
            }
        }
    }

    private function cleanAttributes(DOMElement $el, string $tag): void
    {
        $allowed = array_merge(['style'], self::TAG_ATTRIBUTES[$tag] ?? []);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $attrName = strtolower($attr->nodeName);
            if (!in_array($attrName, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if ($attrName === 'style') {
                $style = $this->cleanStyle($attr->nodeValue ?? '');
                $style === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $style);
                continue;
            }

            $value = trim($attr->nodeValue ?? '');
            $ok = match ($attrName) {
                'align'            => in_array(strtolower($value), ['left', 'right', 'center', 'justify'], true),
                'colspan', 'rowspan', 'span' => ctype_digit($value) && (int) $value >= 1 && (int) $value <= 50,
                default            => false,
            };
            $ok ? $el->setAttribute($attr->nodeName, $value) : $el->removeAttribute($attr->nodeName);
        }
    }

    private function cleanStyle(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $declaration) {
            if (!str_contains($declaration, ':')) {
                continue;
            }
            [$prop, $value] = array_map('trim', explode(':', $declaration, 2));
            $prop = strtolower($prop);

            if (!in_array($prop, self::ALLOWED_STYLE_PROPERTIES, true)
                || $value === ''
                || !preg_match(self::SAFE_STYLE_VALUE, $value)
                || preg_match(self::FORBIDDEN_STYLE_WORDS, $value)
                || str_contains($value, '{{')) {
                continue;
            }
            $kept[] = $prop . ': ' . $value;
        }

        return implode('; ', $kept);
    }
}
