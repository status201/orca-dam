<?php $__env->startSection('title', 'Discover Unmapped Objects'); ?>

<?php $__env->startSection('content'); ?>
<div x-data="discoverObjects()">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Discover Unmapped Objects</h1>
        <p class="text-gray-600 mt-2">Find and import objects in your S3 bucket that aren't yet tracked in ORCA</p>
    </div>
    
    <!-- Scan button -->
    <div class="mb-6">
        <button @click="scanBucket"
                :disabled="scanning"
                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center">
            <template x-if="!scanning">
                <span><i class="fas fa-search mr-2"></i> Scan Bucket</span>
            </template>
            <template x-if="scanning">
                <span><i class="fas fa-spinner fa-spin mr-2"></i> Scanning...</span>
            </template>
        </button>
    </div>
    
    <!-- Results -->
    <div x-show="scanned" x-cloak>
        <!-- Summary -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold">
                        Found <span x-text="unmappedObjects.length" class="text-blue-600"></span> unmapped object(s)
                    </h2>
                    <p class="text-gray-600 text-sm mt-1">Select objects to import into ORCA</p>
                </div>
                
                <div class="flex space-x-3" x-show="unmappedObjects.length > 0">
                    <button @click="selectAll"
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        <i class="fas fa-check-double mr-2"></i> Select All
                    </button>
                    
                    <button @click="deselectAll"
                            class="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">
                        <i class="fas fa-times mr-2"></i> Deselect All
                    </button>
                    
                    <button @click="importSelected"
                            :disabled="selectedObjects.length === 0 || importing"
                            class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed">
                        <template x-if="!importing">
                            <span>
                                <i class="fas fa-file-import mr-2"></i> 
                                Import <span x-text="selectedObjects.length"></span> Selected
                            </span>
                        </template>
                        <template x-if="importing">
                            <span><i class="fas fa-spinner fa-spin mr-2"></i> Importing...</span>
                        </template>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Object list -->
        <div x-show="unmappedObjects.length > 0" class="bg-white rounded-lg shadow-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left">
                                <input type="checkbox"
                                       @change="$event.target.checked ? selectAll() : deselectAll()"
                                       :checked="selectedObjects.length === unmappedObjects.length && unmappedObjects.length > 0"
                                       class="rounded text-blue-600 focus:ring-blue-500">
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Preview
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Filename
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Type
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Size
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Last Modified
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="object in unmappedObjects" :key="object.key">
                            <tr :class="selectedObjects.includes(object.key) ? 'bg-blue-50' : ''">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <input type="checkbox"
                                           :value="object.key"
                                           x-model="selectedObjects"
                                           class="rounded text-blue-600 focus:ring-blue-500">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <template x-if="object.mime_type.startsWith('image/')">
                                        <img :src="object.url"
                                             :alt="object.filename"
                                             class="w-16 h-16 object-cover rounded">
                                    </template>
                                    <template x-if="!object.mime_type.startsWith('image/')">
                                        <div class="w-16 h-16 bg-gray-100 rounded flex items-center justify-center">
                                            <i class="fas text-5xl opacity-60"
                                               :class="[getFileIcon(object.mime_type, object.filename), getFileIconColor(getFileIcon(object.mime_type, object.filename))]"></i>
                                        </div>
                                    </template>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900" x-text="object.filename"></div>
                                    <div class="text-xs text-gray-500 font-mono truncate max-w-xs" x-text="object.key"></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-xs px-2 py-1 bg-gray-100 text-gray-700 rounded" x-text="object.mime_type"></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" x-text="formatFileSize(object.size)">
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600" x-text="formatDate(object.last_modified)">
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- No results -->
        <div x-show="unmappedObjects.length === 0" class="bg-white rounded-lg shadow-lg p-12 text-center">
            <i class="fas fa-check-circle text-6xl text-green-500 mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-700 mb-2">All Clear!</h3>
            <p class="text-gray-600">All objects in your S3 bucket are already tracked in ORCA.</p>
        </div>
    </div>
</div>

