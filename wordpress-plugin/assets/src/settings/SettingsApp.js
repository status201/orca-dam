import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
    Button,
    Card, CardBody, CardHeader,
    Notice,
    TextControl,
    __experimentalVStack as VStack,
} from '@wordpress/components';

export function SettingsApp() {
    const config = window.orcaDamSettings || {};
    const [baseUrl, setBaseUrl] = useState(config.baseUrl || '');
    const [token, setToken] = useState('');
    const [folder, setFolder] = useState(config.defaultFolder || '');
    const [healthStatus, setHealthStatus] = useState(null);
    const [testing, setTesting] = useState(false);

    useEffect(() => {
        if (new URLSearchParams(window.location.search).get('orca-saved') === '1') {
            setHealthStatus({ type: 'success', text: __('Settings saved.', 'orca-dam-picker') });
        }
    }, []);

    const testConnection = async () => {
        setTesting(true);
        setHealthStatus(null);
        try {
            const res = await fetch(`${config.restUrl}/health`, {
                headers: { 'X-WP-Nonce': config.nonce },
            });
            const body = await res.json();
            setHealthStatus(
                body.reachable
                    ? { type: 'success', text: __('Connected to ORCA.', 'orca-dam-picker') }
                    : { type: 'error', text: __('ORCA returned', 'orca-dam-picker') + ' ' + body.orca_status }
            );
        } catch (e) {
            setHealthStatus({ type: 'error', text: e.message });
        } finally {
            setTesting(false);
        }
    };

    return (
        <VStack spacing={4}>
            <h1>{__('ORCA DAM Picker', 'orca-dam-picker')}</h1>
            {healthStatus && (
                <Notice status={healthStatus.type} isDismissible onRemove={() => setHealthStatus(null)}>
                    {healthStatus.text}
                </Notice>
            )}

            <Card>
                <CardHeader><h2 style={{ margin: 0 }}>{__('Connection', 'orca-dam-picker')}</h2></CardHeader>
                <CardBody>
                    <form method="POST" action={config.saveUrl}>
                        <input type="hidden" name="action" value="orca_dam_save_settings" />
                        <input type="hidden" name="_wpnonce" value={config.saveNonce} />
                        <VStack spacing={3}>
                            <TextControl
                                label={__('ORCA base URL', 'orca-dam-picker')}
                                value={baseUrl}
                                onChange={setBaseUrl}
                                name="base_url"
                                type="url"
                                placeholder="https://dam.example.com"
                                __nextHasNoMarginBottom
                            />
                            <TextControl
                                label={__('API token (Sanctum)', 'orca-dam-picker')}
                                help={config.hasToken
                                    ? __('A token is configured. Leave empty to keep it.', 'orca-dam-picker')
                                    : __('Paste a token created in ORCA → API Tokens.', 'orca-dam-picker')}
                                value={token}
                                onChange={setToken}
                                name="token"
                                type="password"
                                __nextHasNoMarginBottom
                            />
                            <TextControl
                                label={__('Default folder filter (optional)', 'orca-dam-picker')}
                                value={folder}
                                onChange={setFolder}
                                name="default_folder"
                                placeholder="assets/wordpress"
                                __nextHasNoMarginBottom
                            />
                            <div style={{ display: 'flex', gap: 8 }}>
                                <Button variant="primary" type="submit">{__('Save', 'orca-dam-picker')}</Button>
                                <Button variant="secondary" onClick={testConnection} disabled={testing} type="button">
                                    {testing ? __('Testing…', 'orca-dam-picker') : __('Test connection', 'orca-dam-picker')}
                                </Button>
                            </div>
                        </VStack>
                    </form>
                </CardBody>
            </Card>

            <Card>
                <CardHeader><h2 style={{ margin: 0 }}>{__('Usage tracking', 'orca-dam-picker')}</h2></CardHeader>
                <CardBody>
                    <p>
                        {__('When this site uses an ORCA asset in a post, ORCA records a reference tag of the form', 'orca-dam-picker')}{' '}
                        <code>wp:{config.siteHost}/post/&lt;id&gt;</code>.
                    </p>
                </CardBody>
            </Card>

            <BrokenAssetsCard restUrl={config.restUrl} nonce={config.nonce} />
        </VStack>
    );
}

function BrokenAssetsCard({ restUrl, nonce }) {
    const [state, setState] = useState({ count: null, items: [] });
    const [queued, setQueued] = useState(false);

    const load = () => {
        fetch(`${restUrl}/broken`, { headers: { 'X-WP-Nonce': nonce } })
            .then((r) => r.json())
            .then((body) => setState({ count: body.count ?? 0, items: body.items ?? [] }))
            .catch(() => setState({ count: 0, items: [] }));
    };

    useEffect(load, []);

    const triggerScan = async () => {
        setQueued(true);
        await fetch(`${restUrl}/scan`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
        });
    };

    return (
        <Card>
            <CardHeader><h2 style={{ margin: 0 }}>{__('Broken assets', 'orca-dam-picker')}</h2></CardHeader>
            <CardBody>
                {state.count === null && <p>…</p>}
                {state.count === 0 && <p>{__('No broken assets detected.', 'orca-dam-picker')}</p>}
                {state.count > 0 && (
                    <>
                        <p>{__('%d shell(s) reference assets that no longer exist in ORCA.', 'orca-dam-picker').replace('%d', state.count)}</p>
                        <ul style={{ maxHeight: 220, overflow: 'auto', borderTop: '1px solid #ddd' }}>
                            {state.items.map((item) => (
                                <li key={item.attachment_id} style={{ padding: '6px 0', borderBottom: '1px solid #eee' }}>
                                    <code>#{item.asset_id}</code> &mdash; {item.post_title || `attachment ${item.attachment_id}`}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                <div style={{ marginTop: 12, display: 'flex', gap: 8, alignItems: 'center' }}>
                    <Button variant="secondary" onClick={triggerScan} disabled={queued} type="button">
                        {__('Run scan now', 'orca-dam-picker')}
                    </Button>
                    {queued && <span style={{ fontSize: 12, opacity: 0.7 }}>{__('Scan queued — refresh in a minute.', 'orca-dam-picker')}</span>}
                </div>
            </CardBody>
        </Card>
    );
}
