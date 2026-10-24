<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}
$dbPath = $dataDir . '/database.sqlite';

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT,
        password_hash TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $colsStmt = $db->query("PRAGMA table_info(users)");
    $cols = [];
    while ($c = $colsStmt->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $c['name'];
    }
    if (!in_array('email', $cols)) {
        $db->exec("ALTER TABLE users ADD COLUMN email TEXT");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS user_watchlist (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        coin_name TEXT NOT NULL,
        target_price REAL,
        alert_direction TEXT,
        UNIQUE(user_id, coin_name)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS user_notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        coin_name TEXT,
        message TEXT NOT NULL,
        is_read INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    die("Błąd bazy danych: " . $e->getMessage());
}

$error = '';
$success = '';
$isRegisterMode = isset($_GET['mode']) && $_GET['mode'] === 'register';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $isRegisterMode = true; 
        $regName = trim($_POST['reg_name'] ?? '');
        $regEmail = trim($_POST['reg_email'] ?? '');
        $regPassword = $_POST['reg_password'] ?? '';

        if (empty($regName) || empty($regEmail) || empty($regPassword)) {
            $error = "Wypełnij wszystkie pola (Imię, E-mail, Hasło).";
        } elseif (!filter_var($regEmail, FILTER_VALIDATE_EMAIL)) {
            $error = "Podano niepoprawny adres e-mail.";
        } else {
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :username OR email = :email");
            $stmt->execute([':username' => $regName, ':email' => $regEmail]);
            if ($stmt->fetch()) {
                $error = "Użytkownik o takim imieniu lub adresie e-mail już istnieje.";
            } else {
                $hash = password_hash($regPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :hash)");
                $stmt->execute([':username' => $regName, ':email' => $regEmail, ':hash' => $hash]);
                $success = "Konto założone! Możesz się teraz zalogować.";
                $isRegisterMode = false; 
            }
        }
    } elseif ($action === 'login') {
        $isRegisterMode = false;
        $loginData = trim($_POST['login_data'] ?? '');
        $loginPassword = $_POST['login_password'] ?? '';

        if (empty($loginData) || empty($loginPassword)) {
            $error = "Wypełnij dane logowania i hasło.";
        } else {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :login OR email = :login");
            $stmt->execute([':login' => $loginData]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($loginPassword, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                header('Location: index.php');
                exit;
            } else {
                $error = "Nieprawidłowe imię (e-mail) lub hasło.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isRegisterMode ? 'Rejestracja' : 'Logowanie' ?> – Numizmatyka NBP</title>
    <style>
        :root {
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --primary: #0284c7;
            --primary-hover: #0369a1;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .login-box {
            background: var(--card-bg);
            padding: 30px 40px;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 320px;
            border: 1px solid var(--border-color);
        }
        h2 { text-align: center; margin-top: 0; margin-bottom: 20px; font-size: 22px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 5px; color: #475569; }
        input[type="text"], input[type="password"], input[type="email"] {
            width: 100%; padding: 10px; border: 1px solid #cbd5e1;
            border-radius: 6px; box-sizing: border-box; font-size: 14px;
            outline: none; transition: border-color 0.2s;
        }
        input:focus { border-color: var(--primary); }
        .btn {
            width: 100%; padding: 10px; background-color: var(--primary);
            color: #fff; border: none; border-radius: 6px; font-weight: 600;
            cursor: pointer; font-size: 14px; margin-top: 10px; transition: background-color 0.2s;
        }
        .btn:hover { background-color: var(--primary-hover); }
        .msg { padding: 10px; border-radius: 6px; font-size: 13px; text-align: center; margin-bottom: 15px; }
        .msg.error { background: #fee2e2; color: #991b1b; }
        .msg.success { background: #dcfce7; color: #166534; }
        
        .switch-link {
            text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 20px;
        }
        .switch-link a {
            color: var(--primary); text-decoration: none; font-weight: 600;
        }
        .switch-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="login-box">
    <h2><?= $isRegisterMode ? 'Utwórz konto' : 'Numizmatyka NBP' ?></h2>
    
    <?php if ($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="msg success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <!-- ФОРМА ВХОДА -->
    <form method="POST" action="login.php" style="<?= $isRegisterMode ? 'display: none;' : '' ?>">
        <div class="form-group">
            <label>Imię lub E-mail</label>
            <input type="text" name="login_data" required <?= !$isRegisterMode ? 'autofocus' : '' ?>>
        </div>
        <div class="form-group">
            <label>Hasło</label>
            <input type="password" name="login_password" required>
        </div>
        <button type="submit" name="action" value="login" class="btn">Zaloguj się</button>
        
        <div class="switch-link">
            Nie masz konta? <a href="login.php?mode=register">Zarejestruj się</a>
        </div>
    </form>

    <form method="POST" action="login.php?mode=register" style="<?= $isRegisterMode ? 'display: block;' : 'display: none;' ?>">
        <div class="form-group">
            <label>Imię</label>
            <input type="text" name="reg_name" required <?= $isRegisterMode ? 'autofocus' : '' ?>>
        </div>
        <div class="form-group">
            <label>E-mail</label>
            <input type="email" name="reg_email" required>
        </div>
        <div class="form-group">
            <label>Hasło</label>
            <input type="password" name="reg_password" required>
        </div>
        <button type="submit" name="action" value="register" class="btn">Zarejestruj konto</button>
        
        <div class="switch-link">
            Masz już konto? <a href="login.php">Zaloguj się</a>
        </div>
    </form>
</div>

</body>
</html>