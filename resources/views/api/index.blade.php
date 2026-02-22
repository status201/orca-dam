@extends('layouts.app')

@section('title', __('API Docs & Management'))

@section('content')
<div x-data="apiDocs()" x-init="init()">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">{{ __('API Docs & Management') }}</h1>
        <p class="text-gray-600 mt-2">{{ __('Interactive API documentation and token management') }}</p>
    </div>

    <!-- Tab Navigation -->
    <div class="tab-navigation-container mb-6 border-b border-gray-200 w-full overflow-y-hidden overflow-x-auto">
        <nav class="-mb-px flex space-x-8">
            <button @click="activeTab = 'dashboard'; if (!dashboardLoaded) loadDashboard();"
                    :class="activeTab === 'dashboard' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-tachometer-alt mr-2"></i>{{ __('Dashboard') }}
            </button>

            <button @click="activeTab = 'swagger'; if (!swaggerLoaded) loadSwagger();"
                    :class="activeTab === 'swagger' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-book-open mr-2"></i>{{ __('Swagger') }}
            </button>

            <button @click="activeTab = 'tokens'; if (!tokensLoaded) loadTokens();"
                    :class="activeTab === 'tokens' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-key mr-2"></i>{{ __('API Tokens') }}
                <span x-show="tokenCount > 0"
                      class="ml-2 px-2 py-0.5 text-xs bg-purple-100 text-purple-700 rounded-full"
                      x-text="tokenCount"></span>
            </button>

            <button @click="activeTab = 'jwt'; if (!jwtLoaded) loadJwtSecrets();"
                    :class="activeTab === 'jwt' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-shield-alt mr-2"></i>{{ __('JWT Secrets') }}
                <span x-show="jwtSecretCount > 0"
                      class="ml-2 px-2 py-0.5 text-xs bg-green-100 text-green-700 rounded-full"
                      x-text="jwtSecretCount"></span>
            </button>
        </nav>
    </div>

    <!-- Dashboard Tab -->
    <div x-show="activeTab === 'dashboard'" class="space-y-6">
        <!-- Loading State -->
        <div x-show="loadingDashboard" class="p-12 text-center">
            <i class="fas fa-spinner fa-spin text-4xl text-gray-400 mb-4"></i>
            <p class="text-gray-600">{{ __('Loading dashboard...') }}</p>
        </div>

        <div x-show="!loadingDashboard" class="space-y-6">
            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">{{ __('API Tokens') }}</p>
                            <p class="text-3xl font-bold text-purple-600" x-text="dashboardData.tokenCount"></p>
                        </div>
                        <i class="fas fa-key text-4xl text-purple-200"></i>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">{{ __('JWT Secrets') }}</p>
                            <p class="text-3xl font-bold text-green-600" x-text="dashboardData.jwtSecretCount"></p>
                        </div>
                        <i class="fas fa-shield-alt text-4xl text-green-200"></i>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500">{{ __('API Users') }}</p>
                            <p class="text-3xl font-bold text-blue-600" x-text="dashboardData.apiUserCount"></p>
                        </div>
                        <i class="fas fa-robot text-4xl text-blue-200"></i>
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
                                <p class="text-sm font-medium text-gray-900">{{ __('Sanctum') }}</p>
                                <p class="attention text-xs text-green-600">{{ __('Active') }}</p>
                            </div>
                        </div>

                        <!-- JWT Status -->
                        <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                            <div class="attention w-3 h-3 rounded-full mr-3"
                                 :class="dashboardData.jwtEnvEnabled && dashboardData.jwtSettingEnabled ? 'bg-green-500' : 'bg-red-500'"></div>
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ __('JWT') }}</p>
                                <p class="attention text-xs"
                                   :class="dashboardData.jwtEnvEnabled && dashboardData.jwtSettingEnabled ? 'text-green-600' : 'text-red-600'">
                                    <template x-if="dashboardData.jwtEnvEnabled && dashboardData.jwtSettingEnabled">
                                        <span>{{ __('Active') }}</span>
                                    </template>
                                    <template x-if="!dashboardData.jwtEnvEnabled">
                                        <span>{{ __('Disabled (env)') }}</span>
                                    </template>
                                    <template x-if="dashboardData.jwtEnvEnabled && !dashboardData.jwtSettingEnabled">
                                        <span>{{ __('Disabled (setting)') }}</span>
                                    </template>
                                </p>
                            </div>
                        </div>

                        <!-- Public Meta Status -->
                        <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                            <div class="attention w-3 h-3 rounded-full mr-3"
                                 :class="dashboardData.metaEndpointEnabled ? 'bg-green-500' : 'bg-red-500'"></div>
                            <div>
                                <p class="text-sm font-medium text-gray-900">{{ __('Public Meta') }}</p>
                                <p class="attention text-xs"
                                   :class="dashboardData.metaEndpointEnabled ? 'text-green-600' : 'text-red-600'"
                                   x-text="dashboardData.metaEndpointEnabled ? @js(__('Enabled')) : @js(__('Disabled'))"></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- API Settings -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-cog mr-2"></i>{{ __('API Settings') }}
                    </h3>
                </div>
                <div class="p-6 space-y-6">
                    <!-- JWT Authentication Setting -->
                    <div class="flex items-start justify-between" x-show="dashboardData.jwtEnvEnabled">
                        <div class="flex-1">
                            <div class="flex items-center">
                                <h4 class="text-sm font-medium text-gray-900">{{ __('JWT Authentication') }}</h4>
                                <span class="attention ml-2 px-2 py-0.5 text-xs bg-green-100 text-green-700 rounded-full">{{ __('ENV Enabled') }}</span>
                            </div>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ __('Enable or disable JWT authentication for API requests. When disabled, only Sanctum tokens will work.') }}
                            </p>
                        </div>
                        <div class="ml-4">
                            <button @click="saveApiSetting('jwt_enabled_override', !dashboardData.jwtSettingEnabled)"
                                    :disabled="savingSettings"
                                    class="attention relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-orca-black focus:ring-offset-2"
                                    :class="dashboardData.jwtSettingEnabled ? 'bg-green-600' : 'bg-gray-200'"
                                    role="switch"
                                    :aria-checked="dashboardData.jwtSettingEnabled">
                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                      :class="dashboardData.jwtSettingEnabled ? 'translate-x-5' : 'translate-x-0'"></span>
                            </button>
                        </div>
                    </div>

                    <!-- JWT Disabled by ENV notice -->
                    <div x-show="!dashboardData.jwtEnvEnabled" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                        <div class="flex items-start">
                            <i class="attention fas fa-exclamation-triangle text-yellow-600 mt-0.5 mr-3"></i>
                            <div>
                                <h4 class="attention text-sm font-medium text-yellow-800">{{ __('JWT Disabled in Environment') }}</h4>
                                <p class="text-sm text-yellow-700 mt-1">
                                    {{ __('JWT authentication is disabled via') }} <code class="bg-yellow-100 px-1 rounded">JWT_ENABLED=false</code> {{ __('in your .env file.') }}
                                    {{ __('To enable JWT, set') }} <code class="bg-yellow-100 px-1 rounded">JWT_ENABLED=true</code> {{ __('and restart the application.') }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <hr class="border-gray-200">

                    <!-- Public Meta Endpoint Setting -->
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <h4 class="text-sm font-medium text-gray-900">{{ __('Public Meta Endpoint') }}</h4>
                            <p class="text-sm text-gray-500 mt-1">
                                {{ __('Enable or disable the public') }} <code class="bg-gray-100 px-1 rounded">/api/assets/meta</code> {{ __('endpoint.') }}
                                {{ __('This endpoint allows fetching asset metadata without authentication.') }}
                            </p>
                        </div>
                        <div class="ml-4">
                            <button @click="saveApiSetting('api_meta_endpoint_enabled', !dashboardData.metaEndpointEnabled)"
                                    :disabled="savingSettings"
                                    class="attention relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-orca-black focus:ring-offset-2"
                                    :class="dashboardData.metaEndpointEnabled ? 'bg-green-600' : 'bg-gray-200'"
                                    role="switch"
                                    :aria-checked="dashboardData.metaEndpointEnabled">
                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                      :class="dashboardData.metaEndpointEnabled ? 'translate-x-5' : 'translate-x-0'"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-link mr-2"></i>{{ __('Quick Links') }}
                    </h3>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <button @click="navigateToTab('swagger')"
                            class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors text-left">
                        <div class="flex-shrink-0 w-10 h-10 flex items-center justify-center bg-orange-100 rounded-lg mr-4">
                            <i class="fas fa-book-open text-orange-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">{{ __('Swagger Documentation') }}</p>
                            <p class="text-sm text-gray-500">{{ __('View interactive API docs') }}</p>
                        </div>
                    </button>
                    <button @click="navigateToTab('tokens')"
                            class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors text-left">
                        <div class="flex-shrink-0 w-10 h-10 flex items-center justify-center bg-purple-100 rounded-lg mr-4">
                            <i class="fas fa-key text-purple-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">{{ __('API Tokens') }}</p>
                            <p class="text-sm text-gray-500">{{ __('Manage Sanctum tokens') }}</p>
                        </div>
                    </button>
                    <button @click="navigateToTab('jwt')"
                            class="flex items-center p-4 bg-gray-50 rounded-lg hover:bg-gray-100 transition-colors text-left">
                        <div class="flex-shrink-0 w-10 h-10 flex items-center justify-center bg-green-100 rounded-lg mr-4">
                            <i class="fas fa-shield-alt text-green-600"></i>
                        </div>
                        <div>
                            <p class="font-medium text-gray-900">{{ __('JWT Secrets') }}</p>
                            <p class="text-sm text-gray-500">{{ __('Configure JWT auth') }}</p>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Swagger Tab -->
    <div x-show="activeTab === 'swagger'" class="space-y-6">
        <!-- Info Box -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <h4 class="text-sm font-semibold text-blue-900 mb-2">
                <i class="fas fa-info-circle mr-1"></i>{{ __('API Authentication') }}
            </h4>
            <div class="text-xs text-blue-800 space-y-2">
                <p>{{ __('Click the') }} <strong>"{{ __('Authorize') }}"</strong> {{ __('button below to authenticate. All endpoints except') }} <code class="bg-blue-100 px-1 rounded">/api/assets/meta</code> {{ __('require authentication.') }}</p>

                <p><strong>{{ __('Two authentication methods are supported:') }}</strong></p>
                <ul class="list-disc ml-4 space-y-1">
                    <li><strong>{{ __('Sanctum Tokens') }}</strong> — {{ __('Long-lived tokens for backend integrations. Manage in the') }} <button @click="navigateToTab('tokens')" class="underline font-semibold">{{ __('API Tokens') }}</button> {{ __('tab.') }}</li>
                    <li><strong>{{ __('JWT') }}</strong> — {{ __('Short-lived tokens for frontend RTE integrations. Generate secrets in the') }} <button @click="navigateToTab('jwt')" class="underline font-semibold">{{ __('JWT Secrets') }}</button> {{ __('tab.') }}</li>
                </ul>

                <p class="text-blue-600"><i class="fas fa-book mr-1"></i>{{ __('See') }} <code class="bg-blue-100 px-1 rounded">RTE_INTEGRATION.md</code> {{ __('for detailed integration examples.') }}</p>
            </div>
        </div>

        <!-- Swagger UI Container -->
        <div class="swagger-ui-container bg-white rounded-lg shadow">
            <div x-show="!swaggerLoaded && !swaggerError" class="p-12 text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-gray-400 mb-4"></i>
                <p class="text-gray-600">{{ __('Loading Swagger UI...') }}</p>
            </div>
            <div x-show="swaggerError" class="p-12 text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
                <p class="text-red-600 font-medium">{{ __('Failed to load Swagger UI') }}</p>
                <p class="text-gray-500 text-sm mt-2" x-text="swaggerError"></p>
                <button @click="loadSwagger()" class="mt-4 px-4 py-2 bg-orca-black text-white rounded hover:bg-orca-black-hover">
                    <i class="fas fa-redo mr-2"></i>{{ __('Retry') }}
                </button>
            </div>
            <div id="swagger-ui"></div>
        </div>
    </div>

    <!-- API Tokens Tab -->
    <div x-show="activeTab === 'tokens'" class="space-y-6">
        <!-- Token Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Total Tokens') }}</p>
                        <p class="text-3xl font-bold text-purple-600" x-text="tokenCount"></p>
                    </div>
                    <i class="fas fa-key text-4xl text-purple-200"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('API Users') }}</p>
                        <p class="text-3xl font-bold text-blue-600" x-text="apiUserCount"></p>
                    </div>
                    <i class="fas fa-robot text-4xl text-blue-200"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <button @click="loadTokens()"
                        class="w-full h-full flex items-center justify-center text-gray-600 hover:text-gray-900">
                    <i class="fas fa-sync-alt text-2xl" :class="{'fa-spin': loadingTokens}"></i>
                    <span class="ml-2">{{ __('Refresh') }}</span>
                </button>
            </div>
        </div>

        <!-- Create Token Form -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-plus-circle mr-2"></i>{{ __('Create New Token') }}
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Token Name -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Token Name') }} *</label>
                        <input type="text"
                               x-model="newToken.name"
                               placeholder="{{ __('e.g., TinyMCE Integration') }}"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent bg-white text-gray-900">
                        <p class="text-xs text-gray-500 mt-1">{{ __('A descriptive name for this token') }}</p>
                    </div>

                    <!-- User Selection -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('User') }}</label>
                        <div class="space-y-3">
                            <div class="flex items-center gap-4">
                                <label class="flex items-center">
                                    <input type="radio" name="userTypeToggle" :checked="!newToken.createNew" @click="newToken.createNew = false" class="text-orca-black focus:ring-orca-black">
                                    <span class="ml-2 text-sm text-gray-700">{{ __('Existing user') }}</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" name="userTypeToggle" :checked="newToken.createNew" @click="newToken.createNew = true" class="text-orca-black focus:ring-orca-black">
                                    <span class="ml-2 text-sm text-gray-700">{{ __('Create new API user') }}</span>
                                </label>
                            </div>

                            <!-- Existing User Dropdown -->
                            <div x-show="!newToken.createNew">
                                <select x-model="newToken.userId"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent bg-white text-gray-900">
                                    <option value="">{{ __('Select a user...') }}</option>
                                    <template x-for="user in tokenUsers" :key="user.id">
                                        <option :value="user.id" x-text="user.name + ' (' + user.email + ') - ' + user.role"></option>
                                    </template>
                                </select>
                            </div>

                            <!-- New User Fields -->
                            <div x-show="newToken.createNew" class="space-y-3">
                                <input type="text"
                                       x-model="newToken.newUserName"
                                       placeholder="{{ __('Name for API user') }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent bg-white text-gray-900">
                                <input type="email"
                                       x-model="newToken.newUserEmail"
                                       placeholder="{{ __('Email for API user') }}"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent bg-white text-gray-900">
                                <p class="text-xs text-gray-500">{{ __('API users have limited permissions: view, upload, and update assets only.') }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button @click="createToken()"
                            :disabled="creatingToken || !newToken.name || (!newToken.createNew && !newToken.userId) || (newToken.createNew && (!newToken.newUserName || !newToken.newUserEmail))"
                            class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover disabled:opacity-50">
                        <i class="fas mr-2" :class="creatingToken ? 'fa-spinner fa-spin' : 'fa-key'"></i>
                        {{ __('Create Token') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- New Token Display Modal -->
        <div x-show="createdToken.plainText" class="bg-green-50 border-2 border-green-300 rounded-lg p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    <i class="attention fas fa-check-circle text-3xl text-green-600"></i>
                </div>
                <div class="flex-grow">
                    <h4 class="text-lg font-semibold text-green-800 mb-2">{{ __('Token Created Successfully!') }}</h4>
                    <p class="text-sm text-green-700 mb-4">
                        <strong>{{ __('Important:') }}</strong> {{ __('Copy this token now. It will NOT be shown again!') }}
                    </p>

                    <div class="bg-white rounded-lg p-4 border border-green-300">
                        <div class="flex items-center justify-between gap-4">
                            <code class="text-sm font-mono text-gray-900 break-all" x-text="createdToken.plainText"></code>
                            <button @click="copyToken()"
                                    class="attention flex-shrink-0 px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700">
                                <i class="fas fa-copy mr-1"></i>{{ __('Copy') }}
                            </button>
                        </div>
                    </div>

                    <div class="attention mt-4 text-sm text-green-700">
                        <p><strong>{{ __('User:') }}</strong> <span x-text="createdToken.userName"></span> (<span x-text="createdToken.userEmail"></span>)</p>
                        <p><strong>{{ __('Role:') }}</strong> <span x-text="createdToken.userRole"></span></p>
                    </div>

                    <button @click="createdToken = {plainText: '', userName: '', userEmail: '', userRole: ''}; loadTokens();"
                            class="attention mt-4 text-sm text-green-700 hover:text-green-900 underline">
                        {{ __('Dismiss and refresh list') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Existing Tokens Table -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-list mr-2"></i>{{ __('Existing Tokens') }}
                </h3>
            </div>
            <div class="overflow-x-auto">
                <div x-show="loadingTokens" class="p-8 text-center text-gray-500">
                    <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                    <p>{{ __('Loading tokens...') }}</p>
                </div>
                <div x-show="!loadingTokens && tokens.length === 0" class="p-8 text-center text-gray-500">
                    <i class="fas fa-key text-4xl mb-3 text-gray-300"></i>
                    <p>{{ __('No API tokens found. Create one above to get started.') }}</p>
                </div>
                <table x-show="!loadingTokens && tokens.length > 0" class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('ID') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Name') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('User') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Created') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Last Used') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="token in tokens" :key="token.id">
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-900" x-text="token.id"></td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900" x-text="token.name"></td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div x-text="token.user_name"></div>
                                    <div class="text-xs text-gray-400" x-text="token.user_email"></div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                          :class="{
                                              'bg-red-100 text-red-800': token.user_role === 'api',
                                              'bg-blue-100 text-blue-800': token.user_role === 'editor',
                                              'bg-purple-100 text-purple-800': token.user_role === 'admin'
                                          }"
                                          x-text="token.user_role"></span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600" x-text="token.created_at"></td>
                                <td class="px-6 py-4 text-sm text-gray-600" x-text="token.last_used_at || @js(__('Never'))"></td>
                                <td class="px-6 py-4 text-sm">
                                    <button @click="revokeToken(token.id, token.name)"
                                            class="attention text-red-600 hover:text-red-900">
                                        <i class="fas fa-trash mr-1"></i>{{ __('Revoke') }}
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- API Info Box -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-blue-900 mb-2">
                <i class="fas fa-info-circle mr-1"></i>{{ __('Using API Tokens') }}
            </h4>
            <div class="text-xs text-blue-800 space-y-1">
                <p>{{ __('Include the token in API requests using the Authorization header:') }}</p>
                <code class="block bg-blue-100 p-2 rounded mt-2 font-mono">Authorization: Bearer YOUR_TOKEN_HERE</code>
                <p class="mt-2"><strong>{{ __('API users') }}</strong> {{ __('(role: api) have limited permissions: view, create, and update assets. They cannot delete assets, access trash, discover unmapped files, or export data.') }}</p>
                <p>{{ __('See') }} <code class="bg-blue-100 px-1 rounded">RTE_INTEGRATION.md</code> {{ __('for integration examples.') }}</p>
            </div>
        </div>
    </div>

    <!-- JWT Secrets Tab -->
    <div x-show="activeTab === 'jwt'" class="space-y-6">

        <!-- JWT Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Users with JWT Secrets') }}</p>
                        <p class="text-3xl font-bold text-green-600" x-text="jwtSecretCount"></p>
                    </div>
                    <i class="fas fa-shield-alt text-4xl text-green-200"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">{{ __('Total Users') }}</p>
                        <p class="text-3xl font-bold text-blue-600" x-text="jwtAllUsers.length"></p>
                    </div>
                    <i class="fas fa-users text-4xl text-blue-200"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <button @click="loadJwtSecrets()"
                        class="w-full h-full flex items-center justify-center text-gray-600 hover:text-gray-900">
                    <i class="fas fa-sync-alt text-2xl" :class="{'fa-spin': loadingJwt}"></i>
                    <span class="ml-2">{{ __('Refresh') }}</span>
                </button>
            </div>
        </div>
        <!-- JWT Status Banner -->
        <div :class="jwtEnabled && jwtSettingEnabled ? 'bg-green-50 border-green-200' : 'bg-yellow-50  border-yellow-200'"
             class="border rounded-lg p-4">
            <div class="flex items-center">
                <i :class="jwtEnabled && jwtSettingEnabled ? 'fa-check-circle text-green-600' : 'fa-exclamation-triangle text-yellow-600'"
                   class="attention fas text-xl mr-3"></i>
                <div>
                    <p :class="jwtEnabled && jwtSettingEnabled ? 'text-green-800' : 'text-yellow-800'" class="font-medium">
                        {{ __('JWT Authentication is') }} <span x-text="jwtEnabled && jwtSettingEnabled ? @js(__('Enabled')) : @js(__('Disabled'))"></span>
                    </p>
                    <p x-show="!jwtEnabled" class="text-xs text-yellow-700 mt-1">
                        {{ __('Set') }} <code class="bg-yellow-100 px-1 rounded">JWT_ENABLED=true</code> {{ __('in your .env file to enable JWT authentication.') }}
                    </p>
                    <p x-show="!jwtSettingEnabled" class="text-xs text-yellow-700 mt-1">
                        {{ __('JWT Authentication is turned off in the Dashboard Settings.') }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Generate JWT Secret Form -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-plus-circle mr-2"></i>{{ __('Generate JWT Secret') }}
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Select User') }} *</label>
                        <select x-model="jwtSelectedUserId"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent bg-white text-gray-900">
                            <option value="">{{ __('Select a user...') }}</option>
                            <template x-for="user in jwtAllUsers" :key="user.id">
                                <option :value="user.id" x-text="user.name + ' (' + user.email + ') - ' + user.role"></option>
                            </template>
                        </select>
                        <p class="text-xs text-gray-500 mt-1 mt-2">{{ __('The user whose credentials will be used for JWT-authenticated requests') }}</p>
                    </div>
                    <div class="flex items-center">
                        <button @click="generateJwtSecret()"
                                :disabled="generatingJwt || !jwtSelectedUserId"
                                class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover disabled:opacity-50">
                            <i class="fas mr-2" :class="generatingJwt ? 'fa-spinner fa-spin' : 'fa-shield-alt'"></i>
                            {{ __('Generate Secret') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generated Secret Display -->
        <div x-show="generatedJwtSecret.secret" class="bg-green-50 border-2 border-green-300 rounded-lg p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    <i class="attention fas fa-check-circle text-3xl text-green-600"></i>
                </div>
                <div class="flex-grow">
                    <h4 class="text-lg font-semibold text-green-800 mb-2">{{ __('JWT Secret Generated!') }}</h4>
                    <p class="text-sm text-green-700 mb-4">
                        <strong>{{ __('Important:') }}</strong> {{ __('Copy this secret now. It will NOT be shown again!') }}
                    </p>

                    <div class="bg-white rounded-lg p-4 border border-green-300 mb-4">
                        <label class="block text-xs text-gray-500 mb-1">{{ __('JWT Secret') }}</label>
                        <div class="flex items-center justify-between gap-4">
                            <code class="text-sm font-mono text-gray-900 break-all" x-text="generatedJwtSecret.secret"></code>
                            <button @click="copyJwtSecret()"
                                    class="attention flex-shrink-0 px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700">
                                <i class="fas fa-copy mr-1"></i>{{ __('Copy') }}
                            </button>
                        </div>
                    </div>

                    <div class="attention text-sm text-green-700 mb-4">
                        <p><strong>{{ __('User:') }}</strong> <span x-text="generatedJwtSecret.userName"></span> (<span x-text="generatedJwtSecret.userEmail"></span>)</p>
                        <p><strong>{{ __('Role:') }}</strong> <span x-text="generatedJwtSecret.userRole"></span></p>
                        <p><strong>{{ __('User ID:') }}</strong> <span x-text="generatedJwtSecret.userId"></span> {{ __("(use this in the JWT 'sub' claim)") }}</p>
                    </div>

                    <!-- Example Code -->
                    <div class="attention bg-gray-800 rounded-lg p-4 text-sm">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-gray-400 text-xs">{{ __('Example: Node.js JWT Generation') }}</span>
                            <button @click="copyJwtExample()"
                                    class="text-xs text-gray-400 hover:text-white">
                                <i class="fas fa-copy mr-1"></i>{{ __('Copy') }}
                            </button>
                        </div>
                        <pre class="text-green-400 overflow-x-auto"><code>const jwt = require('jsonwebtoken');

const token = jwt.sign(
  { sub: <span x-text="generatedJwtSecret.userId"></span> },  // User ID
  'YOUR_SECRET_HERE',  // Replace with your secret
  { expiresIn: '1h', algorithm: 'HS256' }
);

// Use in requests:
// Authorization: Bearer {token}</code></pre>
                    </div>

                    <button @click="generatedJwtSecret = {}; loadJwtSecrets();"
                            class="attention mt-4 text-sm text-green-700 hover:text-green-900:text-green-100 underline">
                        {{ __('Dismiss and refresh list') }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Existing JWT Secrets Table -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-list mr-2"></i>{{ __('Users with JWT Secrets') }}
                </h3>
            </div>
            <div class="overflow-x-auto">
                <div x-show="loadingJwt" class="p-8 text-center text-gray-500">
                    <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                    <p>{{ __('Loading JWT secrets...') }}</p>
                </div>
                <div x-show="!loadingJwt && jwtUsersWithSecrets.length === 0" class="p-8 text-center text-gray-500">
                    <i class="fas fa-shield-alt text-4xl mb-3 text-gray-300"></i>
                    <p>{{ __('No users have JWT secrets. Generate one above to get started.') }}</p>
                </div>
                <table x-show="!loadingJwt && jwtUsersWithSecrets.length > 0" class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('ID') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('User') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Role') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Generated') }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <template x-for="user in jwtUsersWithSecrets" :key="user.id">
                            <tr>
                                <td class="px-6 py-4 text-sm text-gray-900" x-text="user.id"></td>
                                <td class="px-6 py-4 text-sm text-gray-600">
                                    <div x-text="user.name"></div>
                                    <div class="text-xs text-gray-400" x-text="user.email"></div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full"
                                          :class="{
                                              'bg-purple-100 text-purple-800': user.role === 'api',
                                              'bg-blue-100 text-blue-800': user.role === 'editor',
                                              'bg-red-100 text-red-800': user.role === 'admin'
                                          }"
                                          x-text="user.role"></span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600" x-text="user.generated_at || @js(__('Unknown'))"></td>
                                <td class="px-6 py-4 text-sm space-x-2">
                                    <button @click="jwtSelectedUserId = user.id; generateJwtSecret()"
                                            class="text-blue-600 hover:text-blue-900:text-blue-300">
                                        <i class="fas fa-redo mr-1"></i>{{ __('Regenerate') }}
                                    </button>
                                    <button @click="revokeJwtSecret(user.id, user.name)"
                                            class="attention text-red-600 hover:text-red-900:text-red-300">
                                        <i class="fas fa-trash mr-1"></i>{{ __('Revoke') }}
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- JWT Technical Info -->
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-gray-900 mb-2">
                <i class="fas fa-code mr-1"></i>{{ __('JWT Technical Details') }}
            </h4>
            <div class="text-xs text-gray-600 space-y-2">
                <p><strong>{{ __('Algorithm:') }}</strong> HS256 (HMAC-SHA256)</p>
                <p><strong>{{ __('Required claims:') }}</strong></p>
                <ul class="list-disc ml-4">
                    <li><code class="bg-gray-200 px-1 rounded">sub</code> - {{ __('User ID (integer)') }}</li>
                    <li><code class="bg-gray-200 px-1 rounded">exp</code> - {{ __('Expiration timestamp') }}</li>
                    <li><code class="bg-gray-200 px-1 rounded">iat</code> - {{ __('Issued-at timestamp') }}</li>
                </ul>
                <p><strong>{{ __('Max token lifetime:') }}</strong> {{ __('10 hour (configurable via') }} <code class="bg-gray-200 px-1 rounded">JWT_MAX_TTL</code>)</p>
                <p><strong>{{ __('Usage:') }}</strong> {{ __('Include in Authorization header as') }} <code class="bg-gray-200 px-1 rounded">Bearer {token}</code></p>
            </div>
        </div>
        <!-- JWT Info Box -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <h4 class="text-sm font-semibold text-blue-900 mb-2">
                <i class="fas fa-info-circle mr-1"></i>{{ __('About JWT Authentication') }}
            </h4>
            <div class="text-xs text-blue-800 space-y-2">
                <p>{{ __('JWT authentication allows external systems to generate short-lived tokens for API access. This is ideal for frontend RTE integrations where you don\'t want to expose long-lived Sanctum tokens.') }}</p>
                <p><strong>{{ __('How it works:') }}</strong></p>
                <ol class="list-decimal ml-4 space-y-1">
                    <li>{{ __('Generate a JWT secret for a user (above)') }}</li>
                    <li>{{ __('Share the secret with your external backend system') }}</li>
                    <li>{{ __('Your backend generates short-lived JWTs using the secret') }}</li>
                    <li>{{ __('Your frontend uses the JWT for ORCA API requests') }}</li>
                </ol>
            </div>
        </div>


    </div>
</div>
@endsection

@push('scripts')
<script>
window.__pageData = {
    jwtEnvEnabled: {{ $jwtEnvEnabled ? 'true' : 'false' }},
    routes: {
        dashboard: '{{ route('api.dashboard') }}',
        settingsUpdate: '{{ route('api.settings.update') }}',
        tokens: '{{ route('api.tokens') }}',
        tokensStore: '{{ route('api.tokens.store') }}',
        tokensBase: '{{ url('api-docs/tokens') }}',
        jwtSecrets: '{{ route('api.jwt-secrets') }}',
        jwtSecretsBase: '{{ url('api-docs/jwt-secrets') }}'
    },
    translations: {
        failedLoadDashboard: @js(__('Failed to load dashboard data')),
        settingUpdated: @js(__('Setting updated successfully')),
        failedUpdateSetting: @js(__('Failed to update setting')),
        failedLoadTokens: @js(__('Failed to load tokens')),
        failedCreateToken: @js(__('Failed to create token')),
        tokenCreated: @js(__('Token created successfully')),
        confirmRevokeToken: @js(__('Are you sure you want to revoke the token')),
        cannotBeUndone: @js(__('This cannot be undone.')),
        tokenRevoked: @js(__('Token revoked successfully')),
        failedRevokeToken: @js(__('Failed to revoke token')),
        tokenCopied: @js(__('Token copied to clipboard')),
        copiedToClipboard: @js(__('Copied to clipboard')),
        failedCopy: @js(__('Failed to copy')),
        failedLoadJwtSecrets: @js(__('Failed to load JWT secrets')),
        failedGenerateJwtSecret: @js(__('Failed to generate JWT secret')),
        confirmRevokeJwtSecret: @js(__('Are you sure you want to revoke the JWT secret for')),
        jwtRevokeWarning: @js(__('Any JWTs generated with this secret will no longer work.')),
        jwtSecretRevoked: @js(__('JWT secret revoked successfully')),
        failedRevokeJwtSecret: @js(__('Failed to revoke JWT secret')),
        jwtSecretCopied: @js(__('JWT secret copied to clipboard')),
        exampleCodeCopied: @js(__('Example code copied to clipboard'))
    }
};
</script>
@endpush

@push('styles')
<style>
/* Swagger UI Customizations */
#swagger-ui {
    padding: 2rem 1rem;
}

#swagger-ui .topbar {
    display: none;
}
#swagger-ui .swagger-ui {
    padding: 1rem 0;
}
#swagger-ui .info {
    margin: 0 0 2rem 0;
}

