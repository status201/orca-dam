@extends('layouts.app')

@section('title', 'API Docs & Management')

@section('content')
<div x-data="apiDocs()" x-init="init()">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-gray-900">API Docs &amp; Management</h1>
        <p class="text-gray-600 mt-2">Interactive API documentation and token management</p>
    </div>

    <!-- Tab Navigation -->
    <div class="mb-6 border-b border-gray-200 w-full overflow-x-scroll sm:overflow-x-hidden overflow-y-hidden">
        <nav class="-mb-px flex space-x-8">
            <button @click="activeTab = 'swagger'; if (!swaggerLoaded) loadSwagger();"
                    :class="activeTab === 'swagger' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-book-open mr-2"></i>Swagger
            </button>

            <button @click="activeTab = 'tokens'; if (!tokensLoaded) loadTokens();"
                    :class="activeTab === 'tokens' ? 'border-orca-black text-orca-black' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                    class="whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                <i class="fas fa-key mr-2"></i>API Tokens
                <span x-show="tokenCount > 0"
                      class="ml-2 px-2 py-0.5 text-xs bg-purple-100 text-purple-700 rounded-full"
                      x-text="tokenCount"></span>
            </button>
        </nav>
    </div>

    <!-- Swagger Tab -->
    <div x-show="activeTab === 'swagger'" class="space-y-6">
        <!-- Info Box -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
            <h4 class="text-sm font-semibold text-blue-900 mb-2">
                <i class="fas fa-info-circle mr-1"></i>API Authentication
            </h4>
            <div class="text-xs text-blue-800 space-y-1">
                <p>Click the <strong>"Authorize"</strong> button below to authenticate with your API token.</p>
                <p>All endpoints except <code class="bg-blue-100 px-1 rounded">/api/assets/meta</code> require authentication.</p>
                <p>You can create and manage API tokens in the <button @click="activeTab = 'tokens'; if (!tokensLoaded) loadTokens();" class="underline font-semibold">API Tokens</button> tab.</p>
            </div>
        </div>

        <!-- Swagger UI Container -->
        <div class="bg-white dark:bg-orca-black-hover rounded-lg shadow">
            <div x-show="!swaggerLoaded && !swaggerError" class="p-12 text-center">
                <i class="fas fa-spinner fa-spin text-4xl text-gray-400 mb-4"></i>
                <p class="text-gray-600">Loading Swagger UI...</p>
            </div>
            <div x-show="swaggerError" class="p-12 text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
                <p class="text-red-600 font-medium">Failed to load Swagger UI</p>
                <p class="text-gray-500 text-sm mt-2" x-text="swaggerError"></p>
                <button @click="loadSwagger()" class="mt-4 px-4 py-2 bg-orca-black text-white rounded hover:bg-orca-black-hover">
                    <i class="fas fa-redo mr-2"></i>Retry
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
                        <p class="text-sm font-medium text-gray-500">Total Tokens</p>
                        <p class="text-3xl font-bold text-purple-600" x-text="tokenCount"></p>
                    </div>
                    <i class="fas fa-key text-4xl text-purple-200"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500">API Users</p>
                        <p class="text-3xl font-bold text-blue-600" x-text="apiUserCount"></p>
                    </div>
                    <i class="fas fa-robot text-4xl text-blue-200"></i>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-6">
                <button @click="loadTokens()"
                        class="w-full h-full flex items-center justify-center text-gray-600 hover:text-gray-900">
                    <i class="fas fa-sync-alt text-2xl" :class="{'fa-spin': loadingTokens}"></i>
                    <span class="ml-2">Refresh</span>
                </button>
            </div>
        </div>

        <!-- Create Token Form -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-plus-circle mr-2"></i>Create New Token
                </h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Token Name -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Token Name *</label>
                        <input type="text"
                               x-model="newToken.name"
                               placeholder="e.g., TinyMCE Integration"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">A descriptive name for this token</p>
                    </div>

                    <!-- User Selection -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">User</label>
                        <div class="space-y-3">
                            <div class="flex items-center gap-4">
                                <label class="flex items-center">
                                    <input type="radio" x-model="newToken.createNew" :value="false" class="text-orca-black focus:ring-orca-black">
                                    <span class="ml-2 text-sm text-gray-700">Existing user</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="radio" x-model="newToken.createNew" :value="true" class="text-orca-black focus:ring-orca-black">
                                    <span class="ml-2 text-sm text-gray-700">Create new API user</span>
                                </label>
                            </div>

                            <!-- Existing User Dropdown -->
                            <div x-show="!newToken.createNew">
                                <select x-model="newToken.userId"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                    <option value="">Select a user...</option>
                                    <template x-for="user in tokenUsers" :key="user.id">
                                        <option :value="user.id" x-text="user.name + ' (' + user.email + ') - ' + user.role"></option>
                                    </template>
                                </select>
                            </div>

                            <!-- New User Fields -->
                            <div x-show="newToken.createNew" class="space-y-3">
                                <input type="text"
                                       x-model="newToken.newUserName"
                                       placeholder="Name for API user"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                <input type="email"
                                       x-model="newToken.newUserEmail"
                                       placeholder="Email for API user"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orca-black focus:border-transparent">
                                <p class="text-xs text-gray-500">API users have limited permissions: view, upload, and update assets only.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-3">
                    <button @click="createToken()"
                            :disabled="creatingToken || !newToken.name || (!newToken.createNew && !newToken.userId) || (newToken.createNew && (!newToken.newUserName || !newToken.newUserEmail))"
                            class="px-6 py-2 bg-orca-black text-white rounded-lg hover:bg-orca-black-hover disabled:opacity-50">
                        <i class="fas mr-2" :class="creatingToken ? 'fa-spinner fa-spin' : 'fa-key'"></i>
                        Create Token
                    </button>
                </div>
            </div>
        </div>

        <!-- New Token Display Modal -->
        <div x-show="createdToken.plainText" class="bg-green-50 border-2 border-green-300 rounded-lg p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    <i class="fas fa-check-circle text-3xl text-green-600"></i>
                </div>
                <div class="flex-grow">
                    <h4 class="text-lg font-semibold text-green-800 mb-2">Token Created Successfully!</h4>
                    <p class="text-sm text-green-700 mb-4">
                        <strong>Important:</strong> Copy this token now. It will NOT be shown again!
                    </p>

                    <div class="bg-white rounded-lg p-4 border border-green-300">
                        <div class="flex items-center justify-between gap-4">
                            <code class="text-sm font-mono text-gray-900 break-all" x-text="createdToken.plainText"></code>
                            <button @click="copyToken()"
                                    class="flex-shrink-0 px-3 py-1.5 bg-green-600 text-white rounded hover:bg-green-700">
                                <i class="fas fa-copy mr-1"></i>Copy
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 text-sm text-green-700">
                        <p><strong>User:</strong> <span x-text="createdToken.userName"></span> (<span x-text="createdToken.userEmail"></span>)</p>
                        <p><strong>Role:</strong> <span x-text="createdToken.userRole"></span></p>
                    </div>

                    <button @click="createdToken = {plainText: '', userName: '', userEmail: '', userRole: ''}; loadTokens();"
                            class="mt-4 text-sm text-green-700 hover:text-green-900 underline">
                        Dismiss and refresh list
                    </button>
                </div>
            </div>
        </div>

        <!-- Existing Tokens Table -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    <i class="fas fa-list mr-2"></i>Existing Tokens
                </h3>
            </div>
            <div class="overflow-x-auto">
                <div x-show="loadingTokens" class="p-8 text-center text-gray-500">
                    <i class="fas fa-spinner fa-spin text-3xl mb-2"></i>
                    <p>Loading tokens...</p>
                </div>
                <div x-show="!loadingTokens && tokens.length === 0" class="p-8 text-center text-gray-500">
                    <i class="fas fa-key text-4xl mb-3 text-gray-300"></i>
                    <p>No API tokens found. Create one above to get started.</p>
                </div>
                <table x-show="!loadingTokens && tokens.length > 0" class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Created</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Used</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
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
                                              'bg-purple-100 text-purple-800': token.user_role === 'api',
                                              'bg-blue-100 text-blue-800': token.user_role === 'editor',
                                              'bg-red-100 text-red-800': token.user_role === 'admin'
                                          }"
                                          x-text="token.user_role"></span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600" x-text="token.created_at"></td>
                                <td class="px-6 py-4 text-sm text-gray-600" x-text="token.last_used_at || 'Never'"></td>
                                <td class="px-6 py-4 text-sm">
                                    <button @click="revokeToken(token.id, token.name)"
                                            class="text-red-600 hover:text-red-900">
                                        <i class="fas fa-trash mr-1"></i>Revoke
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
                <i class="fas fa-info-circle mr-1"></i>Using API Tokens
            </h4>
            <div class="text-xs text-blue-800 space-y-1">
                <p>Include the token in API requests using the Authorization header:</p>
                <code class="block bg-blue-100 p-2 rounded mt-2 font-mono">Authorization: Bearer YOUR_TOKEN_HERE</code>
                <p class="mt-2"><strong>API users</strong> (role: api) have limited permissions: view, create, and update assets. They cannot delete assets, access trash, discover unmapped files, or export data.</p>
                <p>See <code class="bg-blue-100 px-1 rounded">RTE_INTEGRATION.md</code> for integration examples.</p>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function apiDocs() {
    return {
        // Tab state
        activeTab: 'swagger',
        validTabs: ['swagger', 'tokens'],

        // Swagger state
        swaggerLoaded: false,
        swaggerError: null,

        // API Tokens state
        tokens: [],
        tokenUsers: [],
        tokenCount: 0,
        apiUserCount: 0,
        tokensLoaded: false,
        loadingTokens: false,
        creatingToken: false,
        newToken: {
            name: '',
            userId: '',
            createNew: false,
            newUserName: '',
            newUserEmail: ''
        },
        createdToken: {
            plainText: '',
            userName: '',
            userEmail: '',
            userRole: ''
        },

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
                    this.activeTab = 'swagger';
                }
            });

            // Load Swagger on init if it's the active tab
            if (this.activeTab === 'swagger') {
                this.loadSwagger();
            }
        },

        // Load Swagger UI
        async loadSwagger() {
            if (this.swaggerLoaded) return;

            this.swaggerError = null;

            try {
                // Load Swagger UI CSS
                if (!document.querySelector('link[href*="swagger-ui"]')) {
                    const link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = 'https://unpkg.com/swagger-ui-dist@5/swagger-ui.css';
                    document.head.appendChild(link);
                }

                // Load Swagger UI Bundle and Standalone Preset
                await this.loadScript('https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js');
                await this.loadScript('https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js');

                // Wait a tick for scripts to fully initialize
                await new Promise(resolve => setTimeout(resolve, 200));

                // Initialize Swagger UI with proper checks
                const SwaggerUIBundle = window.SwaggerUIBundle;
                const SwaggerUIStandalonePreset = window.SwaggerUIStandalonePreset;

                if (!SwaggerUIBundle) {
                    throw new Error('SwaggerUIBundle not loaded');
                }

                const config = {
                    url: '/api-docs/openapi.json',
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    persistAuthorization: true,
                    tryItOutEnabled: true,
                    filter: true,
                    validatorUrl: null,
                    defaultModelsExpandDepth: 1,
                    defaultModelExpandDepth: 1,
                    displayRequestDuration: true,
                    showExtensions: true,
                    showCommonExtensions: true
                };

                // Add presets if available
                if (SwaggerUIBundle.presets && SwaggerUIBundle.presets.apis) {
                    config.presets = [SwaggerUIBundle.presets.apis];
                    if (SwaggerUIStandalonePreset) {
                        config.presets.push(SwaggerUIStandalonePreset);
                        config.layout = 'StandaloneLayout';
                    }
                }

                // Add plugins if available
                if (SwaggerUIBundle.plugins && SwaggerUIBundle.plugins.DownloadUrl) {
                    config.plugins = [SwaggerUIBundle.plugins.DownloadUrl];
                }

                SwaggerUIBundle(config);

                this.swaggerLoaded = true;
            } catch (error) {
                console.error('Failed to load Swagger UI:', error);
                this.swaggerError = error.message || 'Failed to load Swagger UI';
            }
        },

        // Load external script
        loadScript(src) {
            return new Promise((resolve, reject) => {
                // Check if already loaded
                if (document.querySelector(`script[src="${src}"]`)) {
                    resolve();
                    return;
                }

                const script = document.createElement('script');
                script.src = src;
                script.onload = resolve;
                script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
                document.head.appendChild(script);
            });
        },

        // Load tokens
        async loadTokens() {
            this.loadingTokens = true;
            try {
                const response = await fetch('{{ route('api.tokens') }}');
                const data = await response.json();
                this.tokens = data.tokens;
                this.tokenUsers = data.users;
                this.tokenCount = data.tokens.length;
                this.apiUserCount = data.users.filter(u => u.role === 'api').length;
                this.tokensLoaded = true;
            } catch (error) {
                console.error('Failed to load tokens:', error);
                window.showToast('Failed to load tokens', 'error');
            } finally {
                this.loadingTokens = false;
            }
        },

        // Create token
        async createToken() {
            this.creatingToken = true;
            try {
                const body = {
                    token_name: this.newToken.name,
                    create_new: this.newToken.createNew,
                };

                if (this.newToken.createNew) {
                    body.new_user_name = this.newToken.newUserName;
                    body.new_user_email = this.newToken.newUserEmail;
                } else {
                    body.user_id = this.newToken.userId;
                }

                const response = await fetch('{{ route('api.tokens.store') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(body),
                });

                const result = await response.json();

                if (!response.ok) {
                    // Handle validation errors
                    if (result.errors) {
                        const firstError = Object.values(result.errors)[0];
                        window.showToast(Array.isArray(firstError) ? firstError[0] : firstError, 'error');
                    } else {
                        window.showToast(result.message || 'Failed to create token', 'error');
                    }
                    return;
                }

                if (result.success) {
                    this.createdToken = {
                        plainText: result.token.plain_text,
                        userName: result.token.user_name,
                        userEmail: result.token.user_email,
                        userRole: result.token.user_role
                    };

                    // Reset form
                    this.newToken = {
                        name: '',
                        userId: '',
                        createNew: false,
                        newUserName: '',
                        newUserEmail: ''
                    };

                    window.showToast('Token created successfully', 'success');
                } else {
                    window.showToast(result.message || 'Failed to create token', 'error');
                }
            } catch (error) {
                console.error('Failed to create token:', error);
                window.showToast('Failed to create token', 'error');
            } finally {
                this.creatingToken = false;
            }
        },

        // Revoke token
        async revokeToken(id, name) {
            if (!confirm(`Are you sure you want to revoke the token "${name}"? This cannot be undone.`)) {
                return;
            }

            try {
                const response = await fetch(`{{ url('api-docs/tokens') }}/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();

                if (result.success) {
                    window.showToast('Token revoked successfully', 'success');
                    this.loadTokens();
                } else {
                    window.showToast(result.message || 'Failed to revoke token', 'error');
                }
            } catch (error) {
                console.error('Failed to revoke token:', error);
                window.showToast('Failed to revoke token', 'error');
            }
        },

        // Copy token to clipboard
        copyToken() {
            const text = this.createdToken.plainText;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    window.showToast('Token copied to clipboard', 'success');
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    this.fallbackCopyToClipboard(text);
                });
            } else {
                this.fallbackCopyToClipboard(text);
            }
        },

        // Fallback copy method for non-secure contexts
        fallbackCopyToClipboard(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-9999px';
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                window.showToast('Token copied to clipboard', 'success');
            } catch (err) {
                console.error('Fallback copy failed:', err);
                window.showToast('Failed to copy token', 'error');
            }
            textArea.remove();
        }
    };
}
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
