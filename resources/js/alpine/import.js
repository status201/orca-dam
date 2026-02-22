function importMetadata() {
    const pageData = window.__pageData?.import || {};

    return {
        step: 1,
        matchField: 's3_key',
        csvData: '',
        csvFileName: '',
        dragActive: false,
        loading: false,
        errorMessage: '',
        previewData: { total: 0, matched: 0, unmatched: 0, skipped: 0, results: [] },
        importResult: { updated: 0, skipped: 0, errors: [] },

        get matchedResults() {
            return this.previewData.results?.filter(r => r.status === 'matched') || [];
        },

        get unmatchedResults() {
            return this.previewData.results?.filter(r => r.status === 'not_found') || [];
        },

        get hasValidationErrors() {
            return this.matchedResults.some(r => r.errors && r.errors.length > 0);
        },

        handleFileDrop(e) {
            this.dragActive = false;
            const file = e.dataTransfer.files[0];
            if (file) this.readCsvFile(file);
        },

        handleFileSelect(e) {
            const file = e.target.files[0];
            if (file) this.readCsvFile(file);
            e.target.value = '';
        },

        readCsvFile(file) {
            if (!file.name.match(/\.(csv|txt)$/i)) {
                this.errorMessage = pageData.translations.pleaseSelectCsv;
                return;
            }
            const reader = new FileReader();
            reader.onload = (e) => {
                this.csvData = e.target.result;
                this.csvFileName = file.name;
            };
            reader.onerror = () => {
                this.errorMessage = pageData.translations.failedToReadFile;
            };
            reader.readAsText(file);
        },

        extractError(data) {
            if (data.error) return data.error;
            if (data.errors) {
                const firstField = Object.keys(data.errors)[0];
                if (firstField) return data.errors[firstField][0];
            }
            if (data.message) return data.message;
            return pageData.translations.unexpectedError;
        },

        async previewImport() {
            this.loading = true;
            this.errorMessage = '';

            try {
                const response = await fetch(pageData.routes.importPreview, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': pageData.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        csv_data: this.csvData,
                        match_field: this.matchField,
                    }),
                });

                if (!response.ok) {
                    try {
                        const data = await response.json();
                        this.errorMessage = this.extractError(data);
                    } catch {
                        this.errorMessage = pageData.translations.unexpectedError + ' (' + response.status + ')';
                    }
                    return;
                }

                this.previewData = await response.json();
                this.step = 2;
            } catch (e) {
                this.errorMessage = pageData.translations.networkError;
            } finally {
                this.loading = false;
            }
        },

        async runImport() {
            this.loading = true;
            this.errorMessage = '';

            try {
                const response = await fetch(pageData.routes.importImport, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': pageData.csrfToken,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        csv_data: this.csvData,
                        match_field: this.matchField,
                    }),
                });

                if (!response.ok) {
                    try {
                        const data = await response.json();
                        this.errorMessage = this.extractError(data);
                    } catch {
                        this.errorMessage = pageData.translations.unexpectedError + ' (' + response.status + ')';
                    }
                    return;
                }

                this.importResult = await response.json();
                this.step = 3;
            } catch (e) {
                this.errorMessage = pageData.translations.networkError;
            } finally {
                this.loading = false;
            }
        },

        startOver() {
            this.step = 1;
            this.csvData = '';
            this.csvFileName = '';
            this.previewData = { total: 0, matched: 0, unmatched: 0, skipped: 0, results: [] };
            this.importResult = { updated: 0, skipped: 0, errors: [] };
            this.errorMessage = '';
        },
    }
}

window.importMetadata = importMetadata;

export default importMetadata;
