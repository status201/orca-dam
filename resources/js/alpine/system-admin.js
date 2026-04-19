import { applyFirefoxBgLocalPolyfill } from './firefox-bg-local-polyfill';

export function systemAdmin() {
    const pageData = window.__systemPageData;

    return {
        activeTab: 'settings',

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
        rootFolderLocked: true,
        settingsSaved: false,
        settingsError: '',
        savingSettings: false,
        regenerating: false,
        verifyingIntegrity: false,
        missingAssetsCount: pageData.missingAssetsCount,
        integrityCheckQueued: false,
        integrityQueuedCount: 0,
        refreshingIntegrity: false,
        _lastColorSwatchContent: '',

        systemInfo: {
          jwtEnvEnabled: pageData.jwtEnvEnabled,
        },
        cloudflareEnvEnabled: pageData.cloudflareEnvEnabled,

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
        testElapsed: 0,
        testEstimate: null,
        testRunId: null,
        testRunStatus: null,
        testRunStartedAt: null,
        testRunWorkerWarning: false,
        testPollHandle: null,
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
                    this.activeTab = 'settings';
                }
            });

            // Initial load
            this.refreshQueueStatus();
            this.refreshSupervisorStatus();

            // Deep-link: ?section=<id> scrolls to that element after the tab renders
            const params = new URLSearchParams(window.location.search);
            const section = params.get('section');
            if (section) {
                this.$nextTick(() => {
                    const el = document.getElementById(section);
                    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
                params.delete('section');
                const qs = params.toString();
                const clean = window.location.pathname + (qs ? '?' + qs : '') + window.location.hash;
                history.replaceState(null, '', clean);
            }

            // Color swatches for TikZ color package textarea
            this.$nextTick(() => this.updateColorSwatches());
            this.$watch('settings.tikz_color_package', () => {
                this.$nextTick(() => this.updateColorSwatches());
            });
            this.$watch('activeTab', (tab) => {
                if (tab === 'settings') {
                    this.$nextTick(() => this.updateColorSwatches());
                }
            });
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

        unlockRootFolder() {
            if (confirm(pageData.translations.rootFolderUnlockConfirm)) {
                this.rootFolderLocked = false;
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
                    this.docContent = this.addHeadingIds(result.content);
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
            this.stopTestPolling();

            this.runningTests = true;
            this.testOutput = '';
            this.testProgress = 0;
            this.testElapsed = 0;
            this.testEstimate = null;
            this.testRunId = null;
            this.testRunStatus = 'queued';
            this.testRunStartedAt = Date.now();
            this.testRunWorkerWarning = false;
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

                if (!result.success || !result.run_id) {
                    this.testOutput = result.error || pageData.translations.failedRunTests;
                    window.showToast(pageData.translations.failedRunTests, 'error');
                    this.runningTests = false;
                    return;
                }

                this.testRunId = result.run_id;
                this.testPollHandle = setInterval(() => this.pollTestRun(), 750);
                // Fire an immediate poll so the UI updates before the first tick.
                this.pollTestRun();
                // Surface the freshly-queued job in the Queue tab without
                // requiring a manual refresh.
                this.refreshQueueStatus();
            } catch (error) {
                console.error('Failed to run tests:', error);
                this.testOutput = 'Error: ' + error.message;
                window.showToast(pageData.translations.failedRunTests, 'error');
                this.runningTests = false;
            }
        },

        async pollTestRun() {
            if (!this.testRunId) return;

            try {
                const url = pageData.routes.runTestsStatus.replace('__RUN_ID__', this.testRunId);
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!response.ok) {
                    // If the run vanished (cache evicted / server restart), stop polling gracefully.
                    if (response.status === 404) {
                        this.stopTestPolling();
                        this.runningTests = false;
                        this.testOutput = pageData.translations.testRunLost || 'Test run not found.';
                        window.showToast(pageData.translations.failedRunTests, 'error');
                    }
                    return;
                }

                const result = await response.json();
                if (!result.success || !result.state) return;

                const state = result.state;
                this.testRunStatus = state.status;
                this.testElapsed = state.duration || 0;
                this.testEstimate = state.estimate || null;

                // Pest/PHPUnit buffer their output until the subprocess exits,
                // so we can't stream per-test counters on Windows. Drive the
                // bar off wall-clock elapsed vs. last run's duration — caps at
                // 95% so the final jump to 100 always coincides with "done".
                if (state.status === 'running' && this.testEstimate && this.testEstimate > 0) {
                    const pct = (this.testElapsed / this.testEstimate) * 100;
                    this.testProgress = Math.min(95, Math.max(0, pct));
                } else if (state.status === 'completed' || state.status === 'failed' || state.status === 'aborted') {
                    this.testProgress = 100;
                }

                // Show a worker-missing hint if we've been queued for a while.
                if (state.status === 'queued' && Date.now() - this.testRunStartedAt > 3000) {
                    this.testRunWorkerWarning = true;
                } else {
                    this.testRunWorkerWarning = false;
                }

                if (state.status === 'completed' || state.status === 'failed' || state.status === 'aborted') {
                    this.finishTestRun(state);
                }
            } catch (error) {
                console.error('Failed to poll test run:', error);
            }
        },

        finishTestRun(state) {
            this.stopTestPolling();

            const stats = state.stats || {
                total: (state.passed || 0) + (state.failed || 0) + (state.skipped || 0),
                passed: state.passed || 0,
                failed: state.failed || 0,
                skipped: state.skipped || 0,
                assertions: 0,
                duration: state.duration || 0,
                tests: [],
            };

            this.testStats = stats;
            this.testOutput = state.output_tail || '';
            this.testProgress = 100;
            this.runningTests = false;
            this.testRunWorkerWarning = false;

            this.$nextTick(() => this.autoExpandFailedSuites());

            // Refresh the Queue tab so the now-finished job disappears without
            // requiring a manual refresh.
            this.refreshQueueStatus();

            if (state.status === 'aborted') {
                window.showToast(pageData.translations.testsAborted || 'Tests aborted', 'info');
            } else if (state.status === 'failed' && state.error) {
                this.testOutput = state.error + (state.output_tail ? '\n\n' + state.output_tail : '');
                window.showToast(pageData.translations.failedRunTests, 'error');
            } else if (stats.failed > 0) {
                window.showToast(pageData.translations.testsCompleted + ' ' + stats.failed + ' ' + pageData.translations.failed, 'error');
            } else {
                window.showToast(pageData.translations.all + ' ' + stats.passed + ' ' + pageData.translations.testsPassed, 'success');
            }
        },

        stopTestPolling() {
            if (this.testPollHandle) {
                clearInterval(this.testPollHandle);
                this.testPollHandle = null;
            }
        },

        async abortTestRun() {
            if (!this.testRunId) return;

            try {
                const url = pageData.routes.runTestsAbort.replace('__RUN_ID__', this.testRunId);
                await fetch(url, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                // The next poll tick will see status=aborted and call finishTestRun().
            } catch (error) {
                console.error('Failed to abort test run:', error);
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

        addHeadingIds(html) {
            const div = document.createElement('div');
            div.innerHTML = html;
            div.querySelectorAll('h1, h2, h3, h4, h5, h6').forEach(heading => {
                if (!heading.id) {
                    heading.id = heading.textContent.trim()
                        .toLowerCase()
                        .replace(/[^\w\s-]/g, '')
                        .replace(/\s+/g, '-');
                }
            });
            return div.innerHTML;
        },

        handleDocClick(event) {
            const link = event.target.closest('a[href^="#"]');
            if (!link) return;

            event.preventDefault();
            const targetId = link.getAttribute('href').substring(1);
            const target = document.getElementById(targetId);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth' });
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
        },

        updateColorSwatches() {
            var ta = this.$refs.tikzColorPackage;
            if (!ta) return;

            var content = this.settings.tikz_color_package || '';

            if (content === this._lastColorSwatchContent) return;
            this._lastColorSwatchContent = content;

            var lines = content.split('\n');
            var style = window.getComputedStyle(ta);
            var fontSize = parseFloat(style.fontSize);
            var lineHeight = parseFloat(style.lineHeight) || fontSize * 1.5;
            var paddingTop = parseFloat(style.paddingTop);
            var gutterWidth = 24;

            var hexPattern = /\\definecolor\{[^}]*\}\{html\}\{([0-9a-f]{3,8})\}/i;
            var rgbPattern = /\\definecolor\{[^}]*\}\{rgb\}\{(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\}/i;

            var circles = '';
            var hasAny = false;

            for (var i = 0; i < lines.length; i++) {
                var hexMatch = lines[i].match(hexPattern);
                var rgbMatch = lines[i].match(rgbPattern);
                var color = null;

                if (hexMatch) {
                    color = '#' + hexMatch[1];
                } else if (rgbMatch) {
                    color = 'rgb(' + rgbMatch[1] + ',' + rgbMatch[2] + ',' + rgbMatch[3] + ')';
                }

                if (color) {
                    hasAny = true;
                    var cy = paddingTop + (i + 0.5) * lineHeight;
                    var cx = gutterWidth / 2;
                    var r = 5;
                    circles += '<circle cx="' + cx + '" cy="' + cy + '" r="' + r + '" fill="' + color + '" stroke="#d1d5db" stroke-width="1"/>';
                }
            }

            if (!hasAny) {
                ta.style.backgroundImage = 'none';
                ta.style.paddingLeft = '';
                return;
            }

            var svgHeight = paddingTop + lines.length * lineHeight + 20;
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + gutterWidth + '" height="' + svgHeight + '">' +
                circles +
                '</svg>';

            ta.style.backgroundImage = 'url("data:image/svg+xml,' + encodeURIComponent(svg) + '")';
            ta.style.backgroundAttachment = 'local';
            ta.style.backgroundRepeat = 'no-repeat';
            ta.style.paddingLeft = (gutterWidth + 8) + 'px';
            applyFirefoxBgLocalPolyfill(ta);
        }
    };
}
window.systemAdmin = systemAdmin;
