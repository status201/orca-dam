export function featureTour(isAdmin) {
    const pageData = window.__pageData || {};
    const routes = pageData.routes || {};
    const t = pageData.translations || {};

    return {
        currentSlide: 0,
        isPlaying: true,
        autoPlayInterval: null,
        features: [
            {
                icon: 'fas fa-cloud-upload-alt',
                bgColor: 'bg-blue-500',
                btnColor: 'bg-blue-600 hover:bg-blue-700',
                title: t.uploadAssets,
                description: t.uploadAssetsDesc,
                link: routes.assetsCreate,
                btnText: t.uploadNow
            },
            {
                icon: 'fas fa-search',
                bgColor: 'bg-green-500',
                btnColor: 'bg-green-600 hover:bg-green-700',
                title: t.searchFilter,
                description: t.searchFilterDesc,
                link: routes.assetsIndex,
                btnText: t.browseAssets
            },
            {
                icon: 'fas fa-tags',
                bgColor: 'bg-purple-500',
                btnColor: 'bg-purple-600 hover:bg-purple-700',
                title: t.smartTagging,
                description: t.smartTaggingDesc,
                link: routes.tagsIndex,
                btnText: t.manageTags
            },
            {
                icon: 'fas fa-copy',
                bgColor: 'bg-yellow-500',
                btnColor: 'bg-yellow-600 hover:bg-yellow-700',
                title: t.shareAssets,
                description: t.shareAssetsDesc,
                link: routes.assetsIndex,
                btnText: t.viewAssets
            },
            ...(isAdmin ? [
                {
                    icon: 'fas fa-search-plus',
                    bgColor: 'bg-indigo-500',
                    btnColor: 'bg-indigo-600 hover:bg-indigo-700',
                    title: t.discoverAssets,
                    description: t.discoverAssetsDesc,
                    link: routes.discoverIndex,
                    btnText: t.scanBucket
                },
                {
                    icon: 'fas fa-trash-restore',
                    bgColor: 'bg-red-500',
                    btnColor: 'bg-red-600 hover:bg-red-700',
                    title: t.trashRestore,
                    description: t.trashRestoreDesc,
                    link: routes.assetsTrash,
                    btnText: t.viewTrash
                },
                {
                    icon: 'fas fa-download',
                    bgColor: 'bg-teal-500',
                    btnColor: 'bg-teal-600 hover:bg-teal-700',
                    title: t.exportData,
                    description: t.exportDataDesc,
                    link: routes.exportIndex,
                    btnText: t.exportCsv
                },
                {
                    icon: 'fas fa-users',
                    bgColor: 'bg-pink-500',
                    btnColor: 'bg-pink-600 hover:bg-pink-700',
                    title: t.manageUsers,
                    description: t.manageUsersDesc,
                    link: routes.usersIndex,
                    btnText: t.manageUsersBtn
                }
            ] : [])
        ],

        nextSlide() {
            if (this.currentSlide < this.features.length - 1) {
                this.currentSlide++;
            } else {
                this.currentSlide = 0; // Loop back to start
            }
        },

        previousSlide() {
            if (this.currentSlide > 0) {
                this.currentSlide--;
            } else {
                this.currentSlide = this.features.length - 1; // Loop to end
            }
        },

        pauseAutoPlay() {
            this.isPlaying = false;
            if (this.autoPlayInterval) {
                clearInterval(this.autoPlayInterval);
                this.autoPlayInterval = null;
            }
        },

        startAutoPlay() {
            this.isPlaying = true;
            if (!this.autoPlayInterval) {
                this.autoPlayInterval = setInterval(() => {
                    this.nextSlide();
                }, 7000);
            }
        },

        toggleAutoPlay() {
            if (this.isPlaying) {
                this.pauseAutoPlay();
            } else {
                this.startAutoPlay();
            }
        },

        init() {
            // Start auto-play
            this.startAutoPlay();
        }
    }
}
window.featureTour = featureTour;
