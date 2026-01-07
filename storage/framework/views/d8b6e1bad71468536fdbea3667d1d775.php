<?php $__env->startSection('title', 'Users'); ?>

<?php $__env->startSection('content'); ?>
<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Users</h1>
            <p class="text-gray-600 mt-2">Manage system users and their roles</p>
        </div>
        <a href="<?php echo e(route('users.create')); ?>" class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover flex items-center">
            <i class="fas fa-plus mr-2"></i> Add User
        </a>
    </div>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Name
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Email
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Role
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Actions
                </th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            <?php $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <tr>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <div>
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo e($user->name); ?>

                                <?php if($user->id === auth()->id()): ?>
                                    <span class="ml-2 text-xs text-gray-500">(You)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900"><?php echo e($user->email); ?></div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo e($user->isAdmin() ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700'); ?>">
                        <?php echo e(ucfirst($user->role)); ?>

                    </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <a href="<?php echo e(route('users.edit', $user)); ?>" class="text-orca-black hover:text-orca-black-hover mr-3">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <?php if($user->id !== auth()->id()): ?>
                        <form action="<?php echo e(route('users.destroy', $user)); ?>" method="POST" class="inline"
                              onsubmit="return confirm('Are you sure you want to delete this user?');">
                            <?php echo csrf_field(); ?>
                            <?php echo method_field('DELETE'); ?>
                            <button type="submit" class="text-red-600 hover:text-red-900">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </tbody>
    </table>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH C:\Users\gijso\Herd\orca-dam\resources\views/users/index.blade.php ENDPATH**/ ?>