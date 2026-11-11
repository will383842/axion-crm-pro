<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Lien de connexion</title>
</head>
<body style="font-family: Manrope, Arial, sans-serif; background: #FAF9F5; padding: 32px;">
  <div style="max-width: 560px; margin: 0 auto; background: #FFFFFF; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
    <h1 style="font-size: 20px; color: #141413;">Connexion à {{ config('app.name') }}</h1>
    <p style="color: #5E5D59; line-height: 1.6;">
      Cliquez sur le bouton ci-dessous pour vous connecter. Lien à usage unique, valide
      <strong>{{ $ttl }} minutes</strong>.
    </p>
    <p style="text-align: center; margin: 24px 0;">
      <a href="{{ $link }}" style="display: inline-block; background: #B23F16; color: #FFFFFF; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 600;">
        Me connecter
      </a>
    </p>
    <p style="color: #5E5D59; font-size: 12px;">
      Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.
    </p>
  </div>
</body>
</html>
