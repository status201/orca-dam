<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\Tag;
use App\Support\ColumnLimits;

class CsvImportService
{
    public const ALLOWED_LICENSE_TYPES = [
        'public_domain',
        'cc_by',
        'cc_by_sa',
        'cc_by_nd',
        'cc_by_nc',
        'cc_by_nc_sa',
        'cc_by_nc_nd',
        'fair_use',
        'all_rights_reserved',
    ];

    public const UPDATABLE_FIELDS = [
        'filename',
        'alt_text',
        'caption',
        'license_type',
        'license_expiry_date',
        'copyright',
        'copyright_source',
    ];

    public function parseCsv(string $csvData): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csvData));

        if (count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);

        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = $values[$i] ?? '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function calculateChanges(Asset $asset, array $row): array
    {
        $changes = [];

        foreach (self::UPDATABLE_FIELDS as $field) {
            if (isset($row[$field]) && trim($row[$field]) !== '') {
                $newValue = trim($row[$field]);
                $currentValue = (string) ($asset->$field ?? '');

                if ($field === 'license_expiry_date' && $asset->license_expiry_date) {
                    $currentValue = $asset->license_expiry_date->format('Y-m-d');
                }

                if ($newValue !== $currentValue) {
                    $changes[$field] = [
                        'from' => $currentValue,
                        'to' => $newValue,
                    ];
                }
            }
        }

        if (isset($row['user_tags']) && trim($row['user_tags']) !== '') {
            $changes['user_tags'] = [
                'add' => trim($row['user_tags']),
            ];
        }

        if (isset($row['reference_tags']) && trim($row['reference_tags']) !== '') {
            $changes['reference_tags'] = [
                'add' => trim($row['reference_tags']),
            ];
        }

        return $changes;
    }

    public function validateRow(array $row): array
    {
        $errors = [];

        if (isset($row['license_type']) && trim($row['license_type']) !== '') {
            if (! in_array(trim($row['license_type']), self::ALLOWED_LICENSE_TYPES)) {
                $errors[] = __('Invalid license type: ":value"', ['value' => trim($row['license_type'])]);
            }
        }

        if (isset($row['license_expiry_date']) && trim($row['license_expiry_date']) !== '') {
            $date = trim($row['license_expiry_date']);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
                $errors[] = __('Invalid date format: ":value". Use YYYY-MM-DD.', ['value' => $date]);
            }
        }

        // CSV is the one write path with no FormRequest behind it, so nothing else caps these
        // cells. Without this check an over-long copyright reached the driver and turned the
        // whole import into a 500 that named no row. alt_text/caption are TEXT columns and are
        // absent from ColumnLimits::CHARS, so they are skipped rather than bounded here.
        foreach (self::UPDATABLE_FIELDS as $field) {
            if (! isset($row[$field]) || trim($row[$field]) === '') {
                continue;
            }

            if (! isset(ColumnLimits::CHARS['assets'][$field])) {
                continue;
            }

            $limit = ColumnLimits::for('assets', $field);
            // mb_strlen, not strlen: MySQL counts characters, and "©" in a copyright line
            // costs two bytes — byte-counting would reject values that fit.
            $length = mb_strlen(trim($row[$field]));

            if ($length > $limit) {
                $errors[] = __('The :field value is too long (:length characters, maximum :max).', [
                    'field' => $field,
                    'length' => $length,
                    'max' => $limit,
                ]);
            }
        }

        // Tag cells are the one place an over-length value did not error — TagInputParser::parse()
        // silently *drops* a name longer than Tag::MAX_NAME_LENGTH, so the row reported as updated
        // and the tag simply never appeared. Report it instead.
        foreach (['user_tags', 'reference_tags'] as $field) {
            if (! isset($row[$field]) || trim($row[$field]) === '') {
                continue;
            }

            foreach (explode(',', $row[$field]) as $name) {
                $name = trim($name);

                if ($name !== '' && mb_strlen($name) > Tag::MAX_NAME_LENGTH) {
                    $errors[] = __('The tag ":value" is too long (:length characters, maximum :max).', [
                        'value' => mb_substr($name, 0, 30).'…',
                        'length' => mb_strlen($name),
                        'max' => Tag::MAX_NAME_LENGTH,
                    ]);
                }
            }
        }

        return $errors;
    }
}
