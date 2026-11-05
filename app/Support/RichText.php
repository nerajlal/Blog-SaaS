<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

final class RichText
{
    /** Keep supported editor markup while discarding executable HTML and attributes. */
    public static function clean(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET);
            $body = $document->getElementsByTagName('body')->item(0);
            return $body ? self::children($body) : '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function children(DOMNode $parent): string
    {
        $result = '';
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $result .= htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'form'], true)) {
                continue;
            }
            if (!in_array($tag, ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'pre', 'code', 'ol', 'ul', 'li', 'a', 'span', 'sub', 'sup'], true)) {
                $result .= self::children($node);
                continue;
            }
            $attributes = '';
            if ($tag === 'a') {
                $href = trim($node->getAttribute('href'));
                if (preg_match('~^(https?://|mailto:|/(?!/)|\#)~i', $href) && !preg_match('/[\x00-\x20\x7f]/', $href)) {
                    $attributes = ' href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" rel="noopener noreferrer"';
                }
            }
            $classes = array_intersect(explode(' ', $node->getAttribute('class')), [
                'ql-align-center', 'ql-align-right', 'ql-align-justify', 'ql-indent-1', 'ql-indent-2', 'ql-indent-3', 'ql-syntax',
            ]);
            if ($classes) {
                $attributes .= ' class="'.implode(' ', $classes).'"';
            }
            if ($tag === 'li' && in_array($node->getAttribute('data-list'), ['ordered', 'bullet'], true)) {
                $attributes .= ' data-list="'.$node->getAttribute('data-list').'"';
            }
            $result .= '<'.$tag.$attributes.'>'.($tag === 'br' ? '' : self::children($node).'</'.$tag.'>');
        }
        return $result;
    }
}
