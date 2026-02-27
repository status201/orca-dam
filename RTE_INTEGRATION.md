# ORCA DAM - Rich Text Editor Integration

## Authentication

ORCA supports two auth methods. Include the token in all API requests:
```
Authorization: Bearer <token>
```

### Sanctum Tokens (Backend Integrations)

Long-lived tokens for server-to-server calls. **Never expose to frontend code.**

**Generate via Web UI** (recommended):
1. Log in as admin → **API Docs** → **API Tokens** tab
2. Set token name, select/create user → **Create Token**
3. Copy immediately — shown only once

**Generate via CLI:**
```bash
php artisan token:create user@example.com --name="My Integration"
php artisan token:create --new          # Create new API user + token
php artisan token:list                  # List all tokens
php artisan token:revoke 5             # Revoke by ID
```

> **Tip**: Use a dedicated API user (role: `api`) for integrations — can view/create/update assets but cannot delete or access admin features.

### JWT (Frontend Integrations)

Short-lived tokens safe for browser code. Your backend generates JWTs using a shared secret.

**Enable in `.env`:**
```env
JWT_ENABLED=true
JWT_MAX_TTL=36000    # Max lifetime in seconds (default: 10h)
```

**Generate secret** via Web UI (API Docs → JWT Secrets) or CLI:
```bash
php artisan jwt:generate user@example.com
php artisan jwt:list
php artisan jwt:revoke user@example.com
```

**Required JWT claims:**

| Claim | Description | Required |
|-------|-------------|----------|
| `sub` | ORCA user ID (integer) | Yes |
| `exp` | Expiration timestamp (Unix) | Yes |
| `iat` | Issued-at timestamp (Unix) | Yes |
| `iss` | Issuer identifier | Only if `JWT_ISSUER` configured |

### When to Use Which

| Scenario | Auth |
|----------|------|
| Backend-to-backend, cron jobs, SSR | Sanctum |
| Frontend RTE picker, SPA, mobile | JWT |

---

## JWT Generation Examples

### Node.js
```javascript
const jwt = require('jsonwebtoken');

function generateOrcaToken(userId, jwtSecret) {
    return jwt.sign({ sub: userId }, jwtSecret, { expiresIn: '1h', algorithm: 'HS256' });
}

app.get('/api/orca-token', authenticate, (req, res) => {
    res.json({ token: generateOrcaToken(ORCA_USER_ID, process.env.ORCA_JWT_SECRET) });
});
```

### PHP
```php
use Firebase\JWT\JWT;

function generateOrcaToken(int $userId, string $jwtSecret): string {
    return JWT::encode([
        'sub' => $userId, 'iat' => time(), 'exp' => time() + 3600,
    ], $jwtSecret, 'HS256');
}
```

### Python
```python
import jwt
from datetime import datetime, timedelta

def generate_orca_token(user_id: int, jwt_secret: str) -> str:
    return jwt.encode(
        {'sub': user_id, 'iat': datetime.utcnow(), 'exp': datetime.utcnow() + timedelta(hours=1)},
        jwt_secret, algorithm='HS256'
    )
```

### Java (Spring Boot)

Requires `io.jsonwebtoken:jjwt-api:0.12.5` (+ `jjwt-impl`, `jjwt-jackson` runtime).

```java
import io.jsonwebtoken.Jwts;
import io.jsonwebtoken.SignatureAlgorithm;
import io.jsonwebtoken.security.Keys;

private String generateOrcaToken(Long userId, String secret) {
    Instant now = Instant.now();
    SecretKey key = Keys.hmacShaKeyFor(secret.getBytes(StandardCharsets.UTF_8));
    return Jwts.builder()
            .claim("sub", userId)
            .issuedAt(Date.from(now))
            .expiration(Date.from(now.plus(1, ChronoUnit.HOURS)))
            .signWith(key, SignatureAlgorithm.HS256)
            .compact();
}
```

