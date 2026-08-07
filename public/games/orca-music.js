/**
 * ORCA Feeding Frenzy - Chiptune music + SFX
 * Pure Web Audio API synthesis (no audio files).
 *
 * Deep Funk in E minor: a Patrice Rushen-style walking bass under a clavinet
 * lead, with a breakbeat on top. The lead has three states, driven by how many
 * threats are on screen:
 *
 *   calm   (0 threats)  LEAD  — the 16-bar tune, A A' B A'' (clavinet)
 *   tense  (1 threat)   LEAD thinned to its skeleton notes; pad thins too
 *   frenzy (2+ threats) CHASE — an 8-bar 70s chase riff on overdriven wah
 *                       guitar, punctuated by CHASE_STABS chord hits
 *
 * The bass switches to a JAWS half-step drone independently (bassNoteForStep).
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
            // Dev/lab hooks — kept in sync with the real façade so an
            // unsupported browser never throws on a missing method.
            setPadEnabled() {}, setLeadReactive() {},
            setWahAmount() {}, setVolume() {},
            getBar() { return { bar: 0, section: '-' }; },
        };
        return;
    }

    // --- Config ---
    const TARGET_VOLUME = 0.18;
    const LOOKAHEAD = 0.1;          // seconds to schedule ahead
    const SCHEDULER_INTERVAL = 25;  // ms
    const BPM = 132;
    const SIXTEENTH = 60 / BPM / 4;
    const BAR_SEC = SIXTEENTH * 16;
    // Chase-guitar overdrive depth. Higher = gnarlier and more squared-off;
    // it also compresses harder, so raising it means widening the envelope
    // range in guitarNote() to keep any dynamics at all.
    const GUITAR_DRIVE = 6.0;

    const NOTES = {
        'D2': 73.42, 'E2': 82.41, 'F2': 87.31, 'F#2': 92.50, 'G2': 98.00, 'G#2': 103.83, 'A2': 110.00, 'A#2': 116.54, 'B2': 123.47,
        'C3': 130.81, 'D3': 146.83, 'E3': 164.81, 'F3': 174.61, 'G3': 196.00, 'A3': 220.00, 'B3': 246.94,
        'C4': 261.63, 'D4': 293.66, 'E4': 329.63, 'F4': 349.23, 'F#4': 369.99, 'G4': 392.00, 'G#4': 415.30, 'A4': 440.00, 'A#4': 466.16, 'B4': 493.88,
        'C5': 523.25, 'D5': 587.33, 'E5': 659.25, 'F5': 698.46, 'F#5': 739.99, 'G5': 783.99, 'G#5': 830.61, 'A5': 880.00, 'A#5': 932.33, 'B5': 987.77,
        'C6': 1046.50, 'D6': 1174.66, 'E6': 1318.51,
    };

    // Pad chord voicings, deliberately low-mid (E3–F#4) so they sit under the
    // lead rather than fighting it.
    const CHORDS = {
        Em:  ['E3', 'G3', 'B3'],
        G:   ['G3', 'B3', 'D4'],
        Am7: ['A3', 'C4', 'E4'],
        Bm7: ['B3', 'D4', 'F#4'],
        C:   ['C3', 'E3', 'G3'],
    };

    // Chase stab voicings — the same chord vocabulary as the pad, but voiced
    // up into the mid register and carrying their 7ths/6ths, which is what
    // makes a funk stab bite instead of thud.
    const STAB_CHORDS = {
        Em:  ['E4', 'G4', 'B4'],
        Am7: ['A3', 'C4', 'E4', 'G4'],
        Bm7: ['B3', 'D4', 'F#4', 'A4'],
        G:   ['G3', 'B3', 'D4', 'E4'],
        C:   ['C4', 'E4', 'G4'],
    };

    // ---------------------------------------------------------------------
    // Melody tables
    //
    // 16 sixteenths per bar. Entries:
    //   null                    = rest
    //   'X5'                    = strike note for 1 sixteenth
    //   ['X5', n]               = strike note held n sixteenths
    //   ['X5', n, 'a']          = accented   |   ['X5', n, 's'] = soft
    //   ['X5', n, 'a', 0.6]     = sounds 60% of the time (default 1)
    //   [['X5','G5'], n, 'a']   = picks one of these pitches each pass
    //
    // The last two are what stop the chase looping identically forever; see
    // CHASE. The tune itself uses none of them — it is meant to be learnable.
    // ---------------------------------------------------------------------

    // --- The tune: 16 bars (256 steps), A A' B A'' ---
    //   bars  1–4   A    hook statement + answer, 16th run, then a bar of air
    //   bars  5–8   A'   restatement; bar 6 is the single high climax
    //   bars  9–12  B    bridge: lower, longer notes, softer
    //   bars 13–16  A''  return + climactic tag + turnaround
    // Bar 1 states the hook falling to a low G4; bar 2 answers by mirroring it
    // upward. Repeated note + wide leap + held landing + space.
    const LEAD = [
        // Bar 1 — HOOK: statement, descends and lands low
        null,  null,  'E5',  'E5',  null,  null,  'D5',  null,
        null,  'B4',  null,  null,  ['G4', 4], null, null, null,
        // Bar 2 — HOOK: answer, mirrors the statement upward
        null,  null,  'E5',  'E5',  null,  null,  'G5',  null,
        null,  'A5',  null,  null,  ['B5', 4], null, null, null,
        // Bar 3 — 16th-note development, the busy contrast bar
        'B5',  'A5',  'G5',  'E5',  null,  'D5',  null,  'B4',
        null,  null,  'G4',  'A4',  'B4',  null,  'D5',  null,
        // Bar 4 — air (the bass gets the bar to itself), then a chromatic pickup
        null,  null,  null,  null,  null,  null,  null,  null,
        null,  null,  'E5',  null,  'G5',  'A5',  'A#5', 'B5',
        // Bar 5 — A': hook restated
        null,  null,  'E5',  'E5',  null,  null,  'D5',  null,
        null,  'B4',  null,  null,  ['G4', 4], null, null, null,
        // Bar 6 — A': answer reaches D6, the loop's high point
        null,  null,  'E5',  'E5',  null,  null,  'G5',  null,
        null,  'B5',  null,  null,  ['D6', 4], null, null, null,
        // Bar 7 — descent with mixed rhythm (not the off-beat 8ths again)
        'D6',  null,  'B5',  'A5',  null,  null,  'G5',  'E5',
        null,  null,  'D5',  null,  ['B4', 3], null, null, 'G4',
        // Bar 8 — lands long, then a pickup into the bridge
        ['E5', 6], null, null, null, null,  null,  null,  null,
        null,  null,  null,  null,  null,  'B4',  'D5',  'E5',
        // Bar 9 — BRIDGE: low, sustained, soft
        ['G4', 6], null, null, null, null,  null,  null,  null,
        ['A4', 4], null, null, null, ['B4', 4], null, null, null,
        // Bar 10 — bridge continues, falling
        ['D5', 6], null, null, null, null,  null,  null,  null,
        ['B4', 4], null, null, null, ['A4', 4], null, null, null,
        // Bar 11 — rising sequence out of the bridge
        ['G4', 4], null, null, null, ['A4', 4], null, null, null,
        ['B4', 4], null, null, null, ['D5', 4], null, null, null,
        // Bar 12 — long note, then a rising pickup back to the hook
        ['E5', 8], null, null, null, null,  null,  null,  null,
        null,  null,  null,  null,  null,  null,  'B4',  'D5',
        // Bar 13 — A'': hook returns
        null,  null,  'E5',  'E5',  null,  null,  'D5',  null,
        null,  'B4',  null,  null,  ['G4', 4], null, null, null,
        // Bar 14 — answer
        null,  null,  'E5',  'E5',  null,  null,  'G5',  null,
        null,  'A5',  null,  null,  ['B5', 4], null, null, null,
        // Bar 15 — climactic tag, touches E6 once and once only
        'B5',  'D6',  null,  ['E6', 3], null, null, null, 'D6',
        null,  'B5',  'A5',  null,  'G5',  null,  'E5',  'D5',
        // Bar 16 — turnaround: hold, then a whole bar of air
        ['E5', 8], null, null, null, null,  null,  null,  null,
        null,  null,  null,  null,  null,  null,  null,  'B4',
    ];

    const PAD = [
        'Em', 'Em', 'Am7', 'Bm7',
        'Em', 'Em', 'G',   'Bm7',
        'C',  'G',  'Am7', 'Bm7',
        'Em', 'Em', 'Am7', 'Em',
    ];

    // Per-bar articulation. Composed, not derived — the hook is always bright,
    // the bridge is always soft.
    const DYNAMICS = [
        'bright', 'bright', 'normal', 'normal',
        'bright', 'bright', 'normal', 'normal',
        'soft',   'soft',   'soft',   'soft',
        'bright', 'bright', 'bright', 'normal',
    ];

    // --- Frenzy: the chase riff, 8 bars (128 steps) ---
    // At 2+ threats the tune drops out and this takes over. High-speed 70s
    // chase funk — Schifrin/Shaft territory: a riff cell rather than a lyrical
    // melody, driven by relentless 16ths, blue notes (A#) and chromatic
    // descents that lock to the bass's E-F-G-F drone.
    //
    // Structure: riff (1) · answer (2) · 16th burst (3) · chromatic descent
    // (4) · riff an octave DOWN (5) · sweep back up (6) · descending run (7) ·
    // turnaround stabbing up to E6 (8).
    //
    // The space here is the point — 間 (ma), the interval that does the work.
    // Two rules shape it. First, the line always rests on the "and" of beats
    // 2 and 4 (steps 6 and 14), which is exactly where CHASE_STABS lands, so
    // riff and stabs interlock instead of piling up. Second, the silence is
    // *structured*: the riff bars share one rhythm (steps 0, 1, 3, 7, 11 —
    // an off-beat pulse every four steps after the opening cell) and then
    // stop dead for the last quarter of the bar. The ear learns that shape
    // and hears the gap as part of it.
    //
    // Two earlier drafts got this wrong in both directions: bars 3 and 7 ran
    // all sixteen steps (unhearable), and the correction still sat at 8.8
    // notes a bar (busy). This averages 5.5, and the burst bars 3 and 7 carry
    // the density so the riff bars don't have to.
    //
    // It also never plays the same way twice. Some notes carry a probability
    // and some offer a choice of pitch, so the riff keeps reshaping itself
    // instead of looping identically for as long as the sharks are around.
    // What varies is chosen carefully, because random is not the same as
    // interesting:
    //   · steps 0 and 3 of every riff bar, the chromatic F#-F-E of bar 4 and
    //     the E6 landing in bar 8 are FIXED — they are the riff's identity,
    //     and an ear that can't rely on them hears noise, not a tune;
    //   · nothing is ever added on steps 6 or 14, which belong to the stabs;
    //   · nothing is added in the structural silences — the ma stays put.
    // Only ornaments flicker, and only inside bars that are already busy.
    const CHASE = [
        // Bar 1 — the riff cell, then four steps of nothing
        ['E5', 1, 'a'], ['E5', 1, null, 0.85], ['G5', 1, null, 0.2], 'G5',
        null,  null,  null,  [['D5', 'E5'], 1, null, 0.8],
        null,  null,  null,  [['B4', 'D5'], 1],
        null,  null,  null,  null,
        // Bar 2 — same rhythm, answered upward: sequence is what makes it stick
        ['E5', 1, 'a'], ['E5', 1, null, 0.85], ['B5', 1, null, 0.2], 'G5',
        null,  null,  null,  [['B5', 'A5'], 1, null, 0.85],
        null,  null,  null,  [['A5', 'G5'], 1],
        null,  null,  null,  null,
        // Bar 3 — BURST: a 16th cluster, a hole, a second cluster
        'B4',  [['D5', 'E5'], 1], [['E5', 'G5'], 1, null, 0.9], 'G5',
        null,  null,  null,  null,
        [['B5', 'D6'], 1], null, ['A5', 1, null, 0.7], [['G5', 'A5'], 1, null, 0.85],
        null,  null,  null,  null,
        // Bar 4 — chromatic descent (F#-F-E, matching the bass) onto a held E
        ['D6', 1, 'a'], null, ['B5', 1, null, 0.3], ['A#5', 1, null, 0.75],
        null,  null,  null,  'G5',
        'F#5', 'F5',  null,  null,
        ['E5', 4], null, null, null,
        // Bar 5 — riff an octave down: the chase drops into the cellar
        ['E4', 1, 'a'], ['E4', 1, null, 0.85], ['G4', 1, null, 0.2], 'G4',
        null,  null,  null,  [['D4', 'E4'], 1, null, 0.8],
        null,  null,  null,  [['B3', 'D4'], 1],
        null,  null,  null,  null,
        // Bar 6 — same rhythm, sweeping back up out of the low register
        ['E4', 1, 'a'], ['E4', 1, null, 0.85], null, 'G4',
        null,  null,  null,  [['B4', 'A4'], 1, null, 0.85],
        null,  null,  null,  [['E5', 'D5'], 1],
        null,  null,  null,  [['A5', 'B5'], 1],
        // Bar 7 — BURST, descending: the mirror of bar 3
        [['B5', 'D6'], 1], 'A5', [['G5', 'A5'], 1, null, 0.9], 'E5',
        null,  null,  null,  null,
        ['A#5', 1, null, 0.8], null, [['B5', 'A5'], 1], ['A5', 1, null, 0.75],
        null,  null,  null,  null,
        // Bar 8 — turnaround: reaches E6, holds, drops back into the riff
        ['E5', 1, 'a'], null, null, [['A#5', 'B5'], 1, null, 0.8],
        null,  null,  null,  [['D6', 'B5'], 1],
        ['E6', 4, 'a'], null, null,  null,
        null,  null,  null,  'B5',
    ];

    // Chord stabs under the chase, on the "and" of 2 and 4 with extra pushes
    // in the busier bars. Harmony follows the same Em / Am7 / Bm7 / G cycle
    // the pad uses, so the chase stays in the tune's world.
    const CHASE_STABS = [
        // Bar 1 — Em
        null, null, null, null, null, null, 'Em',  null,
        null, null, null, null, null, null, 'Em',  null,
        // Bar 2 — Em
        null, null, null, null, null, null, 'Em',  null,
        null, null, null, null, null, null, 'Em',  null,
        // Bar 3 — Am7, with a push on the "e" of 2
        null, null, null, null, 'Am7', null, 'Am7', null,
        null, null, null, null, null, 'Am7', null, null,
        // Bar 4 — Bm7
        null, null, null, null, null, null, 'Bm7', null,
        null, null, 'Bm7', null, null, null, 'Bm7', null,
        // Bar 5 — Em
        null, null, null, null, null, null, 'Em',  null,
        null, null, null, null, null, null, 'Em',  null,
        // Bar 6 — Em
        null, null, null, null, null, null, 'Em',  null,
        null, null, null, null, null, null, 'Em',  null,
        // Bar 7 — G
        null, null, null, null, 'G',  null, 'G',   null,
        null, null, null, null, null, 'G',  null, null,
        // Bar 8 — Bm7 pulls back home to the Em riff
        null, null, null, null, null, null, 'Bm7', null,
        null, null, null, null, 'Bm7', null, 'Bm7', null,
    ];

    // --- Intro pickup / outro cadence ---
    const INTRO_PICKUP = ['B4', 'D5', 'E5', 'F#5', 'G5', 'A5', 'A#5', 'B5'];
    const INTRO_STAB = ['E5', 'G5', 'B5', 'D6'];
    const OUTRO_LICK = ['B5', 'A5', 'G5', 'E5'];
    const OUTRO_CHORD = ['E5', 'G5', 'B5'];

    // --- State ---
    let ctx = null;
    let compressor, masterGain, musicGain, sfxGain, leadGain, bassGain, drumGain, padGain, stabGain;
    let wahFilter = null, wahLfo = null, wahDepth = null, toneCap = null;
    let bassFilter = null, padFilter = null, stabFilter = null;
    let guitarPre = null, guitarDrive = null, guitarBody = null, guitarCut = null, guitarOut = null;
    let wahChasing = false;
    let noiseBuffer = null;
    let started = false;
    let muted = false;
    let schedulerHandle = null;
    let nextStepTime = 0;
    let currentStep = 0;
    let pendingDanger = 0;
    let dangerLatched = 0;
    let absBar = -1;
    // Lab-tunable knobs. Defaults are the shipping values.
    let padEnabled = true;
    let leadReactive = true;
    // 0.6 rather than full: the resonant peak and the chase guitar's
    // harmonics stack, and past ~60% the pair turns shrill.
    let wahAmount = 0.6;
    let volume = TARGET_VOLUME;

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
        masterGain.gain.value = muted ? 0 : volume;
        masterGain.connect(compressor);

        musicGain = ctx.createGain();
        musicGain.gain.value = 1.0;
        musicGain.connect(masterGain);

        sfxGain = ctx.createGain();
        sfxGain.gain.value = 1.0;
        sfxGain.connect(masterGain);

        leadGain = ctx.createGain();
        leadGain.gain.value = 0.7;

        // Wah-wah on the lead bus: a resonant lowpass swept by an LFO.
        //   leadGain ─► wahFilter ─► toneCap ─► musicGain
        //   wahLfo ─► wahDepth ─► wahFilter.frequency
        // Q and depth are well below the original 7 / 900 Hz: that combination
        // parked a sharp resonant peak around 2 kHz, right where the ear is
        // most sensitive, which is most of why the lead grated on repeat.
        wahFilter = ctx.createBiquadFilter();
        wahFilter.type = 'lowpass';
        wahFilter.frequency.value = 950; // sweep center
        wahFilter.Q.value = 3.5;         // enough "quack", far less fatigue
        leadGain.connect(wahFilter);

        // Fixed tone cap after the wah — shaves the harshest top end that
        // survives the sweep.
        toneCap = ctx.createBiquadFilter();
        toneCap.type = 'lowpass';
        toneCap.frequency.value = 3500;
        toneCap.Q.value = 0.7;
        wahFilter.connect(toneCap);
        toneCap.connect(musicGain);

        wahLfo = ctx.createOscillator();
        wahLfo.type = 'sine';
        // Half-note rate at 132 BPM — a slower sweep reads as intentional
        // where the old quarter-note rate got seasick over a long round.
        // setWahMode() doubles this during the chase.
        wahLfo.frequency.value = 1.1;
        wahDepth = ctx.createGain();
        wahDepth.gain.value = 450;   // ±450 Hz around 950 → 500–1400 Hz
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

        // Pad bus: soft sustained chords well under the lead, gently filtered
        // so they add harmonic body without muddying the mix.
        padGain = ctx.createGain();
        padGain.gain.value = 1.0;
        padFilter = ctx.createBiquadFilter();
        padFilter.type = 'lowpass';
        padFilter.frequency.value = 1300;
        padFilter.Q.value = 0.6;
        padGain.connect(padFilter);
        padFilter.connect(musicGain);

        // Chase-stab bus: its own path so the stabs can stay brighter and
        // punchier than the pad without opening padFilter up on the pad too.
        stabGain = ctx.createGain();
        stabGain.gain.value = 1.0;
        stabFilter = ctx.createBiquadFilter();
        stabFilter.type = 'lowpass';
        stabFilter.frequency.value = 3100;   // open enough for the attack to read
        stabFilter.Q.value = 1.1;
        stabGain.connect(stabFilter);
        stabFilter.connect(musicGain);

        // Wah-guitar voice chain, used by the chase lead. A raw oscillator
        // reads as synthetic no matter how it's filtered; what makes an
        // electric guitar sound like one is the amp's soft clipping, which
        // adds harmonics and compresses the dynamics. Notes share ONE
        // overdrive stage and put their envelope BEFORE it, so a harder note
        // clips harder — the way a real amp responds to picking.
        //   noteEnv ─► guitarPre ─► guitarDrive ─► guitarBody ─► guitarCut
        //           ─► guitarOut ─► wahFilter (the same pedal the lead uses)
        // Gain staging matters more than it looks, because drive and dynamics
        // pull against each other: the harder the shaper clips, the more it
        // squashes a loud note toward a quiet one. At GUITAR_DRIVE = 6 with a
        // pre of 1.8, an accented note came out barely 1% above an unaccented
        // one — all grit, no playing. Keeping pre lowish (1.2) and widening
        // the ENVELOPE's range instead (see guitarNote) buys the gnarl while
        // leaving accents ~23% louder, so the riff still has attack.
        guitarPre = ctx.createGain();
        guitarPre.gain.value = 1.2;

        guitarDrive = ctx.createWaveShaper();
        guitarDrive.curve = makeDriveCurve(GUITAR_DRIVE);
        guitarDrive.oversample = '4x';   // essential at this drive — kills aliasing

        guitarBody = ctx.createBiquadFilter();
        guitarBody.type = 'peaking';     // presence bump: the "honk" of a pickup
        guitarBody.frequency.value = 2200;
        guitarBody.Q.value = 1.2;
        guitarBody.gain.value = 5;

        guitarCut = ctx.createBiquadFilter();
        guitarCut.type = 'highpass';     // guitars have no sub — keeps it clear of the bass
        guitarCut.frequency.value = 160;
        guitarCut.Q.value = 0.7;

        guitarOut = ctx.createGain();
        guitarOut.gain.value = 0.15;     // balance against the clav lead

        guitarPre.connect(guitarDrive);
        guitarDrive.connect(guitarBody);
        guitarBody.connect(guitarCut);
        guitarCut.connect(guitarOut);
        guitarOut.connect(wahFilter);

        // Pre-bake a half-second white noise buffer for percussion + SFX.
        noiseBuffer = ctx.createBuffer(1, Math.floor(ctx.sampleRate * 0.5), ctx.sampleRate);
        const data = noiseBuffer.getChannelData(0);
        for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;

        applyWahAmount();

        if (ctx.state === 'suspended') ctx.resume().catch(() => {});
    }

    // Soft-clip ("overdrive") transfer curve for the guitar. tanh, normalised
    // so the curve still spans -1..1 — that's the classic amp saturation
    // shape: near-linear when quiet, progressively squashed as it's pushed.
    function makeDriveCurve(amount) {
        const n = 1024;
        const curve = new Float32Array(n);
        const norm = Math.tanh(amount);
        for (let i = 0; i < n; i++) {
            const x = (i * 2) / n - 1;
            curve[i] = Math.tanh(amount * x) / norm;
        }
        return curve;
    }

    // A wah pedal on a guitar is more resonant than the clavinet setting —
    // that pronounced peak is the "quack". The chase gets a sharper Q.
    function wahQ() {
        return wahChasing ? 1 + 5.0 * wahAmount : 1 + 2.5 * wahAmount;
    }

    function applyWahAmount() {
        if (!wahFilter || !wahDepth) return;
        wahDepth.gain.value = 450 * wahAmount;
        wahFilter.Q.value = wahQ();
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
    function clavNote(freq, when, durSteps, accent, holdSec, muffled, gainMul) {
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

        // Register-aware brightness: the sawtooth's upper harmonics are what
        // make high notes stab. Roll the saw back as pitch climbs so the top
        // of the melody stays round — this is the single biggest anti-fatigue
        // fix, and it's why the climax notes can now be used at all.
        const rolloff = freq > 700 ? Math.max(0.45, 1 - (freq - 700) / 1400) : 1;

        let peak = muffled ? 0.11 : (accent ? 0.36 : 0.28);
        peak *= (gainMul != null ? gainMul : 1);
        if (freq > 900) peak *= 0.9;
        const sawAmt = (muffled ? 0.22 : 0.55) * rolloff;
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

    // Soft sustained chord under the melody. Danger thins it, then kills it.
    function padChord(chordName, when, durSec, danger) {
        if (!ctx || !padEnabled || danger >= 2) return;
        const voicing = CHORDS[chordName];
        if (!voicing) return;
        // Tense: root + third only, quieter — the harmony pulls back.
        const list = danger === 1 ? voicing.slice(0, 2) : voicing;
        const g = danger === 1 ? 0.035 : 0.055;

        list.forEach((n) => {
            const freq = NOTES[n];
            if (!freq) return;
            const osc = ctx.createOscillator();
            osc.type = 'triangle';
            osc.frequency.value = freq;
            const env = ctx.createGain();
            env.gain.setValueAtTime(0, when);
            env.gain.linearRampToValueAtTime(g, when + 0.12);
            env.gain.setValueAtTime(g, when + Math.max(0.14, durSec - 0.18));
            env.gain.linearRampToValueAtTime(0, when + durSec);
            osc.connect(env).connect(padGain);
            osc.start(when);
            osc.stop(when + durSec + 0.05);
        });
    }

    // Wah-guitar voice for the chase. Two sawtooths a few cents apart (string
    // richness), through the shared overdrive stage above. The envelope is
    // what separates it from clavNote(): a clav is a hard pluck that decays
    // straight to nothing, where a guitar attacks fast, drops to a SUSTAIN
    // level and holds there — that sustain is most of what your ear reads as
    // "guitar" rather than "harpsichord".
    function guitarNote(freq, when, durSteps, accent) {
        if (!ctx) return;
        const noteSec = durSteps * SIXTEENTH;
        // Tighter tail than the clav: all notes share one overdrive stage, so
        // long ringing tails would intermodulate into mush at this drive.
        const stopAt = when + Math.min(0.5, noteSec + 0.14);
        // Deliberately wide: 2:1 into the shaper. Heavy clipping compresses
        // this back down to ~23% at the output, which is the dynamic range
        // the riff actually plays with.
        const peak = accent ? 0.30 : 0.15;
        const sustain = peak * 0.45;

        const osc1 = ctx.createOscillator();
        osc1.type = 'sawtooth';
        osc1.frequency.value = freq;

        const osc2 = ctx.createOscillator();
        osc2.type = 'sawtooth';
        osc2.frequency.value = freq;
        osc2.detune.value = 8;           // slight beating, like two coils

        const mix2 = ctx.createGain();
        mix2.gain.value = 0.5;

        const env = ctx.createGain();
        env.gain.setValueAtTime(0, when);
        env.gain.linearRampToValueAtTime(peak, when + 0.004);          // pick attack
        env.gain.exponentialRampToValueAtTime(sustain, when + 0.07);   // drop to sustain
        env.gain.setTargetAtTime(0.0001, Math.max(when + 0.075, when + noteSec * 0.8), 0.05);
        env.gain.setValueAtTime(0.00005, stopAt - 0.005);
        env.gain.linearRampToValueAtTime(0, stopAt);

        osc1.connect(env);
        osc2.connect(mix2).connect(env);
        env.connect(guitarPre);

        osc1.start(when);
        osc2.start(when);
        osc1.stop(stopAt);
        osc2.stop(stopAt);

        // Pick transient, accents only — at 16th-note density every note
        // carrying one would just fizz.
        if (accent) {
            noise(when, 0.014, {
                gain: 0.05,
                filterType: 'highpass',
                filterFreq: 2600,
                target: guitarOut,
            });
        }
    }

    // Short, punchy chord hit for the chase — sawtooth for bite over a
    // detuned triangle for body, with a fast attack and a ~130ms decay. The
    // point of a stab is that it stops: it punctuates the riff rather than
    // filling under it the way the pad does.
    function chordStab(chordName, when, accent) {
        if (!ctx) return;
        const voicing = STAB_CHORDS[chordName];
        if (!voicing) return;
        const peak = accent ? 0.085 : 0.065;
        const dur = 0.11;                    // shorter = more staccato, more articulate

        voicing.forEach((n, i) => {
            const freq = NOTES[n];
            if (!freq) return;
            // Strum spread: a chord whose voices all begin on the same sample
            // reads as a synth pad triggered by a sequencer. Offsetting each
            // by 2.5ms is barely perceptible as time and very perceptible as
            // "someone played this" — and it stops the attacks stacking into
            // one thick transient, which is most of the articulation.
            const at = when + i * 0.0025;

            const oscSaw = ctx.createOscillator();
            oscSaw.type = 'sawtooth';
            oscSaw.frequency.value = freq;
            const oscTri = ctx.createOscillator();
            oscTri.type = 'triangle';
            oscTri.frequency.value = freq * 1.004;

            const sawGain = ctx.createGain();
            sawGain.gain.value = 0.5;        // more saw = more bite and definition
            const triGain = ctx.createGain();
            triGain.gain.value = 0.55;

            const env = ctx.createGain();
            env.gain.setValueAtTime(0, at);
            env.gain.linearRampToValueAtTime(peak, at + 0.003);
            env.gain.setTargetAtTime(0, at + 0.003, 0.026);
            env.gain.setValueAtTime(0.0001, at + dur - 0.005);
            env.gain.linearRampToValueAtTime(0, at + dur);

            oscSaw.connect(sawGain).connect(env);
            oscTri.connect(triGain).connect(env);
            env.connect(stabGain);

            oscSaw.start(at);
            oscTri.start(at);
            oscSaw.stop(at + dur + 0.02);
            oscTri.stop(at + dur + 0.02);
        });
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
    // The pattern loops four times over the 16-bar lead.
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

    // The chase runs the wah harder, faster and more resonant — that pedal
    // working overtime is a large part of what makes 70s chase funk sound
    // like a chase, and a guitar wah quacks harder than a clavinet one.
    function setWahMode(chasing, when) {
        if (!wahLfo || !wahFilter) return;
        wahChasing = chasing;
        wahLfo.frequency.cancelScheduledValues(when);
        wahLfo.frequency.setTargetAtTime(chasing ? 2.2 : 1.1, when, 0.08);
        wahFilter.Q.cancelScheduledValues(when);
        wahFilter.Q.setTargetAtTime(wahQ(), when, 0.08);
    }

    function scheduleStep(step, when) {
        const barInLoop = Math.floor(step / 16) % DYNAMICS.length;

        // Latch danger at bar boundaries to avoid mid-bar pattern stutter.
        if (step % 16 === 0) {
            dangerLatched = pendingDanger;
            absBar++;

            const chasing = leadReactive && dangerLatched >= 2;
            // Glide the wah center to match this bar. The chase overrides the
            // tune's composed articulation — it's always wide open.
            const dyn = DYNAMICS[barInLoop];
            const center = chasing ? 1150
                : (dyn === 'soft' ? 620 : (dyn === 'bright' ? 1100 : 950));
            wahFilter.frequency.cancelScheduledValues(when);
            wahFilter.frequency.setTargetAtTime(center, when, 0.06);
            setWahMode(chasing, when);

            padChord(PAD[barInLoop], when, BAR_SEC, dangerLatched);
        }

        // --- Lead ---
        const dyn = DYNAMICS[barInLoop];
        const muffled = dyn === 'soft';
        const gainMul = dyn === 'normal' ? 0.85 : 1;

        const chasing = leadReactive && dangerLatched >= 2;
        // The chase has its own 8-bar cycle, independent of the 16-bar tune.
        const entry = chasing ? CHASE[step % CHASE.length] : LEAD[step];

        // Chord stabs punctuate the chase where the riff rests. The pad is
        // silent at this danger level, so these carry the harmony instead.
        if (chasing) {
            const stabChord = CHASE_STABS[step % CHASE_STABS.length];
            if (stabChord) chordStab(stabChord, when, (step % 16) === 6);
        }

        let leadNote = null;
        let durSteps = 1;
        let mark = null;
        if (typeof entry === 'string') {
            leadNote = entry;
        } else if (Array.isArray(entry)) {
            leadNote = entry[0];
            durSteps = entry[1] || 1;
            mark = entry[2] || null;
            // A choice of pitches: pick one for this pass.
            if (Array.isArray(leadNote)) {
                leadNote = leadNote[(Math.random() * leadNote.length) | 0];
            }
            // A probability: this ornament may simply not sound this time.
            if (entry[3] != null && Math.random() >= entry[3]) leadNote = null;
        }

        if (leadNote) {
            // Accent: explicit mark wins, otherwise the funk push points
            // (the "and" of beats 1 and 3) plus any held landing note.
            // The old rule accented ~90% of notes, which is no accent at all —
            // and at the chase's 16th-note density that would be unbearable.
            const accent = mark === 'a' || (mark !== 's' && ((step % 8) === 2 || durSteps >= 3));
            // The chase is never palm-muted; it needs the saw's bite.
            const noteMuffled = (muffled && !chasing) || mark === 's';

            // Tense: play only the skeleton — accents and held landings — so
            // the melody thins out instead of noodling over the JAWS bass.
            const skip = leadReactive && dangerLatched === 1 && !(accent || durSteps >= 3);
            if (!skip) {
                // The chase swaps the clavinet for the wah guitar.
                if (chasing) {
                    guitarNote(NOTES[leadNote], when, durSteps, accent);
                } else {
                    clavNote(NOTES[leadNote], when, durSteps, accent, undefined,
                             noteMuffled, gainMul);
                }
            }
        }

        // --- Bass (unchanged) ---
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

    // --- Intro pickup ---
    // Short pickup lick (with the wah doing its thing underneath), landing on
    // an Em7 stab and a low E bass anchor.
    function flourish(when) {
        if (!ctx) return;
        const stepDur = 0.075;
        let time = when;
        INTRO_PICKUP.forEach((n) => {
            clavNote(NOTES[n], time, 1, false);
            time += stepDur;
        });
        const stab = time + 0.04;
        INTRO_STAB.forEach((n) => clavNote(NOTES[n], stab, 4, true));
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

        // Reset wah to the bright center, resting sweep rate and clavinet
        // resonance, so the flourish doesn't inherit a soft section or a
        // chase left over from the previous round.
        wahChasing = false;
        wahFilter.frequency.cancelScheduledValues(now);
        wahFilter.frequency.setValueAtTime(1100, now);
        wahFilter.Q.cancelScheduledValues(now);
        wahFilter.Q.setValueAtTime(wahQ(), now);
        wahLfo.frequency.cancelScheduledValues(now);
        wahLfo.frequency.setValueAtTime(1.1, now);

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
        // A round that ended mid-chase leaves the wah fast, sharp and wide
        // open; settle it back so the cadence reads as calm, not panicked.
        wahChasing = false;
        wahLfo.frequency.cancelScheduledValues(now);
        wahLfo.frequency.setValueAtTime(1.1, now);
        wahFilter.Q.cancelScheduledValues(now);
        wahFilter.Q.setValueAtTime(wahQ(), now);

        const begin = now + 0.05;
        // Soft descending pentatonic lick: B5 → A5 → G5 → E5 (in-key, no
        // chromatic stinger). Eighth-note pacing — relaxed, not panicked.
        const step = 0.18;
        OUTRO_LICK.forEach((n, i) => {
            clavNote(NOTES[n], begin + i * step, 2, false);
        });

        // Final Em chord — rings out via clavinet decay with the wah
        // animating it. Held longer than a normal loop note.
        const chordAt = begin + OUTRO_LICK.length * step + 0.08;
        OUTRO_CHORD.forEach((n) => clavNote(NOTES[n], chordAt, 1, false, 1.6));

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
        masterGain.gain.linearRampToValueAtTime(muted ? 0 : volume, now + 0.08);
    }

    function isMuted() { return muted; }
    function isSupported() { return true; }

    // --- Dev / audition hooks (used by music-lab.html) ---
    function setPadEnabled(on) { padEnabled = !!on; }
    function setLeadReactive(on) { leadReactive = !!on; }
    function setWahAmount(x) {
        wahAmount = Math.max(0, Math.min(1, Number(x) || 0));
        applyWahAmount();
    }
    function setVolume(v) {
        volume = Math.max(0, Math.min(1, Number(v) || 0));
        if (!ctx || muted) return;
        const now = ctx.currentTime;
        masterGain.gain.cancelScheduledValues(now);
        masterGain.gain.setValueAtTime(masterGain.gain.value, now);
        masterGain.gain.linearRampToValueAtTime(volume, now + 0.05);
    }
    function getBar() {
        const chasing = leadReactive && dangerLatched >= 2;
        const bar = Math.floor(currentStep / 16);
        if (chasing) {
            return {
                bar: bar + 1,
                section: 'CHASE ' + ((Math.floor(currentStep / 16) % 8) + 1) + '/8',
                dynamics: 'driving',
            };
        }
        const section = bar < 4 ? 'A' : bar < 8 ? "A'" : bar < 12 ? 'B (bridge)' : 'A"';
        return { bar: bar + 1, section: section, dynamics: DYNAMICS[bar] || '-' };
    }

    window.OrcaMusic = {
        init, start, stop, setDanger,
        sfxCatch, sfxJump, sfxHit, sfxGameOver,
        setMuted, isMuted, isSupported,
        setPadEnabled, setLeadReactive, setWahAmount, setVolume, getBar,
    };
})();
