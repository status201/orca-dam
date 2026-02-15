/**
 * ORCA Feeding Frenzy - Easter Egg Game
 * A retro Atari-style game where you control an orca to catch fish and avoid sharks.
 */
(function () {
    'use strict';

    // --- Config ---
    let GAME_HEIGHT = 300; // updated dynamically from footer size
    const ORCA_W = 80;
    const ORCA_H = 60;
    const MOVE_SPEED = 250;       // px/s
    const JUMP_VELOCITY = -420;   // px/s (upward)
    const GRAVITY = 800;          // px/s^2
    const FISH_SPAWN_INTERVAL = 1.2; // seconds
    const SHARK_MIN_INTERVAL = 5;
    const SHARK_MAX_INTERVAL = 12;
    const FAST_FISH_SPEED = 280;
    const SLOW_FISH_SPEED = 120;
    const SHARK_SPEED_MIN = 290;
    const SHARK_SPEED_MAX = 460;
    const SHARK_W = 96;
    const SHARK_H = 48;
    const FAST_FISH_POINTS = 50;
    const SLOW_FISH_POINTS = 10;
    const COLLISION_MARGIN = 8;

    // Idle swim drift constants
    const IDLE_DRIFT_X_AMP = 8;    // px horizontal oscillation amplitude
    const IDLE_DRIFT_X_FREQ = 0.4; // Hz
    const IDLE_DRIFT_Y_AMP = 6;    // px vertical oscillation amplitude
    const IDLE_DRIFT_Y_FREQ = 0.6; // Hz

    // --- State ---
    let score, lives, orcaX, orcaY, orcaVelocity, isJumping, restingY;
    let entities, spawnTimer, sharkTimer, nextSharkInterval;
    let gameTime; // total elapsed game time for idle drift
    let keys = {};
    let running = false;
    let lastTimestamp = 0;
    let animFrameId = null;

    // DOM refs
    let gameArea, orcaEl, hudScore, hudLives, footer, footerContent;

    // Cached SVGs
    let svgCache = {};
    let orcaSvgHtml = ''; // cached orca logo innerHTML

    // Herringbone (fish skeleton) SVG for hit effect — facing right
    const HERRINGBONE_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 60" width="80" height="60">
      <g fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" opacity="0.9">
        <line x1="10" y1="30" x2="70" y2="30"/>
        <circle cx="68" cy="26" r="3" fill="white" stroke="none"/>
        <line x1="20" y1="30" x2="15" y2="20"/><line x1="20" y1="30" x2="15" y2="40"/>
        <line x1="28" y1="30" x2="34" y2="22"/><line x1="28" y1="30" x2="34" y2="38"/>
        <line x1="38" y1="30" x2="44" y2="20"/><line x1="38" y1="30" x2="44" y2="40"/>
        <line x1="48" y1="30" x2="54" y2="18"/><line x1="48" y1="30" x2="54" y2="42"/>
        <line x1="58" y1="30" x2="64" y2="16"/><line x1="58" y1="30" x2="64" y2="44"/>
      </g>
    </svg>`;

    // Small herringbone (for silver/fast fish, matches 36×21)
    const BONE_SMALL_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 36 21" width="36" height="21">
      <g fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round" opacity="0.9">
        <line x1="4" y1="10.5" x2="30" y2="10.5"/>
        <circle cx="5" cy="10.5" r="2.5" fill="white" stroke="none"/>
        <line x1="12" y1="10.5" x2="9" y2="4"/><line x1="12" y1="10.5" x2="9" y2="17"/>
        <line x1="19" y1="10.5" x2="16" y2="4"/><line x1="19" y1="10.5" x2="16" y2="17"/>
        <line x1="26" y1="10.5" x2="23" y2="4"/><line x1="26" y1="10.5" x2="23" y2="17"/>
        <line x1="30" y1="10.5" x2="35" y2="4"/><line x1="30" y1="10.5" x2="35" y2="17"/>
      </g>
    </svg>`;

    // Large herringbone (for gold/slow fish, matches 52×29)
    const BONE_LARGE_SVG = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 52 29" width="52" height="29">
      <g fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" opacity="0.9">
        <line x1="5" y1="14.5" x2="43" y2="14.5"/>
        <circle cx="6" cy="14.5" r="3" fill="white" stroke="none"/>
        <line x1="14" y1="14.5" x2="10" y2="5"/><line x1="14" y1="14.5" x2="10" y2="24"/>
        <line x1="22" y1="14.5" x2="18" y2="5"/><line x1="22" y1="14.5" x2="18" y2="24"/>
        <line x1="30" y1="14.5" x2="26" y2="5"/><line x1="30" y1="14.5" x2="26" y2="24"/>
        <line x1="38" y1="14.5" x2="34" y2="5"/><line x1="38" y1="14.5" x2="34" y2="24"/>
        <line x1="43" y1="14.5" x2="50" y2="5"/><line x1="43" y1="14.5" x2="50" y2="24"/>
      </g>
    </svg>`;

    // --- Init (called after lazy load) ---
    function init() {
        const basePath = '/games/';
        Promise.all([
            fetch(basePath + 'fish-silver.svg').then(r => r.text()),
            fetch(basePath + 'fish-gold.svg').then(r => r.text()),
            fetch(basePath + 'shark.svg').then(r => r.text()),
        ]).then(([silver, gold, shark]) => {
            svgCache.silver = silver;
            svgCache.gold = gold;
            svgCache.shark = shark;

            // Hide loader
            const loader = document.getElementById('orca-game-loader');
            if (loader) loader.style.display = 'none';

            startGame();
        }).catch(err => {
            console.error('Failed to load game assets:', err);
            const loader = document.getElementById('orca-game-loader');
            if (loader) loader.style.display = 'none';
            window.__orcaGameLoaded = false;
        });
    }

    // --- Start Game ---
    function startGame() {
        footer = document.getElementById('orca-footer');
        footerContent = document.getElementById('footer-content');
        gameArea = document.getElementById('orca-game-area');

        if (!footer || !footerContent || !gameArea) return;

        // Capture logo position BEFORE hiding footer content (only if visible)
        const logoContainer = document.getElementById('orca-logo-container');
        const footerRect = footer.getBoundingClientRect();
        let logoStartX = null;
        let logoStartY = null;
        if (logoContainer && logoContainer.offsetParent !== null) {
            const logoRect = logoContainer.getBoundingClientRect();
            if (logoRect.width > 0 && logoRect.height > 0) {
                logoStartX = logoRect.left - footerRect.left + (logoRect.width - ORCA_W) / 2;
                logoStartY = logoRect.top - footerRect.top + (logoRect.height - ORCA_H) / 2;
            }
        }

        // Activate game mode
        footer.classList.add('game-mode');
        footerContent.style.display = 'none';
        gameArea.style.display = 'block';
        gameArea.innerHTML = '';
        gameArea.setAttribute('tabindex', '0');
        gameArea.focus();

        // Use actual footer height as game height
        GAME_HEIGHT = footer.offsetHeight;

        // Reset state
        score = 0;
        lives = 3;
        restingY = GAME_HEIGHT - ORCA_H - 20;
        // Start orca exactly where the logo was, fall back to center of game area
        orcaX = logoStartX !== null ? logoStartX : (gameArea.offsetWidth - ORCA_W) / 2;
        orcaY = logoStartY !== null ? Math.min(logoStartY, restingY) : (GAME_HEIGHT - ORCA_H) / 2;
        orcaVelocity = 0;
        isJumping = false;
        entities = [];
        spawnTimer = 0;
        sharkTimer = 0;
        gameTime = 0;
        nextSharkInterval = randomRange(SHARK_MIN_INTERVAL, SHARK_MAX_INTERVAL);
        keys = {};

        // Create orca element
        orcaEl = document.createElement('div');
        orcaEl.className = 'game-orca';
        // Clone orca SVG from the footer logo and cache it
        const logoSvg = document.querySelector('#orca-logo-container svg');
        if (logoSvg) {
            orcaEl.appendChild(logoSvg.cloneNode(true));
            orcaSvgHtml = orcaEl.innerHTML;
        }
        orcaEl.style.left = orcaX + 'px';
        orcaEl.style.top = orcaY + 'px';
        gameArea.appendChild(orcaEl);

        // Create HUD
        const hud = document.createElement('div');
        hud.className = 'game-hud';

        hudScore = document.createElement('div');
        hudScore.className = 'game-score';
        hudScore.textContent = '000000';
        hud.appendChild(hudScore);

        hudLives = document.createElement('div');
        hudLives.className = 'game-lives';
        updateLivesDisplay();
        hud.appendChild(hudLives);

        gameArea.appendChild(hud);

        showInstructions();
    }

    // --- Instructions Overlay ---
    function showInstructions() {
        const overlay = document.createElement('div');
        overlay.className = 'game-instructions';
        overlay.innerHTML = `
            <h2>ORCA FEEDING FRENZY</h2>
            <div class="controls">
                <span>&uarr; &darr; &larr; &rarr;</span> SWIM AROUND<br>
                <span>SPACE</span> JUMP
            </div>
            <div class="fish-info">
                <span class="silver">SILVER FISH</span> = ${FAST_FISH_POINTS} PTS (fast!)<br>
                <span class="gold">GOLD FISH</span> = ${SLOW_FISH_POINTS} PTS (slow)<br>
                <span class="danger">AVOID THE SHARKS!</span>
            </div>
            <div class="start-prompt">PRESS SPACE TO START</div>
        `;
        gameArea.appendChild(overlay);

        function onStart(e) {
            if (e.code === 'Space' || e.key === ' ') {
                e.preventDefault();
                gameArea.removeEventListener('keydown', onStart);
                overlay.remove();
                beginGameLoop();
            }
        }
        gameArea.addEventListener('keydown', onStart);
    }

    // --- Game Loop ---
    function beginGameLoop() {
        running = true;
        lastTimestamp = 0;

        gameArea.addEventListener('keydown', onKeyDown);
        gameArea.addEventListener('keyup', onKeyUp);

        animFrameId = requestAnimationFrame(gameLoop);
    }

    function gameLoop(timestamp) {
        if (!running) return;

        if (!lastTimestamp) lastTimestamp = timestamp;
        let dt = (timestamp - lastTimestamp) / 1000;
        lastTimestamp = timestamp;

        // Cap delta to prevent jumps after tab switch
        if (dt > 0.05) dt = 0.05;

        update(dt);
        render();

        animFrameId = requestAnimationFrame(gameLoop);
    }

    // --- Update ---
    function update(dt) {
        const areaWidth = gameArea.offsetWidth;
        gameTime += dt;

        // Orca movement (arrow keys)
        if (keys['ArrowUp']) {
            orcaY -= MOVE_SPEED * dt;
        }
        if (keys['ArrowDown']) {
            orcaY += MOVE_SPEED * dt;
        }
        if (keys['ArrowLeft']) {
            orcaX -= MOVE_SPEED * dt;
        }
        if (keys['ArrowRight']) {
            orcaX += MOVE_SPEED * dt;
        }

        // Jump
        if (keys['Space'] && !isJumping) {
            isJumping = true;
            orcaVelocity = JUMP_VELOCITY;
            orcaEl.style.animationPlayState = 'paused';
        }

        // Gravity
        if (isJumping) {
            orcaVelocity += GRAVITY * dt;
            orcaY += orcaVelocity * dt;

            if (orcaY >= restingY) {
                orcaY = restingY;
                orcaVelocity = 0;
                isJumping = false;
                orcaEl.style.animationPlayState = 'running';
            }
        }

        // Clamp orca position
        if (orcaY < 0) orcaY = 0;
        if (orcaY > GAME_HEIGHT - ORCA_H) orcaY = GAME_HEIGHT - ORCA_H;
        if (orcaX < 0) orcaX = 0;
        if (orcaX > areaWidth - ORCA_W) orcaX = areaWidth - ORCA_W;

        // Spawn fish
        spawnTimer += dt;
        if (spawnTimer >= FISH_SPAWN_INTERVAL) {
            spawnTimer -= FISH_SPAWN_INTERVAL;
            spawnFish(areaWidth);
        }

        // Spawn shark
        sharkTimer += dt;
        if (sharkTimer >= nextSharkInterval) {
            sharkTimer = 0;
            nextSharkInterval = randomRange(SHARK_MIN_INTERVAL, SHARK_MAX_INTERVAL);
            spawnShark(areaWidth);
        }

        // Move entities and check collisions
        for (let i = entities.length - 1; i >= 0; i--) {
            const e = entities[i];
            e.x -= e.speed * dt;

            // Shark single lunge: one sudden vertical shift partway across the screen
            if (e.weave && !e.lungeDone) {
                const screenPct = 1 - (e.x / gameArea.offsetWidth);
                if (screenPct >= e.lungeTrigger) {
                    e.lungeDone = true;
                    e.lungeTarget = Math.max(100, Math.min(e.baseY + e.weaveAmp, GAME_HEIGHT - e.h));
                }
            }
            if (e.lungeDone && e.y !== e.lungeTarget) {
                const dir = e.lungeTarget > e.y ? 1 : -1;
                e.y += dir * 300 * dt;
                if ((dir === 1 && e.y >= e.lungeTarget) || (dir === -1 && e.y <= e.lungeTarget)) {
                    e.y = e.lungeTarget;
                }
            }

            // Off-screen removal
            if (e.x < -e.w) {
                e.el.remove();
                entities.splice(i, 1);
                continue;
            }

            // AABB collision with orca
            const m = COLLISION_MARGIN;
            if (
                orcaX + m < e.x + e.w - m &&
                orcaX + ORCA_W - m > e.x + m &&
                orcaY + m < e.y + e.h - m &&
                orcaY + ORCA_H - m > e.y + m
            ) {
                if (e.type === 'fish') {
                    // Catch fish
                    score += e.points;
                    showCatchEffect(e.x, e.y, '+' + e.points);
                    showBoneEffect(e.x, e.y, e.points === FAST_FISH_POINTS);
                    e.el.remove();
                    entities.splice(i, 1);
                } else if (e.type === 'shark') {
                    // Hit by shark
                    lives--;
                    updateLivesDisplay();

                    if (lives <= 0) {
                        // Fatal hit: orca becomes herringbone skeleton
                        orcaEl.innerHTML = HERRINGBONE_SVG;
                        orcaEl.classList.add('game-hit-blink');
                        showBloodSplash(orcaX + ORCA_W / 2, orcaY + ORCA_H / 2);
                    } else {
                        // Non-fatal hit: blood splash + blink, orca survives
                        showBloodSplash(orcaX + ORCA_W / 2, orcaY + ORCA_H / 2);
                        orcaEl.classList.add('game-hit-flash', 'game-hit-blink');
                        setTimeout(() => {
                            orcaEl.classList.remove('game-hit-flash', 'game-hit-blink');
                        }, 600);
                    }
                    e.el.remove();
                    entities.splice(i, 1);

                    if (lives <= 0) {
                        running = false;
                        setTimeout(() => gameOver(), 1700);
                        return;
                    }
                }
            }
        }
    }

    // --- Render ---
    function render() {
        // Idle swim drift: gentle oscillation layered on top of player position
        const driftX = Math.sin(gameTime * Math.PI * 2 * IDLE_DRIFT_X_FREQ) * IDLE_DRIFT_X_AMP;
        const driftY = Math.sin(gameTime * Math.PI * 2 * IDLE_DRIFT_Y_FREQ) * IDLE_DRIFT_Y_AMP;
        orcaEl.style.left = (orcaX + driftX) + 'px';
        orcaEl.style.top = (orcaY + driftY) + 'px';
        hudScore.textContent = String(score).padStart(6, '0');

        for (const e of entities) {
            e.el.style.left = e.x + 'px';
            e.el.style.top = e.y + 'px';
        }
    }

    // --- Spawn Functions ---
    function spawnFish(areaWidth) {
        const isFast = Math.random() < 0.4;
        const el = document.createElement('div');
        el.className = 'game-fish ' + (isFast ? 'fast' : 'slow');
        el.innerHTML = isFast ? svgCache.silver : svgCache.gold;

        const w = isFast ? 36 : 52;
        const h = isFast ? 21 : 29;
        // Spawn below the wave area (top ~100px) across the remaining height
        const minY = 100;
        const y = minY + Math.random() * (GAME_HEIGHT * 0.7 - minY);

        el.style.left = areaWidth + 'px';
        el.style.top = y + 'px';
        gameArea.appendChild(el);

        entities.push({
            type: 'fish',
            el: el,
            x: areaWidth,
            y: y,
            w: w,
            h: h,
            speed: isFast ? FAST_FISH_SPEED : SLOW_FISH_SPEED,
            points: isFast ? FAST_FISH_POINTS : SLOW_FISH_POINTS,
        });
    }

    function spawnShark(areaWidth) {
        // Spawn 1-3 sharks in a pack, staggered horizontally
        const count = 1 + Math.floor(Math.random() * 3); // 1, 2, or 3
        for (let i = 0; i < count; i++) {
            const el = document.createElement('div');
            el.className = 'game-shark';
            el.innerHTML = svgCache.shark;

            const w = SHARK_W;
            const h = SHARK_H;
            // Spawn below the wave area (top ~100px)
            const minY = 100;
            const y = minY + Math.random() * (GAME_HEIGHT - h - minY);
            // Stagger horizontally so they don't stack
            const x = areaWidth + i * randomRange(60, 140);
            const speed = randomRange(SHARK_SPEED_MIN, SHARK_SPEED_MAX);

            el.style.left = x + 'px';
            el.style.top = y + 'px';
            gameArea.appendChild(el);

            // ~60% of sharks do a single vertical lunge
            const weaves = Math.random() < 0.6;
            // Random direction: positive = down, negative = up
            const lungeDir = Math.random() < 0.5 ? 1 : -1;
            entities.push({
                type: 'shark',
                el: el,
                x: x,
                y: y,
                baseY: y,
                w: w,
                h: h,
                speed: speed,
                weave: weaves,
                weaveAmp: weaves ? lungeDir * randomRange(40, 80) : 0,
                lungeTrigger: randomRange(0.2, 0.6),
                lungeDone: false,
                lungeTarget: y,
            });
        }
    }

    // --- Effects ---
    function showCatchEffect(x, y, text) {
        const el = document.createElement('div');
        el.className = 'game-catch-effect';
        el.textContent = text;
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        gameArea.appendChild(el);
        setTimeout(() => el.remove(), 800);
    }

    function showBloodSplash(cx, cy) {
        // Spawn pixel-art blood chunks in random directions
        const count = 8 + Math.floor(Math.random() * 5);
        for (let i = 0; i < count; i++) {
            const el = document.createElement('div');
            el.className = 'game-blood-chunk';
            // Randomise size for chunky pixel feel
            const size = 4 + Math.floor(Math.random() * 6);
            el.style.width = size + 'px';
            el.style.height = size + 'px';
            el.style.left = cx + 'px';
            el.style.top = cy + 'px';
            // Random direction via CSS custom properties
            const angle = Math.random() * Math.PI * 2;
            const dist = 30 + Math.random() * 50;
            el.style.setProperty('--dx', Math.cos(angle) * dist + 'px');
            el.style.setProperty('--dy', Math.sin(angle) * dist + 'px');
            // Slight delay stagger for juiciness
            el.style.animationDelay = (Math.random() * 0.08) + 's';
            gameArea.appendChild(el);
            setTimeout(() => el.remove(), 700);
        }

        // "OUCH!" text for arcade fun
        const ouch = document.createElement('div');
        ouch.className = 'game-ouch-text';
        const phrases = ['OUCH!', 'OW!', 'CHOMP!', 'YIKES!', 'ARGH!'];
        ouch.textContent = phrases[Math.floor(Math.random() * phrases.length)];
        ouch.style.left = cx + 'px';
        ouch.style.top = (cy - 20) + 'px';
        gameArea.appendChild(ouch);
        setTimeout(() => ouch.remove(), 800);
    }

    function showBoneEffect(x, y, isFast) {
        const el = document.createElement('div');
        el.className = 'game-bone-effect';
        el.innerHTML = isFast ? BONE_SMALL_SVG : BONE_LARGE_SVG;
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        gameArea.appendChild(el);
        setTimeout(() => el.remove(), 1000);
    }

    function updateLivesDisplay() {
        if (!hudLives) return;
        hudLives.innerHTML = '';
        for (let i = 0; i < 3; i++) {
            const dot = document.createElement('div');
            dot.className = 'game-life-dot' + (i >= lives ? ' lost' : '');
            hudLives.appendChild(dot);
        }
    }

    // --- Game Over ---
    function gameOver() {
        running = false;
        if (animFrameId) cancelAnimationFrame(animFrameId);
        gameArea.removeEventListener('keydown', onKeyDown);
        gameArea.removeEventListener('keyup', onKeyUp);

        // Save high score
        let highScores = [];
        try {
            highScores = JSON.parse(localStorage.getItem('orca_high_scores') || '[]');
        } catch (_) { /* ignore */ }
        highScores.push(score);
        highScores.sort((a, b) => b - a);
        highScores = highScores.slice(0, 5);
        localStorage.setItem('orca_high_scores', JSON.stringify(highScores));

        // Build overlay
        const overlay = document.createElement('div');
        overlay.className = 'game-over-screen';

        let scoresHtml = highScores.map((s, i) =>
            `<li class="${s === score && i === highScores.indexOf(score) ? 'current' : ''}">${String(i + 1).padStart(2, ' ')}. ${String(s).padStart(6, '0')}</li>`
        ).join('');

        overlay.innerHTML = `
            <h2>GAME OVER</h2>
            <div class="final-score">SCORE: ${String(score).padStart(6, '0')}</div>
            <div class="high-scores">
                <h3>HIGH SCORES</h3>
                <ol>${scoresHtml}</ol>
            </div>
            <div class="buttons">
                <button class="play-again">PLAY AGAIN</button>
                <button class="quit">QUIT</button>
            </div>
        `;

        gameArea.appendChild(overlay);

        overlay.querySelector('.play-again').addEventListener('click', () => {
            overlay.remove();
            clearEntities();
            startGame();
        });

        overlay.querySelector('.quit').addEventListener('click', () => {
            overlay.remove();
            quitGame();
        });

        // Also allow keyboard: R to retry, Q/Esc to quit
        function onGameOverKey(e) {
            if (e.code === 'KeyR') {
                gameArea.removeEventListener('keydown', onGameOverKey);
                overlay.remove();
                clearEntities();
                startGame();
            } else if (e.code === 'KeyQ' || e.code === 'Escape') {
                gameArea.removeEventListener('keydown', onGameOverKey);
                overlay.remove();
                quitGame();
            }
        }
        gameArea.addEventListener('keydown', onGameOverKey);
    }

    // --- Quit Game ---
    function quitGame() {
        running = false;
        if (animFrameId) cancelAnimationFrame(animFrameId);
        gameArea.removeEventListener('keydown', onKeyDown);
        gameArea.removeEventListener('keyup', onKeyUp);
        keys = {};

        clearEntities();
        gameArea.style.display = 'none';
        gameArea.innerHTML = '';

        if (footer) footer.classList.remove('game-mode');
        if (footerContent) footerContent.style.display = '';

        window.__orcaGameLoaded = false;
    }

    function clearEntities() {
        for (const e of entities) {
            e.el.remove();
        }
        entities = [];
    }

    // --- Input Handlers ---
    function onKeyDown(e) {
        if (['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Space'].includes(e.code)) {
            e.preventDefault();
            keys[e.code] = true;
        }
    }

    function onKeyUp(e) {
        keys[e.code] = false;
    }

    // --- Helpers ---
    function randomRange(min, max) {
        return min + Math.random() * (max - min);
    }

    // Expose public API
    window.OrcaGame = { init: init };
})();
