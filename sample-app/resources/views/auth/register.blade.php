<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f0524">
    <title>Inscription — CLICK ARENA</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;700;900&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <main class="arena auth-card">
        <h1 class="auth-title">CLICK ARENA</h1>
        <p class="auth-sub">Crée ton compte et grimpe au classement</p>

        <form method="POST" action="/register" class="auth-form">
            @csrf
            <label class="field">
                <span class="field__lbl">Pseudo</span>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
                @error('name') <span class="field-error">{{ $message }}</span> @enderror
            </label>
            <label class="field">
                <span class="field__lbl">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
                @error('email') <span class="field-error">{{ $message }}</span> @enderror
            </label>
            <label class="field">
                <span class="field__lbl">Mot de passe</span>
                <input type="password" name="password" required autocomplete="new-password">
                @error('password') <span class="field-error">{{ $message }}</span> @enderror
            </label>
            <label class="field">
                <span class="field__lbl">Confirme le mot de passe</span>
                <input type="password" name="password_confirmation" required autocomplete="new-password">
            </label>

            <button type="submit" class="btn-primary">Créer mon compte</button>
        </form>

        <p class="auth-switch">Déjà un compte ? <a href="/login">Connecte-toi</a></p>
    </main>
</body>
</html>
