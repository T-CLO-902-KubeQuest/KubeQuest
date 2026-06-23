<!DOCTYPE html>
<html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#0f0524">
        <title>CLICK ARENA — Counter de fou</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="/css/app.css">
    </head>
    <body>
        <!-- Calque confettis plein écran -->
        <canvas id="fx" aria-hidden="true"></canvas>

        <main class="arena">
            <!-- En-tête : rang + niveau -->
            <header class="hud">
                <div class="rank">
                    <span class="rank-icon" id="rank-icon">🥚</span>
                    <span class="rank-title" id="rank-title">Novice du Clic</span>
                </div>
                <button id="mute" class="mute" type="button" aria-label="Couper le son" aria-pressed="false">🔊</button>
            </header>

            <!-- Barre d'XP -->
            <div class="xp">
                <div class="xp-head">
                    <span class="level-badge">Niv. <b id="level">0</b></span>
                    <span class="xp-text"><span id="xp-into">0</span> / <span id="xp-need">10</span> XP</span>
                </div>
                <div class="xp-track"><div class="xp-fill" id="xp-fill"></div></div>
            </div>

            <!-- Compteur -->
            <p class="counter-label">Clics totaux</p>
            <p id="value" aria-live="polite" aria-atomic="true">{{ $value }}</p>

            <!-- Bouton magique -->
            <button id="add" class="add-btn" type="button">
                <span class="add-btn__plus">+1</span>
                <span class="add-btn__glow" aria-hidden="true"></span>
            </button>

            <!-- Mini-stats -->
            <div class="ministats">
                <div class="ministat"><span class="ministat__num" id="stat-today">0</span><span class="ministat__lbl">aujourd'hui</span></div>
                <div class="ministat"><span class="ministat__num" id="stat-cpm">0</span><span class="ministat__lbl">clics/min</span></div>
                <div class="ministat"><span class="ministat__num" id="stat-age">—</span><span class="ministat__lbl">âge</span></div>
            </div>

            <!-- Vitrine d'achievements -->
            <section class="achievements" aria-label="Succès">
                <h2 class="achievements__title">Succès</h2>
                <div class="badges" id="badges"></div>
            </section>
        </main>

        <!-- Région des toasts -->
        <div class="toasts" id="toasts" aria-live="assertive" aria-atomic="false"></div>

        <script>window.__INITIAL_TOTAL__ = {{ (int) $value }};</script>
        <script src="/js/app.js" defer></script>
    </body>
</html>
