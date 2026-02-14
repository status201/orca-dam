import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

// Defer Alpine start until DOM is fully loaded
document.addEventListener('DOMContentLoaded', () => {
    Alpine.start();

    // Random orca jump at long intervals
    function scheduleRandomOrcaJump() {
        // Random interval between 45 seconds and 3 minutes (45000-180000 ms)
        const minInterval = 45000;
        const maxInterval = 180000;
        const randomInterval = Math.floor(Math.random() * (maxInterval - minInterval + 1)) + minInterval;

        setTimeout(() => {
            const footerOrca = document.querySelector('.footer-logo-container svg');
            if (footerOrca) {
                footerOrca.classList.add('orca-jump');
                setTimeout(() => footerOrca.classList.remove('orca-jump'), 1100);
            }
            // Schedule next jump
            scheduleRandomOrcaJump();
        }, randomInterval);
    }

    // Start the random jumps
    scheduleRandomOrcaJump();

    // Easter egg: double-click orca logo to launch game
    const orcaLogo = document.getElementById('orca-logo-container');
    if (orcaLogo) {
        orcaLogo.addEventListener('dblclick', function (e) {
            e.preventDefault();
            if (window.__orcaGameLoaded) return;
            window.__orcaGameLoaded = true;

            const loader = document.getElementById('orca-game-loader');
            if (loader) loader.style.display = '';

            // Lazy-load game CSS
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = '/games/orca-game.css';
            document.head.appendChild(link);

            // Lazy-load game JS
            const script = document.createElement('script');
            script.src = '/games/orca-game.js';
            script.onload = function () {
                if (window.OrcaGame) window.OrcaGame.init();
            };
            script.onerror = function () {
                if (loader) loader.style.display = 'none';
                window.__orcaGameLoaded = false;
            };
            document.head.appendChild(script);
        });
    }
});

// Toast notification system
window.showToast = function(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast fixed top-4 right-4 px-6 py-4 rounded-lg shadow-lg text-white z-50 transition-opacity duration-300 ${
        type === 'error' ? 'bg-red-600' : 'bg-green-600'
    }`;
    toast.textContent = message;

    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
};

// Copy to clipboard utility
window.copyToClipboard = function(text) {
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(() => {
            window.showToast('URL copied to clipboard!');
        }).catch(err => {
            console.error('Failed to copy:', err);
            window.showToast('Failed to copy URL', 'error');
        });
    } else {
        // Fallback for older browsers
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        document.body.appendChild(textArea);
        textArea.select();
        try {
            document.execCommand('copy');
            window.showToast('URL copied to clipboard!');
        } catch (err) {
            console.error('Failed to copy:', err);
            window.showToast('Failed to copy URL', 'error');
        }
        textArea.remove();
    }
};
