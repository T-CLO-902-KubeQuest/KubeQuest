/* =============================================================
   CLICK ARENA — moteur de jeu (vanilla JS, zéro dépendance)
   Tout dérive de la DB : chaque clic = 1 ligne horodatée.
   ============================================================= */
(() => {
    "use strict";

    /* ---------- Config ---------- */

    // Courbe d'XP : niveau 0→1 coûte 10 clics, puis +5 par niveau.
    function levelFromTotal(total) {
        let level = 0, rem = total, need = 10;
        while (rem >= need) { rem -= need; level++; need = 10 + level * 5; }
        return { level, into: rem, need };
    }

    const RANKS = [
        { min: 0,  icon: "🥚", title: "Novice du Clic" },
        { min: 3,  icon: "🐣", title: "Apprenti" },
        { min: 6,  icon: "🔥", title: "Cliqueur Confirmé" },
        { min: 10, icon: "⚡", title: "Vétéran du Clic" },
        { min: 15, icon: "💎", title: "Maître du Clic" },
        { min: 22, icon: "👑", title: "Grand Maître" },
        { min: 30, icon: "🌌", title: "Légende Vivante" },
    ];
    const rankFor = (lvl) => RANKS.reduce((acc, r) => (lvl >= r.min ? r : acc), RANKS[0]);

    const ACHIEVEMENTS = [
        { id: "first",       icon: "👆", label: "Premier Pas",  test: (t) => t >= 1 },
        { id: "ten",         icon: "🔟", label: "Échauffement", test: (t) => t >= 10 },
        { id: "fifty",       icon: "💪", label: "Sérieux",      test: (t) => t >= 50 },
        { id: "hundred",     icon: "💯", label: "Centurion",    test: (t) => t >= 100 },
        { id: "fivehundred", icon: "🤖", label: "Machine",      test: (t) => t >= 500 },
        { id: "thousand",    icon: "🏆", label: "Millénaire",   test: (t) => t >= 1000 },
        { id: "speed",       icon: "🚀", label: "Speed Demon",  test: () => false }, // débloqué par la vitesse
    ];

    const MILESTONES = [100, 500, 1000, 5000];

    const EGGS = {
        42:   { icon: "🌌", title: "La Réponse",        sub: "à la vie, l'univers et le reste" },
        69:   { icon: "😎", title: "Nice",              sub: "" },
        404:  { icon: "🤷", title: "Counter Not Found", sub: "et pourtant il est là" },
        666:  { icon: "😈", title: "Diabolique",        sub: "" },
        1337: { icon: "👾", title: "ÉLITE",             sub: "l33t" },
    };

    const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    /* ---------- DOM ---------- */
    const $ = (id) => document.getElementById(id);
    const els = {
        value: $("value"), add: $("add"), level: $("level"),
        xpInto: $("xp-into"), xpNeed: $("xp-need"), xpFill: $("xp-fill"),
        rankIcon: $("rank-icon"), rankTitle: $("rank-title"),
        today: $("stat-today"), cpm: $("stat-cpm"), age: $("stat-age"),
        badges: $("badges"), toasts: $("toasts"), mute: $("mute"), fx: $("fx"),
        arena: document.querySelector(".arena"),
    };

    /* ---------- État ---------- */
    const state = {
        total: window.__INITIAL_TOTAL__ | 0,
        today: 0,
        firstAt: null,
        unlocked: new Set(),
        clickTimes: [], // horodatages locaux (cpm + speed demon)
    };

    /* ---------- Audio (synthétisé, aucun fichier) ---------- */
    const audio = {
        ctx: null,
        muted: localStorage.getItem("ca_muted") === "1",
        init() { if (!this.ctx) { try { this.ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch (_) {} } },
        tone(freq, dur, type = "square", gain = 0.08) {
            if (this.muted || !this.ctx) return;
            const t = this.ctx.currentTime;
            const osc = this.ctx.createOscillator();
            const g = this.ctx.createGain();
            osc.type = type; osc.frequency.value = freq;
            g.gain.setValueAtTime(gain, t);
            g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
            osc.connect(g).connect(this.ctx.destination);
            osc.start(t); osc.stop(t + dur);
        },
        blip(pitch = 1) { this.tone(420 * pitch, 0.08); },
        arpeggio(notes) { notes.forEach((f, i) => setTimeout(() => this.tone(f, 0.16, "triangle", 0.1), i * 70)); },
    };

    /* ---------- FX : confettis + étincelles (canvas) ---------- */
    const fx = (() => {
        const c = els.fx, ctx = c.getContext("2d");
        let parts = [], raf = null;
        const colors = ["#7b5bff", "#00e5ff", "#ff2e88", "#ffd166", "#ffffff"];

        function resize() {
            c.width = window.innerWidth; c.height = window.innerHeight;
        }
        resize();
        window.addEventListener("resize", resize);

        function loop() {
            ctx.clearRect(0, 0, c.width, c.height);
            for (let i = parts.length - 1; i >= 0; i--) {
                const p = parts[i];
                p.vy += p.g; p.x += p.vx; p.y += p.vy; p.rot += p.vr; p.life--;
                ctx.save();
                ctx.globalAlpha = Math.max(0, p.life / p.maxLife);
                ctx.translate(p.x, p.y); ctx.rotate(p.rot);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.s / 2, -p.s / 2, p.s, p.s * p.ratio);
                ctx.restore();
                if (p.life <= 0 || p.y > c.height + 40) parts.splice(i, 1);
            }
            raf = parts.length ? requestAnimationFrame(loop) : (raf = null);
        }
        function spawn(arr) {
            if (reduceMotion) return;
            parts.push(...arr);
            if (!raf) raf = requestAnimationFrame(loop);
        }
        return {
            // Explosion festive depuis le haut/centre
            confetti(power = 120) {
                const arr = [];
                for (let i = 0; i < power; i++) {
                    const ang = Math.PI * (0.25 + (i / power) * 0.5);
                    const sp = 6 + (i % 7);
                    arr.push({
                        x: c.width / 2, y: c.height * 0.32,
                        vx: Math.cos(ang) * sp * (i % 2 ? 1 : -1), vy: -Math.abs(Math.sin(ang) * sp) - 4,
                        g: 0.18, rot: i, vr: (i % 5 - 2) * 0.1,
                        s: 7 + (i % 6), ratio: 0.5 + (i % 3) * 0.4,
                        color: colors[i % colors.length], life: 90 + (i % 40), maxLife: 130,
                    });
                }
                spawn(arr);
            },
            // Étincelles depuis un point (le bouton)
            burst(x, y, power = 16) {
                const arr = [];
                for (let i = 0; i < power; i++) {
                    const ang = (i / power) * Math.PI * 2;
                    const sp = 3 + (i % 5);
                    arr.push({
                        x, y, vx: Math.cos(ang) * sp, vy: Math.sin(ang) * sp - 1,
                        g: 0.12, rot: i, vr: 0.2,
                        s: 5 + (i % 4), ratio: 1,
                        color: colors[i % colors.length], life: 40 + (i % 20), maxLife: 60,
                    });
                }
                spawn(arr);
            },
        };
    })();

    /* ---------- Toasts ---------- */
    function toast(icon, title, sub = "", variant = "") {
        const el = document.createElement("div");
        el.className = "toast" + (variant ? " toast--" + variant : "");
        el.innerHTML =
            `<span class="toast__icon">${icon}</span>` +
            `<span class="toast__body"><span class="toast__title">${title}</span>` +
            (sub ? `<span class="toast__sub">${sub}</span>` : "") + `</span>`;
        els.toasts.appendChild(el);
        setTimeout(() => {
            el.classList.add("out");
            el.addEventListener("animationend", () => el.remove(), { once: true });
        }, 3200);
    }

    /* ---------- Rendu ---------- */
    function shake(big) {
        if (reduceMotion) return;
        const cls = big ? "shake--big" : "shake";
        els.arena.classList.remove(cls); void els.arena.offsetWidth; els.arena.classList.add(cls);
    }

    function renderHUD() {
        const { level, into, need } = levelFromTotal(state.total);
        const rank = rankFor(level);
        els.level.textContent = level;
        els.xpInto.textContent = into;
        els.xpNeed.textContent = need;
        els.xpFill.style.width = Math.min(100, (into / need) * 100) + "%";
        els.rankIcon.textContent = rank.icon;
        els.rankTitle.textContent = rank.title;
        return level;
    }

    function renderMiniStats() {
        els.today.textContent = state.today;
        // cpm : clics locaux sur les 60 dernières secondes
        const now = performance.now();
        state.clickTimes = state.clickTimes.filter((t) => now - t < 60000);
        els.cpm.textContent = state.clickTimes.length;
        els.age.textContent = formatAge(state.firstAt);
    }

    function formatAge(iso) {
        if (!iso) return "neuf";
        const then = new Date(iso.replace(" ", "T") + "Z").getTime();
        if (isNaN(then)) return "—";
        const sec = Math.max(0, (Date.now() - then) / 1000);
        if (sec < 3600) return Math.floor(sec / 60) + "m";
        if (sec < 86400) return Math.floor(sec / 3600) + "h";
        return Math.floor(sec / 86400) + "j";
    }

    function buildBadges() {
        els.badges.innerHTML = "";
        for (const a of ACHIEVEMENTS) {
            const b = document.createElement("div");
            b.className = "badge";
            b.dataset.id = a.id;
            b.dataset.label = a.label;
            b.dataset.unlocked = "false";
            b.textContent = a.icon;
            els.badges.appendChild(b);
        }
    }

    function markBadge(id, justNow) {
        const b = els.badges.querySelector(`.badge[data-id="${id}"]`);
        if (!b || b.dataset.unlocked === "true") return;
        b.dataset.unlocked = "true";
        if (justNow && !reduceMotion) {
            b.classList.add("justUnlocked");
            b.addEventListener("animationend", () => b.classList.remove("justUnlocked"), { once: true });
        }
    }

    function unlock(a, celebrate) {
        if (state.unlocked.has(a.id)) return;
        state.unlocked.add(a.id);
        markBadge(a.id, celebrate);
        if (celebrate) {
            toast(a.icon, "Succès débloqué !", a.label, "gold");
            audio.arpeggio([523, 659, 784]);
            fx.confetti(70);
        }
    }

    function syncAchievements(celebrate) {
        for (const a of ACHIEVEMENTS) {
            if (a.test(state.total)) unlock(a, celebrate);
        }
    }

    /* ---------- Cœur : appliquer une nouvelle valeur ---------- */
    function applyTotal(newTotal, opts = {}) {
        const prev = state.total;
        if (newTotal === prev && !opts.force) return;
        state.total = newTotal;

        els.value.textContent = newTotal;
        if (!reduceMotion) {
            els.value.classList.remove("bump"); void els.value.offsetWidth; els.value.classList.add("bump");
            setTimeout(() => els.value.classList.remove("bump"), 200);
        }

        const prevLevel = levelFromTotal(prev).level;
        const newLevel = renderHUD();

        if (!opts.silent) {
            // Level-up
            if (newLevel > prevLevel) {
                shake(true);
                fx.confetti(120);
                audio.arpeggio([392, 523, 659, 784]);
                const rank = rankFor(newLevel);
                toast("✨", `Niveau ${newLevel} !`, `${rank.icon} ${rank.title}`, "");
            }
            // Paliers
            for (const m of MILESTONES) {
                if (prev < m && newTotal >= m) {
                    fx.confetti(160);
                    toast("🎉", `${m} clics !`, "palier atteint", "hot");
                }
            }
            // Easter eggs (uniquement sur clic perso, valeur exacte)
            if (opts.fromClick && EGGS[newTotal]) {
                const e = EGGS[newTotal];
                toast(e.icon, e.title, e.sub, "hot");
                audio.arpeggio([659, 880]);
            }
        }

        syncAchievements(!opts.silent);
        renderMiniStats();
    }

    /* ---------- Speed Demon ---------- */
    function checkSpeed() {
        if (state.unlocked.has("speed")) return;
        const now = performance.now();
        const recent = state.clickTimes.filter((t) => now - t < 5000);
        if (recent.length >= 10) {
            localStorage.setItem("ca_speed", "1");
            unlock(ACHIEVEMENTS.find((a) => a.id === "speed"), true);
        }
    }

    /* ---------- Clic ---------- */
    let pending = false;
    async function onClick() {
        audio.init();
        if (!reduceMotion) {
            els.add.classList.remove("pop"); void els.add.offsetWidth; els.add.classList.add("pop");
            const r = els.add.getBoundingClientRect();
            fx.burst(r.left + r.width / 2, r.top + r.height / 2, 16);
        }
        audio.blip(1 + (state.clickTimes.length % 6) * 0.05);
        state.clickTimes.push(performance.now());
        state.today += 1;
        renderMiniStats();
        checkSpeed();

        if (pending) return; // évite les requêtes empilées, l'UI reste réactive
        pending = true;
        try {
            const res = await fetch("/api/counter/add", { headers: { Accept: "application/json" } });
            const data = await res.json();
            applyTotal(data.value, { fromClick: true });
        } catch (_) {
            toast("⚠️", "Hors-ligne", "le clic n'a pas été enregistré", "hot");
            state.today = Math.max(0, state.today - 1);
            renderMiniStats();
        } finally {
            pending = false;
        }
    }

    /* ---------- Polling (compteur global temps réel) ---------- */
    async function poll() {
        try {
            const res = await fetch("/api/counter/stats", { headers: { Accept: "application/json" } });
            const s = await res.json();
            state.firstAt = s.first_at || state.firstAt;
            if (typeof s.today === "number") state.today = Math.max(state.today, s.today);
            if (typeof s.total === "number" && s.total > state.total) {
                applyTotal(s.total, { remote: true });
            } else {
                renderMiniStats();
            }
        } catch (_) { /* silencieux */ }
    }

    /* ---------- Mute ---------- */
    function renderMute() {
        els.mute.textContent = audio.muted ? "🔇" : "🔊";
        els.mute.setAttribute("aria-pressed", String(audio.muted));
        els.mute.setAttribute("aria-label", audio.muted ? "Activer le son" : "Couper le son");
    }

    /* ---------- Init ---------- */
    function init() {
        buildBadges();
        if (localStorage.getItem("ca_speed") === "1") {
            state.unlocked.add("speed"); markBadge("speed", false);
        }
        applyTotal(state.total, { silent: true, force: true }); // état initial sans célébration
        renderMute();

        els.add.addEventListener("click", onClick);
        els.mute.addEventListener("click", () => {
            audio.muted = !audio.muted;
            localStorage.setItem("ca_muted", audio.muted ? "1" : "0");
            renderMute();
        });
        // Espace / Entrée déjà gérés nativement par le <button>.

        poll();
        setInterval(poll, 6000);
        setInterval(renderMiniStats, 1000); // rafraîchit le cpm glissant
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
