<?php $__env->startSection('title', 'Assets'); ?>

<?php $__env->startSection('content'); ?>
<div x-data="assetGrid()">
    <!-- Header with search and filters -->
    <div class="mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Assets</h1>
                <p class="text-gray-600 mt-2">Browse and manage your digital assets</p>
            </div>
            
            <div class="flex flex-col sm:flex-row gap-3">
                <!-- Search -->
                <div class="relative">
                    <input type="text"
                           x-model="search"
                           @keyup.enter="applyFilters"
                           placeholder="Search assets..."
                           class="w-full sm:w-64 pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </div>

                <!-- Sort -->
                <select x-model="sort"
                        @change="applyFilters"
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="date_desc">Newest First</option>
                    <option value="date_asc">Oldest First</option>
                    <option value="size_desc">Largest First</option>
                    <option value="size_asc">Smallest First</option>
                    <option value="name_asc">Name A-Z</option>
                    <option value="name_desc">Name Z-A</option>
                </select>

                <!-- Type filter -->
                <select x-model="type"
                        @change="applyFilters"
                        class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">All Types</option>
                    <option value="image">Images</option>
                    <option value="video">Videos</option>
                    <option value="application">Documents</option>
                </select>

                <!-- Tag filter -->
                <button @click="showTagFilter = !showTagFilter"
                        class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 flex items-center justify-center">
                    <i class="fas fa-filter mr-2"></i>
                    <span x-text="selectedTags.length > 0 ? `Tags (${selectedTags.length})` : 'Filter Tags'"></span>
                </button>

                <!-- Upload button -->
                <a href="<?php echo e(route('assets.create')); ?>"
                   class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 flex items-center justify-center whitespace-nowrap">
                    <i class="fas fa-upload mr-2"></i> Upload
                </a>
            </div>
        </div>
        
        <!-- Tag filter dropdown -->
        <div x-show="showTagFilter"
             x-cloak
             @click.away="if (selectedTags.length === 0) showTagFilter = false"
             class="mt-4 bg-white border border-gray-200 rounded-lg shadow-lg p-4">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-semibold">Filter by Tags</h3>
                <div class="flex gap-2">
                    <button @click="applyFilters()"
                            x-show="tagsChanged()"
                            class="text-sm px-4 py-1 bg-blue-600 text-white hover:bg-blue-700 rounded-lg transition">
                        <i class="fas fa-check mr-1"></i> Apply
                    </button>
                    <button @click="selectedTags = []"
                            x-show="selectedTags.length > 0"
                            class="text-sm px-3 py-1 text-red-600 hover:bg-red-50 rounded-lg transition">
                        <i class="fas fa-times mr-1"></i> Clear All
                    </button>
                </div>
            </div>
            <div class="max-h-96 overflow-y-auto">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 2xl:grid-cols-8 gap-2">
                    <?php $__currentLoopData = $tags; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <label class="flex items-start space-x-2 p-2 hover:bg-gray-50 rounded cursor-pointer border border-gray-200">
                        <input type="checkbox"
                               value="<?php echo e($tag->id); ?>"
                               x-model="selectedTags"
                               class="rounded text-blue-600 focus:ring-blue-500 flex-shrink-0 mt-0.5">
                        <div class="flex flex-col gap-1 min-w-0 flex-1">
                            <span class="text-sm font-medium truncate"><?php echo e($tag->name); ?></span>
                            <span class="text-xs px-2 py-0.5 rounded-full <?php echo e($tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'); ?> inline-block w-fit">
                                <?php echo e($tag->type); ?>

                            </span>
                        </div>
                    </label>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            </div>

            <?php if(count($tags) === 0): ?>
            <p class="text-gray-500 text-sm">No tags available yet.</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Asset grid -->
    <?php if($assets->count() > 0): ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 2xl:grid-cols-10 gap-4">
        <?php $__currentLoopData = $assets; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $asset): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div class="group relative bg-white rounded-lg shadow hover:shadow-lg transition-shadow overflow-hidden cursor-pointer"
             x-data="assetCard(<?php echo e($asset->id); ?>)"
             @click="window.location.href = '<?php echo e(route('assets.show', $asset)); ?>'">
            <!-- Thumbnail -->
            <div class="aspect-square bg-gray-100 relative">
                <?php if($asset->isImage() && $asset->thumbnail_url): ?>
                    <img src="<?php echo e($asset->thumbnail_url); ?>"
                         alt="<?php echo e($asset->filename); ?>"
                         class="w-full h-full object-cover"
                         loading="lazy">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center">
                        <?php
                            $icon = $asset->getFileIcon();
                            $colorClass = match($icon) {
                                'fa-file-pdf' => 'text-red-500',
                                'fa-file-word' => 'text-blue-600',
                                'fa-file-excel' => 'text-green-600',
                                'fa-file-powerpoint' => 'text-orange-500',
                                'fa-file-zipper' => 'text-yellow-600',
                                'fa-file-code' => 'text-purple-600',
                                'fa-file-video' => 'text-pink-600',
                                'fa-file-audio' => 'text-indigo-600',
                                'fa-file-csv' => 'text-teal-600',
                                'fa-file-lines' => 'text-gray-500',
                                default => 'text-gray-400'
                            };
                        ?>
                        <i class="fas <?php echo e($icon); ?> text-9xl <?php echo e($colorClass); ?> opacity-60"></i>
                    </div>
                <?php endif; ?>

                <!-- Overlay with actions -->
                <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-50 transition-all flex items-center justify-center opacity-0 group-hover:opacity-100">
                    <button @click.stop="downloadAsset('<?php echo e(route('assets.download', $asset)); ?>')"
                            :disabled="downloading"
                            :class="downloading ? 'bg-green-600' : 'bg-white hover:bg-gray-100'"
                            :title="downloading ? 'Downloading...' : 'Download'"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="downloading ? 'fas fa-spinner fa-spin text-white' : 'fas fa-download'"></i>
                    </button>
                    <button @click.stop="copyAssetUrl('<?php echo e($asset->url); ?>')"
                            :class="copied ? 'bg-green-600' : 'bg-white hover:bg-gray-100'"
                            :title="copied ? 'Copied!' : 'Copy URL'"
                            class="text-gray-900 px-3 py-2 rounded-lg transition-all duration-300 mr-2">
                        <i :class="copied ? 'fas fa-check text-white' : 'fas fa-copy'"></i>
                    </button>
                    <a href="<?php echo e(route('assets.edit', $asset)); ?>"
                       @click.stop
                       class="bg-white text-gray-900 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors"
                       title="Edit">
                        <i class="fas fa-edit"></i>
                    </a>
                </div>
            </div>
            
            <!-- Info -->
            <div class="p-3">
                <p class="text-sm font-medium text-gray-900 truncate" title="<?php echo e($asset->filename); ?>">
                    <?php echo e($asset->filename); ?>

                </p>
                <div class="text-xs text-gray-500 mt-1 space-y-0.5">
                    <p><i class="fas fa-hdd mr-1"></i><?php echo e($asset->formatted_size); ?></p>
                    <p title="<?php echo e($asset->updated_at); ?>"><i class="fas fa-clock mr-1"></i><?php echo e($asset->updated_at->diffForHumans()); ?></p>
                    <p class="truncate" title="<?php echo e($asset->user->name); ?>"><i class="fas fa-user mr-1"></i><?php echo e($asset->user->name); ?></p>
                </div>

                <?php if($asset->tags->count() > 0): ?>
                <div class="flex flex-wrap gap-1 mt-2">
                    <?php $__currentLoopData = $asset->tags->take(2); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <span class="text-xs px-2 py-0.5 rounded-full <?php echo e($tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'); ?>">
                        <?php echo e($tag->name); ?>

                    </span>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

                    <?php if($asset->tags->count() > 2): ?>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-700">
                        +<?php echo e($asset->tags->count() - 2); ?>

                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
    
    <!-- Pagination -->
    <div class="mt-8">
        <?php echo e($assets->links()); ?>

    </div>
    
    <?php else: ?>
    <div class="text-center py-12">
        <i class="fas fa-images text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">No assets found</h3>
        <p class="text-gray-500 mb-6">
            <?php if(request()->has('search') || request()->has('tags') || request()->has('type')): ?>
                Try adjusting your filters or
                <a href="<?php echo e(route('assets.index')); ?>" class="text-blue-600 hover:underline">clear all filters</a>
            <?php else: ?>
                Get started by uploading your first asset
            <?php endif; ?>
        </p>
        <a href="<?php echo e(route('assets.create')); ?>" class="inline-flex items-center px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
            <i class="fas fa-upload mr-2"></i> Upload Assets
        </a>
    </div>
    <?php endif; ?>
