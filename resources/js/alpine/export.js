export function exportAssets() {
    return {
        folder: '',
        fileType: '',
        selectedTags: [],
        tagSearch: '',
        tagSort: 'name_asc',
        tagType: '',

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

        init() {
            // Sync pinnedTags when selection changes
            this.$watch('selectedTags', (ids) => {
                for (const id of ids) {
                    const alreadyPinned = this.pinnedTags.some(t => String(t.id) === String(id));
                    if (!alreadyPinned) {
                        const tag = this.filterTags.find(t => String(t.id) === String(id));
                        if (tag) this.pinnedTags.push(tag);
                    }
                }
                const idSet = new Set(ids.map(id => String(id)));
                this.pinnedTags = this.pinnedTags.filter(t => idSet.has(String(t.id)));
            });

            // Load first page of tags on init
            this.loadFilterTags(1);
        },

        get displayTags() {
            const selectedSet = new Set(this.selectedTags.map(id => String(id)));
            return this.filterTags.filter(tag => !selectedSet.has(String(tag.id)));
        },

        get filterHasMore() {
            return this.filterTagsPage < this.filterTagsLastPage;
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
                if (this.tagType) params.set('type', this.tagType);

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

        onFilterTagTypeChange() {
            this.loadFilterTags(1);
        },

        resetFilters() {
            this.folder = '';
            this.fileType = '';
            this.selectedTags = [];
            this.pinnedTags = [];
            this.tagSearch = '';
            this.tagType = '';
            this.tagSort = 'name_asc';
            this.loadFilterTags(1);
        }
    }
}

window.exportAssets = exportAssets;