<?php $__env->startPush('scripts'); ?>
<script>
function discoverObjects() {
    return {
        scanning: false,
        scanned: false,
        importing: false,
        unmappedObjects: [],
        selectedObjects: [],
        
        async scanBucket() {
            this.scanning = true;
            this.scanned = false;
            this.unmappedObjects = [];
            this.selectedObjects = [];
            
            try {
                const response = await fetch('<?php echo e(route('discover.scan')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                
                const data = await response.json();
                this.unmappedObjects = data.objects || [];
                this.scanned = true;
                
                window.showToast(`Found ${data.count} unmapped object(s)`);
            } catch (error) {
                console.error('Scan error:', error);
                window.showToast('Failed to scan bucket', 'error');
            } finally {
                this.scanning = false;
            }
        },
        
        selectAll() {
            this.selectedObjects = this.unmappedObjects.map(obj => obj.key);
        },
        
        deselectAll() {
            this.selectedObjects = [];
        },
        
        async importSelected() {
            if (this.selectedObjects.length === 0) return;
            
            const confirmed = confirm(`Import ${this.selectedObjects.length} object(s)? This will create database records and generate thumbnails for images.`);
            if (!confirmed) return;
            
            this.importing = true;
            
            try {
                const response = await fetch('<?php echo e(route('discover.import')); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        keys: this.selectedObjects
                    }),
                });
                
                const data = await response.json();
                
                window.showToast(data.message);
                
                // Remove imported objects from the list
                this.unmappedObjects = this.unmappedObjects.filter(
                    obj => !this.selectedObjects.includes(obj.key)
                );
                this.selectedObjects = [];
                
                // Redirect to assets after a delay
                setTimeout(() => {
                    window.location.href = '<?php echo e(route('assets.index')); ?>';
                }, 2000);
                
            } catch (error) {
                console.error('Import error:', error);
                window.showToast('Failed to import objects', 'error');
            } finally {
                this.importing = false;
            }
        },
        
        formatFileSize(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let size = bytes;
            let unitIndex = 0;
            
            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex++;
            }
            
            return `${size.toFixed(2)} ${units[unitIndex]}`;
        },
        
        formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
        },

        getFileIcon(mimeType, filename) {
            const icons = {
                'application/pdf': 'fa-file-pdf',
                'application/msword': 'fa-file-word',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'fa-file-word',
                'application/vnd.ms-excel': 'fa-file-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'fa-file-excel',
                'application/vnd.ms-powerpoint': 'fa-file-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'fa-file-powerpoint',
                'application/zip': 'fa-file-zipper',
                'application/x-zip-compressed': 'fa-file-zipper',
                'application/x-rar-compressed': 'fa-file-zipper',
                'application/x-7z-compressed': 'fa-file-zipper',
                'text/plain': 'fa-file-lines',
                'text/csv': 'fa-file-csv',
                'application/json': 'fa-file-code',
                'text/html': 'fa-file-code',
                'text/css': 'fa-file-code',
                'text/javascript': 'fa-file-code',
                'application/javascript': 'fa-file-code',
                'video/mp4': 'fa-file-video',
                'video/mpeg': 'fa-file-video',
                'video/quicktime': 'fa-file-video',
                'video/x-msvideo': 'fa-file-video',
                'audio/mpeg': 'fa-file-audio',
                'audio/wav': 'fa-file-audio',
                'audio/ogg': 'fa-file-audio'
            };

            if (icons[mimeType]) {
                return icons[mimeType];
            }

            // Check by file extension as fallback
            const ext = filename.toLowerCase().split('.').pop();
            const extIcons = {
                'pdf': 'fa-file-pdf',
                'doc': 'fa-file-word',
                'docx': 'fa-file-word',
                'xls': 'fa-file-excel',
                'xlsx': 'fa-file-excel',
                'ppt': 'fa-file-powerpoint',
                'pptx': 'fa-file-powerpoint',
                'zip': 'fa-file-zipper',
                'rar': 'fa-file-zipper',
                '7z': 'fa-file-zipper',
                'txt': 'fa-file-lines',
                'csv': 'fa-file-csv',
                'json': 'fa-file-code',
                'html': 'fa-file-code',
                'css': 'fa-file-code',
                'js': 'fa-file-code',
                'mp4': 'fa-file-video',
                'mov': 'fa-file-video',
                'avi': 'fa-file-video',
                'mp3': 'fa-file-audio',
                'wav': 'fa-file-audio'
            };

            return extIcons[ext] || 'fa-file';
        },

        getFileIconColor(icon) {
            const colors = {
                'fa-file-pdf': 'text-red-500',
                'fa-file-word': 'text-blue-600',
                'fa-file-excel': 'text-green-600',
                'fa-file-powerpoint': 'text-orange-500',
                'fa-file-zipper': 'text-yellow-600',
                'fa-file-code': 'text-purple-600',
                'fa-file-video': 'text-pink-600',
                'fa-file-audio': 'text-indigo-600',
                'fa-file-csv': 'text-teal-600',
                'fa-file-lines': 'text-gray-500'
            };

            return colors[icon] || 'text-gray-400';
        }
    };
}
</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\gijso\Herd\orca-dam\resources\views/discover/index.blade.php ENDPATH**/ ?>