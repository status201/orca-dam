/**
 * ORCA Feeding Frenzy - Chiptune music + SFX
 * Pure Web Audio API synthesis (no audio files).
 */
(function () {
    'use strict';

    const AudioContextCtor = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextCtor) {
        window.OrcaMusic = {
            init() {}, start() {}, stop() {},
            setDanger() {},
            sfxCatch() {}, sfxJump() {}, sfxHit() {}, sfxGameOver() {},
            setMuted() {}, isMuted() { return true; },
            isSupported() { return false; },
        };
        return;
    }

    // --- Config ---
    const TARGET_VOLUME = 0.18;
    const LOOKAHEAD = 0.1;          // seconds to schedule ahead
    const SCHEDULER_INTERVAL = 25;  // ms
    const BPM = 132;
    const SIXTEENTH = 60 / BPM / 4;

    const NOTES = {
        'D2': 73.42, 'E2': 82.41, 'F2': 87.31, 'F#2': 92.50, 'G2': 98.00, 'G#2': 103.83, 'A2': 110.00, 'A#2': 116.54, 'B2': 123.47,
        'C3': 130.81, 'D3': 146.83, 'E3': 164.81, 'F3': 174.61, 'G3': 196.00, 'A3': 220.00, 'B3': 246.94,
        'C4': 261.63, 'D4': 293.66, 'E4': 329.63, 'F4': 349.23, 'F#4': 369.99, 'G4': 392.00, 'G#4': 415.30, 'A4': 440.00, 'A#4': 466.16, 'B4': 493.88,
        'C5': 523.25, 'D5': 587.33, 'E5': 659.25, 'F5': 698.46, 'F#5': 739.99, 'G5': 783.99, 'G#5': 830.61, 'A5': 880.00, 'A#5': 932.33, 'B5': 987.77,
        'C6': 1046.50, 'D6': 1174.66, 'E6': 1318.51,
    };

    // 8-bar lead melody in E-minor pentatonic + blues. 16 sixteenths per
    // bar = 128 steps.
    // Entries:
    //   null         = rest
    //   'X5'         = strike note for 1 sixteenth
    //   ['X5', n]    = strike note for n sixteenths (rings out longer)
    //
    // Patrice Rushen-style: a 2-bar clavinet HOOK (bars 1–2) that locks
    // into the walking bass via off-beat 16ths and pentatonic-blues notes.
    // Bars 3–4 develop the hook, bars 5–6 re-state it with a twist, bar 7
    // is a bluesy 16th-note build, bar 8 lands.
    const LEAD = [
        // Bar 1 — HOOK A (off-beat E + G stabs, walks back through D)
        null,  'E5',  null,  'G5',  null,  'B5',  null,  'E5',
        null,  'D5',  'E5',  null,  'G5',  null,  'E5',  null,
        // Bar 2 — HOOK B (reaches up to D6, walks back)
        null,  'E5',  null,  'G5',  null,  'D6',  null,  'B5',
        null,  'A5',  'G5',  null,  'E5',  null,  'D5',  null,
        // Bar 3 — climb up a 4th
        null,  'G5',  null,  'A5',  null,  'B5',  null,  'D6',
        null,  'B5',  'A5',  null,  'G5',  null,  'A5',  null,
        // Bar 4 — bluesy descent with A#5 (b5) passing tone
        null,  'A5',  'A#5', 'A5',  null,  'G5',  null,  'E5',
        null,  'D5',  'E5',  null,  ['G5', 4], null,  null,  null,
        // Bar 5 — HOOK A re-stated
        null,  'E5',  null,  'G5',  null,  'B5',  null,  'E5',
        null,  'D5',  'E5',  null,  'G5',  null,  'E5',  null,
        // Bar 6 — HOOK B variation, settles low on B4
        null,  'E5',  null,  'G5',  null,  'D6',  null,  'B5',
        null,  'A5',  'G5',  'E5',  'D5',  null,  'B4',  null,
        // Bar 7 — bluesy 16th-note build
        'G5',  'A#5', 'B5',  'D6',  'B5',  'A5',  'G5',  null,
        'A5',  'G5',  'E5',  'D5',  'E5',  'G5',  'A5',  'B5',
        // Bar 8 — land on a long E with a turn-around tag
        ['E5', 4], null,  null,  null,  'G5',  'B5',  'D6',  null,
        ['E5', 6], null,  null,  null,  null,  null,  null,  null,
    ];

    // --- State ---
    let ctx = null;
    let compressor, masterGain, musicGain, sfxGain, leadGain, bassGain, drumGain;
    let wahFilter = null, wahLfo = null, wahDepth = null;
    let bassFilter = null;
    let noiseBuffer = null;
    let started = false;
    let muted = false;
    let schedulerHandle = null;
    let nextStepTime = 0;
    let currentStep = 0;
    let pendingDanger = 0;
    let dangerLatched = 0;
    // Absolute bar counter (doesn't reset on lead-loop wrap). The 3-bar
    // muffle cycle is keyed off this so muffle/normal phases drift across
    // the 8-bar lead, giving each lead loop a different muffling layout.
    let absBar = -1;

    // Load persisted mute state up front so isMuted() returns sensibly pre-init.
    try {
        muted = localStorage.getItem('orca_music_muted') === '1';
    } catch (e) { /* ignore */ }

    function saveMuted() {
        try { localStorage.setItem('orca_music_muted', muted ? '1' : '0'); } catch (e) { /* ignore */ }
    }

    function init() {
        if (ctx) {
            if (ctx.state === 'suspended') ctx.resume().catch(() => {});
            return;
        }
        ctx = new AudioContextCtor();

        compressor = ctx.createDynamicsCompressor();
        compressor.threshold.value = -16;
        compressor.knee.value = 10;
        compressor.ratio.value = 4;
        compressor.attack.value = 0.005;
        compressor.release.value = 0.12;
        compressor.connect(ctx.destination);

        masterGain = ctx.createGain();
        masterGain.gain.value = muted ? 0 : TARGET_VOLUME;
        masterGain.connect(compressor);

        musicGain = ctx.createGain();
        musicGain.gain.value = 1.0;
        musicGain.connect(masterGain);

        sfxGain = ctx.createGain();
        sfxGain.gain.value = 1.0;
        sfxGain.connect(masterGain);

        leadGain = ctx.createGain();
        leadGain.gain.value = 0.7;

        // Wah-wah filter on the lead bus: a resonant lowpass whose cutoff
        // is swept by an LFO. Lead is fully wet — that's how a real wah
        // pedal sounds, and it's what gives clavinet that vocal "wow-wow".
        //   leadGain ─► wahFilter ─► musicGain
        //   wahLfo ─► wahDepth ─► wahFilter.frequency  (cutoff modulation)
        wahFilter = ctx.createBiquadFilter();
        wahFilter.type = 'lowpass';
        wahFilter.frequency.value = 1200; // sweep center
        wahFilter.Q.value = 7;            // resonant — that's the "quack"
        leadGain.connect(wahFilter);
        wahFilter.connect(musicGain);

        wahLfo = ctx.createOscillator();
        wahLfo.type = 'sine';
        wahLfo.frequency.value = 2.2; // quarter-note rate at 132 BPM
        wahDepth = ctx.createGain();
        wahDepth.gain.value = 900;    // ±900 Hz around 1200 → 300–2100 Hz
        wahLfo.connect(wahDepth);
        wahDepth.connect(wahFilter.frequency);
        wahLfo.start();

        bassGain = ctx.createGain();
        bassGain.gain.value = 0.9;
        // Synth-bass lowpass: tames the sawtooth's high harmonics into that
        // round Moog-ish bass tone (think Patrice Rushen's "Forget Me Nots").
        bassFilter = ctx.createBiquadFilter();
        bassFilter.type = 'lowpass';
        bassFilter.frequency.value = 700;
        bassFilter.Q.value = 1.8;
        bassGain.connect(bassFilter);
        bassFilter.connect(musicGain);

        drumGain = ctx.createGain();
        drumGain.gain.value = 0.55;
        drumGain.connect(musicGain);

        // Pre-bake a half-second white noise buffer for percussion + SFX.
        noiseBuffer = ctx.createBuffer(1, Math.floor(ctx.sampleRate * 0.5), ctx.sampleRate);
        const data = noiseBuffer.getChannelData(0);
        for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;

        if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    }

    function tone(freq, when, duration, options) {
        if (!ctx) return;
        const opts = options || {};
        const osc = ctx.createOscillator();
        osc.type = opts.wave || 'square';
        osc.frequency.setValueAtTime(freq, when);
        if (opts.detune) osc.detune.value = opts.detune;
        if (opts.slideTo) {
            const slideTime = opts.slideTime != null ? opts.slideTime : duration;
            osc.frequency.exponentialRampToValueAtTime(Math.max(1, opts.slideTo), when + slideTime);
        }

        const gain = opts.gain != null ? opts.gain : 0.3;
        const attack = opts.attack != null ? opts.attack : 0.005;
        const release = opts.release != null ? opts.release : 0.06;
        const env = ctx.createGain();
        env.gain.setValueAtTime(0, when);
        env.gain.linearRampToValueAtTime(gain, when + attack);
        const sustainEnd = Math.max(when + attack, when + duration - release);
        env.gain.setValueAtTime(gain, sustainEnd);
        env.gain.linearRampToValueAtTime(0, when + duration);

        osc.connect(env).connect(opts.target || leadGain);
        osc.start(when);
        osc.stop(when + duration + 0.05);
    }

    function noise(when, duration, options) {
        if (!ctx || !noiseBuffer) return;
        const opts = options || {};
        const src = ctx.createBufferSource();
        src.buffer = noiseBuffer;
        const filter = ctx.createBiquadFilter();
        filter.type = opts.filterType || 'highpass';
        filter.frequency.value = opts.filterFreq || 4000;
        filter.Q.value = opts.filterQ || 1;
        const env = ctx.createGain();
        const gain = opts.gain != null ? opts.gain : 0.2;
        env.gain.setValueAtTime(gain, when);
        env.gain.exponentialRampToValueAtTime(0.001, when + duration);
        src.connect(filter).connect(env).connect(opts.target || drumGain);
        src.start(when, 0, duration);
        src.stop(when + duration + 0.05);
    }

    // --- Drums ---
    function kick(when) {
        tone(80, when, 0.18, {
            wave: 'sine',
            gain: 0.45,
            attack: 0.001,
            release: 0.05,
            target: drumGain,
            slideTo: 40,
            slideTime: 0.1,
        });
    }

    function hat(when, gain) {
        noise(when, 0.04, {
            gain: gain != null ? gain : 0.1,
            filterType: 'highpass',
            filterFreq: 7000,
            target: drumGain,
        });
    }

    // Longer, slightly more open noise burst for that breakbeat "tssh" splash.
    function openHat(when) {
        noise(when, 0.18, {
            gain: 0.11,
            filterType: 'highpass',
            filterFreq: 6500,
            target: drumGain,
        });
    }

    function snare(when) {
        noise(when, 0.08, {
            gain: 0.18,
            filterType: 'bandpass',
            filterFreq: 2500,
            filterQ: 1.2,
            target: drumGain,
        });
        tone(180, when, 0.06, {
            wave: 'triangle',
            gain: 0.13,
            attack: 0.001,
            release: 0.04,
            target: drumGain,
            slideTo: 110,
            slideTime: 0.05,
        });
    }

    // Clavinet-style pluck voice: sawtooth (harmonic bite) + lightly detuned
    // triangle (warmth), with a percussive exponential decay. Routes through
    // leadGain so it picks up the wah filter automatically.
    function clavNote(freq, when, durSteps, accent, holdSec, muffled) {
        if (!ctx) return;
        // Short, snappy decay for staccato notes; held notes ring out more
        // but every clavinet note still decays — you can't "sustain" a pluck.
        // Optional holdSec overrides the cap for sustained chord voicings.
        // muffled = palm-mute feel: quieter, warmer (less saw bite), faster decay.
        const noteSec = durSteps * SIXTEENTH;
        const baseDecay = Math.min(0.18, 0.05 + noteSec * 0.35);
        const decayTau = holdSec != null
            ? holdSec * 0.28
            : (muffled ? baseDecay * 0.5 : baseDecay);
        const stopAt = when + (holdSec != null
            ? holdSec
            : Math.min(0.7, noteSec + 0.25));
        const peak = muffled ? 0.11 : (accent ? 0.36 : 0.30);
        const sawAmt = muffled ? 0.22 : 0.55;
        const triAmt = muffled ? 0.55 : 0.45;

        const oscSaw = ctx.createOscillator();
        oscSaw.type = 'sawtooth';
        oscSaw.frequency.value = freq;

        const oscTri = ctx.createOscillator();
        oscTri.type = 'triangle';
        oscTri.frequency.value = freq * 1.006; // slight detune for chorus

        const sawGain = ctx.createGain();
        sawGain.gain.value = sawAmt;
        const triGain = ctx.createGain();
        triGain.gain.value = triAmt;

        const env = ctx.createGain();
        env.gain.setValueAtTime(0, when);
        env.gain.linearRampToValueAtTime(peak, when + 0.003);
        env.gain.setTargetAtTime(0, when + 0.003, decayTau);
        // Hard cutoff so we don't leave dangling oscillators.
        env.gain.setValueAtTime(0.0001, stopAt - 0.005);
        env.gain.linearRampToValueAtTime(0, stopAt);

        oscSaw.connect(sawGain).connect(env);
        oscTri.connect(triGain).connect(env);
        env.connect(leadGain);

        oscSaw.start(when);
        oscTri.start(when);
        oscSaw.stop(stopAt);
        oscTri.stop(stopAt);
    }

    // Quieter, tighter snare for funk syncopation between the main hits.
    function ghostSnare(when) {
        noise(when, 0.045, {
            gain: 0.075,
            filterType: 'bandpass',
            filterFreq: 2200,
            filterQ: 1.5,
            target: drumGain,
        });
    }

    // Snare FLAM: quiet grace note ~18ms before the main hit. The classic
    // "fa-PUT" rudiment — adds weight without doubling the volume.
    function snareFlam(when) {
        noise(when - 0.018, 0.035, {
            gain: 0.1,
            filterType: 'bandpass',
            filterFreq: 2400,
            filterQ: 1.4,
            target: drumGain,
        });
        snare(when);
    }

    // --- Loop ---
    // 2-bar breakbeat (32 steps). Chase-scene urgency: syncopated kicks
    // off the downbeats, ghost snares around the backbeat, fast open-hat
    // shuffle and a snare flam on bar 2's beat 2.
    //   K = kick   S = snare   F = snare FLAM   g = ghost snare
    //   h = closed hat   H = open hat (replaces closed)
    const DRUM_PATTERN = [
        // Bar 1 — groove with half-shuffle (open hats on the + of 2 and 4)
        // 1     e    +    a    2     e    +    a    3    e    +    a    4    e    +    a
        'Kh',  'h',  'h',  'h',  'Sh', 'h', 'gH', 'gh',
        'h',   'h',  'Kh', 'h',  'Sh', 'h', 'H',  'h',
        // Bar 2 — FAST open-hat shuffle (open on every +) + flam on beat 2
        'Kh',  'h',  'H',  'Kh', 'Fh', 'h', 'H',  'gh',
        'h',   'h',  'KH', 'h',  'Sh', 'h', 'gH', 'gh',
    ];

    // 4-bar walking bass in E minor, Patrice Rushen-style: octave jumps
    // (E2↔E3), chromatic walks (F#-F-E, A-G-F#), syncopated 16ths.
    // The pattern loops twice over the 8-bar lead.
    const BASS_CALM = [
        // Bar 1 — Em vamp with chromatic tail (F#-F)
        'E2',  null,  'E3',  null,  'E2',  null,  'E3',  'G2',
        null,  'E2',  null,  'E3',  null,  'F#2', null,  'F2',
        // Bar 2 — climb up to A/B, walk back through D
        'E2',  null,  'E3',  null,  'E2',  null,  'E3',  'G2',
        null,  'A2',  null,  'B2',  null,  'D3',  null,  'B2',
        // Bar 3 — Em vamp + descent (A → G → F#)
        'E2',  null,  'E3',  null,  'E2',  null,  'E3',  'G2',
        null,  'A2',  null,  'G2',  null,  'F#2', null,  'E2',
        // Bar 4 — turnaround through F (Phrygian b2) back to E
        'E2',  null,  'E3',  null,  'E2',  null,  'E3',  'G2',
        null,  'F#2', null,  'F2',  null,  'E2',  null,  'F2',
    ];

    function bassNoteForStep(step, danger) {
        if (danger <= 0) {
            return BASS_CALM[step % BASS_CALM.length];
        } else if (danger === 1) {
            const sub = step % 16;
            // Tense — quarter notes alternating (JAWS half-step)
            if (sub === 0) return 'E2';
            if (sub === 4) return 'F2';
            if (sub === 8) return 'E2';
            if (sub === 12) return 'F2';
        } else {
            // Frenzy — rising motif on eighths
            const p = ['E2', null, 'F2', null, 'G2', null, 'F2', null,
                       'E2', null, 'F2', null, 'G2', null, 'F2', null];
            return p[step % 16];
        }
        return null;
    }

    function scheduleStep(step, when) {
        // Latch danger at bar boundaries to avoid mid-bar pattern stutter.
        if (step % 16 === 0) {
            dangerLatched = pendingDanger;
            absBar++;
        }

        // 3-bar muffle cycle (3 normal, 3 muffled, repeat). Because 6 doesn't
        // divide the 8-bar lead, the muffle phases drift through the melody
        // — the hook lands on a different muffle state each loop. Glide the
        // wah cutoff center at every 3-bar section boundary.
        if (step % 16 === 0 && absBar % 3 === 0) {
            const muffledSection = (absBar % 6) >= 3;
            const target = muffledSection ? 650 : 1200;
            wahFilter.frequency.cancelScheduledValues(when);
            wahFilter.frequency.setTargetAtTime(target, when, 0.06);
        }

        // Lead: entries may be null | 'NOTE' | ['NOTE', durSixteenths].
        const entry = LEAD[step];
        let leadNote = null;
        let durSteps = 1;
        if (typeof entry === 'string') {
            leadNote = entry;
        } else if (Array.isArray(entry)) {
            leadNote = entry[0];
            durSteps = entry[1] || 1;
        }
        if (leadNote) {
            // Funky accent: off-beats hit slightly harder than on-beats.
            const onBeat = (step % 4) === 0;
            // 3-bar normal / 3-bar muffled, keyed off the absolute bar
            // counter so the phase drifts through the 8-bar lead loop.
            const muffled = (absBar % 6) >= 3;
            clavNote(NOTES[leadNote], when, durSteps, !onBeat, undefined, muffled);
        }

        const bassNote = bassNoteForStep(step, dangerLatched);
        if (bassNote) {
            // Calm walking bass: short 16th-note synth bass (sawtooth + filter).
            // Tense/frenzy: longer triangle notes for the JAWS drone feel.
            const isCalm = dangerLatched === 0;
            // Calm walking bass plays ~9 notes/bar, so keep each one short
            // and articulate (no long sustains) — that's the funk feel.
            const dur = isCalm ? SIXTEENTH * 0.85 :
                        dangerLatched === 1 ? SIXTEENTH * 3 :
                                              SIXTEENTH * 1.6;
            tone(NOTES[bassNote], when, dur, {
                wave: isCalm ? 'sawtooth' : 'triangle',
                gain: isCalm ? 0.36 : 0.3,
                attack: 0.002,
                release: 0.025,
                target: bassGain,
            });
        }

        // Breakbeat: dispatch via the 2-bar DRUM_PATTERN table.
        const events = DRUM_PATTERN[step % DRUM_PATTERN.length];
        if (events.indexOf('K') !== -1) kick(when);
        if (events.indexOf('F') !== -1) snareFlam(when);
        else if (events.indexOf('S') !== -1) snare(when);
        if (events.indexOf('g') !== -1) ghostSnare(when);
        if (events.indexOf('H') !== -1) {
            openHat(when);
        } else if (events.indexOf('h') !== -1) {
            // Hat accent on the "and" of each beat (subs 2, 6, 10, 14),
            // softer fills on every other 16th — the breakbeat swing.
            const subInBar = step % 16;
            const accent = (subInBar === 2 || subInBar === 6 || subInBar === 10 || subInBar === 14);
            hat(when, accent ? 0.10 : 0.05);
        }
    }

    function scheduler() {
        if (!ctx) return;
        while (nextStepTime < ctx.currentTime + LOOKAHEAD) {
            scheduleStep(currentStep, nextStepTime);
            nextStepTime += SIXTEENTH;
            currentStep = (currentStep + 1) % LEAD.length;
        }
    }

    // --- Funky clavinet pickup intro ---
    // Short chromatic pickup lick (with the wah doing its thing underneath),
    // landing on an Em7 chord stab and a low E bass anchor.
    function flourish(when) {
        if (!ctx) return;
        const stepDur = 0.075;
        let t = when;
        // Pickup lick climbs through pentatonic + Phrygian b2 + blue b5.
        const pickup = ['B4', 'D5', 'E5', 'F5', 'G5', 'A5', 'A#5', 'B5'];
        pickup.forEach((n) => {
            clavNote(NOTES[n], t, 1, false);
            t += stepDur;
        });
        // Em7 stab on the and-of-4 going into the loop.
        const stab = t + 0.04;
        clavNote(NOTES['E5'], stab, 4, true);
        clavNote(NOTES['G5'], stab, 4, true);
        clavNote(NOTES['B5'], stab, 4, true);
        clavNote(NOTES['D6'], stab, 4, true);
        // Low bass anchor (dry — bypasses the wah).
        tone(NOTES['E2'], stab, 0.5, {
            wave: 'triangle',
            gain: 0.38,
            attack: 0.003,
            release: 0.3,
            target: bassGain,
        });
        // Kick to lock in the count-in.
        kick(stab);
    }

    // --- Public lifecycle ---
    function start() {
        if (!ctx) return;
        if (started) return;
        started = true;
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});

        const now = ctx.currentTime;
        musicGain.gain.cancelScheduledValues(now);
        musicGain.gain.setValueAtTime(1.0, now);

        // Reset wah cutoff to the bright "normal" center so the flourish
        // doesn't inherit a muffled state from a previous round.
        wahFilter.frequency.cancelScheduledValues(now);
        wahFilter.frequency.setValueAtTime(1200, now);

        flourish(now);

        currentStep = 0;
        absBar = -1;
        // Tight clavinet pickup is ~0.6s long; loop kicks in right after
        // the Em7 stab so the groove drops in on the next downbeat.
        nextStepTime = now + 0.85;
        if (schedulerHandle) clearInterval(schedulerHandle);
        schedulerHandle = setInterval(scheduler, SCHEDULER_INTERVAL);
    }

    function stop() {
        if (!ctx) return;
        if (schedulerHandle) {
            clearInterval(schedulerHandle);
            schedulerHandle = null;
        }
        started = false;
        const now = ctx.currentTime;
        musicGain.gain.cancelScheduledValues(now);
        musicGain.gain.setValueAtTime(musicGain.gain.value, now);
        musicGain.gain.linearRampToValueAtTime(0, now + 0.25);
    }

    function setDanger(level) {
        pendingDanger = Math.max(0, Math.min(2, level | 0));
    }

    // --- SFX ---
    function sfxCatch(points) {
        if (!ctx) return;
        const when = ctx.currentTime;
        let arp;
        if (points >= 75) arp = ['G5', 'B5', 'D6'];
        else if (points >= 50) arp = ['E5', 'G5', 'B5'];
        else arp = ['C5', 'E5', 'G5'];
        arp.forEach((n, i) => {
            tone(NOTES[n], when + i * 0.05, 0.1, {
                wave: 'square',
                gain: 0.28,
                attack: 0.002,
                release: 0.04,
                target: sfxGain,
            });
        });
    }

    function sfxJump() {
        if (!ctx) return;
        tone(400, ctx.currentTime, 0.09, {
            wave: 'sine',
            gain: 0.22,
            attack: 0.001,
            release: 0.04,
            target: sfxGain,
            slideTo: 900,
            slideTime: 0.08,
        });
    }

    function sfxHit() {
        if (!ctx) return;
        const when = ctx.currentTime;
        tone(220, when, 0.28, {
            wave: 'square',
            gain: 0.32,
            attack: 0.001,
            release: 0.12,
            target: sfxGain,
            slideTo: 80,
            slideTime: 0.22,
            detune: -25,
        });
        noise(when, 0.22, {
            gain: 0.25,
            filterType: 'lowpass',
            filterFreq: 900,
            target: sfxGain,
        });
        // Brief music duck for emphasis.
        musicGain.gain.cancelScheduledValues(when);
        musicGain.gain.setValueAtTime(musicGain.gain.value, when);
        musicGain.gain.linearRampToValueAtTime(0.3, when + 0.04);
        musicGain.gain.linearRampToValueAtTime(1.0, when + 0.5);
    }

    function sfxGameOver() {
        if (!ctx) return;
        const now = ctx.currentTime;
        // stop() was called just before us and is fading musicGain to 0.
        // Cancel that fade so the cadence plays cleanly through the same
        // clavinet+wah path as the loop (matching timbre).
        musicGain.gain.cancelScheduledValues(now);
        musicGain.gain.setValueAtTime(1.0, now);

        const start = now + 0.05;
        // Soft descending pentatonic lick: B5 → A5 → G5 → E5 (in-key, no
        // chromatic stinger). Eighth-note pacing — relaxed, not panicked.
        const step = 0.18;
        const lick = ['B5', 'A5', 'G5', 'E5'];
        lick.forEach((n, i) => {
            clavNote(NOTES[n], start + i * step, 2, false);
        });

        // Final Em chord (E5 + G5 + B5) — rings out via clavinet decay
        // with the wah animating it. Held longer than a normal loop note.
        const chordAt = start + lick.length * step + 0.08;
        clavNote(NOTES['E5'], chordAt, 1, false, 1.6);
        clavNote(NOTES['G5'], chordAt, 1, false, 1.6);
        clavNote(NOTES['B5'], chordAt, 1, false, 1.6);

        // Low E bass anchor through the synth-bass filter (rounded Moog tone).
        tone(NOTES['E2'], chordAt, 1.1, {
            wave: 'sawtooth',
            gain: 0.32,
            attack: 0.003,
            release: 0.6,
            target: bassGain,
        });
    }

    function setMuted(m) {
        muted = !!m;
        saveMuted();
        if (!ctx) return;
        const now = ctx.currentTime;
        masterGain.gain.cancelScheduledValues(now);
        masterGain.gain.setValueAtTime(masterGain.gain.value, now);
        masterGain.gain.linearRampToValueAtTime(muted ? 0 : TARGET_VOLUME, now + 0.08);
    }

    function isMuted() { return muted; }
    function isSupported() { return true; }

    window.OrcaMusic = {
        init, start, stop, setDanger,
        sfxCatch, sfxJump, sfxHit, sfxGameOver,
        setMuted, isMuted, isSupported,
    };
})();
