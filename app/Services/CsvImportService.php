<?php

namespace App\Services;

use App\Models\Asset;

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

        return $errors;
    }
}
