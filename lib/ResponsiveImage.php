<?php
/**
 * @package redaxo\media-manager-extras
 */

namespace KLXM\MediaManagerExtras;

use DOMDocument;
use DOMElement;
use rex;
use rex_file;
use rex_media_manager;
use rex_sql;

class ResponsiveImage
{
    private const NON_PIXEL_FORMATS = ['svg', 'pdf', 'eps'];
    private const SRCSET_PLACEHOLDER_PREFIX = 'rex_media_type=';

    public static function isNonPixelFormat(string $file): bool
    {
        $extension = mb_strtolower(rex_file::extension($file));
        return in_array($extension, self::NON_PIXEL_FORMATS, true);
    }

    /**
     * @return array<int, string>
     */
    public static function parseSrcsetString(string $srcsetString): array
    {
        $srcset = [];
        $items = array_map('trim', explode(',', $srcsetString));

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $item);
            if (!is_array($parts) || count($parts) < 2) {
                continue;
            }

            $width = (int) $parts[0];
            if ($width <= 0) {
                continue;
            }

            $descriptor = implode(' ', array_slice($parts, 1));
            if ($descriptor === '') {
                continue;
            }

            $srcset[$width] = $descriptor;
        }

        ksort($srcset);

        return $srcset;
    }

    /**
     * @return array<int, string>
     */
    public static function getSrcsetConfig(string $type): array
    {
        $sql = rex_sql::factory();
        $sql->setQuery(
            'SELECT e.parameters
             FROM ' . rex::getTable('media_manager_type') . ' t
             JOIN ' . rex::getTable('media_manager_type_effect') . ' e ON t.id = e.type_id
             WHERE t.name = :type AND e.effect = "srcset_helper"
             ORDER BY e.priority
             LIMIT 1',
            ['type' => $type],
        );

        if ($sql->getRows() === 0) {
            return [];
        }

        $parameters = $sql->getArrayValue('parameters');
        if (!isset($parameters['rex_effect_srcset_helper']['srcset'])) {
            return [];
        }

        return self::parseSrcsetString((string) $parameters['rex_effect_srcset_helper']['srcset']);
    }

    public static function getSrcsetString(string $type, string $file): string
    {
        $srcsetConfig = self::getSrcsetConfig($type);
        if (count($srcsetConfig) === 0) {
            return '';
        }

        $entries = [];
        foreach ($srcsetConfig as $width => $descriptor) {
            $entries[] = rex_media_manager::getUrl($type . '__' . $width, $file) . ' ' . $descriptor;
        }

        return implode(', ', $entries);
    }

    public static function replaceMediaTags(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;

        $previousState = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        if (!$loaded) {
            return $html;
        }

        self::replaceImgPlaceholders($dom);
        self::replaceSourcePlaceholders($dom);

        $result = $dom->saveHTML();
        if (!is_string($result)) {
            return $html;
        }

        return preg_replace('/^<\?xml[^>]+>/', '', $result) ?? $html;
    }

    public static function getImageByType(string $file, string $type, array $attributes = []): string
    {
        $mediaUrl = rex_media_manager::getUrl($type, $file);

        if (self::isNonPixelFormat($file)) {
            return '<img src="' . rex_escape($mediaUrl) . '"' . self::renderAttributes($attributes) . ' />';
        }

        return '<img src="' . rex_escape($mediaUrl) . '" srcset="' . self::SRCSET_PLACEHOLDER_PREFIX . rex_escape($type) . '"' . self::renderAttributes($attributes) . ' />';
    }

    public static function getImgTag(string $file, string $type, array $attributes = []): string
    {
        return self::getImageByType($file, $type, $attributes);
    }

    public static function getPictureTag(string $file, string $defaultType, array $sources = [], array $imgAttributes = []): string
    {
        if (self::isNonPixelFormat($file)) {
            return self::getImageByType($file, $defaultType, $imgAttributes);
        }

        $mediaUrl = rex_media_manager::getUrl($defaultType, $file);
        $html = '<picture>';

        foreach ($sources as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $html .= '<source media="' . rex_escape($key) . '" srcset="' . self::SRCSET_PLACEHOLDER_PREFIX . rex_escape($value) . '" data-file="' . rex_escape($file) . '">';
                continue;
            }

            if (!is_array($value) || !isset($value['media'], $value['type'])) {
                continue;
            }

            $sourceFile = isset($value['file']) ? (string) $value['file'] : $file;
            $sizes = isset($value['sizes']) ? (string) $value['sizes'] : '';

            $html .= '<source media="' . rex_escape((string) $value['media']) . '" srcset="' . self::SRCSET_PLACEHOLDER_PREFIX . rex_escape((string) $value['type']) . '" data-file="' . rex_escape($sourceFile) . '"';
            if ($sizes !== '') {
                $html .= ' sizes="' . rex_escape($sizes) . '"';
            }
            $html .= '>';
        }

        $html .= '<source srcset="' . self::SRCSET_PLACEHOLDER_PREFIX . rex_escape($defaultType) . '" data-file="' . rex_escape($file) . '">';
        $html .= '<img src="' . rex_escape($mediaUrl) . '"' . self::renderAttributes($imgAttributes) . '>';
        $html .= '</picture>';

        return $html;
    }

    private static function replaceImgPlaceholders(DOMDocument $dom): void
    {
        $images = $dom->getElementsByTagName('img');
        for ($i = 0; $i < $images->length; ++$i) {
            $image = $images->item($i);
            if (!$image instanceof DOMElement) {
                continue;
            }

            $srcset = trim($image->getAttribute('srcset'));
            if (!str_starts_with($srcset, self::SRCSET_PLACEHOLDER_PREFIX)) {
                continue;
            }

            $type = trim(substr($srcset, strlen(self::SRCSET_PLACEHOLDER_PREFIX)));
            if ($type === '') {
                continue;
            }

            $file = self::extractFilenameFromUrl($image->getAttribute('src'));
            if ($file === null || self::isNonPixelFormat($file)) {
                continue;
            }

            $resolvedSrcset = self::getSrcsetString($type, $file);
            if ($resolvedSrcset === '') {
                continue;
            }

            $image->setAttribute('srcset', $resolvedSrcset);
        }
    }

    private static function replaceSourcePlaceholders(DOMDocument $dom): void
    {
        $sources = $dom->getElementsByTagName('source');
        for ($i = 0; $i < $sources->length; ++$i) {
            $source = $sources->item($i);
            if (!$source instanceof DOMElement) {
                continue;
            }

            $srcset = trim($source->getAttribute('srcset'));
            if (!str_starts_with($srcset, self::SRCSET_PLACEHOLDER_PREFIX)) {
                continue;
            }

            $type = trim(substr($srcset, strlen(self::SRCSET_PLACEHOLDER_PREFIX)));
            if ($type === '') {
                continue;
            }

            $file = trim($source->getAttribute('data-file'));
            if ($file === '') {
                $file = self::extractFilenameFromPicture($source) ?? '';
            }

            if ($file === '' || self::isNonPixelFormat($file)) {
                continue;
            }

            $resolvedSrcset = self::getSrcsetString($type, $file);
            if ($resolvedSrcset === '') {
                continue;
            }

            $source->setAttribute('srcset', $resolvedSrcset);
        }
    }

    private static function extractFilenameFromPicture(DOMElement $source): ?string
    {
        $picture = $source->parentNode;
        if (!$picture instanceof DOMElement || $picture->tagName !== 'picture') {
            return null;
        }

        $images = $picture->getElementsByTagName('img');
        if ($images->length === 0) {
            return null;
        }

        $image = $images->item(0);
        if (!$image instanceof DOMElement) {
            return null;
        }

        return self::extractFilenameFromUrl($image->getAttribute('src'));
    }

    private static function extractFilenameFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);

        $queryString = parse_url($url, PHP_URL_QUERY);
        if (!is_string($queryString) || $queryString === '') {
            return null;
        }

        parse_str($queryString, $query);
        if (!is_array($query) || !isset($query['rex_media_file'])) {
            return null;
        }

        $file = (string) $query['rex_media_file'];
        return $file !== '' ? $file : null;
    }

    /**
     * @param array<string, string> $attributes
     */
    private static function renderAttributes(array $attributes): string
    {
        $rendered = '';
        foreach ($attributes as $name => $value) {
            if ($name === '') {
                continue;
            }

            $rendered .= ' ' . rex_escape($name) . '="' . rex_escape($value) . '"';
        }

        return $rendered;
    }
}
