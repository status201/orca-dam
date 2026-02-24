export function assetGrid() {
    const config = window.assetGridConfig || {};
    return {
        search: config.search || '',
        type: config.type || '',
        folder: config.folder || '',
        sort: config.sort || 'date_desc',
        selectedTags: config.selectedTags || [],
        initialTags: config.initialTags || [],
        showTagFilter: false,
        viewMode: localStorage.getItem('orcaAssetViewMode') || 'grid',
        fitMode: localStorage.getItem('orcaAssetFitMode') || 'cover',
        perPage: localStorage.getItem('orcaAssetsPerPage') || config.perPage || '24',
        tagSearch: '',
        tagSort: 'name_asc',
        allTagsData: config.allTagsData || [],
        folderCount: config.folderCount || 1,

        init() {
            // If user has a stored preference and URL doesn't have per_page, apply it
            const storedPerPage = localStorage.getItem('orcaAssetsPerPage');
            const urlParams = new URLSearchParams(window.location.search);
            if (storedPerPage && !urlParams.has('per_page') && storedPerPage !== config.perPage) {
                urlParams.set('per_page', storedPerPage);
                window.location.href = config.indexRoute + '?' + urlParams.toString();
            }
        },

        saveViewMode() {
            localStorage.setItem('orcaAssetViewMode', this.viewMode);
        },

        saveFitMode() {
            localStorage.setItem('orcaAssetFitMode', this.fitMode);
        },

        savePerPage() {
            localStorage.setItem('orcaAssetsPerPage', this.perPage);
        },

        tagsChanged() {
            // Check if the selected tags differ from initial tags
            if (this.selectedTags.length !== this.initialTags.length) {
                return true;
            }
            // Check if all tags match (order doesn't matter)
            const selected = [...this.selectedTags].sort();
            const initial = [...this.initialTags].sort();
            return !selected.every((tag, index) => tag === initial[index]);
        },

        applyFilters() {
            const params = new URLSearchParams();

            if (this.search) params.append('search', this.search);
            if (this.type) params.append('type', this.type);
            if (this.folder) params.append('folder', this.folder);
            if (this.sort) params.append('sort', this.sort);
            if (this.perPage) params.append('per_page', this.perPage);
            if (this.selectedTags.length > 0) {
                this.selectedTags.forEach(tag => params.append('tags[]', tag));
            }

            window.location.href = config.indexRoute + (params.toString() ? '?' + params.toString() : '');
        },

        copyUrl(url) {
            window.copyToClipboard(url);
        },

        get sortedTags() {
            const sorted = [...this.allTagsData];
            switch (this.tagSort) {
                case 'name_desc':
                    return sorted.sort((a, b) => b.name.localeCompare(a.name));
                case 'most_used':
                    return sorted.sort((a, b) => b.assets_count - a.assets_count);
                case 'least_used':
                    return sorted.sort((a, b) => a.assets_count - b.assets_count);
                case 'newest':
                    return sorted.sort((a, b) => b.created_at.localeCompare(a.created_at));
                case 'oldest':
                    return sorted.sort((a, b) => a.created_at.localeCompare(b.created_at));
                case 'name_asc':
                default:
                    return sorted.sort((a, b) => a.name.localeCompare(b.name));
            }
        },

        shouldShowTag(tag) {
            // Always show selected tags
            if (this.selectedTags.includes(tag.id)) {
                return true;
            }
            // Filter unselected tags by search
            if (!this.tagSearch.trim()) {
                return true;
            }
            return tag.name.toLowerCase().includes(this.tagSearch.toLowerCase());
        }
    };
}
window.assetGrid = assetGrid;

export function assetCard(assetId) {
    const translations = window.assetTranslations || {};
    return {
        copied: false,
        downloading: false,

        async downloadAsset(url) {
            this.downloading = true;
            try {
                // Trigger the download
                window.location.href = url;

                // Show success state briefly
                setTimeout(() => {
                    this.downloading = false;
                }, 2000);
            } catch (error) {
                console.error('Download failed:', error);
                this.downloading = false;
                window.showToast(translations.downloadFailed || 'Download failed', 'error');
            }
        },

        copyAssetUrl(url) {
            window.copyToClipboard(url);
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        }
    };
}
window.assetCard = assetCard;