</div>

<?php $__env->startPush('scripts'); ?>
<script>
function assetGrid() {
    return {
        search: <?php echo json_encode(request('search', ''), 512) ?>,
        type: <?php echo json_encode(request('type', ''), 512) ?>,
        sort: <?php echo json_encode(request('sort', 'date_desc'), 512) ?>,
        selectedTags: <?php echo json_encode(request('tags', []), 512) ?>,
        initialTags: <?php echo json_encode(request('tags', []), 512) ?>,
        showTagFilter: false,

        tagsChanged() {
            // Check if the selected tags differ from initial tags
            if (this.selectedTags.length !== this.initialTags.length) {
                return true;
            }
            // Check if all tags match (order doesn't matter)
            const selected = [...this.selectedTags].sort();
            const initial = [...this.initialTags].sort();
            return !selected.every((tag, index) => tag === initial[index]);
        },

        applyFilters() {
            const params = new URLSearchParams();

            if (this.search) params.append('search', this.search);
            if (this.type) params.append('type', this.type);
            if (this.sort) params.append('sort', this.sort);
            if (this.selectedTags.length > 0) {
                this.selectedTags.forEach(tag => params.append('tags[]', tag));
            }

            window.location.href = '<?php echo e(route('assets.index')); ?>' + (params.toString() ? '?' + params.toString() : '');
        },

        copyUrl(url) {
            window.copyToClipboard(url);
        }
    };
}

function assetCard(assetId) {
    return {
        copied: false,
        downloading: false,

        async downloadAsset(url) {
            this.downloading = true;
            try {
                // Trigger the download
                window.location.href = url;

                // Show success state briefly
                setTimeout(() => {
                    this.downloading = false;
                }, 2000);
            } catch (error) {
                console.error('Download failed:', error);
                this.downloading = false;
                window.showToast('Download failed', 'error');
            }
        },

        copyAssetUrl(url) {
            window.copyToClipboard(url);
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        }
    };
}
</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\gijso\Herd\orca-dam\resources\views/assets/index.blade.php ENDPATH**/ ?>