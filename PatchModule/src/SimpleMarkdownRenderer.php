<?php

declare(strict_types=1);

/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * SimpleMarkdownRenderer - Converts a Keep-a-Changelog Markdown slice to safe HTML.
 * Supports the subset emitted by PatchCreator: headings, unordered lists, pipe tables,
 * bold, italic, inline code, links, and horizontal rules.
 * Unsupported: fenced code blocks, ordered lists, nested lists, blockquotes, images,
 * autolinks, raw HTML pass-through.
 */

namespace PatchModule;

/**
 * Converts a Keep-a-Changelog Markdown slice to sanitised HTML.
 *
 * All input is HTML-escaped before any transformation so injected markup is inert.
 * Only the tags this class emits can appear in the output.
 *
 * @package PatchModule
 */
class SimpleMarkdownRenderer
{
    /** Placeholder token wrapping inline-code spans during inline processing */
    private const CODE_PLACEHOLDER_PREFIX = "\x00CODE\x00";
    private const CODE_PLACEHOLDER_SUFFIX = "\x00ENDCODE\x00";

    /**
     * Render a Markdown string to HTML.
     *
     * Returns null when the input is null or whitespace-only. The output is
     * safe for injection into an HTML page via innerHTML — all dynamic content
     * originates from the HTML-escaped source.
     *
     * @param string|null $markdown Raw Markdown text (typically from patch_history.release_notes)
     * @return string|null Rendered HTML fragment, or null when there is nothing to show
     */
    public static function render(?string $markdown): ?string
    {
        if ($markdown === null) {
            return null;
        }

        $text = trim($markdown);
        if ($text === '') {
            return null;
        }

        // Escape the ENTIRE input first so no raw HTML can survive into the output.
        // ENT_SUBSTITUTE replaces invalid UTF-8 sequences instead of returning empty string.
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines  = explode("\n", str_replace("\r\n", "\n", $escaped));
        $output = '';

        // Block-state machine
        $state       = 'none'; // none | paragraph | list | table
        $buffer      = [];     // accumulated lines for the current block
        $tableHeader = [];     // column headers for the current table

        $flushBlock = static function () use (&$state, &$buffer, &$tableHeader, &$output): void {
            if ($state === 'paragraph' && $buffer !== []) {
                $output .= '<p>' . self::inlinePass(implode(' ', $buffer)) . '</p>' . "\n";
            } elseif ($state === 'list' && $buffer !== []) {
                $output .= '<ul>' . "\n";
                foreach ($buffer as $item) {
                    $output .= '<li>' . self::inlinePass($item) . '</li>' . "\n";
                }
                $output .= '</ul>' . "\n";
            } elseif ($state === 'table' && ($tableHeader !== [] || $buffer !== [])) {
                $output .= '<table class="table table-sm table-bordered">' . "\n";
                if ($tableHeader !== []) {
                    $output .= '<thead><tr>';
                    foreach ($tableHeader as $cell) {
                        $output .= '<th>' . self::inlinePass(trim($cell)) . '</th>';
                    }
                    $output .= '</tr></thead>' . "\n";
                }
                if ($buffer !== []) {
                    $output .= '<tbody>' . "\n";
                    foreach ($buffer as $row) {
                        $cells = self::parseTableRow($row);
                        $output .= '<tr>';
                        foreach ($cells as $cell) {
                            $output .= '<td>' . self::inlinePass(trim($cell)) . '</td>';
                        }
                        $output .= '</tr>' . "\n";
                    }
                    $output .= '</tbody>' . "\n";
                }
                $output .= '</table>' . "\n";
            }
            $state       = 'none';
            $buffer      = [];
            $tableHeader = [];
        };

        $inTable     = false;
        $tableRowIdx = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Blank line — flush current block
            if ($trimmed === '') {
                $flushBlock();
                $inTable     = false;
                $tableRowIdx = 0;
                continue;
            }

            // Horizontal rule (exactly ---)
            if ($trimmed === '---') {
                $flushBlock();
                $inTable     = false;
                $tableRowIdx = 0;
                $output .= '<hr>' . "\n";
                continue;
            }

            // Headings (## / ### / ####)
            if (str_starts_with($trimmed, '#### ')) {
                $flushBlock();
                $inTable = false;
                $output .= '<h5>' . self::inlinePass(substr($trimmed, 5)) . '</h5>' . "\n";
                continue;
            }
            if (str_starts_with($trimmed, '### ')) {
                $flushBlock();
                $inTable = false;
                $output .= '<h4>' . self::inlinePass(substr($trimmed, 4)) . '</h4>' . "\n";
                continue;
            }
            if (str_starts_with($trimmed, '## ')) {
                $flushBlock();
                $inTable = false;
                $output .= '<h3>' . self::inlinePass(substr($trimmed, 3)) . '</h3>' . "\n";
                continue;
            }

            // Table row (starts with | and contains at least one more |)
            if ($trimmed[0] === '|' && strpos($trimmed, '|', 1) !== false) {
                // Separator row — |---|---| — skip, used only to detect header boundary
                if (preg_match('/^\|[\s\-:|]+\|[\s\-:||]*$/', $trimmed)) {
                    // The previous row was the header; it was stored in $buffer, move to $tableHeader
                    if ($state === 'table' && $buffer !== [] && $tableHeader === []) {
                        $tableHeader = self::parseTableRow(array_pop($buffer));
                        // Reset $tableRowIdx — body rows start fresh
                        $tableRowIdx = 0;
                    }
                    continue;
                }

                if ($state !== 'table') {
                    $flushBlock();
                    $state       = 'table';
                    $tableRowIdx = 0;
                }

                if ($tableHeader === []) {
                    // Still waiting for separator — treat as potential header
                    $buffer[] = $trimmed;
                } else {
                    $buffer[] = $trimmed;
                }
                $tableRowIdx++;
                $inTable = true;
                continue;
            }

            // If we were in a table and hit a non-table line, flush first
            if ($inTable) {
                $flushBlock();
                $inTable     = false;
                $tableRowIdx = 0;
            }

            // Unordered list item
            if (preg_match('/^[-*] (.+)/', $trimmed, $m)) {
                if ($state !== 'list') {
                    $flushBlock();
                    $state = 'list';
                }
                $buffer[] = $m[1];
                continue;
            }

            // Plain paragraph line
            if ($state !== 'paragraph') {
                $flushBlock();
                $state = 'paragraph';
            }
            $buffer[] = $trimmed;
        }

