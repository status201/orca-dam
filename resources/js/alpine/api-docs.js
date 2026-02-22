export function apiDocs() {
    const pageData = window.__pageData || {};
    const routes = pageData.routes || {};
    const t = pageData.translations || {};

    return {
        // Tab state
        activeTab: 'dashboard',
        validTabs: ['dashboard', 'swagger', 'tokens', 'jwt'],

        // Dashboard state
        dashboardLoaded: false,
        loadingDashboard: false,
        dashboardData: {
            tokenCount: 0,
            jwtSecretCount: 0,
            apiUserCount: 0,
            jwtEnvEnabled: pageData.jwtEnvEnabled || false,
            jwtSettingEnabled: true,
            metaEndpointEnabled: true
        },
        savingSettings: false,

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

        // JWT Secrets state
        jwtEnabled: false,
        jwtSettingEnabled: false,
        jwtUsersWithSecrets: [],
        jwtAllUsers: [],
        jwtSecretCount: 0,
        jwtLoaded: false,
        loadingJwt: false,
        generatingJwt: false,
        jwtSelectedUserId: '',
        generatedJwtSecret: {},

        init() {
            // Set active tab from URL hash (replace slash, swagger adds that so it becomes `api-docs#/swagger`)
            const hash = window.location.hash.substring(1).replace('/', '');
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
                    this.activeTab = 'dashboard';
                }
            });

            // Load dashboard on init if it's the active tab (or no hash)
            if (this.activeTab === 'dashboard') {
                this.loadDashboard();
            } else if (this.activeTab === 'swagger') {
                this.loadSwagger();
            } else if (this.activeTab === 'tokens') {
                this.loadTokens();
            } else if (this.activeTab === 'jwt') {
                this.loadJwtSecrets();
            }
        },

        // Navigate to a specific tab
        navigateToTab(tab) {
            this.activeTab = tab;
            if (tab === 'dashboard' && !this.dashboardLoaded) {
                this.loadDashboard();
            } else if (tab === 'swagger' && !this.swaggerLoaded) {
                this.loadSwagger();
            } else if (tab === 'tokens' && !this.tokensLoaded) {
                this.loadTokens();
            } else if (tab === 'jwt' && !this.jwtLoaded) {
                this.loadJwtSecrets();
            }
        },

        // Load dashboard data
        async loadDashboard() {
            if (this.dashboardLoaded) return;

            this.loadingDashboard = true;
            try {
                const response = await fetch(routes.dashboard);
                const data = await response.json();
                this.dashboardData = {
                    tokenCount: data.tokenCount,
                    jwtSecretCount: data.jwtSecretCount,
                    apiUserCount: data.apiUserCount,
                    jwtEnvEnabled: data.jwtEnvEnabled,
                    jwtSettingEnabled: data.jwtSettingEnabled,
                    metaEndpointEnabled: data.metaEndpointEnabled
                };
                // Also update the counts used by other tabs
                this.tokenCount = data.tokenCount;
                this.jwtSecretCount = data.jwtSecretCount;
                this.dashboardLoaded = true;
            } catch (error) {
                console.error('Failed to load dashboard:', error);
                window.showToast(t.failedLoadDashboard, 'error');
            } finally {
                this.loadingDashboard = false;
            }
        },

        // Save API setting
        async saveApiSetting(key, value) {
            this.savingSettings = true;
            try {
                const response = await fetch(routes.settingsUpdate, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ key, value }),
                });

                const result = await response.json();

                if (result.success) {
                    // Update local state
                    if (key === 'jwt_enabled_override') {
                        this.dashboardData.jwtSettingEnabled = value;
                        this.jwtSettingEnabled = value;
                    } else if (key === 'api_meta_endpoint_enabled') {
                        this.dashboardData.metaEndpointEnabled = value;
                    }
                    window.showToast(t.settingUpdated, 'success');
                } else {
                    window.showToast(result.message || t.failedUpdateSetting, 'error');
                }
            } catch (error) {
                console.error('Failed to save setting:', error);
                window.showToast(t.failedUpdateSetting, 'error');
            } finally {
                this.savingSettings = false;
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
                await new Promise(resolve => setTimeout(resolve, 300));

                // Initialize Swagger UI with proper checks
                const SwaggerUIBundle = window.SwaggerUIBundle;
                const SwaggerUIStandalonePreset = window.SwaggerUIStandalonePreset;

                if (!SwaggerUIBundle) {
                    throw new Error('SwaggerUIBundle not loaded');
                }

                const config = {
                    url: '/swagger/openapi.json',
                    dom_id: '#swagger-ui',
                    deepLinking: true,
                    persistAuthorization: true,
                    tryItOutEnabled: true,
                    filter: true,
                    validatorUrl: null,
                    defaultModelsExpandDepth: 2,
                    defaultModelExpandDepth: 2,
                    displayRequestDuration: true,
                    showExtensions: true,
                    showCommonExtensions: true
                };
                config.theme = { defaultMode: 'light' };

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
                const response = await fetch(routes.tokens);
                const data = await response.json();
                this.tokens = data.tokens;
                this.tokenUsers = data.users;
                this.tokenCount = data.tokens.length;
                this.apiUserCount = data.users.filter(u => u.role === 'api').length;
                this.tokensLoaded = true;
            } catch (error) {
                console.error('Failed to load tokens:', error);
                window.showToast(t.failedLoadTokens, 'error');
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

                const response = await fetch(routes.tokensStore, {
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
                        window.showToast(result.message || t.failedCreateToken, 'error');
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

                    window.showToast(t.tokenCreated, 'success');
                } else {
                    window.showToast(result.message || t.failedCreateToken, 'error');
                }
            } catch (error) {
                console.error('Failed to create token:', error);
                window.showToast(t.failedCreateToken, 'error');
            } finally {
                this.creatingToken = false;
            }
        },

        // Revoke token
        async revokeToken(id, name) {
            if (!confirm(t.confirmRevokeToken + ` "${name}"? ` + t.cannotBeUndone)) {
                return;
            }

            try {
                const response = await fetch(`${routes.tokensBase}/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();

                if (result.success) {
                    window.showToast(t.tokenRevoked, 'success');
                    this.loadTokens();
                } else {
                    window.showToast(result.message || t.failedRevokeToken, 'error');
                }
            } catch (error) {
                console.error('Failed to revoke token:', error);
                window.showToast(t.failedRevokeToken, 'error');
            }
        },

        // Copy token to clipboard
        copyToken() {
            const text = this.createdToken.plainText;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    window.showToast(t.tokenCopied, 'success');
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    this.fallbackCopyToClipboard(text, t.tokenCopied);
                });
            } else {
                this.fallbackCopyToClipboard(text, t.tokenCopied);
            }
        },

        // Fallback copy method for non-secure contexts
        fallbackCopyToClipboard(text, message = t.copiedToClipboard) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-9999px';
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                window.showToast(message, 'success');
            } catch (err) {
                console.error('Fallback copy failed:', err);
                window.showToast(t.failedCopy, 'error');
            }
            textArea.remove();
        },

        // Load JWT secrets
        async loadJwtSecrets() {
            this.loadingJwt = true;
            try {
                const response = await fetch(routes.jwtSecrets);
                const data = await response.json();
                this.jwtUsersWithSecrets = data.users_with_secrets;
                this.jwtAllUsers = data.all_users;
                this.jwtSecretCount = data.users_with_secrets.length;
                this.jwtEnabled = data.jwt_enabled;
                this.jwtSettingEnabled = data.jwt_setting_enabled;
                this.jwtLoaded = true;
            } catch (error) {
                console.error('Failed to load JWT secrets:', error);
                window.showToast(t.failedLoadJwtSecrets, 'error');
            } finally {
                this.loadingJwt = false;
            }
        },

        // Generate JWT secret
        async generateJwtSecret() {
            if (!this.jwtSelectedUserId) return;

            this.generatingJwt = true;
            try {
                const response = await fetch(`${routes.jwtSecretsBase}/${this.jwtSelectedUserId}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();

                if (result.success) {
                    this.generatedJwtSecret = {
                        secret: result.secret,
                        userId: result.user.id,
                        userName: result.user.name,
                        userEmail: result.user.email,
                        userRole: result.user.role,
                    };
                    this.jwtSelectedUserId = '';
                    window.showToast(result.message, 'success');
                } else {
                    window.showToast(result.message || t.failedGenerateJwtSecret, 'error');
                }
            } catch (error) {
                console.error('Failed to generate JWT secret:', error);
                window.showToast(t.failedGenerateJwtSecret, 'error');
            } finally {
                this.generatingJwt = false;
            }
        },

        // Revoke JWT secret
        async revokeJwtSecret(userId, userName) {
            if (!confirm(t.confirmRevokeJwtSecret + ` "${userName}"? ` + t.jwtRevokeWarning)) {
                return;
            }

            try {
                const response = await fetch(`${routes.jwtSecretsBase}/${userId}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();

                if (result.success) {
                    window.showToast(t.jwtSecretRevoked, 'success');
                    this.loadJwtSecrets();
                } else {
                    window.showToast(result.message || t.failedRevokeJwtSecret, 'error');
                }
            } catch (error) {
                console.error('Failed to revoke JWT secret:', error);
                window.showToast(t.failedRevokeJwtSecret, 'error');
            }
        },

        // Copy JWT secret to clipboard
        copyJwtSecret() {
            const text = this.generatedJwtSecret.secret;
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => {
                    window.showToast(t.jwtSecretCopied, 'success');
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    this.fallbackCopyToClipboard(text, t.jwtSecretCopied);
                });
            } else {
                this.fallbackCopyToClipboard(text, t.jwtSecretCopied);
            }
        },

        // Copy JWT example code
        copyJwtExample() {
            const code = `const jwt = require('jsonwebtoken');

const token = jwt.sign(
  { sub: ${this.generatedJwtSecret.userId} },  // User ID
  'YOUR_SECRET_HERE',  // Replace with your secret
  { expiresIn: '1h', algorithm: 'HS256' }
);

// Use in requests:
// Authorization: Bearer {token}`;

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(code).then(() => {
                    window.showToast(t.exampleCodeCopied, 'success');
                }).catch(err => {
                    console.error('Failed to copy:', err);
                    this.fallbackCopyToClipboard(code, t.exampleCodeCopied);
                });
            } else {
                this.fallbackCopyToClipboard(code, t.exampleCodeCopied);
            }
        }
    };
}
window.apiDocs = apiDocs;
