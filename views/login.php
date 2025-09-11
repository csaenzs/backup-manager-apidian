<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Backup Manager - Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h2>🛡️ Backup Manager</h2>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST" class="login-form">
                <input type="password" name="password" placeholder="Contraseña" required autofocus>
                <button type="submit">Iniciar Sesión</button>
            </form>
            
            <p style="text-align: center; margin-top: 20px; color: #6b7280; font-size: 13px;">
                Contraseña por defecto: admin123<br>
                (Cámbiala después del primer login)
            </p>
        </div>
    </div>
</body>
</html>