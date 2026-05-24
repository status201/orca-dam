import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SearchControl, Spinner, SelectControl, FlexBlock, Flex } from '@wordpress/components';
import { AssetGrid } from './AssetGrid';

const SORT_OPTIONS = [
    { label: __('Newest', 'orca-dam-picker'), value: 'date_desc' },
    { label: __('Oldest', 'orca-dam-picker'), value: 'date_asc' },
    { label: __('Name A → Z', 'orca-dam-picker'), value: 'name_asc' },
    { label: __('Largest first', 'orca-dam-picker'), value: 'size_desc' },
];

export function Picker({ onPick }) {
    const [query, setQuery] = useState('');
    const [debounced, setDebounced] = useState('');
    const [sort, setSort] = useState('date_desc');
    const [tag, setTag] = useState('');
    const [tags, setTags] = useState([]);
    const [pages, setPages] = useState([]);
    const [page, setPage] = useState(1);
    const [loading, setLoading] = useState(false);
    const [hasMore, setHasMore] = useState(false);
    const sentinel = useRef(null);

    // Debounce search input
    useEffect(() => {
        const id = setTimeout(() => setDebounced(query), 250);
        return () => clearTimeout(id);
    }, [query]);

    // Reset paging when filters change
    useEffect(() => {
        setPages([]);
        setPage(1);
    }, [debounced, sort, tag]);

    // Load tags once
    useEffect(() => {
        fetch(`${orcaDam.restUrl}/tags?type=user&per_page=100`, {
            headers: { 'X-WP-Nonce': orcaDam.nonce },
        })
            .then((r) => r.json())
            .then((body) => {
                const list = body?.data || body || [];
                setTags(Array.isArray(list) ? list : []);
            })
            .catch(() => setTags([]));
    }, []);

    // Fetch current page
    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        const params = new URLSearchParams({
            q: debounced,
            sort,
            page: String(page),
            per_page: '24',
            type: 'image',
        });
        if (tag) params.set('tags', tag);

        fetch(`${orcaDam.restUrl}/assets/search?${params}`, {
            headers: { 'X-WP-Nonce': orcaDam.nonce },
        })
            .then((r) => r.json())
            .then((body) => {
                if (cancelled) return;
                const data = Array.isArray(body?.data) ? body.data : [];
                setPages((prev) => (page === 1 ? [data] : [...prev, data]));
                const meta = body?.meta || {};
                setHasMore((meta.current_page || page) < (meta.last_page || page));
            })
            .catch(() => setHasMore(false))
            .finally(() => ! cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [debounced, sort, tag, page]);

    // Infinite scroll
    useEffect(() => {
        if (! sentinel.current || ! hasMore || loading) return;
        const observer = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting) setPage((p) => p + 1);
        });
        observer.observe(sentinel.current);
        return () => observer.disconnect();
    }, [hasMore, loading]);

    const flat = pages.flat();
    const handlePick = useCallback((asset) => onPick && onPick(asset.id), [onPick]);

    return (
        <div className="orca-dam-picker">
            <Flex align="center" gap={3} style={{ marginBottom: 12 }}>
                <FlexBlock>
                    <SearchControl
                        value={query}
                        onChange={setQuery}
                        placeholder={__('Search ORCA assets…', 'orca-dam-picker')}
                        __nextHasNoMarginBottom
                    />
                </FlexBlock>
                <SelectControl
                    value={tag}
                    onChange={setTag}
                    options={[
                        { label: __('All tags', 'orca-dam-picker'), value: '' },
                        ...tags.map((t) => ({ label: t.name, value: String(t.id) })),
                    ]}
                    __nextHasNoMarginBottom
                />
                <SelectControl
                    value={sort}
                    onChange={setSort}
                    options={SORT_OPTIONS}
                    __nextHasNoMarginBottom
                />
            </Flex>
            <AssetGrid assets={flat} onPick={handlePick} />
            {loading && <div style={{ textAlign: 'center', padding: 12 }}><Spinner /></div>}
            {! loading && hasMore && <div ref={sentinel} style={{ height: 1 }} />}
            {! loading && flat.length === 0 && (
                <p style={{ textAlign: 'center', opacity: 0.7 }}>{__('No assets found.', 'orca-dam-picker')}</p>
            )}
        </div>
    );
}
