<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Tag;
use App\Services\CsvImportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected CsvImportService $csvImportService) {}

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

        $rows = $this->csvImportService->parseCsv($request->input('csv_data'));

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
            $changes = $this->csvImportService->calculateChanges($asset, $row);

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
                'errors' => $this->csvImportService->validateRow($row),
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

        $rows = $this->csvImportService->parseCsv($request->input('csv_data'));
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

            $rowErrors = $this->csvImportService->validateRow($row);
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

            foreach (CsvImportService::UPDATABLE_FIELDS as $field) {
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
                $tagNames = array_filter($tagNames, fn ($name) => strlen($name) <= 100);

                if (! empty($tagNames)) {
                    $userTagIds = Tag::resolveUserTagIds($tagNames);
                    $asset->syncTagsWithAttribution($userTagIds, 'user');
                }
            }

            // Handle reference_tags
            $refTagIds = [];
            if (isset($row['reference_tags']) && trim($row['reference_tags']) !== '') {
                $refTagNames = array_filter(array_map('trim', explode(',', $row['reference_tags'])));
                $refTagNames = array_filter($refTagNames, fn ($name) => strlen($name) <= 100);

                if (! empty($refTagNames)) {
                    $refTagIds = Tag::resolveReferenceTagIds($refTagNames);
                    $asset->syncTagsWithAttribution($refTagIds, 'reference');
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
}
