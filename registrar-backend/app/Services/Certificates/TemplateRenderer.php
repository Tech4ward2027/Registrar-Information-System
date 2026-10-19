<?php

namespace App\Services\Certificates;

use App\Exceptions\InvalidCertificateTemplateException;
use App\Exceptions\MissingMergeFieldsException;
use App\Services\Certificates\MergeTags\MergeTagRegistry;
use DOMDocument;
use DOMText;
use DOMXPath;

/**
 * Validates and renders certificate template bodies.
 *
 * Rendering is plain, whitelisted substitution — never Blade/PHP/eval:
 *   1. the body is re-sanitized (defence in depth),
 *   2. each merge tag found in a TEXT node is replaced with its value, set as
 *      the node's text, so DOM serialization escapes it. A value such as
 *      "<script>" can therefore never become markup, and a tag in an
 *      attribute is impossible because attributes were already stripped.
 *
 * Tag syntax:  {{ name }}  or  {{ name|filter }}  (filters: upper, lower, title)
 */
class TemplateRenderer
{
    private const TAG_PATTERN = '/\{\{\s*([a-z][a-z0-9_]*)\s*(?:\|\s*([a-z]+)\s*)?\}\}/';

    public function __construct(
        private readonly MergeTagRegistry $registry,
        private readonly TemplateSanitizer $sanitizer,
    ) {}

    /**
     * Check a (sanitized) body at save time.
     *
     * @return array{tags: list<string>, errors: list<string>}
     */
    public function analyze(string $sanitizedHtml): array
    {
        $tags = [];
        $errors = [];

        foreach ($this->textNodes($this->load($sanitizedHtml)) as $node) {
            $text = $node->nodeValue ?? '';
            if (!str_contains($text, '{{') && !str_contains($text, '}}')) {
                continue;
            }

            preg_match_all(self::TAG_PATTERN, $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                $name = $m[1];
                $filter = $m[2] ?? null;
                if (!$this->registry->has($name)) {
                    $errors[] = "Unknown merge tag: {{ {$name} }}.";
                    continue;
                }
                if ($filter !== null && !in_array($filter, MergeTagRegistry::FILTERS, true)) {
                    $errors[] = "Unknown format \"{$filter}\" on {{ {$name} }}. Allowed: " . implode(', ', MergeTagRegistry::FILTERS) . '.';
                    continue;
                }
                $tags[$name] = true;
            }

            // Anything still looking like a tag after removing the valid ones is malformed.
            $leftover = preg_replace(self::TAG_PATTERN, '', $text) ?? '';
            if (str_contains($leftover, '{{') || str_contains($leftover, '}}')) {
                $errors[] = 'Malformed merge tag near: "' . mb_substr(trim($leftover), 0, 60) . '". '
                    . 'Use {{ tag_name }} with no HTML or spaces inside the name.';
            }
        }

        return ['tags' => array_keys($tags), 'errors' => array_values(array_unique($errors))];
    }

    /**
     * @param list<string>         $requiredFields
     * @param array<string, mixed> $data
     * @return list<string> required fields that have no value
     */
    public function missingRequired(array $requiredFields, array $data): array
    {
        return array_values(array_filter(
            $requiredFields,
            fn (string $name) => $this->registry->value($name, $data) === null
        ));
    }

    /**
     * @param list<string>         $requiredFields
     * @param array<string, mixed> $data
     *
     * @throws MissingMergeFieldsException
     * @throws InvalidCertificateTemplateException
     */
    public function render(string $html, array $data, array $requiredFields = []): string
    {
        $missing = $this->missingRequired($requiredFields, $data);
        if ($missing !== []) {
            throw new MissingMergeFieldsException($missing);
        }

        $dom = $this->load($this->sanitizer->sanitize($html));
        $placeholder = (string) config('certificate_templates.blank_placeholder', '__________');

        foreach ($this->textNodes($dom) as $node) {
            $text = $node->nodeValue ?? '';
            if (!str_contains($text, '{{')) {
                continue;
            }

            $node->nodeValue = preg_replace_callback(
                self::TAG_PATTERN,
                function (array $m) use ($data, $placeholder) {
                    $name = $m[1];
                    if (!$this->registry->has($name)) {
                        throw new InvalidCertificateTemplateException(['content' => ["Unknown merge tag: {{ {$name} }}."]]);
                    }
                    $value = $this->registry->value($name, $data);

                    return $value === null ? $placeholder : $this->registry->applyFilter($value, $m[2] ?? null);
                },
                $text
            );

            // Fail closed if anything tag-like survived.
            if (str_contains($node->nodeValue ?? '', '{{')) {
                throw new InvalidCertificateTemplateException(['content' => ['The template contains a malformed merge tag.']]);
            }
        }

        $out = '';
        $root = $dom->getElementById('ct-root');
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private function load(string $sanitizedHtml): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div id="ct-root">' . $sanitizedHtml . '</div></body></html>',
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    /** @return list<DOMText> */
    private function textNodes(DOMDocument $dom): array
    {
        $nodes = [];
        foreach ((new DOMXPath($dom))->query('//*[@id="ct-root"]//text()') as $node) {
            if ($node instanceof DOMText) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
