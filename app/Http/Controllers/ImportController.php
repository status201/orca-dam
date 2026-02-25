<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    use AuthorizesRequests;

    private const ALLOWED_LICENSE_TYPES = [
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

    private const UPDATABLE_FIELDS = [
        'filename',
        'alt_text',
        'caption',
        'license_type',
        'license_expiry_date',
        'copyright',
        'copyright_source',
    ];

    public function index()
    {
        $this->authorize('access', SystemController::class);

        return view('import.index');
    }

    public function preview(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $request->validate([
            'csv_data' => 'required|string',
            'match_field' => 'required|in:s3_key,filename',
        ]);

        $rows = $this->parseCsv($request->input('csv_data'));

        if (empty($rows)) {
            return response()->json(['error' => __('No valid CSV data found.')], 422);
        }

        $headers = array_keys($rows[0]);
        $matchField = $request->input('match_field');

        if (! in_array($matchField, $headers)) {
            return response()->json([
                'error' => __('The CSV must contain a ":field" column for matching.', ['field' => $matchField]),
            ], 422);
        }

        $results = [];
        $matched = 0;
        $unmatched = 0;
        $skipped = 0;

        foreach ($rows as $index => $row) {
            $matchValue = trim($row[$matchField] ?? '');

            if ($matchValue === '') {
                $skipped++;

                continue;
            }

            $asset = Asset::where($matchField, $matchValue)->first();

            if (! $asset) {
                $unmatched++;
                $results[] = [
                    'row' => $index + 2,
                    'match_value' => $matchValue,
                    'status' => 'not_found',
                ];

                continue;
            }

            $matched++;
            $changes = $this->calculateChanges($asset, $row);

            $results[] = [
                'row' => $index + 2,
                'match_value' => $matchValue,
                'status' => 'matched',
                'asset' => [
                    'id' => $asset->id,
                    'filename' => $asset->filename,
                    'thumbnail_url' => $asset->thumbnail_url,
                    's3_key' => $asset->s3_key,
                ],
                'changes' => $changes,
                'errors' => $this->validateRow($row),
            ];
        }

        return response()->json([
            'matched' => $matched,
            'unmatched' => $unmatched,
            'skipped' => $skipped,
            'total' => count($rows),
            'results' => $results,
        ]);
    }

    public function import(Request $request)
    {
        $this->authorize('access', SystemController::class);

        $request->validate([
            'csv_data' => 'required|string',
            'match_field' => 'required|in:s3_key,filename',
        ]);

        $rows = $this->parseCsv($request->input('csv_data'));
        $matchField = $request->input('match_field');

        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $matchValue = trim($row[$matchField] ?? '');

            if ($matchValue === '') {
                $skipped++;

                continue;
            }

            $asset = Asset::where($matchField, $matchValue)->first();

            if (! $asset) {
                $skipped++;

                continue;
            }

            $rowErrors = $this->validateRow($row);
            if (! empty($rowErrors)) {
                $errors[] = [
                    'row' => $index + 2,
                    'match_value' => $matchValue,
                    'errors' => $rowErrors,
                ];
                $skipped++;

                continue;
            }

            $updateData = [];

            foreach (self::UPDATABLE_FIELDS as $field) {
                if (isset($row[$field]) && trim($row[$field]) !== '') {
                    $updateData[$field] = trim($row[$field]);
                }
            }

            if (! empty($updateData)) {
                $updateData['last_modified_by'] = $request->user()->id;
                $asset->update($updateData);
            }

            // Handle user_tags
            $userTagIds = [];
            if (isset($row['user_tags']) && trim($row['user_tags']) !== '') {
                $tagNames = array_filter(array_map('trim', explode(',', $row['user_tags'])));

                if (! empty($tagNames)) {
                    $userTagIds = Tag::resolveUserTagIds($tagNames);
                    $asset->tags()->syncWithoutDetaching($userTagIds);
                }
            }

            // Handle reference_tags
            $refTagIds = [];
            if (isset($row['reference_tags']) && trim($row['reference_tags']) !== '') {
                $refTagNames = array_filter(array_map('trim', explode(',', $row['reference_tags'])));

                if (! empty($refTagNames)) {
                    $refTagIds = Tag::resolveReferenceTagIds($refTagNames);
                    $asset->tags()->syncWithoutDetaching($refTagIds);
                }
            }

            if (! empty($updateData) || ! empty($userTagIds) || ! empty($refTagIds)) {
                $updated++;
            } else {
                $skipped++;
            }
        }

        return response()->json([
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    private function parseCsv(string $csvData): array
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

    private function calculateChanges(Asset $asset, array $row): array
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

    private function validateRow(array $row): array
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
