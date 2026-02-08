@extends('layouts.app')

@section('title', __('System Administration'))

@section('content')
<div x-data="systemAdmin()" x-init="init()">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('System Administration') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Monitor and manage system resources') }}</p>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation-container mb-6 border-b border-gray-200 w-full overflow-y-hidden overflow-x-auto">
        <nav class="-mb-px flex space-x-8">
            <button @click="activeTab = 'overview'"
                    :class="activeTab === 'overview' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-dashboard mr-2"></i>{{ __('Overview') }}
            </button>

            <button @click="activeTab = 'settings'"
                    :class="activeTab === 'settings' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-cog mr-2"></i>{{ __('Settings') }}
            </button>

            <button @click="activeTab = 'queue'"
                    :class="activeTab === 'queue' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-tasks mr-2"></i>{{ __('Queue') }}
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
                <i class="fas fa-file-lines mr-2"></i>{{ __('Logs') }}
            </button>

            <button @click="activeTab = 'commands'"
                    :class="activeTab === 'commands' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-terminal mr-2"></i>{{ __('Commands') }}
            </button>

            <button @click="activeTab = 'diagnostics'"
                    :class="activeTab === 'diagnostics' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-stethoscope mr-2"></i>{{ __('Diagnostics') }}
            </button>

            <button @click="activeTab = 'documentation'"
                    :class="activeTab === 'documentation' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-book mr-2"></i>{{ __('Documentation') }}
            </button>

            <button @click="activeTab = 'tests'"
                    :class="activeTab === 'tests' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-vial mr-2"></i>{{ __('Tests') }}
                <span x-show="testStats.failed > 0"
                      class="attention ml-2 px-2 py-0.5 text-xs bg-red-100 text-red-700 rounded-full"
                      x-text="testStats.failed"></span>
                <span x-show="testStats.passed > 0 && testStats.failed === 0"
                      class="attention ml-2 px-2 py-0.5 text-xs bg-green-100 text-green-700 rounded-full"
                      x-text="testStats.passed"></span>
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
                        <p class="text-sm font-medium text-gray-500">{{ __('PHP Version') }}</p>
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
                        <p class="text-sm font-medium text-gray-500">{{ __('Laravel') }}</p>
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
                        <p class="text-sm font-medium text-gray-500">{{ __('Environment') }}</p>
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
                        <p class="text-sm font-medium text-gray-500">{{ __('Memory Limit') }}</p>
                        <p class="text-lg font-semibold text-gray-900">{{ $systemInfo['memory_limit'] }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Database Statistics -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-database mr-2"></i>{{ __('Database Statistics') }}
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

        <!-- API Status -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-heartbeat mr-2"></i>{{ __('API Status') }}
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Sanctum Status -->
                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                        <div class="attention w-3 h-3 rounded-full bg-green-500 mr-3"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">Sanctum</p>
                            <p class="attention text-xs text-green-600">{{ __('Active') }}</p>
                        </div>
                    </div>

                    <!-- JWT Status -->
                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                        <div class="attention w-3 h-3 rounded-full mr-3"
                             :class="systemInfo.jwtEnvEnabled === '1' && settings.jwtSettingEnabled === '1' ? 'bg-green-500' : 'bg-red-500'"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">JWT</p>
                            <p class="attention text-xs"
                               :class="systemInfo.jwtEnvEnabled === '1' && settings.jwtSettingEnabled === '1' ? 'text-green-600' : 'text-red-600'">
                                <template x-if="systemInfo.jwtEnvEnabled === '1' && settings.jwtSettingEnabled === '1'">
                                    <span>{{ __('Active') }}</span>
                                </template>
                                <template x-if="systemInfo.jwtEnvEnabled !== '1'">
                                    <span>{{ __('Disabled (env)') }}</span>
                                </template>
                                <template x-if="systemInfo.jwtEnvEnabled === '1' && settings.jwtSettingEnabled !== '1'">
                                    <span>{{ __('Disabled (setting)') }}</span>
                                </template>
                            </p>
                        </div>
                    </div>

                    <!-- Public Meta Status -->
                    <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                        <div class="attention w-3 h-3 rounded-full mr-3"
                             :class="settings.metaEndpointEnabled === '1' ? 'bg-green-500' : 'bg-red-500'"></div>
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ __('Public Meta') }}</p>
                            <p class="attention text-xs"
                               :class="settings.metaEndpointEnabled === '1' ? 'text-green-600' : 'text-red-600'"
                               x-text="settings.metaEndpointEnabled === '1' ? @js(__('Enabled')) : @js(__('Disabled'))"></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-6 pt-[0]">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h4 class="text-sm font-semibold text-blue-900 mb-2">
                        <i class="fas fa-info-circle mr-1"></i>{{ __('About API settings') }}
                    </h4>
                    <div class="text-xs text-blue-800 space-y-1">
                        <p>{{ __('You can find these API settings on the') }} <strong><a href="/api-docs" title="{{ __('API Settings & Documentation') }}">{{ __('API page') }}</a></strong></p>
                    </div>
                </div>
            </div>

        </div>


        <!-- Disk Usage -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-hard-drive mr-2"></i>{{ __('Disk Usage') }}
                </h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">{{ __('Storage (app/)') }}</span>
                        <span class="text-sm font-semibold text-gray-900" x-text="formatBytes({{ $diskUsage['storage_size'] }})"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">{{ __('Logs') }}</span>
                        <span class="text-sm font-semibold text-gray-900" x-text="formatBytes({{ $diskUsage['logs_size'] }})"></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm text-gray-600">{{ __('Cache') }}</span>
                        <span class="text-sm font-semibold text-gray-900" x-text="formatBytes({{ $diskUsage['cache_size'] }})"></span>
                    </div>
                    <div class="flex justify-between items-center pt-4 border-t border-gray-200">
                        <span class="text-sm font-semibold text-gray-900">{{ __('Total Storage') }}</span>
                        <span class="text-lg font-bold text-gray-900" x-text="formatBytes({{ $diskUsage['total_size'] }})"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Tab -->
    <div x-show="activeTab === 'settings'" class="space-y-6">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-sliders-h mr-2"></i>{{ __('Application Settings') }}
                </h3>
                <p class="text-sm text-gray-500 mt-1">{{ __('Configure global application settings') }}</p>
            </div>
            <div class="grid gap-6 p-6 space-y-6">

                <!-- S3 Storage Settings -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">
                        <i class="fab fa-aws mr-2 text-gray-500"></i>{{ __('S3 Storage') }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- S3 Root Folder -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Root folder prefix') }}
                            </label>
                            <input type="text"
                                   x-model="settings.s3_root_folder"
                                   @change="updateSetting('s3_root_folder', settings.s3_root_folder)"
                                   placeholder=""
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                            <p class="text-xs text-gray-500 mt-1">{{ __('S3 prefix for root folder view & uploads. Leave empty for bucket root.') }}</p>
                            <p class="text-xs text-amber-600 mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>{{ __('Changing this does not move existing assets.') }}</p>
                        </div>

                        <!-- Custom Domain -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Custom domain') }}
                            </label>
                            <input type="text"
                                   x-model="settings.custom_domain"
                                   @change="updateSetting('custom_domain', settings.custom_domain)"
                                   placeholder="https://cdn.example.com"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                            <p class="text-xs text-gray-500 mt-1">{{ __('Replaces the S3 bucket domain in asset URLs. Leave empty to use the default S3 URL.') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Display Settings -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">
                        <i class="fas fa-desktop mr-2 text-gray-500"></i>{{ __('Display Settings') }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Items Per Page -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Items per page') }}
                            </label>
                            <select x-model="settings.items_per_page"
                                    @change="updateSetting('items_per_page', settings.items_per_page)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                <option value="12">12</option>
                                <option value="24">24</option>
                                <option value="36">36</option>
                                <option value="48">48</option>
                                <option value="60">60</option>
                                <option value="72">72</option>
                                <option value="96">96</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ __('Number of assets displayed per page in the asset grid') }}</p>
                        </div>

                        <!-- Timezone -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Timezone') }}
                            </label>
                            <select x-model="settings.timezone"
                                    @change="updateSetting('timezone', settings.timezone)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                @php
                                    $groupedTimezones = [];
                                    foreach ($availableTimezones as $tz) {
                                        $parts = explode('/', $tz, 2);
                                        $region = count($parts) > 1 ? $parts[0] : 'Other';
                                        $groupedTimezones[$region][] = $tz;
                                    }
                                @endphp
                                @foreach($groupedTimezones as $region => $timezones)
                                    <optgroup label="{{ $region }}">
                                        @foreach($timezones as $tz)
                                            <option value="{{ $tz }}">{{ $tz }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ __('Application timezone for displaying dates and timestamps') }}</p>
                        </div>

                        <!-- UI Language -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('UI Language') }}
                            </label>
                            <select x-model="settings.locale"
                                    @change="updateSetting('locale', settings.locale)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                @foreach($availableUiLanguages as $code => $label)
                                    <option value="{{ $code }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ __('Application interface language for all users (users can override in their profile)') }}</p>
                        </div>
                    </div>
                </div>

                <!-- AWS Rekognition Settings -->
                <div>
                    <h4 class="text-md font-semibold text-gray-800 mb-4 pb-2 border-b border-gray-200">
                        <i class="fab fa-aws mr-2 text-gray-500"></i>{{ __('AWS Rekognition Settings') }}
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Max Labels -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Maximum AI tags per asset') }}
                            </label>
                            <input type="number"
                                   x-model="settings.rekognition_max_labels"
                                   @change="updateSetting('rekognition_max_labels', settings.rekognition_max_labels)"
                                   min="1"
                                   max="20"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                            <p class="text-xs text-gray-500 mt-1">{{ __('Maximum number of AI-generated tags per asset (1-20)') }}</p>
                        </div>

                        <!-- Language -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('AI tag language') }}
                            </label>
                            <select x-model="settings.rekognition_language"
                                    @change="updateSetting('rekognition_language', settings.rekognition_language)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                @foreach($availableLanguages as $code => $name)
                                    <option value="{{ $code }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ __('Language for AI-generated tags (uses AWS Translate for non-English)') }}</p>
                        </div>

                        <!-- Min Confidence -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                {{ __('Minimum confidence threshold') }}
                            </label>
                            <select x-model="settings.rekognition_min_confidence"
                                    @change="updateSetting('rekognition_min_confidence', settings.rekognition_min_confidence)"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                @for($i = 65; $i <= 99; $i++)
                                    <option value="{{ $i }}">{{ $i }}.0%</option>
                                @endfor
                            </select>
                            <p class="text-xs text-gray-500 mt-1">{{ __('Minimum confidence level for AI-detected labels (65-99%)') }}</p>
                        </div>
                    </div>
                </div>

                <!-- Status Messages -->
                <div x-show="settingsSaved" x-transition class="attention mt-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
                    <i class="fas fa-check-circle mr-2"></i>{{ __('Settings saved successfully') }}
                </div>
                <div x-show="settingsError" x-transition class="attention mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
                    <i class="fas fa-exclamation-circle mr-2"></i><span x-text="settingsError"></span>
                </div>
            </div>
        </div>

        <!-- Info Box -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-blue-900 mb-2">
                <i class="fas fa-info-circle mr-1"></i>{{ __('About Settings') }}
            </h4>
            <div class="text-xs text-blue-800 space-y-1">
                <p>{{ __('Changes are saved automatically when you modify a setting.') }}</p>
                <p>{{ __('Some settings (like language) only affect new AI tags, not existing ones.') }}</p>
                <p>{{ __('AWS Rekognition must be enabled in your environment configuration for AI tagging to work.') }}</p>
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
                        <p class="text-sm font-medium text-gray-500">{{ __('Pending Jobs') }}</p>
                        <p class="attention text-3xl font-bold text-blue-600" x-text="queueStats.pending"></p>
                    </div>
                    <i class="fas fa-clock text-4xl text-blue-200"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Failed Jobs') }}</p>
                        <p class="attention text-3xl font-bold text-red-600" x-text="queueStats.failed"></p>
                    </div>
                    <i class="fas fa-exclamation-triangle text-4xl text-red-200"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Batches') }}</p>
                        <p class="text-3xl font-bold text-purple-600" x-text="queueStats.batches"></p>
                    </div>
                    <i class="fas fa-layer-group text-4xl text-purple-200"></i>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <button @click="refreshQueueStatus()"
                        class="w-full h-full flex items-center justify-center text-gray-600 hover:text-gray-900">
                    <i class="fas fa-sync-alt text-2xl" :class="{'fa-spin': loadingQueue}"></i>
                    <span class="ml-2">{{ __('Refresh') }}</span>
                </button>
            </div>
        </div>

        <!-- Queue Controls -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-sliders-h mr-2"></i>{{ __('Queue Controls') }}
            </h3>
            <div class="actions flex flex-wrap gap-3">
                <button @click="retryAllFailedJobs()"
                        class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                        :disabled="queueStats.failed === 0">
                    <i class="fas fa-redo mr-2"></i>{{ __('Retry All Failed') }}
                </button>

                <button @click="flushFailedJobs()"
                        class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 disabled:opacity-50"
                        :disabled="queueStats.failed === 0">
                    <i class="fas fa-trash mr-2"></i>{{ __('Flush Failed') }}
                </button>

                <button @click="restartWorkers()"
                        class="px-4 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover">
                    <i class="fas fa-power-off mr-2"></i>{{ __('Restart Workers') }}
                </button>
            </div>

            <div class="mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <h4 class="text-sm font-semibold text-blue-900 mb-2">
                    <i class="fas fa-info-circle mr-1"></i>{{ __('Queue Worker Setup') }}
                </h4>
                <div class="text-xs text-blue-800 space-y-1">
                    <p><strong>{{ __('Development:') }}</strong> {{ __('Run manually in terminal:') }} <code class="bg-blue-100 px-1 py-0.5 rounded">php artisan queue:work --tries=3</code></p>
                    <p><strong>{{ __('Production:') }}</strong> {{ __('Use supervisor to manage persistent workers. See') }} <code class="bg-blue-100 px-1 py-0.5 rounded">DEPLOYMENT.md</code> {{ __('for setup instructions.') }}</p>
                    <p><strong>{{ __('Config file:') }}</strong> <code class="bg-blue-100 px-1 py-0.5 rounded">deploy/supervisor/orca-queue-worker.conf</code></p>
                </div>
            </div>
        </div>

        <!-- Supervisor Status -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-server mr-2"></i>{{ __('Supervisor Status') }}
                </h3>
                <button @click="refreshSupervisorStatus()"
                        class="attention px-3 py-1 text-sm bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                    <i class="fas fa-sync-alt mr-1" :class="{'fa-spin': loadingSupervisor}"></i>{{ __('Refresh') }}
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
                        <span class="font-semibold text-gray-700">{{ __('Total Workers:') }}</span>
                        <span class="ml-2 text-gray-900" x-text="supervisorStatus.total"></span>
                    </div>
                    <div class="flex items-center">
                        <span class="font-semibold text-gray-700">{{ __('Running:') }}</span>
                        <span class="attention ml-2 px-2 py-0.5 bg-green-100 text-green-700 rounded-full text-xs font-semibold" x-text="supervisorStatus.running"></span>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Worker Name') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Status') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('PID') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Uptime') }}</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <template x-for="worker in supervisorStatus.workers" :key="worker.name">
                                <tr>
                                    <td class="px-4 py-3 text-sm font-mono text-gray-900" x-text="worker.name"></td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="attention px-2 py-1 text-xs font-semibold rounded-full"
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
                    <i class="fas fa-exclamation-circle mr-2"></i>{{ __('Failed Jobs') }}
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('UUID') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Queue') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Exception') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Failed At') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
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
                                        <i class="fas fa-redo mr-1"></i>{{ __('Retry') }}
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
            <div class="px-6 py-6 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-file-lines mr-2"></i>{{ __('Laravel Log Viewer') }}
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">{{ __('See what\'s going on') }}</p>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <select x-model="logLines" @change="refreshLogs()"
                            class="rounded-md border-gray-300 text-sm">
                        <option value="20">{{ __('20 lines') }}</option>
                        <option value="50">{{ __('50 lines') }}</option>
                        <option value="100">{{ __('100 lines') }}</option>
                        <option value="200">{{ __('200 lines') }}</option>
                    </select>
                    <button @click="refreshLogs()"
                            class="px-4 py-2 text-sm bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        <i class="fas fa-sync-alt mr-2" :class="{'fa-spin': loadingLogs}"></i>{{ __('Refresh') }}
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div x-show="logData.exists" class="space-y-4">
                    <div class="text-sm text-gray-600">
                        <span class="font-semibold">{{ __('File:') }}</span> <span class="break-all font-mono" x-text="logData.path"></span><br>
                        <span class="font-semibold">{{ __('Size:') }}</span> <span x-text="formatBytes(logData.size)"></span>
                    </div>
                    <div class="attention bg-gray-900 rounded-lg p-4 overflow-x-auto">
                        <pre class="text-xs text-green-400 font-mono"><template x-for="(line, index) in logData.lines" :key="index"><div x-text="line" :class="getLogLineColor(line)"></div></template></pre>
                    </div>
                </div>
                <div x-show="!logData.exists" class="text-center py-8 text-gray-500">
                    <i class="fas fa-inbox text-4xl mb-3"></i>
                    <p>{{ __('Click the refresh button') }}</p>
                    <p>{{ __('Choose the amount of lines at the top right.') }}</p>
                    <button @click="refreshLogs()"
                            class="px-4 py-2 mt-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        <i class="fas fa-sync-alt mr-2" :class="{'fa-spin': loadingLogs}"></i>{{ __('Refresh') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Commands Tab -->
    <div x-show="activeTab === 'commands'" class="space-y-6">
        <!-- Command Input -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-6 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-terminal mr-2"></i>{{ __('Execute Artisan Command') }}
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">{{ __('Manage caching, run migrations, etc.') }}</p>
                </div>

                <div class="flex flex-col sm:flex-row items-stretch sm:items-top gap-3">
                    <div>
                        <!--<label class="block text-sm font-medium text-gray-700 mb-2">Command</label>-->
                        <input type="text"
                               x-model="customCommand"
                               @keydown.enter="executeCustomCommand()"
                               placeholder="{{ __('e.g., cache:clear') }}"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg font-mono text-sm">
                        <p class="text-xs text-gray-500 mt-1">
                            <i class="fas fa-shield-alt mr-1"></i>
                            {{ __('Only whitelisted commands are allowed for security.') }}
                        </p>
                    </div>
                    <button @click="executeCustomCommand()"
                            :disabled="!customCommand || executingCommand"
                            class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover disabled:opacity-50">
                        <i class="fas mr-2" :class="executingCommand ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                        {{ __('Execute') }}
                    </button>
                </div>

            </div>

            <div class="space-y-4  p-6">
                <!-- Command Output -->
                <div x-show="commandOutput" class="mt-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Output') }}</label>
                    <div class="attention bg-gray-900 rounded-lg p-4 overflow-x-auto">
                    <pre class="text-xs font-mono"
                         :class="commandSuccess ? 'text-green-400' : 'text-red-400'"
                         x-text="commandOutput"></pre>
                    </div>
                </div>
            </div>

        </div>

        <!-- Suggested Commands -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-list mr-2"></i>{{ __('Suggested Commands') }}
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                @foreach($suggestedCommands as $cmd)
                    <div class="flex flex-col justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 h-full">
                        <div class="flex-grow mb-2">
                            <code class="text-sm font-mono text-gray-900 break-all">{{ $cmd['command'] }}</code>
                            <p class="text-xs text-gray-500 mt-1">{{ $cmd['description'] }}</p>
                        </div>
                        <button @click="customCommand = '{{ $cmd['command'] }}'; executeCustomCommand()"
                                class="w-full px-3 py-1.5 text-sm bg-orca-black text-white rounded hover:bg-orca-black-hover">
                            <i class="fas fa-play mr-1"></i>{{ __('Run') }}
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
                <i class="fas fa-cog mr-2"></i>{{ __('System Configuration') }}
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Queue Driver:') }}</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['queue_driver'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Cache Driver:') }}</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['cache_driver'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Session Driver:') }}</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['session_driver'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Storage Disk:') }}</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['storage_disk'] }}</span>
                    </div>
                </div>
                <div class="space-y-3">
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Timezone:') }}</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['timezone'] }}</span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Debug Mode:') }}</span>
                        <span class="attention text-sm font-semibold {{ $systemInfo['debug_mode'] ? 'text-red-600' : 'text-green-600' }}">
                            {{ $systemInfo['debug_mode'] ? __('Enabled') : __('Disabled') }}
                        </span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Rekognition:') }}</span>
                        <span class="attention text-sm font-semibold {{ $systemInfo['rekognition_enabled'] ? 'text-green-600' : 'text-gray-400' }}">
                            {{ $systemInfo['rekognition_enabled'] ? __('Enabled') : __('Disabled') }}
                        </span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-gray-200">
                        <span class="text-sm text-gray-600">{{ __('Max Execution:') }}</span>
                        <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['max_execution_time'] }}s</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- PHP Configuration -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fab fa-php mr-2"></i>{{ __('PHP Configuration') }}
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">{{ __('Memory Limit:') }}</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['memory_limit'] }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">{{ __('Upload Max Filesize:') }}</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['upload_max_filesize'] }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">{{ __('Post Max Size:') }}</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['post_max_size'] }}</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">{{ __('Max Execution Time:') }}</span>
                    <span class="text-sm font-semibold text-gray-900">{{ $systemInfo['max_execution_time'] }}s</span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">{{ __('GD Extension:') }}</span>
                    <span class="attention text-sm font-semibold {{ $systemInfo['gd_enabled'] ? 'text-green-600' : 'text-red-600' }}">
                        {{ $systemInfo['gd_enabled'] ? __('Enabled') : __('Not Available') }}
                    </span>
                </div>
                <div class="flex justify-between py-2 border-b border-gray-200">
                    <span class="text-sm text-gray-600">{{ __('Imagick Extension:') }}</span>
                    <span class="attention text-sm font-semibold {{ $systemInfo['imagick_enabled'] ? 'text-green-600' : 'text-gray-500' }}">
                        {{ $systemInfo['imagick_enabled'] ? __('Enabled') : __('Not Available') }}
                    </span>
                </div>
            </div>
        </div>

        <!-- Connection Tests -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">
                <i class="fas fa-plug mr-2"></i>{{ __('Connection Tests') }}
            </h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div>
                        <p class="text-sm font-semibold text-gray-900">{{ __('Amazon S3') }}</p>
                        <p class="text-xs text-gray-500">{{ __('Test S3 bucket connectivity') }}</p>
                    </div>
                    <button @click="testS3Connection()"
                            :disabled="testingS3"
                            class="test px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50">
                        <i class="fas mr-2" :class="testingS3 ? 'fa-spinner fa-spin' : 'fa-vial'"></i>
                        {{ __('Test') }}
                    </button>
                </div>
                <div x-show="s3TestResult" class="p-3 rounded-lg"
                     :class="s3TestSuccess ? 'bg-green-50' : 'bg-red-50'">
                    <p class="attention text-sm" :class="s3TestSuccess ? 'text-green-800' : 'text-red-800'"
                       x-text="s3TestMessage"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Documentation Tab -->
    <div x-show="activeTab === 'documentation'" x-init="$watch('activeTab', value => { if (value === 'documentation' && !docContent && !docError) loadDocumentation(); })" class="space-y-6">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-6 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-book mr-2"></i>{{ __('Project Documentation') }}
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">{{ __('Check out ORCA\'s documentation') }}</p>
                </div>
                <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                    <select x-model="selectedDoc"
                            @change="loadDocumentation()"
                            class="rounded-md border-gray-300 text-sm">
                        <option value="USER_MANUAL.md">USER_MANUAL.md</option>
                        <option value="CLAUDE.md">CLAUDE.md</option>
                        <option value="DEPLOYMENT.md">DEPLOYMENT.md</option>
                        <option value="QUICK_REFERENCE.md">QUICK_REFERENCE.md</option>
                        <option value="README.md">README.md</option>
                        <option value="RTE_INTEGRATION.md">RTE_INTEGRATION.md</option>
                        <option value="SETUP_GUIDE.md">SETUP_GUIDE.md</option>
                    </select>
                    <button @click="loadDocumentation()"
                            class="px-4 py-2 text-sm bg-gray-600 text-white rounded-lg hover:bg-gray-700">
                        <i class="fas fa-sync-alt mr-2" :class="{'fa-spin': loadingDoc}"></i>{{ __('Refresh') }}
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div x-show="loadingDoc" class="text-center py-8 text-gray-500">
                    <i class="fas fa-spinner fa-spin text-4xl mb-3"></i>
                    <p>{{ __('Loading documentation...') }}</p>
                </div>
                <div x-show="!loadingDoc && docError" class="text-center py-8 text-red-500">
                    <i class="fas fa-exclamation-circle text-4xl mb-3"></i>
                    <p x-text="docError"></p>
                </div>
                <div x-show="!loadingDoc && !docError && docContent" class="prose-doc">
                    <div x-html="docContent"></div>
                </div>
                <div x-show="!loadingDoc && !docError && !docContent" class="text-center py-8 text-gray-500">
                    <i class="fas fa-book-open text-4xl mb-3"></i>
                    <p>{{ __('Select a documentation file to view its contents.') }}</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tests Tab -->
    <div x-show="activeTab === 'tests'" class="space-y-6">
        <!-- Test Runner Controls -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-vial mr-2"></i>{{ __('Test Runner') }}
                    </h3>
                    <p class="text-sm text-gray-500 mt-1">{{ __('Run automated tests for the application') }}</p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3">
                    <select x-model="testSuite"
                            class="rounded-lg border-gray-300 text-sm">
                        <option value="all">{{ __('All Tests') }}</option>
                        <option value="unit">{{ __('Unit Tests') }}</option>
                        <option value="feature">{{ __('Feature Tests') }}</option>
                    </select>
                    <input type="text"
                           x-model="testFilter"
                           placeholder="{{ __('Filter by name...') }}"
                           class="rounded-lg border-gray-300 text-sm px-3 py-2">
                    <button @click="runTests()"
                            :disabled="runningTests"
                            class="test px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center justify-center">
                        <i class="fas mr-2" :class="runningTests ? 'fa-spinner fa-spin' : 'fa-play'"></i>
                        <span x-text="runningTests ? @js(__('Running...')) : @js(__('Run Tests'))"></span>
                    </button>
                </div>
            </div>

            <!-- Progress Bar -->
            <div x-show="runningTests" class="mb-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm text-gray-600">{{ __('Running tests...') }}</span>
                    <span class="text-sm text-gray-600" x-text="Math.round(testProgress) + '%'"></span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div class="attention bg-green-600 h-2.5 rounded-full transition-all duration-300"
                         :style="'width: ' + Math.round(testProgress) + '%'"></div>
                </div>
            </div>

            <!-- Test Statistics Cards -->
            <div x-show="testStats.total > 0" class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <p class="text-3xl font-bold text-gray-900" x-text="testStats.total"></p>
                    <p class="text-sm text-gray-500">{{ __('Total Tests') }}</p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <p class="attention text-3xl font-bold text-green-600" x-text="testStats.passed"></p>
                    <p class="attention text-sm text-gray-500">{{ __('Passed') }}</p>
                </div>
                <div class="rounded-lg p-4 text-center" :class="testStats.failed > 0 ? 'bg-red-50' : 'bg-gray-50'">
                    <p class="attention text-3xl font-bold" :class="testStats.failed > 0 ? 'text-red-600' : 'text-gray-400'" x-text="testStats.failed"></p>
                    <p class="attention text-sm text-gray-500">{{ __('Failed') }}</p>
                </div>
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <p class="text-3xl font-bold text-blue-600" x-text="testStats.assertions"></p>
                    <p class="text-sm text-gray-500">{{ __('Assertions') }}</p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 text-center">
                    <p class="text-3xl font-bold text-purple-600" x-text="testStats.duration + 's'"></p>
                    <p class="text-sm text-gray-500">{{ __('Duration') }}</p>
                </div>
            </div>

            <!-- Success/Failure Banner -->
            <div x-show="testStats.total > 0 && !runningTests" class="attention mb-6">
                <div x-show="testStats.failed === 0" class="p-4 bg-green-100 border border-green-300 rounded-lg flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-check-circle text-3xl text-green-600"></i>
                    </div>
                    <div class="ml-4">
                        <h4 class="text-lg font-semibold text-green-800">{{ __('All Tests Passed!') }}</h4>
                        <p class="text-sm text-green-700">
                            <span x-text="testStats.passed"></span> {{ __('tests completed successfully with') }}
                            <span x-text="testStats.assertions"></span> {{ __('assertions.') }}
                        </p>
                    </div>
                </div>
                <div x-show="testStats.failed > 0" class="p-4 bg-red-100 border border-red-300 rounded-lg flex items-center">
                    <div class="flex-shrink-0">
                        <i class="fas fa-times-circle text-3xl text-red-600"></i>
                    </div>
                    <div class="ml-4">
                        <h4 class="text-lg font-semibold text-red-800">{{ __('Tests Failed') }}</h4>
                        <p class="text-sm text-red-700">
                            <span x-text="testStats.failed"></span> {{ __('test(s) failed out of') }}
                            <span x-text="testStats.total"></span> {{ __('total tests.') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Test Output -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-terminal mr-2"></i>{{ __('Test Output') }}
                </h3>
                <div class="flex items-center gap-2">
                    <button x-show="testOutput"
                            @click="copyTestOutput()"
                            class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        <i class="fas fa-copy mr-1"></i>{{ __('Copy') }}
                    </button>
                    <button x-show="testOutput"
                            @click="testOutput = ''; testStats = {total: 0, passed: 0, failed: 0, skipped: 0, assertions: 0, duration: 0, tests: []}"
                            class="px-3 py-1.5 text-sm bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                        <i class="fas fa-trash mr-1"></i>{{ __('Clear') }}
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div x-show="!testOutput && !runningTests" class="text-center py-12 text-gray-500">
                    <i class="fas fa-flask text-6xl mb-4 text-gray-300"></i>
                    <p class="text-lg">{{ __('No tests have been run yet') }}</p>
                    <p class="text-sm mt-2">{{ __('Click "Run Tests" to execute the test suite') }}</p>
                </div>
                <div x-show="testOutput || runningTests" class="attention bg-gray-900 rounded-lg p-4 overflow-x-auto max-h-[600px] overflow-y-auto">
                    <pre class="text-sm font-mono whitespace-pre-wrap text-gray-100" x-html="formatTestOutput(testOutput)"></pre>
                </div>
            </div>
        </div>

        <!-- Test Files Overview -->
        <div x-show="testStats.tests && testStats.tests.length > 0" class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-list-check mr-2"></i>{{ __('Test Results by Suite') }}
                </h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <template x-for="(tests, suite) in groupTestsBySuite()" :key="suite">
                        <div class="rounded-lg overflow-hidden"
                             :class="tests.some(t => t.status === 'failed') ? 'border-2 border-red-300' : 'border border-gray-200'">
                            <div class="px-4 py-3 flex items-center justify-between cursor-pointer"
                                 :class="tests.some(t => t.status === 'failed') ? 'bg-red-50' : 'bg-gray-50'"
                                 @click="toggleSuite(suite)">
                                <div class="flex items-center gap-2">
                                    <i class="attention fas fa-chevron-right text-gray-400 transition-transform"
                                       :class="{'rotate-90': expandedSuites.includes(suite)}"></i>
                                    <span class="font-medium text-gray-900" x-text="suite"></span>
                                </div>
                                <div class="attention flex items-center gap-2">
                                    <span class="px-2 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-700"
                                          x-text="tests.filter(t => t.status === 'passed').length + @js(' ' . __('passed'))"></span>
                                    <span x-show="tests.filter(t => t.status === 'failed').length > 0"
                                          class="px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-700"
                                          x-text="tests.filter(t => t.status === 'failed').length + @js(' ' . __('failed'))"></span>
                                </div>
                            </div>
                            <div x-show="expandedSuites.includes(suite)" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" class="border-t border-gray-200">
                                <div class="divide-y divide-gray-100">
                                    <template x-for="test in tests" :key="test.name">
                                        <div class="px-4 py-2 flex items-center gap-3 text-sm"
                                             :class="test.status === 'failed' ? 'bg-red-50' : ''">
                                            <i class="attention fas"
                                               :class="test.status === 'passed' ? 'fa-check text-green-500' : 'fa-times text-red-500'"></i>
                                            <span :class="test.status === 'failed' ? 'text-red-700 font-medium' : 'text-gray-700'" x-text="test.name"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Quick Info -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-blue-900 mb-2">
                <i class="fas fa-info-circle mr-1"></i>{{ __('About Testing') }}
            </h4>
            <div class="text-xs text-blue-800 space-y-1">
                <p>{{ __('Tests are run using') }} <strong>Pest PHP</strong>{{ __(', a testing framework built on PHPUnit.') }}</p>
                <p><strong>{{ __('Unit Tests:') }}</strong> {{ __('Test individual components in isolation (models, services).') }}</p>
                <p><strong>{{ __('Feature Tests:') }}</strong> {{ __('Test complete HTTP requests and responses (controllers, routes).') }}</p>
                <p>{{ __('Tests run against an in-memory SQLite database, so they don\'t affect your real data.') }}</p>
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

        // Settings
        settings: {
            items_per_page: '{{ collect($settings)->firstWhere('key', 'items_per_page')['value'] ?? '24' }}',
            timezone: '{{ collect($settings)->firstWhere('key', 'timezone')['value'] ?? 'UTC' }}',
            locale: '{{ collect($settings)->firstWhere('key', 'locale')['value'] ?? 'en' }}',
            s3_root_folder: '{{ collect($settings)->firstWhere('key', 's3_root_folder')['value'] ?? 'assets' }}',
            custom_domain: '{{ collect($settings)->firstWhere('key', 'custom_domain')['value'] ?? '' }}',
            rekognition_max_labels: '{{ collect($settings)->firstWhere('key', 'rekognition_max_labels')['value'] ?? '5' }}',
            rekognition_language: '{{ collect($settings)->firstWhere('key', 'rekognition_language')['value'] ?? 'en' }}',
            rekognition_min_confidence: '{{ collect($settings)->firstWhere('key', 'rekognition_min_confidence')['value'] ?? '80' }}',
            jwtSettingEnabled: '{{ collect($settings)->firstWhere('key', 'jwt_enabled_override')['value'] ?? '0' }}',
            metaEndpointEnabled: '{{ collect($settings)->firstWhere('key', 'api_meta_endpoint_enabled')['value'] ?? '0' }}',
        },
        settingsSaved: false,
        settingsError: '',
        savingSettings: false,

        systemInfo: {
          jwtEnvEnabled: '{{$systemInfo['jwt_enabled']}}',
        },

        // Documentation
        selectedDoc: 'USER_MANUAL.md',
        docContent: '',
        docError: '',
        loadingDoc: false,

        // Tests
        testSuite: 'all',
        testFilter: '',
        runningTests: false,
        testOutput: '',
        testProgress: 0,
        testStats: {
            total: 0,
            passed: 0,
            failed: 0,
            skipped: 0,
            assertions: 0,
            duration: 0,
            tests: []
        },
        expandedSuites: [],

        validTabs: ['overview', 'settings', 'queue', 'logs', 'commands', 'diagnostics', 'documentation', 'tests'],

        init() {
            // Set active tab from URL hash
            const hash = window.location.hash.substring(1);
            if (hash && this.validTabs.includes(hash)) {
                this.activeTab = hash;
            }

            // Update hash when tab changes
            this.$watch('activeTab', (tab) => {
                const newHash = '#' + tab;
                if (window.location.hash !== newHash) {
                    history.pushState(null, '', newHash);
                }
            });

            // Handle browser back/forward
            window.addEventListener('popstate', () => {
                const hash = window.location.hash.substring(1);
                if (hash && this.validTabs.includes(hash)) {
                    this.activeTab = hash;
                } else {
                    this.activeTab = 'overview';
                }
            });

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
                window.showToast(@js(__('Failed to refresh queue status')), 'error');
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
                window.showToast(@js(__('Failed to refresh logs')), 'error');
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
                this.commandOutput = result.output || result.error || @js(__('No output'));

                if (result.success) {
                    window.showToast(@js(__('Command executed successfully')), 'success');
                } else {
                    window.showToast(@js(__('Command failed')), 'error');
                }
            } catch (error) {
                console.error('Failed to execute command:', error);
                this.commandSuccess = false;
                this.commandOutput = error.message;
                window.showToast(@js(__('Failed to execute command')), 'error');
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
                    window.showToast(@js(__('Job queued for retry')), 'success');
                    this.refreshQueueStatus();
                } else {
                    window.showToast(@js(__('Failed to retry job')), 'error');
                }
            } catch (error) {
                console.error('Failed to retry job:', error);
                window.showToast(@js(__('Failed to retry job')), 'error');
            }
        },

        async retryAllFailedJobs() {
            if (!confirm(@js(__('Retry all failed jobs?')))) return;

            this.customCommand = 'queue:retry all';
            await this.executeCustomCommand();
            setTimeout(() => this.refreshQueueStatus(), 1000);
        },

        async flushFailedJobs() {
            if (!confirm(@js(__('Delete all failed jobs? This cannot be undone.')))) return;

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
                    window.showToast(@js(__('Failed jobs flushed')), 'success');
                    this.refreshQueueStatus();
                } else {
                    window.showToast(@js(__('Failed to flush queue')), 'error');
                }
            } catch (error) {
                console.error('Failed to flush queue:', error);
                window.showToast(@js(__('Failed to flush queue')), 'error');
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
                    window.showToast(@js(__('Queue workers signaled to restart')), 'success');
                } else {
                    window.showToast(@js(__('Failed to restart workers')), 'error');
                }
            } catch (error) {
                console.error('Failed to restart workers:', error);
                window.showToast(@js(__('Failed to restart workers')), 'error');
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
                    message: @js(__('Failed to check supervisor status')),
                    workers: [],
                    total: 0,
                    running: 0
                };
            } finally {
                this.loadingSupervisor = false;
            }
        },

        async updateSetting(key, value) {
            this.savingSettings = true;
            this.settingsSaved = false;
            this.settingsError = '';

            try {
                const response = await fetch('{{ route('system.update-setting') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ key, value }),
                });

                const result = await response.json();

                if (result.success) {
                    this.settingsSaved = true;
                    window.showToast(@js(__('Setting saved')), 'success');
                    setTimeout(() => { this.settingsSaved = false; }, 3000);

                    // When root folder changes, refresh the folder hierarchy from S3
                    if (key === 's3_root_folder') {
                        await this.refreshFolderHierarchy();
                    }
                } else {
                    this.settingsError = result.error || @js(__('Failed to save setting'));
                    window.showToast(this.settingsError, 'error');
                }
            } catch (error) {
                console.error('Failed to update setting:', error);
                this.settingsError = @js(__('Failed to save setting'));
                window.showToast(@js(__('Failed to save setting')), 'error');
            } finally {
                this.savingSettings = false;
            }
        },

        async refreshFolderHierarchy() {
            try {
                const response = await fetch('{{ route('folders.scan') }}', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to refresh folders');
                }

                window.showToast(@js(__('Folder hierarchy refreshed from S3')), 'success');
            } catch (error) {
                console.error('Failed to refresh folder hierarchy:', error);
                window.showToast(@js(__('Failed to refresh folder hierarchy')), 'error');
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
                this.s3TestMessage = @js(__('Connection test failed:')) + ' ' + error.message;
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

        async loadDocumentation() {
            this.loadingDoc = true;
            this.docError = '';
            this.docContent = '';

            try {
                const response = await fetch(`{{ route('system.documentation') }}?file=${encodeURIComponent(this.selectedDoc)}`);
                const result = await response.json();

                if (result.success) {
                    this.docContent = result.content;
                } else {
                    this.docError = result.error || @js(__('Failed to load documentation'));
                }
            } catch (error) {
                console.error('Failed to load documentation:', error);
                this.docError = @js(__('Failed to load documentation:')) + ' ' + error.message;
            } finally {
                this.loadingDoc = false;
            }
        },

        async runTests() {
            this.runningTests = true;
            this.testOutput = '';
            this.testProgress = 0;
            this.testStats = {
                total: 0,
                passed: 0,
                failed: 0,
                skipped: 0,
                assertions: 0,
                duration: 0,
                tests: []
            };
            this.expandedSuites = [];

            // Simulate progress
            const progressInterval = setInterval(() => {
                if (this.testProgress < 90) {
                    this.testProgress += Math.random() * 10;
                }
            }, 500);

            try {
                const response = await fetch('{{ route('system.run-tests') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        suite: this.testSuite,
                        filter: this.testFilter || null,
                    }),
                });

                const result = await response.json();

                if (result.success) {
                    this.testOutput = result.output;
                    this.testStats = result.stats;
                    this.testProgress = 100;

                    // Auto-expand suites with failures
                    this.$nextTick(() => this.autoExpandFailedSuites());

                    if (result.stats.failed > 0) {
                        window.showToast(@js(__('Tests completed:')) + ' ' + result.stats.failed + ' ' + @js(__('failed')), 'error');
                    } else {
                        window.showToast(@js(__('All')) + ' ' + result.stats.passed + ' ' + @js(__('tests passed!')), 'success');
                    }
                } else {
                    this.testOutput = result.error || @js(__('Failed to run tests'));
                    window.showToast(@js(__('Failed to run tests')), 'error');
                }
            } catch (error) {
                console.error('Failed to run tests:', error);
                this.testOutput = 'Error: ' + error.message;
                window.showToast(@js(__('Failed to run tests')), 'error');
            } finally {
                clearInterval(progressInterval);
                this.runningTests = false;
            }
        },

        formatTestOutput(output) {
            if (!output) return '';

            // Escape HTML first
            let formatted = output
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');

            // Color code different parts
            formatted = formatted
                // Pass markers
                .replace(/PASS/g, '<span class="text-green-400 font-bold">PASS</span>')
                // Fail markers
                .replace(/FAIL/g, '<span class="text-red-400 font-bold">FAIL</span>')
                // Checkmarks (passed tests)
                .replace(/✓/g, '<span class="text-green-400">✓</span>')
                // X marks (failed tests)
                .replace(/✗|×/g, '<span class="text-red-400">✗</span>')
                // Test duration
                .replace(/(\d+\.\d+s)/g, '<span class="text-gray-500">$1</span>')
                // Tests summary line
                .replace(/(Tests:\s*)(\d+\s*passed)/gi, '$1<span class="text-green-400 font-bold">$2</span>')
                .replace(/(\d+\s*failed)/gi, '<span class="text-red-400 font-bold">$1</span>')
                // Assertions
                .replace(/\((\d+\s*assertions?)\)/gi, '(<span class="text-blue-400">$1</span>)')
                // Duration line
                .replace(/(Duration:\s*)([\d.]+s)/gi, '$1<span class="text-purple-400">$2</span>')
                // Error messages
                .replace(/(Error|Exception|Failed)/gi, '<span class="text-red-400">$1</span>')
                // File paths in errors
                .replace(/(tests\/[^\s:]+:\d+)/g, '<span class="text-yellow-400">$1</span>');

            return formatted;
        },

        groupTestsBySuite() {
            if (!this.testStats.tests || this.testStats.tests.length === 0) {
                return {};
            }

            const grouped = {};
            for (const test of this.testStats.tests) {
                const suite = test.suite || 'Unknown';
                if (!grouped[suite]) {
                    grouped[suite] = [];
                }
                grouped[suite].push(test);
            }

            // Sort each suite's tests: failed first, then passed
            for (const suite in grouped) {
                grouped[suite].sort((a, b) => {
                    if (a.status === 'failed' && b.status !== 'failed') return -1;
                    if (a.status !== 'failed' && b.status === 'failed') return 1;
                    return 0;
                });
            }

            // Sort suites: those with failures first
            const sortedGrouped = {};
            const suites = Object.keys(grouped);
            suites.sort((a, b) => {
                const aHasFailures = grouped[a].some(t => t.status === 'failed');
                const bHasFailures = grouped[b].some(t => t.status === 'failed');
                if (aHasFailures && !bHasFailures) return -1;
                if (!aHasFailures && bHasFailures) return 1;
                return 0;
            });
            for (const suite of suites) {
                sortedGrouped[suite] = grouped[suite];
            }

            return sortedGrouped;
        },

        autoExpandFailedSuites() {
            if (!this.testStats.tests) return;

            const grouped = this.groupTestsBySuite();
            for (const suite in grouped) {
                if (grouped[suite].some(t => t.status === 'failed')) {
                    if (!this.expandedSuites.includes(suite)) {
                        this.expandedSuites.push(suite);
                    }
                }
            }
        },

        toggleSuite(suite) {
            const index = this.expandedSuites.indexOf(suite);
            if (index > -1) {
                this.expandedSuites.splice(index, 1);
            } else {
                this.expandedSuites.push(suite);
            }
        },

        copyTestOutput() {
            const text = this.testOutput;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    window.showToast(@js(__('Output copied to clipboard')), 'success');
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    this.fallbackCopyToClipboard(text);
                });
            } else {
                this.fallbackCopyToClipboard(text);
            }
        },

        fallbackCopyToClipboard(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            try {
                document.execCommand('copy');
                window.showToast(@js(__('Output copied to clipboard')), 'success');
            } catch (err) {
                console.error('Fallback copy failed:', err);
                window.showToast(@js(__('Failed to copy output')), 'error');
            }
            textArea.remove();
        }
    };
}
</script>
@endpush

