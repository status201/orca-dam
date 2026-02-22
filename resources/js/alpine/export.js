export function exportAssets() {
    return {
        folder: '',
        fileType: '',
        selectedTags: [],
        tagSearch: '',
        tagSearchDebounced: '',
        _tagSearchTimeout: null,
        lastCheckedIndex: null,
        allTagsData: window.__pageData?.allTagsData || [],

        init() {
            this.$watch('tagSearch', (value) => {
                clearTimeout(this._tagSearchTimeout);
                this._tagSearchTimeout = setTimeout(() => {
                    this.tagSearchDebounced = value;
                }, 300);
            });
        },

        handleTagClick(event, tag) {
            const currentIndex = this.allTagsData.findIndex(t => t.id === tag.id);
            if (event.shiftKey && this.lastCheckedIndex !== null && currentIndex !== this.lastCheckedIndex) {
                const anchorIndex = this.lastCheckedIndex;
                this.$nextTick(() => {
                    const isChecked = this.selectedTags.includes(tag.id);
                    const start = Math.min(anchorIndex, currentIndex);
                    const end = Math.max(anchorIndex, currentIndex);
                    for (let i = start; i <= end; i++) {
                        const t = this.allTagsData[i];
                        if (!this.shouldShowTag(t)) continue;
                        if (t.id === tag.id) continue;
                        const idx = this.selectedTags.indexOf(t.id);
                        if (isChecked && idx === -1) {
                            this.selectedTags.push(t.id);
                        } else if (!isChecked && idx !== -1) {
                            this.selectedTags.splice(idx, 1);
                        }
                    }
                });
            }
            this.lastCheckedIndex = currentIndex;
        },

        shouldShowTag(tag) {
            if (this.selectedTags.includes(tag.id)) {
                return true;
            }
            if (!this.tagSearchDebounced.trim()) {
                return true;
            }
            return tag.name.toLowerCase().includes(this.tagSearchDebounced.toLowerCase());
        },

        resetFilters() {
            this.folder = '';
            this.fileType = '';
            this.selectedTags = [];
            this.tagSearch = '';
        }
    }
}

window.exportAssets = exportAssets;
