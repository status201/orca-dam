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

        init() {
            this.loadPage(1);

            // Set up IntersectionObserver for infinite scroll
            this.$nextTick(() => {
                const sentinel = this.$refs.scrollSentinel;
                if (sentinel) {
                    const observer = new IntersectionObserver((entries) => {
                        if (entries[0].isIntersecting && this.hasMore && !this.loadingMore) {
                            this.loadPage(this.currentPage + 1);
                        }
                    }, { rootMargin: '200px' });
                    observer.observe(sentinel);
                }
            });
        },

        get hasMore() {
            return this.currentPage < this.lastPage;
        },

        async loadPage(page) {
            if (page === 1) {
                this.loading = true;
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
