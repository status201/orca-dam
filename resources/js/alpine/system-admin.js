export function systemAdmin() {
    const pageData = window.__systemPageData;

    return {
        activeTab: 'overview',

        // Queue data
        queueStats: pageData.queueStats,
        pendingJobs: [],
        failedJobs: [],
        loadingQueue: false,
        processingJobs: false,

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
        settings: pageData.settings,
        settingsSaved: false,
        settingsError: '',
        savingSettings: false,
        regenerating: false,
        verifyingIntegrity: false,
        missingAssetsCount: pageData.missingAssetsCount,
        integrityCheckQueued: false,
        integrityQueuedCount: 0,
        refreshingIntegrity: false,

        systemInfo: {
          jwtEnvEnabled: pageData.jwtEnvEnabled,
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
                const response = await fetch(pageData.routes.queueStatus);
                const data = await response.json();
                this.queueStats = data.stats;
                this.pendingJobs = data.pending_jobs;
                this.failedJobs = data.failed_jobs;
            } catch (error) {
                console.error('Failed to refresh queue status:', error);
                window.showToast(pageData.translations.failedRefreshQueueStatus, 'error');
            } finally {
                this.loadingQueue = false;
            }
        },

        async refreshLogs() {
            this.loadingLogs = true;
            try {
                const response = await fetch(`${pageData.routes.logs}?lines=${this.logLines}`);
                this.logData = await response.json();
            } catch (error) {
                console.error('Failed to refresh logs:', error);
                window.showToast(pageData.translations.failedRefreshLogs, 'error');
            } finally {
                this.loadingLogs = false;
            }
        },

        async executeCustomCommand() {
            if (!this.customCommand) return;

            this.executingCommand = true;
            this.commandOutput = '';

            try {
                const response = await fetch(pageData.routes.executeCommand, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ command: this.customCommand }),
                });

                const result = await response.json();
                this.commandSuccess = result.success;
                this.commandOutput = result.output || result.error || pageData.translations.noOutput;

                if (result.success) {
                    window.showToast(pageData.translations.commandExecutedSuccessfully, 'success');
                } else {
                    window.showToast(pageData.translations.commandFailed, 'error');
                }
            } catch (error) {
                console.error('Failed to execute command:', error);
                this.commandSuccess = false;
                this.commandOutput = error.message;
                window.showToast(pageData.translations.failedExecuteCommand, 'error');
            } finally {
                this.executingCommand = false;
            }
        },

        async retryJob(uuid) {
            try {
                const response = await fetch(pageData.routes.retryJob, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ job_id: uuid }),
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast(pageData.translations.jobQueuedForRetry, 'success');
                    this.refreshQueueStatus();
                } else {
                    window.showToast(pageData.translations.failedRetryJob, 'error');
                }
            } catch (error) {
                console.error('Failed to retry job:', error);
                window.showToast(pageData.translations.failedRetryJob, 'error');
            }
        },

        async retryAllFailedJobs() {
            if (!confirm(pageData.translations.retryAllFailedJobsConfirm)) return;

            this.customCommand = 'queue:retry all';
            await this.executeCustomCommand();
            setTimeout(() => this.refreshQueueStatus(), 1000);
        },

        async flushFailedJobs() {
            if (!confirm(pageData.translations.deleteAllFailedJobsConfirm)) return;

            try {
                const response = await fetch(pageData.routes.flushQueue, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast(pageData.translations.failedJobsFlushed, 'success');
                    this.refreshQueueStatus();
                } else {
                    window.showToast(pageData.translations.failedFlushQueue, 'error');
                }
            } catch (error) {
                console.error('Failed to flush queue:', error);
                window.showToast(pageData.translations.failedFlushQueue, 'error');
            }
        },

        async restartWorkers() {
            try {
                const response = await fetch(pageData.routes.restartQueue, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast(pageData.translations.queueWorkersSignaledToRestart, 'success');
                } else {
                    window.showToast(pageData.translations.failedRestartWorkers, 'error');
                }
            } catch (error) {
                console.error('Failed to restart workers:', error);
                window.showToast(pageData.translations.failedRestartWorkers, 'error');
            }
        },

        async processQueueJobs() {
            if (this.processingJobs) return;
            this.processingJobs = true;
            try {
                const response = await fetch(pageData.routes.processQueue, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast(pageData.translations.jobsProcessedSuccessfully, 'success');
                } else {
                    window.showToast(result.error || pageData.translations.failedProcessJobs, 'error');
                }
                this.refreshQueueStatus();
            } catch (error) {
                console.error('Failed to process jobs:', error);
                window.showToast(pageData.translations.failedProcessJobs, 'error');
            } finally {
                this.processingJobs = false;
            }
        },

        async refreshSupervisorStatus() {
            this.loadingSupervisor = true;
            try {
                const response = await fetch(pageData.routes.supervisorStatus);
                const data = await response.json();
                this.supervisorStatus = data;
            } catch (error) {
                console.error('Failed to refresh supervisor status:', error);
                this.supervisorStatus = {
                    available: false,
                    message: pageData.translations.failedCheckSupervisorStatus,
                    workers: [],
                    total: 0,
                    running: 0
                };
            } finally {
                this.loadingSupervisor = false;
            }
        },

        async regenerateAllSizes() {
            if (!confirm(pageData.translations.regenerateAllSizesConfirm)) {
                return;
            }

            this.regenerating = true;
            try {
                const response = await fetch(pageData.routes.regenerateResizedImages, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast(result.count + pageData.translations.resizeJobsQueued, 'success');
                } else {
                    window.showToast(pageData.translations.failedQueueRegeneration, 'error');
                }
            } catch (error) {
                console.error('Failed to regenerate:', error);
                window.showToast(pageData.translations.failedQueueRegeneration, 'error');
            } finally {
                this.regenerating = false;
            }
        },

        async verifyIntegrity() {
            if (!confirm(pageData.translations.verifyIntegrityConfirm)) {
                return;
            }

            this.verifyingIntegrity = true;
            try {
                const response = await fetch(pageData.routes.verifyIntegrity, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                const result = await response.json();
                if (result.success) {
                    window.showToast(result.count + pageData.translations.integrityChecksQueued, 'success');
                    this.integrityCheckQueued = true;
                    this.integrityQueuedCount = result.count;
                    this.refreshQueueStatus();
                } else {
                    window.showToast(pageData.translations.failedQueueIntegrityCheck, 'error');
                }
            } catch (error) {
                console.error('Failed to verify integrity:', error);
                window.showToast(pageData.translations.failedQueueIntegrityCheck, 'error');
            } finally {
                this.verifyingIntegrity = false;
            }
        },

        async refreshIntegrityStatus() {
            this.refreshingIntegrity = true;
            try {
                const response = await fetch(pageData.routes.integrityStatus);
                const result = await response.json();
                this.missingAssetsCount = result.missing;
                this.integrityCheckQueued = false;
                this.refreshQueueStatus();
            } catch (error) {
                console.error('Failed to refresh integrity status:', error);
            } finally {
                this.refreshingIntegrity = false;
            }
        },

        async updateSetting(key, value) {
            this.savingSettings = true;
            this.settingsSaved = false;
            this.settingsError = '';

            try {
                const response = await fetch(pageData.routes.updateSetting, {
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
                    window.showToast(pageData.translations.settingSaved, 'success');
                    setTimeout(() => { this.settingsSaved = false; }, 3000);

                    // When root folder changes, refresh the folder hierarchy from S3
                    if (key === 's3_root_folder') {
                        await this.refreshFolderHierarchy();
                    }

                    // Toggle caution tape on nav when maintenance mode changes
                    if (key === 'maintenance_mode') {
                        document.querySelector('nav')?.classList.toggle('maintenance-mode', value === '1');
                    }
                } else {
                    this.settingsError = result.error || pageData.translations.failedSaveSetting;
                    window.showToast(this.settingsError, 'error');
                }
            } catch (error) {
                console.error('Failed to update setting:', error);
                this.settingsError = pageData.translations.failedSaveSetting;
                window.showToast(pageData.translations.failedSaveSetting, 'error');
            } finally {
                this.savingSettings = false;
            }
        },

        async refreshFolderHierarchy() {
            try {
                const response = await fetch(pageData.routes.foldersScan, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to refresh folders');
                }

                window.showToast(pageData.translations.folderHierarchyRefreshed, 'success');
            } catch (error) {
                console.error('Failed to refresh folder hierarchy:', error);
                window.showToast(pageData.translations.failedRefreshFolderHierarchy, 'error');
            }
        },

        async testS3Connection() {
            this.testingS3 = true;
            this.s3TestResult = false;

            try {
                const response = await fetch(pageData.routes.testS3);
                const result = await response.json();

                this.s3TestResult = true;
                this.s3TestSuccess = result.success;
                this.s3TestMessage = result.message + (result.error ? ': ' + result.error : '');
            } catch (error) {
                console.error('S3 test failed:', error);
                this.s3TestResult = true;
                this.s3TestSuccess = false;
                this.s3TestMessage = pageData.translations.connectionTestFailed + ' ' + error.message;
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
                const response = await fetch(`${pageData.routes.documentation}?file=${encodeURIComponent(this.selectedDoc)}`);
                const result = await response.json();

                if (result.success) {
                    this.docContent = result.content;
                } else {
                    this.docError = result.error || pageData.translations.failedLoadDocumentation;
                }
            } catch (error) {
                console.error('Failed to load documentation:', error);
                this.docError = pageData.translations.failedLoadDocumentationColon + ' ' + error.message;
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
                const response = await fetch(pageData.routes.runTests, {
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
                        window.showToast(pageData.translations.testsCompleted + ' ' + result.stats.failed + ' ' + pageData.translations.failed, 'error');
                    } else {
                        window.showToast(pageData.translations.all + ' ' + result.stats.passed + ' ' + pageData.translations.testsPassed, 'success');
                    }
                } else {
                    this.testOutput = result.error || pageData.translations.failedRunTests;
                    window.showToast(pageData.translations.failedRunTests, 'error');
                }
            } catch (error) {
                console.error('Failed to run tests:', error);
                this.testOutput = 'Error: ' + error.message;
                window.showToast(pageData.translations.failedRunTests, 'error');
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
                    window.showToast(pageData.translations.outputCopiedToClipboard, 'success');
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
                window.showToast(pageData.translations.outputCopiedToClipboard, 'success');
            } catch (err) {
                console.error('Fallback copy failed:', err);
                window.showToast(pageData.translations.failedCopyOutput, 'error');
            }
            textArea.remove();
        }
    };
}
window.systemAdmin = systemAdmin;
