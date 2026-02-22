function trashGrid() {
    return {
        init() {
            console.log('Trash grid initialized');
        }
    }
}

function trashCard(assetId) {
    return {
        restoreAsset(id) {
            if (confirm(window.__pageData.confirmRestore)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/assets/${id}/restore`;

                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfToken;

                form.appendChild(csrfInput);
                document.body.appendChild(form);
                form.submit();
            }
        },

        confirmDelete(id) {
            if (confirm(window.__pageData.confirmPermanentDelete)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `/assets/${id}/force-delete`;

                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = '_token';
                csrfInput.value = csrfToken;

                const methodInput = document.createElement('input');
                methodInput.type = 'hidden';
                methodInput.name = '_method';
                methodInput.value = 'DELETE';

                form.appendChild(csrfInput);
                form.appendChild(methodInput);
                document.body.appendChild(form);
                form.submit();
            }
        }
    }
}

window.trashGrid = trashGrid;
window.trashCard = trashCard;

export { trashGrid, trashCard };