export function assetRow(assetId, initialTags, initialLicense, assetUrl) {
    const translations = window.assetTranslations || {};
    return {
        assetId: assetId,
        tags: initialTags,
        license: initialLicense,
        previousLicense: initialLicense,
        assetUrl: assetUrl,
        addingTag: false,
        newTagName: '',
        loading: false,
        copied: false,
        showSuggestions: false,
        filteredSuggestions: [],
        selectedSuggestionIndex: -1,

        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        },

        showToast(message, type = 'success') {
            // Use existing toast if available, otherwise fallback to console
            if (window.showToast) {
                window.showToast(message, type);
            } else {
                console.log(`[${type}] ${message}`);
            }
        },

        copyUrl() {
            if (window.copyToClipboard) {
                window.copyToClipboard(this.assetUrl);
            } else {
                navigator.clipboard.writeText(this.assetUrl);
            }
            this.copied = true;
            setTimeout(() => {
                this.copied = false;
            }, 2000);
        },

        showAddTagInput() {
            this.addingTag = true;
            // Focus the input field after Alpine renders it
            this.$nextTick(() => {
                this.$refs.tagInput.focus();
            });
        },

        cancelAddTag() {
            this.addingTag = false;
            this.newTagName = '';
            this.showSuggestions = false;
            this.selectedSuggestionIndex = -1;
        },

        filterTagSuggestions() {
            const input = this.newTagName.toLowerCase().trim();
            const existingTagNames = this.tags.map(t => t.name.toLowerCase());

            if (input === '') {
                // Show all tags not already on this asset
                this.filteredSuggestions = (window.allTags || [])
                    .filter(tag => !existingTagNames.includes(tag.toLowerCase()))
                    .slice(0, 10);
            } else {
                // Filter tags that match the input and aren't already on this asset
                this.filteredSuggestions = (window.allTags || [])
                    .filter(tag =>
                        tag.toLowerCase().includes(input) &&
                        !existingTagNames.includes(tag.toLowerCase())
                    )
                    .slice(0, 10);
            }

            this.showSuggestions = true;
            this.selectedSuggestionIndex = -1;
        },

        selectSuggestion(suggestion) {
            this.newTagName = suggestion;
            this.showSuggestions = false;
            this.selectedSuggestionIndex = -1;
            this.addTag();
        },

        selectNextSuggestion() {
            if (this.filteredSuggestions.length === 0) return;

            this.selectedSuggestionIndex =
                (this.selectedSuggestionIndex + 1) % this.filteredSuggestions.length;

            // Update input with selected suggestion
            if (this.selectedSuggestionIndex >= 0) {
                this.newTagName = this.filteredSuggestions[this.selectedSuggestionIndex];
            }
        },

        selectPrevSuggestion() {
            if (this.filteredSuggestions.length === 0) return;

            this.selectedSuggestionIndex =
                this.selectedSuggestionIndex <= 0
                    ? this.filteredSuggestions.length - 1
                    : this.selectedSuggestionIndex - 1;

            // Update input with selected suggestion
            if (this.selectedSuggestionIndex >= 0) {
                this.newTagName = this.filteredSuggestions[this.selectedSuggestionIndex];
            }
        },

        async removeTag(tag) {
            if (this.loading) return;

            this.loading = true;
            try {
                const response = await fetch(`/assets/${this.assetId}/tags/${tag.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to remove tag');
                }

                // Remove tag from local array
                this.tags = this.tags.filter(t => t.id !== tag.id);
                this.showToast(translations.tagRemoved || 'Tag removed successfully', 'success');
            } catch (error) {
                console.error('Failed to remove tag:', error);
                this.showToast(translations.tagRemoveFailed || 'Failed to remove tag', 'error');
            } finally {
                this.loading = false;
            }
        },

        async addTag() {
            if (this.loading || !this.newTagName.trim()) return;

            this.loading = true;
            try {
                const response = await fetch(`/assets/${this.assetId}/tags`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ tags: [this.newTagName.trim()] }),
                });

                if (!response.ok) {
                    throw new Error('Failed to add tag');
                }

                const data = await response.json();

                // Response includes all tags - update our local array
                if (data.tags && Array.isArray(data.tags)) {
                    this.tags = data.tags.map(t => ({
                        id: t.id,
                        name: t.name,
                        type: t.type
                    }));
                }

                this.showToast(translations.tagAdded || 'Tag added successfully', 'success');
                this.newTagName = '';
                this.addingTag = false;
                this.showSuggestions = false;
                this.selectedSuggestionIndex = -1;
            } catch (error) {
                console.error('Failed to add tag:', error);
                this.showToast(translations.tagAddFailed || 'Failed to add tag', 'error');
            } finally {
                this.loading = false;
            }
        },

        async updateLicense() {
            if (this.loading) return;

            // this.license has already been updated by x-model
            const newLicense = this.license;
            const oldLicense = this.previousLicense;

            // Don't make request if value hasn't actually changed
            if (newLicense === oldLicense) return;

            this.loading = true;

            try {
                // Use POST with _method override for better compatibility
                const formData = new FormData();
                formData.append('_method', 'PATCH');
                formData.append('license_type', newLicense || '');

                const response = await fetch(`/assets/${this.assetId}`, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: formData,
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    console.error('Update failed:', errorData);
                    throw new Error(errorData.message || 'Failed to update license');
                }

                const data = await response.json();
                // Update previousLicense to the new value after successful save
                this.previousLicense = newLicense;
                this.showToast(translations.licenseUpdated || 'License updated successfully', 'success');
            } catch (error) {
                console.error('Failed to update license:', error);
                this.showToast(translations.licenseUpdateFailed || 'Failed to update license', 'error');
                // Revert to previous value on error
                this.license = oldLicense;
            } finally {
                this.loading = false;
            }
        },

        async deleteAsset() {
            if (this.loading) return;

            if (!confirm(translations.deleteConfirm || 'Are you sure you want to delete this asset? It will be moved to trash.')) {
                return;
            }

            this.loading = true;
            try {
                const response = await fetch(`/assets/${this.assetId}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                });

                if (!response.ok) {
                    throw new Error('Failed to delete asset');
                }

                this.showToast(translations.assetDeleted || 'Asset deleted successfully', 'success');

                // Reload page after short delay to show updated list
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } catch (error) {
                console.error('Failed to delete asset:', error);
                this.showToast(translations.assetDeleteFailed || 'Failed to delete asset', 'error');
                this.loading = false;
            }
        }
    };
}
window.assetRow = assetRow;
