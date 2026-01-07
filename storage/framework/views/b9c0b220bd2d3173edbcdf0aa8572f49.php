<?php $__env->startSection('title', $asset->filename); ?>

<?php $__env->startSection('content'); ?>
<div class="max-w-6xl mx-auto" x-data="assetDetail()">
    <!-- Back button -->
    <div class="mb-6">
        <a href="<?php echo e(route('assets.index')); ?>" class="inline-flex items-center text-blue-600 hover:text-blue-700">
            <i class="fas fa-arrow-left mr-2"></i> Back to Assets
        </a>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Preview column -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow-lg overflow-hidden">
                <?php if($asset->isImage()): ?>
                    <img src="<?php echo e($asset->url); ?>"
                         alt="<?php echo e($asset->filename); ?>"
                         class="w-full h-auto">
                <?php else: ?>
                    <div class="aspect-video bg-gray-100 flex items-center justify-center">
                        <div class="text-center">
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
                            <i class="fas <?php echo e($icon); ?> <?php echo e($colorClass); ?> opacity-60 mb-4" style="font-size: 12rem;"></i>
                            <p class="text-gray-600 font-medium"><?php echo e($asset->mime_type); ?></p>
                            <p class="text-gray-500 text-sm mt-1"><?php echo e(strtoupper(pathinfo($asset->filename, PATHINFO_EXTENSION))); ?> File</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- URL Copy Section -->
            <div class="mt-6 bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-3">Asset URL</h3>
                <div class="flex items-center space-x-2">
                    <input type="text"
                           value="<?php echo e($asset->url); ?>"
                           readonly
                           class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm font-mono">
                    <button @click="copyUrl('<?php echo e($asset->url); ?>', 'main')"
                            :class="copiedStates.main ? 'bg-green-600 hover:bg-green-700' : 'bg-blue-600 hover:bg-blue-700'"
                            class="px-4 py-2 text-white rounded-lg whitespace-nowrap transition-all duration-300">
                        <i :class="copiedStates.main ? 'fas fa-check' : 'fas fa-copy'" class="mr-2"></i>
                        <span x-text="copiedStates.main ? 'Copied!' : 'Copy'"></span>
                    </button>
                </div>

                <?php if($asset->thumbnail_url): ?>
                <div class="mt-4">
                    <h4 class="text-sm font-semibold mb-2 text-gray-700">Thumbnail URL</h4>
                    <div class="flex items-center space-x-2">
                        <input type="text"
                               value="<?php echo e($asset->thumbnail_url); ?>"
                               readonly
                               class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-sm font-mono">
                        <button @click="copyUrl('<?php echo e($asset->thumbnail_url); ?>', 'thumb')"
                                :class="copiedStates.thumb ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700'"
                                class="px-4 py-2 text-white rounded-lg whitespace-nowrap transition-all duration-300">
                            <i :class="copiedStates.thumb ? 'fas fa-check' : 'fas fa-copy'" class="mr-2"></i>
                            <span x-text="copiedStates.thumb ? 'Copied!' : 'Copy'"></span>
                        </button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Info column -->
        <div class="space-y-6">
            <!-- Details card -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h2 class="text-2xl font-bold mb-4 break-words"><?php echo e($asset->filename); ?></h2>
                
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-gray-500">File Size</dt>
                        <dd class="font-medium"><?php echo e($asset->formatted_size); ?></dd>
                    </div>
                    
                    <?php if($asset->width && $asset->height): ?>
                    <div>
                        <dt class="text-gray-500">Dimensions</dt>
                        <dd class="font-medium"><?php echo e($asset->width); ?> × <?php echo e($asset->height); ?> px</dd>
                    </div>
                    <?php endif; ?>
                    
                    <div>
                        <dt class="text-gray-500">Type</dt>
                        <dd class="font-medium"><?php echo e($asset->mime_type); ?></dd>
                    </div>
                    
                    <div>
                        <dt class="text-gray-500">Uploaded By</dt>
                        <dd class="font-medium"><?php echo e($asset->user->name); ?></dd>
                    </div>
                    
                    <div>
                        <dt class="text-gray-500">Uploaded</dt>
                        <dd class="font-medium"><?php echo e($asset->created_at->format('M d, Y')); ?></dd>
                    </div>
                </dl>
                
                <?php if($asset->alt_text): ?>
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">Alt Text</h4>
                    <p class="text-sm text-gray-600"><?php echo e($asset->alt_text); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if($asset->caption): ?>
                <div class="mt-4 pt-4 border-t">
                    <h4 class="text-sm font-semibold text-gray-700 mb-1">Caption</h4>
                    <p class="text-sm text-gray-600"><?php echo e($asset->caption); ?></p>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Tags card -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Tags</h3>
                
                <?php if($asset->tags->count() > 0): ?>
                <div class="flex flex-wrap gap-2 mb-4">
                    <?php $__currentLoopData = $asset->tags; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-sm <?php echo e($tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'); ?>">
                        <?php echo e($tag->name); ?>

                        <?php if($tag->type === 'ai'): ?>
                        <i class="fas fa-robot ml-2 text-xs"></i>
                        <?php endif; ?>
                    </span>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
                
                <div class="flex flex-wrap gap-2 text-xs">
                    <?php if($asset->userTags->count() > 0): ?>
                    <span class="text-gray-500">
                        <i class="fas fa-user mr-1"></i> <?php echo e($asset->userTags->count()); ?> user tag(s)
                    </span>
                    <?php endif; ?>
                    
                    <?php if($asset->aiTags->count() > 0): ?>
                    <span class="text-gray-500">
                        <i class="fas fa-robot mr-1"></i> <?php echo e($asset->aiTags->count()); ?> AI tag(s)
                    </span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <p class="text-gray-500 text-sm">No tags yet</p>
                <?php endif; ?>
            </div>
            
            <!-- Actions card -->
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h3 class="text-lg font-semibold mb-4">Actions</h3>
                
                <div class="space-y-3">
                    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('update', $asset)): ?>
                    <a href="<?php echo e(route('assets.edit', $asset)); ?>" 
                       class="block w-full px-4 py-2 bg-blue-600 text-white text-center rounded-lg hover:bg-blue-700">
                        <i class="fas fa-edit mr-2"></i> Edit Asset
                    </a>
                    <?php endif; ?>
                    
                    <a href="<?php echo e(route('assets.download', $asset)); ?>"
                       class="block w-full px-4 py-2 bg-gray-600 text-white text-center rounded-lg hover:bg-gray-700">
                        <i class="fas fa-download mr-2"></i> Download
                    </a>
                    
                    <?php if (app(\Illuminate\Contracts\Auth\Access\Gate::class)->check('delete', $asset)): ?>
                    <form action="<?php echo e(route('assets.destroy', $asset)); ?>" 
                          method="POST"
                          onsubmit="return confirm('Are you sure you want to delete this asset? This action cannot be undone.')">
                        <?php echo csrf_field(); ?>
                        <?php echo method_field('DELETE'); ?>
                        <button type="submit" 
                                class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            <i class="fas fa-trash mr-2"></i> Delete Asset
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php $__env->startPush('scripts'); ?>
<script>
function assetDetail() {
    return {
        copiedStates: {
            main: false,
            thumb: false
        },

        copyUrl(url, type) {
            // Try modern clipboard API first
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(() => {
                    this.copiedStates[type] = true;
                    window.showToast('URL copied to clipboard!');
                    setTimeout(() => {
                        this.copiedStates[type] = false;
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    window.showToast('Failed to copy URL', 'error');
                });
            } else {
                // Fallback for HTTP/older browsers
                const textArea = document.createElement('textarea');
                textArea.value = url;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    this.copiedStates[type] = true;
                    window.showToast('URL copied to clipboard!');
                    setTimeout(() => {
                        this.copiedStates[type] = false;
                    }, 2000);
                } catch (err) {
                    console.error('Failed to copy:', err);
                    window.showToast('Failed to copy URL', 'error');
                }
                textArea.remove();
            }
        }
    };
}
</script>

<style>
@keyframes pulse {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.05);
    }
}
</style>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\gijso\Herd\orca-dam\resources\views/assets/show.blade.php ENDPATH**/ ?>