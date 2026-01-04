# ORCA DAM - Rich Text Editor Integration Examples

## TinyMCE Integration

### Basic Setup

```javascript
tinymce.init({
    selector: 'textarea#content',
    plugins: 'image link media',
    toolbar: 'orcaimage',
    
    // Custom image picker button
    setup: function(editor) {
        editor.ui.registry.addButton('orcaimage', {
            text: 'ORCA Assets',
            icon: 'image',
            onAction: function() {
                openOrcaAssetPicker(function(asset) {
                    editor.insertContent(`<img src="${asset.url}" alt="${asset.alt_text || asset.filename}">`);
                });
            }
        });
    }
});

// ORCA Asset Picker Modal
function openOrcaAssetPicker(callback) {
    const modal = document.createElement('div');
    modal.className = 'orca-modal';
    modal.innerHTML = `
        <div class="orca-modal-content">
            <div class="orca-modal-header">
                <h3>Select Asset</h3>
                <button onclick="this.closest('.orca-modal').remove()">×</button>
            </div>
            <div class="orca-modal-body">
                <input type="text" id="orca-search" placeholder="Search..." />
                <div id="orca-assets-grid"></div>
                <div id="orca-pagination"></div>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    loadOrcaAssets();
    
    function loadOrcaAssets(page = 1, search = '') {
        fetch(`https://your-orca-dam.com/api/assets/search?q=${search}&page=${page}&per_page=24`, {
            headers: {
                'Authorization': 'Bearer YOUR_API_TOKEN',
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            const grid = document.getElementById('orca-assets-grid');
            grid.innerHTML = data.data.map(asset => `
                <div class="orca-asset" onclick='selectAsset(${JSON.stringify(asset)})'>
                    <img src="${asset.thumbnail_url || asset.url}" alt="${asset.filename}">
                    <p>${asset.filename}</p>
                </div>
            `).join('');
        });
    }
    
    window.selectAsset = function(asset) {
        callback(asset);
        modal.remove();
    };
}
```

### CSS for Modal

```css
.orca-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
}

.orca-modal-content {
    background: white;
    border-radius: 8px;
    width: 90%;
    max-width: 1200px;
    max-height: 90vh;
    overflow: hidden;
}

.orca-modal-header {
    padding: 20px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.orca-modal-body {
    padding: 20px;
    overflow-y: auto;
    max-height: calc(90vh - 80px);
}

#orca-assets-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.orca-asset {
    cursor: pointer;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    padding: 8px;
    transition: all 0.2s;
}

.orca-asset:hover {
    border-color: #3b82f6;
    transform: scale(1.05);
}

.orca-asset img {
    width: 100%;
    aspect-ratio: 1;
    object-fit: cover;
    border-radius: 4px;
}

