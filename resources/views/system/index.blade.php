@extends('layouts.app')

@section('title', 'System Administration')

@section('content')
<div x-data="systemAdmin()" x-init="init()">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">System Administration</h1>
        <p class="text-gray-600 mt-2">Monitor and manage system resources</p>
    </div>

    <!-- Tab Navigation -->
    <div class="mb-6 border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <button @click="activeTab = 'overview'"
                    :class="activeTab === 'overview' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-dashboard mr-2"></i>Overview
            </button>

            <button @click="activeTab = 'queue'"
                    :class="activeTab === 'queue' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-tasks mr-2"></i>Queue
                <span x-show="queueStats.pending > 0"
                      class="ml-2 px-2 py-0.5 text-xs bg-blue-100 text-blue-700 rounded-full"
                      x-text="queueStats.pending"></span>
                <span x-show="queueStats.failed > 0"
                      class="ml-2 px-2 py-0.5 text-xs bg-red-100 text-red-700 rounded-full"
                      x-text="queueStats.failed"></span>
            </button>

            <button @click="activeTab = 'logs'"
                    :class="activeTab === 'logs' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-file-lines mr-2"></i>Logs
            </button>

            <button @click="activeTab = 'commands'"
                    :class="activeTab === 'commands' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-terminal mr-2"></i>Commands
            </button>

            <button @click="activeTab = 'diagnostics'"
                    :class="activeTab === 'diagnostics' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-stethoscope mr-2"></i>Diagnostics
            </button>
        </nav>
    </div>

    <!-- Overview Tab -->
    <div x-show="activeTab === 'overview'" class="space-y-6">
        <!-- System Info Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- PHP Version -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-purple-100 rounded-lg p-3">
                        <i class="fab fa-php text-2xl text-purple-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">PHP Version</p>
                        <p class="text-lg font-semibold text-gray-900">{{ $systemInfo['php_version'] }}</p>
                    </div>
                </div>
            </div>

            <!-- Laravel Version -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-red-100 rounded-lg p-3">
                        <i class="fab fa-laravel text-2xl text-red-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Laravel</p>
                        <p class="text-lg font-semibold text-gray-900">{{ $systemInfo['laravel_version'] }}</p>
                    </div>
                </div>
            </div>

            <!-- Environment -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-green-100 rounded-lg p-3">
                        <i class="fas fa-server text-2xl text-green-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Environment</p>
                        <p class="text-lg font-semibold text-gray-900">{{ ucfirst($systemInfo['environment']) }}</p>
                    </div>
                </div>
            </div>

            <!-- Memory Limit -->
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0 bg-blue-100 rounded-lg p-3">
                        <i class="fas fa-memory text-2xl text-blue-600"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-500">Memory Limit</p>
                        <p class="text-lg font-semibold text-gray-900">{{ $systemInfo['memory_limit'] }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Database Statistics -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-database mr-2"></i>Database Statistics
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    @foreach($databaseStats['tables'] as $table => $count)
                        <div class="text-center">
                            <p class="text-2xl font-bold text-gray-900">{{ number_format($count) }}</p>
                            <p class="text-sm text-gray-500">{{ ucfirst(str_replace('_', ' ', $table)) }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- Disk Usage -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-hard-drive mr-2"></i>Disk Usage
                </h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Storage (app/)</span>
                        <span class="text-sm font-semibold text-gray-900" x-text="formatBytes({{ $diskUsage['storage_size'] }})"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Logs</span>
                        <span class="text-sm font-semibold text-gray-900" x-text="formatBytes({{ $diskUsage['logs_size'] }})"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">Cache</span>
                        <span class="text-sm font-semibold text-gray-900" x-text="formatBytes({{ $diskUsage['cache_size'] }})"></span>
                    </div>
                    <div class="flex justify-between items-center pt-4 border-t border-gray-200">
                        <span class="text-sm font-semibold text-gray-900">Total Storage</span>
                        <span class="text-lg font-bold text-gray-900" x-text="formatBytes({{ $diskUsage['total_size'] }})"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Queue Tab -->
    <div x-show="activeTab === 'queue'" class="space-y-6">
        <!-- Queue Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Pending Jobs</p>
                        <p class="text-3xl font-bold text-blue-600" x-text="queueStats.pending"></p>
                    </div>
                    <i class="fas fa-clock text-4xl text-blue-200"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Failed Jobs</p>
                        <p class="text-3xl font-bold text-red-600" x-text="queueStats.failed"></p>
                    </div>
                    <i class="fas fa-exclamation-triangle text-4xl text-red-200"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">Batches</p>
                        <p class="text-3xl font-bold text-purple-600" x-text="queueStats.batches"></p>
                    </div>
                    <i class="fas fa-layer-group text-4xl text-purple-200"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <button @click="refreshQueueStatus()"
                        class="w-full h-full flex items-center justify-center text-gray-600 hover:text-gray-900">
                    <i class="fas fa-sync-alt text-2xl" :class="{'fa-spin': loadingQueue}"></i>
                    <span class="ml-2">Refresh</span>
                </button>
            </div>
        </div>

        <!-- Queue Controls -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-sliders-h mr-2"></i>Queue Controls
            </h3>
            <div class="flex flex-wrap gap-3">
                <button @click="retryAllFailedJobs()"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                        :disabled="queueStats.failed === 0">
                    <i class="fas fa-redo mr-2"></i>Retry All Failed
                </button>

                <button @click="flushFailedJobs()"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                        :disabled="queueStats.failed === 0">
                    <i class="fas fa-trash mr-2"></i>Flush Failed
                </button>

                <button @click="restartWorkers()"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    <i class="fas fa-power-off mr-2"></i>Restart Workers
                </button>
            </div>

            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h4 class="text-sm font-semibold text-blue-900 mb-2">
                    <i class="fas fa-info-circle mr-1"></i>Queue Worker Setup
                </h4>
                <div class="text-xs text-blue-800 space-y-1">
                    <p><strong>Development:</strong> Run manually in terminal: <code class="bg-blue-100 px-1 py-0.5 rounded">php artisan queue:work --tries=3</code></p>
                    <p><strong>Production:</strong> Use supervisor to manage persistent workers. See <code class="bg-blue-100 px-1 py-0.5 rounded">DEPLOYMENT.md</code> for setup instructions.</p>
                    <p><strong>Config file:</strong> <code class="bg-blue-100 px-1 py-0.5 rounded">deploy/supervisor/orca-queue-worker.conf</code></p>
                </div>
            </div>
        </div>

        <!-- Supervisor Status -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-server mr-2"></i>Supervisor Status
                </h3>
                <button @click="refreshSupervisorStatus()"
                        class="px-3 py-1 text-sm bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    <i class="fas fa-sync-alt mr-1" :class="{'fa-spin': loadingSupervisor}"></i>Refresh
                </button>
            </div>

            <div x-show="!supervisorStatus.available" class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p class="text-sm text-yellow-800" x-text="supervisorStatus.message"></p>
            </div>

            <div x-show="supervisorStatus.available && supervisorStatus.workers.length === 0" class="p-4 bg-gray-50 border border-gray-200 rounded-lg">
                <p class="text-sm text-gray-700" x-text="supervisorStatus.message"></p>
            </div>

            <div x-show="supervisorStatus.available && supervisorStatus.workers.length > 0" class="space-y-4">
                <div class="flex items-center space-x-4 text-sm">
                    <div class="flex items-center">
                        <span class="font-semibold text-gray-700">Total Workers:</span>
                        <span class="ml-2 text-gray-900" x-text="supervisorStatus.total"></span>
                    </div>
                    <div class="flex items-center">
                        <span class="font-semibold text-gray-700">Running:</span>
                        <span class="ml-2 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold" x-text="supervisorStatus.running"></span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Worker Name</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">PID</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Uptime</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <template x-for="worker in supervisorStatus.workers" :key="worker.name">
                                <tr>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-900" x-text="worker.name"></td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                              :class="{
                                                  'bg-green-100 text-green-800': worker.status === 'RUNNING',
                                                  'bg-red-100 text-red-800': worker.status === 'STOPPED' || worker.status === 'FATAL' || worker.status === 'EXITED',
                                                  'bg-yellow-100 text-yellow-800': worker.status === 'STARTING' || worker.status === 'BACKOFF',
                                                  'bg-gray-100 text-gray-800': worker.status === 'STOPPING' || worker.status === 'UNKNOWN'
                                              }"
                                              x-text="worker.status">
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600" x-text="worker.pid || '-'"></td>
                                    <td class="px-4 py-3 text-sm text-gray-600" x-text="worker.uptime || '-'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Failed Jobs List -->
        <div x-show="failedJobs.length > 0" class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-exclamation-circle mr-2"></i>Failed Jobs
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">UUID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Queue</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Exception</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Failed At</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="job in failedJobs" :key="job.uuid">
                            <tr>
                                <td class="px-6 py-4 text-sm font-mono text-gray-900" x-text="job.uuid.substring(0, 8)"></td>
                                <td class="px-6 py-4 text-sm text-gray-600" x-text="job.queue"></td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div class="max-w-md truncate" x-text="job.exception"></div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600" x-text="job.failed_at"></td>
                                <td class="px-6 py-4 text-sm">
                                    <button @click="retryJob(job.uuid)"
                                            class="text-blue-600 hover:text-blue-900">
                                        <i class="fas fa-redo mr-1"></i>Retry
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Logs Tab -->
    <div x-show="activeTab === 'logs'" class="space-y-6">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-file-lines mr-2"></i>Laravel Log Viewer
                </h3>
                <div class="flex items-center space-x-3">
                    <select x-model="logLines" @change="refreshLogs()"
                            class="rounded-md border-gray-300 text-sm">
                        <option value="20">20 lines</option>
                        <option value="50">50 lines</option>
                        <option value="100">100 lines</option>
                        <option value="200">200 lines</option>
                    </select>
                    <button @click="refreshLogs()"
                            class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        <i class="fas fa-sync-alt mr-2" :class="{'fa-spin': loadingLogs}"></i>Refresh
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div x-show="logData.exists" class="space-y-4">
                    <div class="text-sm text-gray-600">
                        <span class="font-semibold">File:</span> <span class="font-mono" x-text="logData.path"></span><br>
                        <span class="font-semibold">Size:</span> <span x-text="formatBytes(logData.size)"></span>
                    </div>
                    <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
                        <pre class="text-xs text-green-400 font-mono"><template x-for="(line, index) in logData.lines" :key="index"><div x-text="line" :class="getLogLineColor(line)"></div></template></pre>
                    </div>
                </div>
                <div x-show="!logData.exists" class="text-center py-8 text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-3"></i>
                    <p>No log file found</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Commands Tab -->
    <div x-show="activeTab === 'commands'" class="space-y-6">
        <!-- Command Input -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-terminal mr-2"></i>Execute Artisan Command
            </h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Command</label>
                    <input type="text"
                           x-model="customCommand"
                           @keydown.enter="executeCustomCommand()"
                           placeholder="e.g., cache:clear"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg font-mono text-sm">
                    <p class="text-xs text-gray-500 mt-1">
                        <i class="fas fa-shield-alt mr-1"></i>
                        Only whitelisted commands are allowed for security.
                    </p>
                </div>
                <button @click="executeCustomCommand()"
                        :disabled="!customCommand || executingCommand"
                        class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover disabled:opacity-50">
                    <i class="fas mr-2" :class="executingCommand ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                    Execute
                </button>
            </div>

            <!-- Command Output -->
            <div x-show="commandOutput" class="mt-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Output</label>
                <div class="bg-gray-900 rounded-lg p-4 overflow-x-auto">
                    <pre class="text-xs font-mono"
                         :class="commandSuccess ? 'text-green-400' : 'text-red-400'"
                         x-text="commandOutput"></pre>
                </div>
            </div>
        </div>

        <!-- Suggested Commands -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-list mr-2"></i>Suggested Commands
            </h3>
            <div class="space-y-2">
                @foreach($suggestedCommands as $cmd)
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100">
                        <div class="flex-grow">
                            <code class="text-sm font-mono text-gray-900">{{ $cmd['command'] }}</code>
                            <p class="text-xs text-gray-500 mt-1">{{ $cmd['description'] }}</p>
                        </div>
                        <button @click="customCommand = '{{ $cmd['command'] }}'; executeCustomCommand()"
                                class="ml-4 px-3 py-1 text-sm bg-orca-black text-white rounded hover:bg-orca-black-hover">
                            <i class="fas fa-play mr-1"></i>Run
                        </button>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Diagnostics Tab -->
    <div x-show="activeTab === 'diagnostics'" class="space-y-6">
        <!-- Configuration Details -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-cog mr-2"></i>System Configuration
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Queue Driver:</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['queue_driver'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Cache Driver:</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['cache_driver'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Session Driver:</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['session_driver'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Storage Disk:</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['storage_disk'] }}</span>
                    </div>
                </div>
                <div class="space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Timezone:</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['timezone'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Debug Mode:</span>
                        <span class="text-sm font-semibold {{ $systemInfo['debug_mode'] ? 'text-red-600' : 'text-green-600' }}">
                            {{ $systemInfo['debug_mode'] ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Rekognition:</span>
                        <span class="text-sm font-semibold {{ $systemInfo['rekognition_enabled'] ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $systemInfo['rekognition_enabled'] ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">Max Execution:</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['max_execution_time'] }}s</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- PHP Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fab fa-php mr-2"></i>PHP Configuration
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">Memory Limit:</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['memory_limit'] }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">Upload Max Filesize:</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['upload_max_filesize'] }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">Post Max Size:</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['post_max_size'] }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">Max Execution Time:</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['max_execution_time'] }}s</span>
                </div>
            </div>
        </div>

        <!-- Connection Tests -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-plug mr-2"></i>Connection Tests
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">Amazon S3</p>
                        <p class="text-xs text-gray-500">Test S3 bucket connectivity</p>
                    </div>
                    <button @click="testS3Connection()"
                            :disabled="testingS3"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50">
                        <i class="fas mr-2" :class="testingS3 ? 'fa-spinner fa-spin' : 'fa-vial'"></i>
                        Test
                    </button>
                </div>
                <div x-show="s3TestResult" class="p-3 rounded-lg"
                     :class="s3TestSuccess ? 'bg-green-50' : 'bg-red-50'">
                    <p class="text-sm" :class="s3TestSuccess ? 'text-green-800' : 'text-red-800'"
                       x-text="s3TestMessage"></p>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function systemAdmin() {
    return {
        activeTab: 'overview',

        // Queue data
        queueStats: @json($queueStats),
        pendingJobs: [],
        failedJobs: [],
        loadingQueue: false,

        // Logs data
        logData: { exists: false, lines: [], size: 0, path: '' },
        logLines: 50,
        loadingLogs: false,

        // Commands
        customCommand: '',
        commandOutput: '',
        commandSuccess: false,
        executingCommand: false,

        // Diagnostics
        testingS3: false,
        s3TestResult: false,
        s3TestSuccess: false,
        s3TestMessage: '',

        // Supervisor
        supervisorStatus: {
            available: false,
            message: '',
            workers: [],
            total: 0,
            running: 0
        },
        loadingSupervisor: false,

        init() {
            // Initial load
            this.refreshQueueStatus();
            this.refreshSupervisorStatus();
        },

        async refreshQueueStatus() {
            this.loadingQueue = true;
            try {
                const response = await fetch('{{ route('system.queue-status') }}');
                const data = await response.json();
                this.queueStats = data.stats;
                this.pendingJobs = data.pending_jobs;
                this.failedJobs = data.failed_jobs;
            } catch (error) {
                console.error('Failed to refresh queue status:', error);
                window.showToast('Failed to refresh queue status', 'error');
            } finally {
                this.loadingQueue = false;
            }
        },

        async refreshLogs() {
            this.loadingLogs = true;
            try {
                const response = await fetch(`{{ route('system.logs') }}?lines=${this.logLines}`);
                this.logData = await response.json();
            } catch (error) {
                console.error('Failed to refresh logs:', error);
                window.showToast('Failed to refresh logs', 'error');
            } finally {
                this.loadingLogs = false;
            }
        },

        async executeCustomCommand() {
            if (!this.customCommand) return;

            this.executingCommand = true;
            this.commandOutput = '';

            try {
                const response = await fetch('{{ route('system.execute-command') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ command: this.customCommand }),
                });

                const result = await response.json();
                this.commandSuccess = result.success;
                this.commandOutput = result.output || result.error || 'No output';

                if (result.success) {
                    window.showToast('Command executed successfully', 'success');
                } else {
                    window.showToast('Command failed', 'error');
                }
            } catch (error) {
                console.error('Failed to execute command:', error);
                this.commandSuccess = false;
                this.commandOutput = error.message;
                window.showToast('Failed to execute command', 'error');
            } finally {
                this.executingCommand = false;
            }
        },

        async retryJob(uuid) {
            try {
                const response = await fetch('{{ route('system.retry-job') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ job_id: uuid }),
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast('Job queued for retry', 'success');
                    this.refreshQueueStatus();
                } else {
                    window.showToast('Failed to retry job', 'error');
                }
            } catch (error) {
                console.error('Failed to retry job:', error);
                window.showToast('Failed to retry job', 'error');
            }
        },

        async retryAllFailedJobs() {
            if (!confirm('Retry all failed jobs?')) return;

            this.customCommand = 'queue:retry all';
            await this.executeCustomCommand();
            setTimeout(() => this.refreshQueueStatus(), 1000);
        },

        async flushFailedJobs() {
            if (!confirm('Delete all failed jobs? This cannot be undone.')) return;

            try {
                const response = await fetch('{{ route('system.flush-queue') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast('Failed jobs flushed', 'success');
                    this.refreshQueueStatus();
                } else {
                    window.showToast('Failed to flush queue', 'error');
                }
            } catch (error) {
                console.error('Failed to flush queue:', error);
                window.showToast('Failed to flush queue', 'error');
            }
        },

        async restartWorkers() {
            try {
                const response = await fetch('{{ route('system.restart-queue') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast('Queue workers signaled to restart', 'success');
                } else {
                    window.showToast('Failed to restart workers', 'error');
                }
            } catch (error) {
                console.error('Failed to restart workers:', error);
                window.showToast('Failed to restart workers', 'error');
            }
        },

        async refreshSupervisorStatus() {
            this.loadingSupervisor = true;
            try {
                const response = await fetch('{{ route('system.supervisor-status') }}');
                const data = await response.json();
                this.supervisorStatus = data;
            } catch (error) {
                console.error('Failed to refresh supervisor status:', error);
                this.supervisorStatus = {
                    available: false,
                    message: 'Failed to check supervisor status',
                    workers: [],
                    total: 0,
                    running: 0
                };
            } finally {
                this.loadingSupervisor = false;
            }
        },

        async testS3Connection() {
            this.testingS3 = true;
            this.s3TestResult = false;

            try {
                const response = await fetch('{{ route('system.test-s3') }}');
                const result = await response.json();

                this.s3TestResult = true;
                this.s3TestSuccess = result.success;
                this.s3TestMessage = result.message + (result.error ? ': ' + result.error : '');
            } catch (error) {
                console.error('S3 test failed:', error);
                this.s3TestResult = true;
                this.s3TestSuccess = false;
                this.s3TestMessage = 'Connection test failed: ' + error.message;
            } finally {
                this.testingS3 = false;
            }
        },

        formatBytes(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
        },

        getLogLineColor(line) {
            if (line.includes('ERROR') || line.includes('Exception')) return 'text-red-400';
            if (line.includes('WARNING')) return 'text-yellow-400';
            if (line.includes('INFO')) return 'text-blue-400';
            return 'text-green-400';
        },
    };
}
</script>
@endpush
@endsection