@push('styles')
<style>
/* Documentation Markdown Viewer Styles */
.prose-doc {
    font-size: 0.9375rem;
    line-height: 1.7;
    color: #374151;
}

.prose-doc h1 {
    font-size: 2rem;
    font-weight: 700;
    color: #111827;
    margin-top: 0;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #e5e7eb;
}

.prose-doc h2 {
    font-size: 1.5rem;
    font-weight: 600;
    color: #1f2937;
    margin-top: 2rem;
    margin-bottom: 0.75rem;
    padding-bottom: 0.375rem;
    border-bottom: 1px solid #e5e7eb;
}

.prose-doc h3 {
    font-size: 1.25rem;
    font-weight: 600;
    color: #374151;
    margin-top: 1.5rem;
    margin-bottom: 0.5rem;
}

.prose-doc h4 {
    font-size: 1.1rem;
    font-weight: 600;
    color: #4b5563;
    margin-top: 1.25rem;
    margin-bottom: 0.5rem;
}

.prose-doc p {
    margin-top: 0.75rem;
    margin-bottom: 0.75rem;
}

.prose-doc a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 500;
}

.prose-doc a:hover {
    color: #1d4ed8;
    text-decoration: underline;
}

.prose-doc strong {
    font-weight: 600;
    color: #1f2937;
}

