import { applyShiftSelect } from '../shift-select';

export function tagManager() {
    const t = window.__pageData?.translations || {};
    const config = window.__pageData?.tagConfig || {};

    return {
        showEditModal: false,
        editingTagId: null,
        editingTagName: '',
        searchQuery: '',
        activeSearch: '',
        type: config.type || '',
        sort: config.sort || 'name_asc',
        typeCounts: config.typeCounts || { all: 0, user: 0, ai: 0, reference: 0 },

        // Pagination state
        tags: [],
        loading: false,
        loadingMore: false,
        currentPage: 0,
        lastPage: 1,
        total: 0,
        _searchDebounce: null,

        _observer: null,

        // Bulk selection state
        selected: [],
        lastClickedIndex: null,
        bulkDeleting: false,

        init() {
            this.loadPage(1);
        },

        _setupObserver() {
            if (this._observer) return;

            this.$nextTick(() => {
                const sentinel = this.$refs.scrollSentinel;
                if (sentinel) {
                    this._observer = new IntersectionObserver((entries) => {
                        if (entries[0].isIntersecting && this.hasMore && !this.loadingMore) {
                            this.loadPage(this.currentPage + 1);
                        }
                    }, { rootMargin: '200px' });
                    this._observer.observe(sentinel);
                }
            });
        },

        get hasMore() {
            return this.currentPage < this.lastPage;
        },

        async loadPage(page) {
            if (page === 1) {
                this.loading = true;
                if (this._observer) {
                    this._observer.disconnect();
                    this._observer = null;
                }
            } else {
                this.loadingMore = true;
            }

            try {
                const params = new URLSearchParams();
                params.set('page', page);
                params.set('per_page', '60');
                params.set('sort', this.sort);
                if (this.type) params.set('type', this.type);
                if (this.activeSearch) params.set('search', this.activeSearch);

                const response = await fetch(`/tags?${params.toString()}`, {
                    headers: { 'Accept': 'application/json' }
                });

                if (!response.ok) throw new Error('Failed to load tags');

                const data = await response.json();

                if (page === 1) {
                    this.tags = data.data;
                } else {
                    this.tags = [...this.tags, ...data.data];
                }

                this.currentPage = data.current_page;
                this.lastPage = data.last_page;
                this.total = data.total;
            } catch (error) {
                console.error('Failed to load tags:', error);
            } finally {
                this.loading = false;
                this.loadingMore = false;
                this._setupObserver();
            }
        },

        onSearchInput() {
            clearTimeout(this._searchDebounce);
            this._searchDebounce = setTimeout(() => {
                this.activeSearch = this.searchQuery;
                this.loadPage(1);
            }, 300);
        },

        changeType(newType) {
            this.type = newType;
            this.clearSelection();
            this.loadPage(1);
            this.updateUrl();
        },

        changeSort(newSort) {
            this.sort = newSort;
            this.loadPage(1);
            this.updateUrl();
        },

        updateUrl() {
            const params = new URLSearchParams();
            if (this.type) params.set('type', this.type);
            if (this.sort && this.sort !== 'name_asc') params.set('sort', this.sort);
            const qs = params.toString();
            history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : ''));
        },

        // Selection methods
        toggleSelect(id, event) {
            const ids = this.tags.map(tag => tag.id);
            this.lastClickedIndex = applyShiftSelect(ids, this.selected, id, this.lastClickedIndex, event);
        },

        isSelected(id) {
            return this.selected.includes(id);
        },

        clearSelection() {
            this.selected = [];
            this.lastClickedIndex = null;
        },

        get hasSelection() {
            return this.selected.length > 0;
        },

        get allSelected() {
            return this.tags.length > 0 && this.tags.every(tag => this.selected.includes(tag.id));
        },

        toggleSelectAll() {
            if (this.allSelected) {
                this.selected = [];
            } else {
                this.selected = this.tags.map(tag => tag.id);
            }
            this.lastClickedIndex = null;
        },

        async bulkDeleteSelected() {
            const count = this.selected.length;
            const msg = (t.confirmBulkDelete || 'Are you sure you want to delete :count tags? This will remove them from all assets.').replace(':count', count);
            if (!confirm(msg)) return;

            this.bulkDeleting = true;
            try {
                const response = await fetch('/tags/bulk', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids: this.selected })
                });

                const data = await response.json();

                if (response.ok) {
                    const deletedIds = new Set(this.selected);
                    // Update type counts
                    this.tags.forEach(tag => {
                        if (deletedIds.has(tag.id) && this.typeCounts[tag.type] !== undefined) {
                            this.typeCounts[tag.type]--;
                        }
                    });
                    this.typeCounts.all -= deletedIds.size;
                    // Remove from local array
                    this.tags = this.tags.filter(tag => !deletedIds.has(tag.id));
                    this.total -= data.count;
                    this.clearSelection();
                    window.showToast(data.message || (t.bulkDeleteSuccess || 'Tags deleted successfully'));
                } else {
                    window.showToast(data.message || (t.bulkDeleteFailed || 'Failed to delete tags'), 'error');
                }
            } catch (error) {
                console.error('Bulk delete error:', error);
                window.showToast(t.bulkDeleteFailed || 'Failed to delete tags', 'error');
            } finally {
                this.bulkDeleting = false;
            }
        },

        editTag(id, name) {
            this.editingTagId = id;
            this.editingTagName = name;
            this.showEditModal = true;
        },

        async updateTag() {
            try {
                const response = await fetch(`/tags/${this.editingTagId}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        name: this.editingTagName
                    })
                });

                const data = await response.json();

                if (response.ok) {
                    // Update local array instead of reloading
                    const tag = this.tags.find(t => t.id === this.editingTagId);
                    if (tag) {
                        tag.name = data.tag.name;
                    }
                    this.showEditModal = false;
                    window.showToast(t.tagUpdated || 'Tag updated successfully');
                } else {
                    window.showToast(data.message || t.tagUpdateFailed || 'Failed to update tag', 'error');
                }
            } catch (error) {
                console.error('Update error:', error);
                window.showToast(t.tagUpdateFailed || 'Failed to update tag', 'error');
            }
        },

        async deleteTag(id, name, type) {
            const tagType = type === 'ai' ? (t.aiTag || 'AI tag') : (type === 'reference' ? (t.referenceTag || 'reference tag') : (t.tag || 'tag'));
            if (!confirm((t.confirmDeleteThe || 'Are you sure you want to delete the') + ` ${tagType} "${name}"? ` + (t.removeFromAllAssets || 'This will remove it from all assets.'))) {
                return;
            }

            try {
                const response = await fetch(`/tags/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    }
                });

                const data = await response.json();

                if (response.ok) {
                    // Remove from local array instead of reloading
                    this.tags = this.tags.filter(tag => tag.id !== id);
                    this.total--;
                    // Update type counts
                    if (this.typeCounts[type] !== undefined) {
                        this.typeCounts[type]--;
                    }
                    this.typeCounts.all--;
                    // Remove from selection if selected
                    const selIdx = this.selected.indexOf(id);
                    if (selIdx !== -1) this.selected.splice(selIdx, 1);
                    window.showToast(t.tagDeleted || 'Tag deleted successfully');
                } else {
                    window.showToast(data.message || t.tagDeleteFailed || 'Failed to delete tag', 'error');
                }
            } catch (error) {
                console.error('Delete error:', error);
                window.showToast(t.tagDeleteFailed || 'Failed to delete tag', 'error');
            }
        }
    };
}

window.tagManager = tagManager;
