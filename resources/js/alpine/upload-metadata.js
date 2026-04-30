export function uploadMetadata() {
    var pd = window.__pageData || {};
    var tagsSearchUrl = (pd.routes && pd.routes.tagsSearch) || pd.tagsSearch || '/tags/search';

    return {
        showMetadata: false,
        metadataTags: [],
        metadataReferenceTags: [],
        metadataNewTag: '',
        metadataTagSuggestions: [],
        metadataShowSuggestions: false,
        metadataSelectedIndex: -1,
        metadataSearchTimeout: null,
        metadataLicenseType: '',
        metadataCopyright: '',
        metadataCopyrightSource: '',

        metadataAddTag() {
            var tag = this.metadataNewTag.trim().toLowerCase();
            if (!tag) return;
            if (this.metadataTags.includes(tag) || this.metadataReferenceTags.some(function (t) { return t.name === tag; })) {
                this.metadataNewTag = '';
                return;
            }
            this.metadataTags.push(tag);
            this.metadataNewTag = '';
            this.metadataShowSuggestions = false;
            this.metadataSelectedIndex = -1;
        },

        metadataAddTagOrSelectSuggestion() {
            if (this.metadataSelectedIndex >= 0 && this.metadataTagSuggestions[this.metadataSelectedIndex]) {
                this.metadataSelectSuggestion(this.metadataTagSuggestions[this.metadataSelectedIndex]);
            } else {
                this.metadataAddTag();
            }
        },

        metadataRemoveTag(index) {
            this.metadataTags.splice(index, 1);
        },

        metadataRemoveReferenceTag(index) {
            this.metadataReferenceTags.splice(index, 1);
        },

        metadataSearchTags() {
            clearTimeout(this.metadataSearchTimeout);

            if (this.metadataNewTag.trim().length < 1) {
                this.metadataTagSuggestions = [];
                this.metadataShowSuggestions = false;
                return;
            }

            var self = this;
            this.metadataSearchTimeout = setTimeout(async function () {
                try {
                    var response = await fetch(tagsSearchUrl + '?q=' + encodeURIComponent(self.metadataNewTag) + '&types=user,reference');
                    var data = await response.json();
                    var attachedRefIds = new Set(self.metadataReferenceTags.map(function (t) { return t.id; }));
                    self.metadataTagSuggestions = data.filter(function (tag) {
                        if (tag.type === 'reference') return !attachedRefIds.has(tag.id);
                        return !self.metadataTags.includes(tag.name);
                    });
                    self.metadataShowSuggestions = self.metadataTagSuggestions.length > 0;
                    self.metadataSelectedIndex = -1;
                } catch (error) {
                    console.error('Tag search failed:', error);
                }
            }, 300);
        },

        metadataSelectSuggestion(suggestion) {
            // Backwards-compat: callers used to pass a plain name string
            if (typeof suggestion === 'string') {
                this.metadataNewTag = suggestion;
                this.metadataAddTag();
                return;
            }

            if (suggestion.type === 'reference') {
                if (!this.metadataReferenceTags.some(function (t) { return t.id === suggestion.id; })) {
                    this.metadataReferenceTags.push({ id: suggestion.id, name: suggestion.name });
                }
                this.metadataNewTag = '';
                this.metadataShowSuggestions = false;
                this.metadataSelectedIndex = -1;
                return;
            }

            this.metadataNewTag = suggestion.name;
            this.metadataAddTag();
        },

        metadataNavigateDown() {
            if (this.metadataShowSuggestions && this.metadataTagSuggestions.length > 0) {
                this.metadataSelectedIndex = Math.min(this.metadataSelectedIndex + 1, this.metadataTagSuggestions.length - 1);
            }
        },

        metadataNavigateUp() {
            if (this.metadataShowSuggestions && this.metadataSelectedIndex > 0) {
                this.metadataSelectedIndex--;
            }
        },

        metadataHideSuggestions() {
            var self = this;
            setTimeout(function () {
                self.metadataShowSuggestions = false;
                self.metadataSelectedIndex = -1;
            }, 150);
        },

        getMetadataPayload() {
            var payload = {};
            if (this.metadataTags.length > 0) payload.metadata_tags = this.metadataTags;
            if (this.metadataReferenceTags.length > 0) payload.metadata_reference_tag_ids = this.metadataReferenceTags.map(function (t) { return t.id; });
            if (this.metadataLicenseType) payload.metadata_license_type = this.metadataLicenseType;
            if (this.metadataCopyright) payload.metadata_copyright = this.metadataCopyright;
            if (this.metadataCopyrightSource) payload.metadata_copyright_source = this.metadataCopyrightSource;
            return payload;
        },
    };
}
