import { Passkeys, UserCancelledError } from '@laravel/passkeys';

const readPageData = () => {
    const data = window.__pageData ?? {};
    return {
        routes: data.routes ?? {},
        t: data.translations ?? {},
    };
};

const overridesFor = (optionsRoute, submitRoute) => ({
    routes: { options: optionsRoute, submit: submitRoute },
});

export function passkeyManager() {
    const { routes, t } = readPageData();

    return {
        supported: Passkeys.isSupported(),
        adding: false,
        alias: '',

        async addPasskey() {
            if (!this.supported || this.adding) return;
            this.adding = true;

            try {
                await Passkeys.register({
                    name: this.alias.trim() || t.defaultPasskeyName || 'Passkey',
                    ...overridesFor(routes.passkeyOptions, routes.passkeyStore),
                });

                window.showToast(t.passkeyAdded || 'Passkey added.');
                window.location.reload();
            } catch (err) {
                if (err instanceof UserCancelledError) {
                    window.showToast(t.passkeyCancelled || 'Passkey registration was cancelled.', 'warning');
                    return;
                }

                console.error(err);
                window.showToast(err?.message || t.passkeyAddFailed || 'Failed to add passkey.', 'error');
            } finally {
                this.adding = false;
            }
        },
    };
}

export function passkeyLogin() {
    const { routes, t } = readPageData();
    const overrides = () => overridesFor(routes.passkeyLoginOptions, routes.passkeyLogin);

    const navigate = (result) => {
        window.location.href = result?.redirect || '/';
    };

    return {
        supported: Passkeys.isSupported(),
        loading: false,
        error: '',

        async signIn() {
            if (!this.supported || this.loading) return;
            this.loading = true;
            this.error = '';
            try {
                const result = await Passkeys.verify(overrides());
                navigate(result);
            } catch (err) {
                if (err instanceof UserCancelledError) {
                    return;
                }
                console.error(err);
                this.error = err?.message || (t.passkeyLoginFailed || 'Passkey sign-in failed.');
                window.showToast(this.error, 'error');
            } finally {
                this.loading = false;
            }
        },

        async startConditional() {
            if (!this.supported) return;
            try {
                const result = await Passkeys.autofill(overrides());
                if (result) {
                    navigate(result);
                }
            } catch (err) {
                if (err instanceof UserCancelledError) {
                    return;
                }
                console.warn('Conditional passkey UI failed:', err);
            }
        },
    };
}

window.passkeyManager = passkeyManager;
window.passkeyLogin = passkeyLogin;
