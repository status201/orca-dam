export function tagManager() {
    const t = window.__pageData?.translations || {};

    return {
        showEditModal: false,
        editingTagId: null,
        editingTagName: '',
        searchQuery: '',
        tags: window.__pageData?.tags || [],

        get matchingCount() {
            if (this.searchQuery.length === 0) {
                return this.tags.length;
            }
            const query = this.searchQuery.toLowerCase();
            return this.tags.filter(tag => tag.name.toLowerCase().includes(query)).length;
        },

        get matchingUserCount() {
            const userTags = this.tags.filter(tag => tag.type === 'user');
            if (this.searchQuery.length === 0) {
                return userTags.length;
            }
            const query = this.searchQuery.toLowerCase();
            return userTags.filter(tag => tag.name.toLowerCase().includes(query)).length;
        },

        get matchingAiCount() {
            const aiTags = this.tags.filter(tag => tag.type === 'ai');
            if (this.searchQuery.length === 0) {
                return aiTags.length;
            }
            const query = this.searchQuery.toLowerCase();
            return aiTags.filter(tag => tag.name.toLowerCase().includes(query)).length;
        },

        matchesSearch(tagName) {
            if (this.searchQuery.length === 0) {
                return true;
            }
            return tagName.toLowerCase().includes(this.searchQuery.toLowerCase());
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
                    window.showToast(t.tagUpdated || 'Tag updated successfully');
                    window.location.reload();
                } else {
                    window.showToast(data.message || t.tagUpdateFailed || 'Failed to update tag', 'error');
                }
            } catch (error) {
                console.error('Update error:', error);
                window.showToast(t.tagUpdateFailed || 'Failed to update tag', 'error');
            }
        },

        async deleteTag(id, name, type) {
            const tagType = type === 'ai' ? (t.aiTag || 'AI tag') : (t.tag || 'tag');
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
                    window.showToast(t.tagDeleted || 'Tag deleted successfully');
                    window.location.reload();
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