.prose-doc code {
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
    font-size: 0.875em;
    background-color: #f3f4f6;
    color: #dc2626;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    border: 1px solid #e5e7eb;
}

.prose-doc pre {
    background-color: #1f2937;
    color: #e5e7eb;
    padding: 1rem 1.25rem;
    border-radius: 0.5rem;
    overflow-x: auto;
    margin: 1rem 0;
    border: 1px solid #374151;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.prose-doc pre code {
    background-color: transparent;
    color: #a5f3fc;
    padding: 0;
    border: none;
    font-size: 0.8125rem;
    line-height: 1.6;
}

.prose-doc blockquote {
    border-left: 4px solid #3b82f6;
    background-color: #eff6ff;
    padding: 0.75rem 1rem;
    margin: 1rem 0;
    border-radius: 0 0.375rem 0.375rem 0;
    color: #1e40af;
    font-style: italic;
}

.prose-doc blockquote p {
    margin: 0;
}

.prose-doc ul {
    list-style-type: disc;
    padding-left: 1.5rem;
    margin: 0.75rem 0;
}

.prose-doc ol {
    list-style-type: decimal;
    padding-left: 1.5rem;
    margin: 0.75rem 0;
}

.prose-doc li {
    margin: 0.375rem 0;
    padding-left: 0.25rem;
}

