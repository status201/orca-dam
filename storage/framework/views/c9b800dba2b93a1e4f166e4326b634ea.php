<?php $__env->startSection('title', 'Export Assets'); ?>

<?php $__env->startSection('content'); ?>
<div x-data="exportAssets()">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Export Assets</h1>
        <p class="text-gray-600 mt-2">Export asset metadata to CSV with optional filters</p>
    </div>

    <!-- Export Form -->
    <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
        <form action="<?php echo e(route('export.download')); ?>" method="POST">
            <?php echo csrf_field(); ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- File Type Filter -->
                <div>
                    <label for="file_type" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-file mr-2"></i>File Type
                    </label>
                    <select id="file_type"
                            name="file_type"
                            x-model="fileType"
                            class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        <option value="">All File Types</option>
                        <?php $__currentLoopData = $fileTypes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($type); ?>"><?php echo e(ucfirst($type)); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Filter by file type (e.g., image, video, application)</p>
                </div>

                <!-- Tags Filter -->
                <div>
                    <label for="tags" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-tags mr-2"></i>Tags
                    </label>
                    <select id="tags"
                            name="tags[]"
                            x-model="selectedTags"
                            multiple
                            size="5"
                            class="w-full rounded-lg border-gray-300 focus:border-blue-500 focus:ring-blue-500">
                        <?php $__currentLoopData = $tags; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($tag->id); ?>">
                                <?php echo e($tag->name); ?>

                                <?php if($tag->type === 'ai'): ?>
                                    <i class="fas fa-robot text-xs"></i>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                    <p class="text-xs text-gray-500 mt-1">Hold Ctrl/Cmd to select multiple tags (leave empty for all)</p>
                </div>
            </div>

            <!-- Preview Summary -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <h3 class="text-sm font-semibold text-blue-900 mb-2">
                    <i class="fas fa-info-circle mr-2"></i>Export Information
                </h3>
                <div class="text-sm text-blue-800 space-y-1">
                    <p><strong>Export Format:</strong> CSV (Comma-Separated Values)</p>
                    <p><strong>Included Fields:</strong> id, s3_key, filename, mime_type, size, etag, dimensions, thumbnails, metadata, user info, tags, URLs, timestamps</p>
                    <p><strong>Tags Format:</strong> Comma-separated tag names (not IDs)</p>
                    <p x-show="fileType || selectedTags.length > 0" class="text-blue-900 font-semibold">
                        <i class="fas fa-filter mr-1"></i>
                        Filters active:
                        <span x-show="fileType" x-text="'File Type: ' + fileType"></span>
                        <span x-show="fileType && selectedTags.length > 0">, </span>
                        <span x-show="selectedTags.length > 0" x-text="selectedTags.length + ' tag(s) selected'"></span>
                    </p>
                    <p x-show="!fileType && selectedTags.length === 0" class="text-blue-900 font-semibold">
                        <i class="fas fa-globe mr-1"></i>All assets will be exported
                    </p>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-between">
                <button type="button"
                        @click="resetFilters"
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">
                    <i class="fas fa-undo mr-2"></i>Reset Filters
                </button>

                <button type="submit"
                        class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors flex items-center">
                    <i class="fas fa-download mr-2"></i>Download CSV Export
                </button>
            </div>
        </form>
    </div>

    <!-- Information Section -->
    <div class="bg-gray-50 rounded-lg border border-gray-200 p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">
            <i class="fas fa-question-circle mr-2"></i>About CSV Export
        </h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm text-gray-700">
            <div>
                <h4 class="font-semibold mb-2">What's included:</h4>
                <ul class="space-y-1 list-disc list-inside">
                    <li>Asset ID and database keys</li>
                    <li>File information (name, type, size)</li>
                    <li>S3 storage details (keys, ETag)</li>
                    <li>Image dimensions (if applicable)</li>
                    <li>Metadata (alt text, captions)</li>
                    <li>Uploader information</li>
                    <li>All associated tags (as text)</li>
                    <li>Public URLs for assets</li>
                    <li>Creation and update timestamps</li>
                </ul>
            </div>
            <div>
                <h4 class="font-semibold mb-2">Use cases:</h4>
                <ul class="space-y-1 list-disc list-inside">
                    <li>Backup asset metadata</li>
                    <li>Audit and reporting</li>
                    <li>Import into other systems</li>
                    <li>Data analysis and visualization</li>
                    <li>Integration with spreadsheet tools</li>
                </ul>
                <div class="mt-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-xs text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-1"></i>
                        <strong>Note:</strong> CSV exports contain metadata only. Actual files remain in S3 storage.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportAssets() {
    return {
        fileType: '',
        selectedTags: [],

        resetFilters() {
            this.fileType = '';
            this.selectedTags = [];
        }
    }
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\gijso\Herd\orca-dam\resources\views/export/index.blade.php ENDPATH**/ ?>