        // Flush any trailing open block
        $flushBlock();

        return trim($output) !== '' ? $output : null;
    }

    /**
     * Apply inline transformations to an already-HTML-escaped text fragment.
     *
     * Order: inline-code placeholders → links → bold → italic → reinsert code.
     *
     * @param string $text HTML-escaped text fragment
     * @return string Text with inline HTML applied
     */
    private static function inlinePass(string $text): string
    {
        // 1. Extract inline code spans into placeholders so inner content is not processed
        $codes = [];
        $text  = preg_replace_callback(
            '/`([^`]+)`/',
            static function (array $m) use (&$codes): string {
                $idx          = count($codes);
                $codes[$idx]  = '<code>' . $m[1] . '</code>';
                return self::CODE_PLACEHOLDER_PREFIX . $idx . self::CODE_PLACEHOLDER_SUFFIX;
            },
            $text
        ) ?? $text;

        // 2. Links: [text](url) — only http/https/mailto allowed
        //    The URL pattern allows balanced () inside the URL (e.g. Wikipedia-style links)
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^()]*(?:\([^()]*\)[^()]*)*)\)/',
            static function (array $m): string {
                $linkText = $m[1];
                $url      = $m[2];
                if (preg_match('/^(https?:\/\/|mailto:)/i', $url)) {
                    return '<a href="' . $url . '" rel="noopener" target="_blank">' . $linkText . '</a>';
                }
                // Unsafe URL — keep only the link text
                return $linkText;
            },
            $text
        ) ?? $text;

        // 3. Bold: **text**
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;

        // 4. Italic: *text* (after bold so ** is not partially consumed)
        $text = preg_replace('/\*([^*\n]+)\*/s', '<em>$1</em>', $text) ?? $text;

        // 5. Reinsert code placeholders
        $text = preg_replace_callback(
            '/' . preg_quote(self::CODE_PLACEHOLDER_PREFIX, '/') . '(\d+)' . preg_quote(self::CODE_PLACEHOLDER_SUFFIX, '/') . '/',
            static function (array $m) use ($codes): string {
                return $codes[(int) $m[1]] ?? '';
            },
            $text
        ) ?? $text;

        return $text;
    }

    /**
     * Split a Markdown table row string into cell strings.
     *
     * Strips the leading and trailing | delimiters before splitting.
     *
     * @param string $row A table row like "| Cell A | Cell B |"
     * @return string[] Array of cell strings
     */
    private static function parseTableRow(string $row): array
    {
        $row = trim($row, '| ');
        return explode('|', $row);
    }
}