#swagger-ui .scheme-container {
    padding: 1rem;
    background: #f8fafc;
    border-radius: 0.5rem;
}
html.dark-mode #swagger-ui .scheme-container {
    background: #1c2022;
    box-shadow: 0 1px 2px 0 #545d61;
}

#swagger-ui .opblock-tag {
    font-size: 1.25rem;
    font-weight: 600;
}

#swagger-ui .opblock {
    border-radius: 0.5rem;
    margin-bottom: 0.5rem;
}

#swagger-ui .opblock .opblock-summary {
    border-radius: 0.5rem;
}

#swagger-ui .btn {
    border-radius: 0.375rem;
}

#swagger-ui .btn.execute {
    background-color: #1f2937;
    border-color: #1f2937;
}

#swagger-ui .btn.execute:hover {
    background-color: #374151;
}

#swagger-ui .btn.authorize {
    background-color: #059669;
    border-color: #059669;
}

#swagger-ui .btn.authorize:hover {
    background-color: #047857;
}

#swagger-ui input[type="text"],
#swagger-ui textarea,
#swagger-ui select {
    border-radius: 0.375rem;
}

#swagger-ui .model-box {
    border-radius: 0.5rem;
}

#swagger-ui table tbody tr td {
    padding: 0.75rem;
}
</style>
@endpush