.orca-asset p {
    margin-top: 8px;
    font-size: 12px;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
```

---

## CKEditor Integration

```javascript
ClassicEditor
    .create(document.querySelector('#editor'), {
        toolbar: {
            items: [
                'heading', '|',
                'bold', 'italic', 'link', '|',
                'orcaImageUpload', '|',
                'bulletedList', 'numberedList'
            ]
        }
    })
    .then(editor => {
        // Add custom ORCA button
        editor.ui.componentFactory.add('orcaImageUpload', locale => {
            const view = new ButtonView(locale);
            
            view.set({
                label: 'Insert from ORCA',
                icon: '<svg>...</svg>',
                tooltip: true
            });
            
            view.on('execute', () => {
                openOrcaAssetPicker(asset => {
                    editor.model.change(writer => {
                        const imageElement = writer.createElement('imageBlock', {
                            src: asset.url,
                            alt: asset.alt_text || asset.filename
                        });
                        
                        editor.model.insertContent(imageElement, editor.model.document.selection);
                    });
                });
            });
            
            return view;
        });
    });
```

---

## Quill Editor Integration

```javascript
const quill = new Quill('#editor', {
    theme: 'snow',
    modules: {
        toolbar: {
            container: [
                ['bold', 'italic', 'underline'],
                ['image'],
                ['orca-assets']
            ],
            handlers: {
                'orca-assets': function() {
                    openOrcaAssetPicker(asset => {
                        const range = this.quill.getSelection();
                        this.quill.insertEmbed(range.index, 'image', asset.url);
                    });
                }
            }
        }
    }
});
```

---

## React Component Example

```jsx
import React, { useState, useEffect } from 'react';

function OrcaAssetPicker({ onSelect, apiToken }) {
    const [assets, setAssets] = useState([]);
    const [search, setSearch] = useState('');
    const [loading, setLoading] = useState(false);
    
    useEffect(() => {
        fetchAssets();
    }, [search]);
    
    const fetchAssets = async () => {
        setLoading(true);
        try {
            const response = await fetch(
                `https://your-orca-dam.com/api/assets/search?q=${search}&per_page=24`,
                {
                    headers: {
                        'Authorization': `Bearer ${apiToken}`,
                        'Accept': 'application/json'
                    }
                }
            );
            const data = await response.json();
            setAssets(data.data);
        } catch (error) {
            console.error('Failed to fetch assets:', error);
        } finally {
            setLoading(false);
        }
    };
    
    return (
        <div className="orca-picker">
            <input
                type="text"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search assets..."
                className="search-input"
            />
            
            {loading ? (
                <div className="loading">Loading...</div>
            ) : (
                <div className="assets-grid">
                    {assets.map(asset => (
                        <div
                            key={asset.id}
                            onClick={() => onSelect(asset)}
                            className="asset-card"
                        >
                            <img
                                src={asset.thumbnail_url || asset.url}
                                alt={asset.filename}
                            />
                            <p>{asset.filename}</p>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

// Usage in your editor
function MyEditor() {
    const [showPicker, setShowPicker] = useState(false);
    
    const handleAssetSelect = (asset) => {
        // Insert into editor (implementation depends on your editor)
        insertImage(asset.url, asset.alt_text);
        setShowPicker(false);
    };
    
    return (
        <>
            <button onClick={() => setShowPicker(true)}>
                Insert from ORCA
            </button>
            
            {showPicker && (
                <OrcaAssetPicker
                    onSelect={handleAssetSelect}
                    apiToken="your-api-token"
                />
            )}
        </>
    );
}
```

---

## Vue Component Example

```vue
<template>
  <div class="orca-asset-picker">
    <input
      v-model="searchQuery"
      @input="debouncedSearch"
      placeholder="Search assets..."
      class="search-input"
    />
    
    <div v-if="loading" class="loading">
      Loading...
    </div>
    
    <div v-else class="assets-grid">
      <div
        v-for="asset in assets"
        :key="asset.id"
        @click="selectAsset(asset)"
        class="asset-card"
      >
        <img
          :src="asset.thumbnail_url || asset.url"
          :alt="asset.filename"
        />
        <p>{{ asset.filename }}</p>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted } from 'vue';
import { debounce } from 'lodash';

export default {
  name: 'OrcaAssetPicker',
  props: {
    apiToken: String,
    onSelect: Function
  },
  setup(props) {
    const assets = ref([]);
    const searchQuery = ref('');
    const loading = ref(false);
    
    const fetchAssets = async () => {
      loading.value = true;
      try {
        const response = await fetch(
          `https://your-orca-dam.com/api/assets/search?q=${searchQuery.value}`,
          {
            headers: {
              'Authorization': `Bearer ${props.apiToken}`,
              'Accept': 'application/json'
            }
          }
        );
        const data = await response.json();
        assets.value = data.data;
      } catch (error) {
        console.error('Failed to fetch assets:', error);
      } finally {
        loading.value = false;
      }
    };
    
    const debouncedSearch = debounce(fetchAssets, 300);
    
    const selectAsset = (asset) => {
      props.onSelect(asset);
    };
    
    onMounted(() => {
      fetchAssets();
    });
    
    return {
      assets,
      searchQuery,
      loading,
      debouncedSearch,
      selectAsset
    };
  }
};
</script>
```

---

## WordPress Gutenberg Block

```javascript
import { registerBlockType } from '@wordpress/blocks';
import { Button, Modal } from '@wordpress/components';
import { useState } from '@wordpress/element';

registerBlockType('orca/image-block', {
    title: 'ORCA Image',
    icon: 'format-image',
    category: 'media',
    
    edit: function(props) {
        const [isOpen, setIsOpen] = useState(false);
        const { attributes, setAttributes } = props;
        
        const selectAsset = (asset) => {
            setAttributes({
                url: asset.url,
                alt: asset.alt_text || asset.filename,
                id: asset.id
            });
            setIsOpen(false);
        };
        
        return (
            <>
                {attributes.url ? (
                    <img src={attributes.url} alt={attributes.alt} />
                ) : (
                    <Button onClick={() => setIsOpen(true)}>
                        Select from ORCA
                    </Button>
                )}
                
                {isOpen && (
                    <Modal
                        title="Select Asset from ORCA"
                        onRequestClose={() => setIsOpen(false)}
                    >
                        <OrcaAssetPicker onSelect={selectAsset} />
                    </Modal>
                )}
            </>
        );
    },
    
    save: function(props) {
        return (
            <img
                src={props.attributes.url}
                alt={props.attributes.alt}
            />
        );
    }
});
```

---

## API Token Generation

To generate an API token for your RTE:

```php
// In your Laravel application
$user = User::find(1); // Your service user
$token = $user->createToken('rte-integration')->plainTextToken;
```

Store this token securely in your RTE configuration.

---

## CORS Configuration

If your RTE is on a different domain, configure CORS in Laravel:

```php
// config/cors.php
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['https://your-website.com'],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
```

---

## Best Practices

1. **Token Security**: Never expose API tokens in client-side code. Use server-side proxy or secure storage.

2. **Error Handling**: Always handle API errors gracefully in your RTE integration.

3. **Caching**: Consider caching asset lists to reduce API calls.

4. **Pagination**: Implement infinite scroll or load-more for better UX with large asset libraries.

5. **Search Debouncing**: Debounce search inputs to avoid excessive API calls.

6. **Thumbnails**: Use thumbnail URLs for grid views, full URLs only when inserting.

7. **Alt Text**: Always use the asset's alt_text when available for accessibility.
