export function uploadMetadata() {
    var pd = window.__pageData || {};
    var tagsSearchUrl = (pd.routes && pd.routes.tagsSearch) || pd.tagsSearch || '/tags/search';

    return {
        showMetadata: false,
        metadataTags: [],
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
            if (this.metadataTags.includes(tag)) {
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
                this.metadataSelectSuggestion(this.metadataTagSuggestions[this.metadataSelectedIndex].name);
            } else {
                this.metadataAddTag();
            }
        },

        metadataRemoveTag(index) {
            this.metadataTags.splice(index, 1);
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
                    var response = await fetch(tagsSearchUrl + '?q=' + encodeURIComponent(self.metadataNewTag) + '&type=user');
                    var data = await response.json();
                    self.metadataTagSuggestions = data.filter(function (tag) {
                        return !self.metadataTags.includes(tag.name);
                    });
                    self.metadataShowSuggestions = self.metadataTagSuggestions.length > 0;
                    self.metadataSelectedIndex = -1;
                } catch (error) {
                    console.error('Tag search failed:', error);
                }
            }, 300);
        },

        metadataSelectSuggestion(name) {
            this.metadataNewTag = name;
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
            if (this.metadataLicenseType) payload.metadata_license_type = this.metadataLicenseType;
            if (this.metadataCopyright) payload.metadata_copyright = this.metadataCopyright;
            if (this.metadataCopyrightSource) payload.metadata_copyright_source = this.metadataCopyrightSource;
            return payload;
        },
    };
}
