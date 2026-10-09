<?php

namespace App\Support\Portal;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans an uploaded SVG logo (docs/spec/12, "Settings → Branding"): keeps
 * drawing elements and presentation attributes from an allowlist and drops
 * everything else — scripts, event handlers, links, embedded HTML, external
 * references, editor metadata. No DOCTYPE or entities are accepted (no XXE),
 * and nothing is fetched while parsing.
 *
 * Defence in depth: the logo is shown through <img> (where SVG never runs
 * scripts) and served with a sandboxing Content Security Policy.
 */
final class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern',
        'filter', 'feGaussianBlur', 'feOffset', 'feBlend', 'feColorMatrix', 'feComposite',
        'feFlood', 'feMerge', 'feMergeNode', 'feDropShadow',
    ];

    private const ATTRIBUTES = [
        'id', 'class', 'style', 'transform', 'version', 'viewBox', 'preserveAspectRatio', 'width', 'height',
        'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'd', 'points', 'dx', 'dy',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
        'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity',
        'clip-path', 'clip-rule', 'mask', 'filter', 'display', 'visibility', 'overflow', 'color',
        'vector-effect', 'paint-order', 'enable-background', 'isolation', 'mix-blend-mode',
        'font-family', 'font-size', 'font-weight', 'font-style', 'font-variant', 'text-anchor',
        'dominant-baseline', 'alignment-baseline', 'baseline-shift', 'letter-spacing', 'word-spacing',
        'text-decoration', 'textLength', 'lengthAdjust', 'rotate', 'startOffset',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'patternUnits', 'patternContentUnits', 'patternTransform', 'maskUnits', 'maskContentUnits',
        'clipPathUnits', 'filterUnits', 'primitiveUnits', 'stdDeviation', 'in', 'in2', 'result', 'mode',
        'values', 'type', 'operator', 'k1', 'k2', 'k3', 'k4', 'flood-color', 'flood-opacity', 'href',
    ];

    public static function clean(string $svg): ?string
    {
        // A DOCTYPE is where entities (and external files) would come from.
        if (preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NS) {
            return null;
        }

        self::cleanElement($root);

        return $document->saveXML($root) ?: null;
    }

    private static function cleanElement(DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            if (! self::keepsAttribute($attribute)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            /** @var DOMNode $child */
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI === self::SVG_NS && in_array($child->localName, self::ELEMENTS, true)) {
                    self::cleanElement($child);

                    if ($child->localName === 'style' && ! self::safeCss($child->textContent)) {
                        $element->removeChild($child);
                    }
                } else {
                    $element->removeChild($child);
                }
            } elseif (! in_array($child->nodeType, [XML_TEXT_NODE, XML_CDATA_SECTION_NODE], true)) {
                // Comments, processing instructions and the like.
                $element->removeChild($child);
            }
        }
    }

    private static function keepsAttribute(DOMAttr $attribute): bool
    {
        $name = $attribute->localName;
        $value = trim($attribute->value);

        if ($attribute->namespaceURI === self::XML_NS) {
            return $name === 'space';
        }

        if ($attribute->namespaceURI === self::XLINK_NS || $name === 'href') {
            // Only references inside the drawing itself (a gradient, a symbol).
            return in_array($attribute->namespaceURI, [null, self::XLINK_NS], true)
                && $name === 'href'
                && str_starts_with($value, '#');
        }

        if ($attribute->namespaceURI !== null || ! in_array($name, self::ATTRIBUTES, true)) {
            return false;
        }

        return ! in_array($name, ['style', 'fill', 'stroke', 'filter', 'mask', 'clip-path'], true) || self::safeCss($value);
    }

    /** No external references, imports or script-like values in CSS. */
    private static function safeCss(string $css): bool
    {
        $lower = strtolower($css);

        if (str_contains($lower, '@import') || str_contains($lower, 'expression') || str_contains($lower, 'javascript:')
            || str_contains($lower, '<') || str_contains($lower, '\\')) {
            return false;
        }

        preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $css, $matches);

        foreach ($matches[2] as $target) {
            if (! str_starts_with(trim($target), '#')) {
                return false;
            }
        }

        return ! preg_match('/url\(/i', preg_replace('/url\(\s*([\'"]?)#.*?\1\s*\)/i', '', $css) ?? '');
    }
}
