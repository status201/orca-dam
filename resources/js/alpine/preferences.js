export function preferencesForm() {
    const pageData = window.__pageData || {};
    const routes = pageData.routes || {};
    const t = pageData.translations || {};
    const prefs = pageData.preferences || {};

    return {
        homeFolder: prefs.homeFolder ?? '',
        itemsPerPage: prefs.itemsPerPage ?? 0,
        darkMode: prefs.darkMode ?? 'disabled',
        locale: prefs.locale ?? '',
        saving: false,
        refreshing: false,
        errors: {},

        async save() {
            this.saving = true;
            this.errors = {};

            try {
                const response = await fetch(routes.preferencesUpdate, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        home_folder: this.homeFolder,
                        items_per_page: this.itemsPerPage,
                        dark_mode: this.darkMode,
                        locale: this.locale,
                    }),
                });

                const data = await response.json();

                if (response.ok) {
                    window.showToast(data.message || t.preferencesSaved);
                } else if (response.status === 422) {
                    // Validation errors
                    this.errors = data.errors || {};
                    const firstError = Object.values(this.errors)[0];
                    window.showToast(Array.isArray(firstError) ? firstError[0] : firstError, 'error');
                } else {
                    window.showToast(data.message || t.failedSavePreferences, 'error');
                }
            } catch (error) {
                console.error('Save error:', error);
                window.showToast(t.failedSavePreferences, 'error');
            } finally {
                this.saving = false;
            }
        },

        refreshFolders() {
            this.refreshing = true;
            window.showToast(t.refreshingFolders);
            setTimeout(() => {
                window.location.reload();
            }, 500);
        },

        updateDarkModePreview() {
            const html = document.documentElement;
            html.classList.remove('dark-mode', 'light-mode');

            if (this.darkMode === 'force_dark') {
                html.classList.add('dark-mode');
            } else if (this.darkMode === 'force_light') {
                html.classList.add('light-mode');
            }
        }
    };
}
window.preferencesForm = preferencesForm;