### Frontend Usage
```javascript
async function getOrcaToken() {
    const res = await fetch('/api/orca-token', { credentials: 'include' });
    return (await res.json()).token;
}

async function fetchOrcaAssets(search = '', sort = 'date_desc') {
    const token = await getOrcaToken();
    const res = await fetch(`https://your-orca-dam.com/api/assets?search=${search}&sort=${sort}`, {
        headers: { 'Authorization': `Bearer ${token}`, 'Accept': 'application/json' }
    });
    return res.json();
}
```

---

## Editor Integration Examples

All examples use the same `openOrcaAssetPicker(callback)` pattern — a modal that searches assets and calls `callback(asset)` on selection.

### TinyMCE
```javascript
tinymce.init({
    selector: 'textarea#content',
    plugins: 'image link media',
    toolbar: 'orcaimage',
    setup: (editor) => {
        editor.ui.registry.addButton('orcaimage', {
            text: 'ORCA Assets', icon: 'image',
            onAction: () => openOrcaAssetPicker(asset => {
                editor.insertContent(`<img src="${asset.url}" alt="${asset.alt_text || asset.filename}">`);
            })
        });
    }
});
```

### CKEditor
```javascript
// Inside .then(editor => { ... }) after ClassicEditor.create()
editor.ui.componentFactory.add('orcaImageUpload', locale => {
    const view = new ButtonView(locale);
    view.set({ label: 'Insert from ORCA', tooltip: true });
    view.on('execute', () => openOrcaAssetPicker(asset => {
        editor.model.change(writer => {
            const img = writer.createElement('imageBlock', { src: asset.url, alt: asset.alt_text || asset.filename });
            editor.model.insertContent(img, editor.model.document.selection);
        });
    }));
    return view;
});
```

### Quill
```javascript
const quill = new Quill('#editor', {
    theme: 'snow',
    modules: { toolbar: { container: [['bold', 'italic'], ['orca-assets']], handlers: {
        'orca-assets': function() {
            openOrcaAssetPicker(asset => {
                const range = this.quill.getSelection();
                this.quill.insertEmbed(range.index, 'image', asset.url);
            });
        }
    }}}
});
```

### WordPress Gutenberg
```javascript
registerBlockType('orca/image-block', {
    title: 'ORCA Image', icon: 'format-image', category: 'media',
    edit: (props) => {
        const [isOpen, setIsOpen] = useState(false);
        const { attributes, setAttributes } = props;
        return (<>
            {attributes.url
                ? <img src={attributes.url} alt={attributes.alt} />
                : <Button onClick={() => setIsOpen(true)}>Select from ORCA</Button>}
            {isOpen && <Modal title="Select Asset" onRequestClose={() => setIsOpen(false)}>
                <OrcaAssetPicker onSelect={asset => { setAttributes({ url: asset.url, alt: asset.alt_text }); setIsOpen(false); }} />
            </Modal>}
        </>);
    },
    save: (props) => <img src={props.attributes.url} alt={props.attributes.alt} />
});
```

### Asset Picker Modal (Vanilla JS)

```javascript
function openOrcaAssetPicker(callback) {
    const modal = document.createElement('div');
    modal.className = 'orca-modal';
    modal.innerHTML = `<div class="orca-modal-content">
        <div class="orca-modal-header"><h3>Select Asset</h3><button onclick="this.closest('.orca-modal').remove()">×</button></div>
        <div class="orca-modal-body"><input type="text" id="orca-search" placeholder="Search..." /><div id="orca-assets-grid"></div></div>
    </div>`;
    document.body.appendChild(modal);
    loadAssets();

    function loadAssets(page = 1, search = '') {
        fetch(`https://your-orca-dam.com/api/assets/search?q=${search}&page=${page}&per_page=24`, {
            headers: { 'Authorization': 'Bearer YOUR_API_TOKEN', 'Accept': 'application/json' }
        }).then(r => r.json()).then(data => {
            document.getElementById('orca-assets-grid').innerHTML = data.data.map(a =>
                `<div class="orca-asset" onclick='selectAsset(${JSON.stringify(a)})'><img src="${a.thumbnail_url || a.url}" alt="${a.filename}"><p>${a.filename}</p></div>`
            ).join('');
        });
    }
    window.selectAsset = (asset) => { callback(asset); modal.remove(); };
}
```

### React Component
```jsx
function OrcaAssetPicker({ onSelect, apiToken }) {
    const [assets, setAssets] = useState([]);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);

    useEffect(() => { fetchAssets(); }, [search]);

    const fetchAssets = async () => {
        setLoading(true);
        try {
            const res = await fetch(`https://your-orca-dam.com/api/assets/search?q=${search}&per_page=24`, {
                headers: { 'Authorization': `Bearer ${apiToken}`, 'Accept': 'application/json' }
            });
            setAssets((await res.json()).data);
        } finally { setLoading(false); }
    };

    return (<div>
        <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search assets..." />
        {loading ? <p>Loading...</p> : <div className="assets-grid">
            {assets.map(a => <div key={a.id} onClick={() => onSelect(a)} className="asset-card">
                <img src={a.thumbnail_url || a.url} alt={a.filename} /><p>{a.filename}</p>
            </div>)}
        </div>}
    </div>);
}
```

### Vue Component
```vue
<template>
  <div>
    <input v-model="searchQuery" @input="debouncedSearch" placeholder="Search assets..." />
    <div v-if="loading">Loading...</div>
    <div v-else class="assets-grid">
      <div v-for="asset in assets" :key="asset.id" @click="$emit('select', asset)" class="asset-card">
        <img :src="asset.thumbnail_url || asset.url" :alt="asset.filename" />
        <p>{{ asset.filename }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { debounce } from 'lodash';

const props = defineProps({ apiToken: String });
const assets = ref([]);
const searchQuery = ref('');
const loading = ref(false);

const fetchAssets = async () => {
    loading.value = true;
    try {
        const res = await fetch(`https://your-orca-dam.com/api/assets/search?q=${searchQuery.value}`, {
            headers: { 'Authorization': `Bearer ${props.apiToken}`, 'Accept': 'application/json' }
        });
        assets.value = (await res.json()).data;
    } finally { loading.value = false; }
};
const debouncedSearch = debounce(fetchAssets, 300);
onMounted(fetchAssets);
</script>
```

### Picker CSS
```css
.orca-modal { position: fixed; inset: 0; background: rgba(0,0,0,.7); display: flex; align-items: center; justify-content: center; z-index: 10000; }
.orca-modal-content { background: white; border-radius: 8px; width: 90%; max-width: 1200px; max-height: 90vh; overflow: hidden; }
.orca-modal-header { padding: 20px; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; }
.orca-modal-body { padding: 20px; overflow-y: auto; max-height: calc(90vh - 80px); }
#orca-assets-grid, .assets-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 16px; margin-top: 20px; }
.orca-asset, .asset-card { cursor: pointer; border: 2px solid #e5e7eb; border-radius: 8px; padding: 8px; transition: all .2s; }
.orca-asset:hover, .asset-card:hover { border-color: #3b82f6; transform: scale(1.05); }
.orca-asset img, .asset-card img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 4px; }
.orca-asset p, .asset-card p { margin-top: 8px; font-size: 12px; text-align: center; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
```

---

## CORS Configuration

If your RTE is on a different domain:
```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['https://your-website.com'],
    'allowed_headers' => ['*'],
    'supports_credentials' => true,
];
```

---

## API Quick Reference

### Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/api/assets` | List assets (pagination, search, filters, sorting) |
| `GET` | `/api/assets/search` | Search assets (optimized for picker) |
| `GET` | `/api/assets/{id}` | Single asset details |
| `GET` | `/api/assets/meta?url=` | Metadata by URL (**public**, no auth) |
| `POST` | `/api/assets` | Upload files |
| `PATCH` | `/api/assets/{id}` | Update metadata |
| `GET` | `/api/tags` | List all tags |
| `GET` | `/api/folders` | List S3 folders |
| `POST` | `/api/reference-tags` | Add reference tags to asset(s) (supports batch via `asset_ids`/`s3_keys`) |
| `DELETE` | `/api/reference-tags/{tag}` | Remove reference tag from asset(s) (supports batch via `asset_ids`/`s3_keys`) |

### Query Parameters
| Parameter | Example |
|-----------|---------|
| `search` / `q` | `?search=logo` |
| `type` | `?type=image` |
| `tags` | `?tags=1,2,3` |
| `per_page` | `?per_page=24` (max 100) |
| `page` | `?page=2` |
| `folder` | `?folder=assets/marketing` |
| `sort` | `?sort=date_desc` (default), `date_asc`, `upload_desc`, `upload_asc`, `size_desc`, `size_asc`, `name_asc`, `name_desc` |

---

## Best Practices

1. **Sanctum tokens**: never expose in client-side code — use a server-side proxy
2. **JWT secrets**: store in backend config, never commit to version control
3. **JWTs**: generate with short expiry (1h recommended), refresh when expired
4. **Performance**: debounce search inputs, cache asset lists, use `thumbnail_url` for grids
5. **Accessibility**: always use `alt_text` when inserting images
6. **Custom domain**: if configured, asset URLs use the CDN domain automatically; `/api/assets/meta` accepts both
