<?php $__env->startSection('title', 'Tags'); ?>

<?php $__env->startSection('content'); ?>
<div x-data="tagManager()">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Tags</h1>
        <p class="text-gray-600 mt-2">Browse all tags in your asset library</p>
    </div>
    
    <!-- Filter tabs -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <a href="<?php echo e(route('tags.index')); ?>" 
               class="py-4 px-1 border-b-2 <?php echo e(!request('type') ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'); ?> font-medium text-sm">
                All Tags (<?php echo e($tags->count()); ?>)
            </a>
            <a href="<?php echo e(route('tags.index', ['type' => 'user'])); ?>" 
               class="py-4 px-1 border-b-2 <?php echo e(request('type') === 'user' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'); ?> font-medium text-sm">
                User Tags
            </a>
            <a href="<?php echo e(route('tags.index', ['type' => 'ai'])); ?>" 
               class="py-4 px-1 border-b-2 <?php echo e(request('type') === 'ai' ? 'border-purple-500 text-purple-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'); ?> font-medium text-sm">
                AI Tags
            </a>
        </nav>
    </div>
    
    <?php if($tags->count() > 0): ?>
    <!-- Tags grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-5 gap-4">
        <?php $__currentLoopData = $tags; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tag): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <div class="bg-white rounded-lg shadow hover:shadow-lg transition-shadow p-4">
            <div class="flex items-start justify-between mb-2 gap-3">
                <a href="<?php echo e(route('assets.index', ['tags' => [$tag->id]])); ?>"
                   class="flex-1 min-w-0">
                    <h3 class="text-lg font-semibold text-gray-900 hover:text-blue-600 truncate"><?php echo e($tag->name); ?></h3>
                </a>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full whitespace-nowrap <?php echo e($tag->type === 'ai' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'); ?>">
                        <?php echo e($tag->type); ?>

                        <?php if($tag->type === 'ai'): ?>
                        <i class="fas fa-robot ml-1"></i>
                        <?php endif; ?>
                    </span>

                    <?php if($tag->type === 'user'): ?>
                    <!-- Edit button -->
                    <button @click="editTag(<?php echo e($tag->id); ?>, '<?php echo e(addslashes($tag->name)); ?>')"
                            class="text-gray-500 hover:text-blue-600 p-1.5 hover:bg-blue-50 rounded transition"
                            title="Edit tag">
                        <i class="fas fa-edit text-sm"></i>
                    </button>

                    <!-- Delete button -->
                    <button @click="deleteTag(<?php echo e($tag->id); ?>, '<?php echo e(addslashes($tag->name)); ?>')"
                            class="text-gray-500 hover:text-red-600 p-1.5 hover:bg-red-50 rounded transition"
                            title="Delete tag">
                        <i class="fas fa-trash text-sm"></i>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <p class="text-sm text-gray-600">
                <i class="fas fa-images mr-1"></i>
                <?php echo e($tag->assets_count); ?> <?php echo e(Str::plural('asset', $tag->assets_count)); ?>

            </p>
        </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>
    <?php else: ?>
    <div class="text-center py-12 bg-white rounded-lg shadow">
        <i class="fas fa-tags text-6xl text-gray-300 mb-4"></i>
        <h3 class="text-xl font-semibold text-gray-700 mb-2">No tags found</h3>
        <p class="text-gray-500">
            Tags will appear here as you add them to your assets
        </p>
    </div>
    <?php endif; ?>

    <!-- Edit Tag Modal -->
    <div x-show="showEditModal"
         x-cloak
         class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50"
         @click.self="showEditModal = false">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
            <h2 class="text-2xl font-bold mb-4">Edit Tag</h2>

            <form @submit.prevent="updateTag">
                <div class="mb-4">
                    <label for="editTagName" class="block text-sm font-medium text-gray-700 mb-2">
                        Tag Name
                    </label>
                    <input type="text"
                           id="editTagName"
                           x-model="editingTagName"
                           required
                           maxlength="50"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button"
                            @click="showEditModal = false"
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-save mr-2"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php $__env->startPush('scripts'); ?>
<script>
function tagManager() {
    return {
        showEditModal: false,
        editingTagId: null,
        editingTagName: '',

        editTag(id, name) {
            this.editingTagId = id;
            this.editingTagName = name;
            this.showEditModal = true;
        },

        async updateTag() {
            try {
                const response = await fetch(`/tags/${this.editingTagId}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        name: this.editingTagName
                    })
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast('Tag updated successfully');
                    window.location.reload();
                } else {
                    window.showToast(data.message || 'Failed to update tag', 'error');
                }
            } catch (error) {
                console.error('Update error:', error);
                window.showToast('Failed to update tag', 'error');
            }
        },

        async deleteTag(id, name) {
            if (!confirm(`Are you sure you want to delete the tag "${name}"? This will remove it from all assets.`)) {
                return;
            }

            try {
                const response = await fetch(`/tags/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast('Tag deleted successfully');
                    window.location.reload();
                } else {
                    window.showToast(data.message || 'Failed to delete tag', 'error');
                }
            } catch (error) {
                console.error('Delete error:', error);
                window.showToast('Failed to delete tag', 'error');
            }
        }
    };
}
</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\gijso\Herd\orca-dam\resources\views/tags/index.blade.php ENDPATH**/ ?>