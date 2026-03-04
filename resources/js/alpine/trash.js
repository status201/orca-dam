function trashPage() {
    return {
        viewMode: localStorage.getItem('orcaTrashViewMode') || 'grid',
        fitMode: localStorage.getItem('orcaTrashFitMode') || 'cover',

        // Bulk operation state
        bulkRestoring: false,
        bulkDeleting: false,
        bulkRestoreResults: null,
        bulkDeleteResults: null,
        bulkRestoreShowSummary: false,
        bulkDeleteShowSummary: false,

        init() {
            // Clear stale selections from other pages
            Alpine.store('bulkSelection').clear();
        },

        saveViewMode() {
            localStorage.setItem('orcaTrashViewMode', this.viewMode);
        },

        saveFitMode() {
            localStorage.setItem('orcaTrashFitMode', this.fitMode);
        },

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        },

        restoreAsset(id) {
            if (confirm(window.__pageData.confirmRestore)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/assets/${id}/restore`;

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = this.getCsrfToken();

                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            }
        },

        confirmDelete(id) {
            if (confirm(window.__pageData.confirmPermanentDelete)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/assets/${id}/force-delete`;

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = this.getCsrfToken();

                const methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                methodInput.value = 'DELETE';

                form.appendChild(csrfInput);
                form.appendChild(methodInput);
                document.body.appendChild(form);
                form.submit();
            }
        },

        async bulkRestore() {
            const translations = window.__pageData || {};
            if (!confirm(translations.confirmBulkRestore || 'Restore the selected assets?')) {
                return;
            }

            this.bulkRestoring = true;
            try {
                const response = await fetch('/assets/trash/bulk-restore', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        asset_ids: Alpine.store('bulkSelection').selected,
                    }),
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.message || 'Failed to restore assets');
                }

                const data = await response.json();
                window.showToast(data.message, 'success');

                if (data.restored_filenames && data.restored_filenames.length > 0) {
                    this.bulkRestoreResults = data;
                    this.bulkRestoreShowSummary = true;
                } else {
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (error) {
                console.error('Bulk restore failed:', error);
                window.showToast(translations.bulkRestoreFailed || 'Failed to restore assets', 'error');
            } finally {
                this.bulkRestoring = false;
            }
        },

        get bulkRestoreSummaryText() {
            if (!this.bulkRestoreResults || !this.bulkRestoreResults.restored_filenames) return '';
            return this.bulkRestoreResults.restored_filenames.join('\n');
        },

        bulkRestoreCopySummary() {
            if (window.copyToClipboard) {
                window.copyToClipboard(this.bulkRestoreSummaryText, window.__pageData?.copied);
            } else {
                navigator.clipboard.writeText(this.bulkRestoreSummaryText);
            }
        },

        bulkRestoreDismissSummary() {
            this.bulkRestoreShowSummary = false;
            this.bulkRestoreResults = null;
            window.location.reload();
        },

        async bulkForceDelete() {
            const translations = window.__pageData || {};
            if (!confirm(translations.confirmBulkForceDelete || 'PERMANENTLY DELETE the selected assets? This cannot be undone!')) {
                return;
            }

            this.bulkDeleting = true;
            try {
                const response = await fetch('/assets/trash/bulk-force-delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        _method: 'DELETE',
                        asset_ids: Alpine.store('bulkSelection').selected,
                    }),
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.message || 'Failed to delete assets');
                }

                const data = await response.json();
                window.showToast(data.message, 'success');

                if (data.deleted_keys && data.deleted_keys.length > 0) {
                    this.bulkDeleteResults = data;
                    this.bulkDeleteShowSummary = true;
                } else {
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (error) {
                console.error('Bulk force delete failed:', error);
                window.showToast(translations.bulkForceDeleteFailed || 'Failed to permanently delete assets', 'error');
            } finally {
                this.bulkDeleting = false;
            }
        },

        get bulkDeleteSummaryText() {
            if (!this.bulkDeleteResults || !this.bulkDeleteResults.deleted_keys) return '';
            return this.bulkDeleteResults.deleted_keys.join('\n');
        },

        bulkDeleteCopySummary() {
            if (window.copyToClipboard) {
                window.copyToClipboard(this.bulkDeleteSummaryText, window.__pageData?.copied);
            } else {
                navigator.clipboard.writeText(this.bulkDeleteSummaryText);
            }
        },

        bulkDeleteDismissSummary() {
            this.bulkDeleteShowSummary = false;
            this.bulkDeleteResults = null;
            window.location.reload();
        },
    }
}

window.trashPage = trashPage;

export { trashPage };
