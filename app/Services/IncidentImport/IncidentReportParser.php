<?php

namespace App\Services\IncidentImport;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads an incident report written in Markdown, in this shape:
 *
 *   2026-07-27-some-slug.md
 *   # Incident 2026-07-27 — what happened
 *   **Impact:** who noticed what, for how long.
 */
final class IncidentReportParser
{
    private const string DATE = '/\b(\d{4}-\d{2}-\d{2})\b/';

    /**
     * @throws InvalidArgumentException when the report carries no date
     */
    public function parse(string $filename, string $markdown): ParsedIncident
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $heading = $this->heading($markdown);

        $occurredOn = $this->dateFromFilename($filename) ?? $this->dateFrom($heading ?? '');

        if ($occurredOn === null) {
            throw new InvalidArgumentException('no date in the file name (YYYY-MM-DD-…) or in the heading');
        }

        return new ParsedIncident(
            title: $this->title($heading, $filename),
            occurredOn: $occurredOn,
            impact: $this->impact($markdown),
            body: $markdown,
        );
    }

    private function heading(string $markdown): ?string
    {
        return preg_match('/^# (.+)$/m', $markdown, $matches) === 1 ? trim($matches[1]) : null;
    }

    private function dateFromFilename(string $filename): ?string
    {
        return preg_match('/^(\d{4}-\d{2}-\d{2})-/', basename($filename), $matches) === 1
            ? $this->validDate($matches[1])
            : null;
    }

    private function dateFrom(string $text): ?string
    {
        return preg_match(self::DATE, $text, $matches) === 1 ? $this->validDate($matches[1]) : null;
    }

    private function validDate(string $date): ?string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year) ? $date : null;
    }

    /**
     * The heading without its "Incident 2026-07-27 — " prefix; the file name
     * when there is no heading.
     */
    private function title(?string $heading, string $filename): string
    {
        if ($heading !== null) {
            $title = preg_replace('/^Incident\s+\d{4}-\d{2}-\d{2}\s*[—–-]\s*/iu', '', $heading);

            return Str::limit(trim($title), 250, '…');
        }

        $slug = preg_replace('/^\d{4}-\d{2}-\d{2}-/', '', pathinfo($filename, PATHINFO_FILENAME));

        return Str::limit(Str::ucfirst(str_replace(['-', '_'], ' ', $slug)), 250, '…');
    }

    /**
     * The paragraph that starts with **Impact:**, as one line.
     */
    private function impact(string $markdown): ?string
    {
        if (preg_match('/^\*\*Impact:\*\*\s*(.+?)(?:\n\s*\n|\z)/ms', $markdown, $matches) !== 1) {
            return null;
        }

        return trim((string) preg_replace('/\s*\n\s*/', ' ', $matches[1]));
    }
}
