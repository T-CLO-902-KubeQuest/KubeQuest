<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f0524">
    <title>Connexion — CLICK ARENA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <main class="arena auth-card">
        <h1 class="auth-title">CLICK ARENA</h1>
        <p class="auth-sub">Connecte-toi pour entrer dans l'arène</p>

        <form method="POST" action="/login" class="auth-form">
            @csrf
            <label class="field">
                <span class="field__lbl">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            </label>
            <label class="field">
                <span class="field__lbl">Mot de passe</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <label class="check">
                <input type="checkbox" name="remember"> <span>Se souvenir de moi</span>
            </label>

            @error('email')
                <p class="form-error">⚠️ {{ $message }}</p>
            @enderror

            <button type="submit" class="btn-primary">Entrer</button>
        </form>

        <p class="auth-switch">Pas encore de compte ? <a href="/register">Crée-en un</a></p>
    </main>
</body>
</html>
