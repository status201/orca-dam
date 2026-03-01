export function assetGrid() {
    const config = window.assetGridConfig || {};
    return {
        search: config.search || '',
        type: config.type || '',
        folder: config.folder || '',
        rootFolder: config.rootFolder || '',
        navigating: false,
        sort: config.sort || 'date_desc',
        selectedTags: config.selectedTags || [],
        initialTags: config.initialTags || [],
        showTagFilter: false,
        viewMode: localStorage.getItem('orcaAssetViewMode') || 'grid',
        fitMode: localStorage.getItem('orcaAssetFitMode') || 'cover',
        perPage: config.perPage || '24',
        tagSearch: '',
        tagSort: 'name_asc',
        folderCount: config.folderCount || 1,

        // Lazy-loaded tag filter state
        filterTags: [],
        filterTagsLoading: false,
        filterTagsLoadingMore: false,
        filterTagsPage: 0,
        filterTagsLastPage: 1,
        filterTagsTotal: 0,
        filterTagsLoaded: false,
        pinnedTags: [],
        _filterSearchDebounce: null,
        _bulkSuggestDebounce: null,
        _rowSuggestDebounce: null,

        // Bulk tag management state
        bulkTagInput: '',
        bulkShowSuggestions: false,
        bulkFilteredSuggestions: [],
        bulkSelectedSuggestionIndex: -1,
        bulkRemoveTags: [],
        bulkShowRemovePanel: false,
        bulkLoading: false,
        bulkMoveOpen: false,
        bulkMoveFolder: '',
        bulkMoving: false,
        bulkMoveResults: null,
        bulkMoveShowSummary: false,
        bulkDeleting: false,
        bulkDeleteResults: null,
        bulkDeleteShowSummary: false,

        init() {
            // If page loaded with selected tags, resolve their names for pill display
            if (this.selectedTags.length > 0) {
                this.fetchPinnedTags(this.selectedTags);
            }

            // Sync pinnedTags when selection changes
            this.$watch('selectedTags', (ids) => {
                // Add newly selected tags to pinnedTags
                for (const id of ids) {
                    const alreadyPinned = this.pinnedTags.some(t => String(t.id) === String(id));
                    if (!alreadyPinned) {
                        const tag = this.filterTags.find(t => String(t.id) === String(id));
                        if (tag) this.pinnedTags.push(tag);
                    }
                }
                // Remove unchecked tags from pinnedTags
                const idSet = new Set(ids.map(id => String(id)));
                this.pinnedTags = this.pinnedTags.filter(t => idSet.has(String(t.id)));
            });

            // When tag filter opens, load first page of tags
            this.$watch('showTagFilter', (open) => {
                if (open && !this.filterTagsLoaded) {
                    this.loadFilterTags(1);
                }
            });
        },

        saveViewMode() {
            localStorage.setItem('orcaAssetViewMode', this.viewMode);
        },

        saveFitMode() {
            localStorage.setItem('orcaAssetFitMode', this.fitMode);
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
            if (this.perPage && this.perPage !== config.perPage) params.append('per_page', this.perPage);
            if (this.selectedTags.length > 0) {
                this.selectedTags.forEach(tag => params.append('tags[]', tag));
            }

            this.navigating = true;
            window.location.href = config.indexRoute + (params.toString() ? '?' + params.toString() : '');
        },

        copyUrl(url) {
            window.copyToClipboard(url);
        },

        get displayTags() {
            // Filter out already-selected tags (shown in pinned section)
            const selectedSet = new Set(this.selectedTags.map(id => String(id)));
            return this.filterTags.filter(tag => !selectedSet.has(String(tag.id)));
        },

        get filterHasMore() {
            return this.filterTagsPage < this.filterTagsLastPage;
        },

        async fetchPinnedTags(ids) {
            try {
                const response = await fetch('/tags/by-ids', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids: ids.map(id => parseInt(id)) })
                });
                if (response.ok) {
                    this.pinnedTags = await response.json();
                }
            } catch (error) {
                console.error('Failed to fetch pinned tags:', error);
            }
        },

        async loadFilterTags(page) {
            if (page === 1) {
                this.filterTagsLoading = true;
            } else {
                this.filterTagsLoadingMore = true;
            }

            try {
                const params = new URLSearchParams();
                params.set('page', page);
                params.set('per_page', '60');
                params.set('sort', this.tagSort);
                if (this.tagSearch.trim()) params.set('search', this.tagSearch.trim());

                const response = await fetch(`/tags?${params.toString()}`, {
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) throw new Error('Failed to load tags');

                const data = await response.json();

                if (page === 1) {
                    this.filterTags = data.data;
                } else {
                    this.filterTags = [...this.filterTags, ...data.data];
                }

                this.filterTagsPage = data.current_page;
                this.filterTagsLastPage = data.last_page;
                this.filterTagsTotal = data.total;
                this.filterTagsLoaded = true;
            } catch (error) {
                console.error('Failed to load filter tags:', error);
            } finally {
                this.filterTagsLoading = false;
                this.filterTagsLoadingMore = false;
            }
        },

        onFilterScroll(event) {
            const el = event.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 100) {
                if (this.filterHasMore && !this.filterTagsLoadingMore) {
                    this.loadFilterTags(this.filterTagsPage + 1);
                }
            }
        },

        onFilterTagSearch() {
            clearTimeout(this._filterSearchDebounce);
            this._filterSearchDebounce = setTimeout(() => {
                this.loadFilterTags(1);
            }, 300);
        },

        onFilterTagSortChange() {
            this.loadFilterTags(1);
        },

        // Bulk tag management methods
        getCsrfToken() {
            return document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        },

        bulkFilterTagSuggestions() {
            clearTimeout(this._bulkSuggestDebounce);
            this._bulkSuggestDebounce = setTimeout(async () => {
                try {
                    const input = this.bulkTagInput.trim();
                    const response = await fetch(`/tags/search?q=${encodeURIComponent(input)}`, {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (response.ok) {
                        const tags = await response.json();
                        this.bulkFilteredSuggestions = tags.map(t => t.name).slice(0, 10);
                    }
                } catch (error) {
                    console.error('Tag suggest failed:', error);
                    this.bulkFilteredSuggestions = [];
                }
                this.bulkShowSuggestions = true;
                this.bulkSelectedSuggestionIndex = -1;
            }, 200);
        },

        bulkSelectSuggestion(suggestion) {
            this.bulkTagInput = suggestion;
            this.bulkShowSuggestions = false;
            this.bulkSelectedSuggestionIndex = -1;
            this.bulkAddTag();
        },

        bulkSelectNextSuggestion() {
            if (this.bulkFilteredSuggestions.length === 0) return;
            this.bulkSelectedSuggestionIndex =
                (this.bulkSelectedSuggestionIndex + 1) % this.bulkFilteredSuggestions.length;
            this.bulkTagInput = this.bulkFilteredSuggestions[this.bulkSelectedSuggestionIndex];
        },

        bulkSelectPrevSuggestion() {
            if (this.bulkFilteredSuggestions.length === 0) return;
            this.bulkSelectedSuggestionIndex =
                this.bulkSelectedSuggestionIndex <= 0
                    ? this.bulkFilteredSuggestions.length - 1
                    : this.bulkSelectedSuggestionIndex - 1;
            this.bulkTagInput = this.bulkFilteredSuggestions[this.bulkSelectedSuggestionIndex];
        },

        async bulkAddTag() {
            const tagName = this.bulkTagInput.trim();
            if (this.bulkLoading || !tagName) return;

            this.bulkLoading = true;
            try {
                const response = await fetch('/assets/bulk/tags', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        asset_ids: Alpine.store('bulkSelection').selected,
                        tags: [tagName],
                    }),
                });

                if (!response.ok) throw new Error('Failed to add tags');

                const data = await response.json();
                window.showToast(data.message, 'success');
                this.bulkTagInput = '';
                this.bulkShowSuggestions = false;

                setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                console.error('Bulk add tag failed:', error);
                window.showToast(window.assetTranslations?.tagAddFailed || 'Failed to add tag', 'error');
            } finally {
                this.bulkLoading = false;
            }
        },

        async bulkLoadRemoveTags() {
            if (this.bulkLoading) return;

            this.bulkLoading = true;
            try {
                const response = await fetch('/assets/bulk/tags/list', {
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

                if (!response.ok) throw new Error('Failed to load tags');

                const data = await response.json();
                this.bulkRemoveTags = data.tags;
                this.bulkShowRemovePanel = true;
            } catch (error) {
                console.error('Bulk load tags failed:', error);
                window.showToast('Failed to load tags', 'error');
            } finally {
                this.bulkLoading = false;
            }
        },

        async bulkMoveApply() {
            const translations = window.assetTranslations || {};
            if (!this.bulkMoveFolder) return;
            if (!confirm(translations.moveConfirm || 'This will change the S3 keys of the selected assets. External links to the old URLs will break. Are you sure?')) {
                return;
            }

            this.bulkMoving = true;
            try {
                const response = await fetch('/assets/bulk/move', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        asset_ids: Alpine.store('bulkSelection').selected,
                        destination_folder: this.bulkMoveFolder,
                    }),
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.message || 'Failed to move assets');
                }

                const data = await response.json();
                window.showToast(data.message, 'success');

                if (data.moves && data.moves.length > 0) {
                    this.bulkMoveResults = data;
                    this.bulkMoveShowSummary = true;
                } else {
                    setTimeout(() => window.location.reload(), 800);
                }
            } catch (error) {
                console.error('Bulk move failed:', error);
                window.showToast(translations.moveFailed || 'Failed to move assets', 'error');
            } finally {
                this.bulkMoving = false;
                this.bulkMoveOpen = false;
            }
        },

        get bulkMoveSummaryText() {
            if (!this.bulkMoveResults || !this.bulkMoveResults.moves) return '';
            return this.bulkMoveResults.moves.map(m => `${m.old} → ${m.new}`).join('\n');
        },

        bulkMoveCopySummary() {
            if (window.copyToClipboard) {
                window.copyToClipboard(this.bulkMoveSummaryText);
            } else {
                navigator.clipboard.writeText(this.bulkMoveSummaryText);
            }
        },

        bulkMoveDismissSummary() {
            this.bulkMoveShowSummary = false;
            this.bulkMoveResults = null;
            window.location.reload();
        },

        async bulkForceDelete() {
            const translations = window.assetTranslations || {};
            if (!confirm(translations.forceDeleteConfirm || 'This will PERMANENTLY delete the selected assets, their thumbnails, and all resized formats from S3. External links will no longer work. This action cannot be undone. Are you sure?')) {
                return;
            }

            this.bulkDeleting = true;
            try {
                const response = await fetch('/assets/bulk/force-delete', {
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
                window.showToast(translations.forceDeleteFailed || 'Failed to permanently delete assets', 'error');
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
                window.copyToClipboard(this.bulkDeleteSummaryText);
            } else {
                navigator.clipboard.writeText(this.bulkDeleteSummaryText);
            }
        },

        bulkDeleteDismissSummary() {
            this.bulkDeleteShowSummary = false;
            this.bulkDeleteResults = null;
            window.location.reload();
        },

        async bulkRemoveTag(tagId) {
            if (this.bulkLoading) return;

            this.bulkLoading = true;
            try {
                const response = await fetch('/assets/bulk/tags/remove', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.getCsrfToken(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        asset_ids: Alpine.store('bulkSelection').selected,
                        tag_ids: [tagId],
                    }),
                });

                if (!response.ok) throw new Error('Failed to remove tag');

                const data = await response.json();
                window.showToast(data.message, 'success');

                setTimeout(() => window.location.reload(), 800);
            } catch (error) {
                console.error('Bulk remove tag failed:', error);
                window.showToast(window.assetTranslations?.tagRemoveFailed || 'Failed to remove tag', 'error');
            } finally {
                this.bulkLoading = false;
            }
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
            clearTimeout(this._suggestDebounce);
            this._suggestDebounce = setTimeout(async () => {
                try {
                    const input = this.newTagName.trim();
                    const existingTagNames = this.tags.map(t => t.name.toLowerCase());

                    const response = await fetch(`/tags/search?q=${encodeURIComponent(input)}`, {
                        headers: { 'Accept': 'application/json' }
                    });
                    if (response.ok) {
                        const tags = await response.json();
                        this.filteredSuggestions = tags
                            .map(t => t.name)
                            .filter(name => !existingTagNames.includes(name.toLowerCase()))
                            .slice(0, 10);
                    }
                } catch (error) {
                    console.error('Tag suggest failed:', error);
                    this.filteredSuggestions = [];
                }
                this.showSuggestions = true;
                this.selectedSuggestionIndex = -1;
            }, 200);
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