.prose-doc li > ul,
.prose-doc li > ol {
    margin: 0.25rem 0;
}

.prose-doc hr {
    border: none;
    border-top: 2px solid #e5e7eb;
    margin: 2rem 0;
}

.prose-doc table {
    width: 100%;
    border-collapse: collapse;
    margin: 1rem 0;
    font-size: 0.875rem;
}

.prose-doc thead {
    background-color: #f9fafb;
}

.prose-doc th {
    text-align: left;
    padding: 0.75rem 1rem;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.prose-doc td {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid #e5e7eb;
}

.prose-doc tbody tr:hover {
    background-color: #f9fafb;
}

/* Responsive tables */
@media (max-width: 760px) {
    .prose-doc table {
        width: 100%;
        display: flex;
        flex-direction: column;
    }

    .prose-doc thead,
    .prose-doc tbody {
        display: flex;
        flex-direction: column;
    }

    .prose-doc tr {
        display: flex;
        width: 100%;
    }

    .prose-doc th,
    .prose-doc td {
        flex: 1; /* Distributes space evenly */
    }
}

.prose-doc img {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
    margin: 1rem 0;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

/* Task lists / checkboxes */
.prose-doc input[type="checkbox"] {
    margin-right: 0.5rem;
    accent-color: #2563eb;
}

/* Emoji support */
.prose-doc .emoji {
    font-size: 1.1em;
    vertical-align: middle;
}
</style>
@endpush
@endsection
