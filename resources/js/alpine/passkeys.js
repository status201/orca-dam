import {
    startAuthentication,
    startRegistration,
    browserSupportsWebAuthn,
    browserSupportsWebAuthnAutofill,
} from '@simplewebauthn/browser';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const headers = (extra = {}) => ({
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': csrf(),
    'X-Requested-With': 'XMLHttpRequest',
    ...extra,
});

export function passkeyManager() {
    const pageData = window.__pageData || {};
    const routes = pageData.routes || {};
    const t = pageData.translations || {};

    return {
        supported: browserSupportsWebAuthn(),
        adding: false,
        alias: '',

        async addPasskey() {
            if (!this.supported || this.adding) return;
            this.adding = true;

            try {
                const optionsRes = await fetch(routes.passkeyOptions, {
                    method: 'POST',
                    headers: headers(),
                    credentials: 'same-origin',
                });

                if (!optionsRes.ok) {
                    const data = await optionsRes.json().catch(() => ({}));
                    window.showToast(data.message || t.passkeyAddFailed, 'error');
                    return;
                }

                const options = await optionsRes.json();

                let attestation;
                try {
                    attestation = await startRegistration({ optionsJSON: options });
                } catch (err) {
                    if (err && err.name === 'NotAllowedError') {
                        window.showToast(t.passkeyCancelled || 'Passkey registration was cancelled.', 'warning');
                    } else {
                        console.error(err);
                        window.showToast(t.passkeyAddFailed || 'Failed to add passkey.', 'error');
                    }
                    return;
                }

                const registerRes = await fetch(routes.passkeyStore, {
                    method: 'POST',
                    headers: headers(),
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        ...attestation,
                        alias: this.alias.trim() || null,
                    }),
                });

                if (registerRes.ok || registerRes.status === 201) {
                    window.showToast(t.passkeyAdded || 'Passkey added.');
                    window.location.reload();
                } else {
                    const data = await registerRes.json().catch(() => ({}));
                    window.showToast(data.message || t.passkeyAddFailed || 'Failed to add passkey.', 'error');
                }
            } finally {
                this.adding = false;
            }
        },
    };
}

export function passkeyLogin() {
    const pageData = window.__pageData || {};
    const routes = pageData.routes || {};
    const t = pageData.translations || {};

    const submit = async ({ mediation = 'optional', email = null } = {}) => {
        const optionsRes = await fetch(routes.passkeyLoginOptions, {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            body: JSON.stringify(email ? { email } : {}),
        });

        if (!optionsRes.ok) {
            const data = await optionsRes.json().catch(() => ({}));
            throw new Error(data.message || 'Failed to start passkey sign-in.');
        }

        const options = await optionsRes.json();

        const assertion = await startAuthentication({
            optionsJSON: options,
            useBrowserAutofill: mediation === 'conditional',
        });

        const loginRes = await fetch(routes.passkeyLogin, {
            method: 'POST',
            headers: headers(),
            credentials: 'same-origin',
            body: JSON.stringify(assertion),
        });

        if (!loginRes.ok) {
            const data = await loginRes.json().catch(() => ({}));
            throw new Error(data.message || 'Passkey sign-in failed.');
        }

        const data = await loginRes.json().catch(() => ({}));
        window.location.href = data.redirect || '/';
    };

    return {
        supported: browserSupportsWebAuthn(),
        loading: false,
        error: '',

        async signIn(email = null) {
            if (!this.supported || this.loading) return;
            this.loading = true;
            this.error = '';
            try {
                await submit({ mediation: 'optional', email });
            } catch (err) {
                if (!err || err.name !== 'NotAllowedError') {
                    console.error(err);
                    this.error = err?.message || (t.passkeyLoginFailed || 'Passkey sign-in failed.');
                    window.showToast(this.error, 'error');
                }
            } finally {
                this.loading = false;
            }
        },

        async startConditional() {
            if (!this.supported) return;
            try {
                const supportsAutofill = await browserSupportsWebAuthnAutofill();
                if (!supportsAutofill) return;
                await submit({ mediation: 'conditional' });
            } catch (err) {
                if (err && err.name !== 'NotAllowedError' && err.name !== 'AbortError') {
                    console.warn('Conditional passkey UI failed:', err);
                }
            }
        },
    };
}

window.passkeyManager = passkeyManager;
window.passkeyLogin = passkeyLogin;
