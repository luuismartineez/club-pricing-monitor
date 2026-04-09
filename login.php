<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (is_admin_logged_in()) {
    header('Location: clubs_manage.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = post_param('username');
    $password = post_param('password');

    if ($username === '' || $password === '') {
        $error = 'Introduce usuario y contraseña.';
    } elseif (admin_login($username, $password)) {
        header('Location: clubs_manage.php');
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso admin</title>
    <style>
        :root{
            --bg:#0d120f;
            --bg-2:#121813;
            --panel:#efe7d8;
            --panel-2:#f7f3ea;
            --ink:#182019;
            --ink-soft:#5d655c;
            --gold:#b9985a;
            --gold-2:#d9bf86;
            --line:#d9cfbe;
            --danger-bg:#f3d7d7;
            --danger:#7d2323;
        }

        *{
            box-sizing:border-box;
            margin:0;
            padding:0;
        }

        body{
            min-height:100vh;
            font-family: Inter, Segoe UI, Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(185,152,90,.10), transparent 22%),
                radial-gradient(circle at bottom right, rgba(185,152,90,.08), transparent 20%),
                linear-gradient(180deg, var(--bg) 0%, var(--bg-2) 100%);
            color:#fff;
        }

        .login-wrap{
            min-height:100vh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:32px 16px;
        }

        .login-box{
            width:100%;
            max-width:640px;
            text-align:center;
        }

        .login-kicker{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            padding:8px 14px;
            border-radius:999px;
            background:rgba(255,255,255,.04);
            border:1px solid rgba(255,255,255,.08);
            color:#e8decb;
            font-size:12px;
            font-weight:800;
            letter-spacing:.5px;
            text-transform:uppercase;
            margin-bottom:18px;
        }

        .login-title{
            font-size:clamp(44px, 8vw, 72px);
            line-height:1;
            font-weight:900;
            letter-spacing:-2px;
            color:var(--panel-2);
            text-shadow:
                0 0 10px rgba(185,152,90,.16),
                0 0 24px rgba(185,152,90,.10);
            margin-bottom:14px;
        }

        .login-subtitle{
            max-width:620px;
            margin:0 auto 30px;
            color:#d8d1c5;
            font-size:20px;
            line-height:1.45;
        }

        .login-form{
            width:100%;
            max-width:560px;
            margin:0 auto;
            display:flex;
            flex-direction:column;
            gap:18px;
        }

        .login-input{
            width:100%;
            height:82px;
            border:none;
            outline:none;
            border-radius:28px;
            background:linear-gradient(180deg, var(--panel-2), var(--panel));
            color:var(--ink);
            font-size:22px;
            font-weight:600;
            padding:0 28px;
            box-shadow:
                0 12px 28px rgba(0,0,0,.20),
                inset 0 1px 0 rgba(255,255,255,.55);
            transition:.25s ease;
        }

        .login-input::placeholder{
            color:#667060;
            font-weight:500;
        }

        .login-input:focus{
            transform:translateY(-1px);
            box-shadow:
                0 16px 34px rgba(0,0,0,.24),
                0 0 0 4px rgba(185,152,90,.12),
                inset 0 1px 0 rgba(255,255,255,.55);
        }

        .login-btn{
            width:100%;
            height:84px;
            border:none;
            border-radius:28px;
            cursor:pointer;
            font-size:24px;
            font-weight:900;
            letter-spacing:.2px;
            color:#111512;
            background:linear-gradient(135deg, var(--gold) 0%, var(--gold-2) 100%);
            box-shadow:
                0 16px 34px rgba(185,152,90,.22),
                0 0 20px rgba(185,152,90,.18);
            transition:.25s ease;
        }

        .login-btn:hover{
            transform:translateY(-2px);
            box-shadow:
                0 20px 42px rgba(185,152,90,.28),
                0 0 24px rgba(185,152,90,.20);
        }

        .login-btn:active{
            transform:translateY(0);
        }

        .login-error{
            width:100%;
            max-width:560px;
            margin:0 auto 18px;
            padding:14px 16px;
            border-radius:18px;
            background:var(--danger-bg);
            color:var(--danger);
            font-weight:800;
            text-align:center;
        }

        @media (max-width: 640px){
            .login-title{
                font-size:52px;
            }

            .login-subtitle{
                font-size:17px;
                margin-bottom:24px;
            }

            .login-form{
                max-width:100%;
            }

            .login-input{
                height:72px;
                font-size:20px;
                border-radius:24px;
                padding:0 22px;
            }

            .login-btn{
                height:74px;
                font-size:21px;
                border-radius:24px;
            }
			
			.login-back-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:100%;
    max-width:340px;
    height:58px;
    margin:4px auto 0;
    border-radius:22px;
    background:rgba(255,255,255,.04);
    border:1px solid rgba(255,255,255,.10);
    color:#e8decb;
    font-size:17px;
    font-weight:800;
    transition:.25s ease;
}

.login-back-btn:hover{
    background:rgba(255,255,255,.08);
    transform:translateY(-1px);
}
			
        }
    </style>
</head>
<body>
    <main class="login-wrap">
        <section class="login-box">
            <div class="login-kicker">Acceso privado</div>
            <h1 class="login-title">¡Hola!</h1>
            <p class="login-subtitle">Inicia sesión para acceder al panel de administración.</p>

            <?php if ($error !== ''): ?>
                <div class="login-error"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" class="login-form">
                <input
                    class="login-input"
                    type="text"
                    name="username"
                    placeholder="Usuario"
                    autocomplete="username"
                    required
                >

                <input
                    class="login-input"
                    type="password"
                    name="password"
                    placeholder="Contraseña"
                    autocomplete="current-password"
                    required
                >

                <button class="login-btn" type="submit">Continuar</button>
				
				<a class="login-back-btn" href="index.php">Volver atrás</a>
            </form>
        </section>
    </main>
</body>
</html>