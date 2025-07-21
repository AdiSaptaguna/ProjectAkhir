<?php
session_start();
require_once '../api/koneksi.php';

$loginError = '';

if (isset($_SESSION['user_id'])) {
    $isLoggedIn = true;
} else {
    $isLoggedIn = false;

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username && $password) {
            $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();

                if (password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    header("Location: ../index.php");
                    exit;
                } else {
                    $loginError = "Password salah!";
                }
            } else {
                $loginError = "Username tidak ditemukan!";
            }

            $stmt->close();
        } else {
            $loginError = "Semua field wajib diisi!";
        }
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Login User</title>
    <style>
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f2e6d9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .login-container {
            background: #fff7f0;
            border: 1px solid #d6bfa4;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(100, 80, 50, 0.2);
            width: 100%;
            max-width: 400px;
        }

        .login-container h2 {
            color: #5a3e2b;
            text-align: center;
            margin-bottom: 20px;
        }

        label {
            color: #5a3e2b;
            font-weight: 600;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-top: 6px;
            margin-bottom: 15px;
            border: 1px solid #b79b83;
            border-radius: 6px;
            background-color: #f8f2ec;
        }

        input[type="submit"] {
            background-color: #8b5e3c;
            color: white;
            border: none;
            padding: 12px 20px;
            width: 100%;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        input[type="submit"]:hover {
            background-color: #6e4a2f;
        }

        .message {
            text-align: center;
            color: red;
            margin-bottom: 15px;
        }

        .success-message {
            color: green;
            text-align: center;
            margin-bottom: 15px;
        }

        a {
            display: block;
            text-align: center;
            color: #5a3e2b;
            margin-top: 15px;
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .brand {
            text-align: center;
            font-weight: bold;
            margin-bottom: 10px;
            font-size: 18px;
            color: #8b5e3c;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="brand">☕ CoffeeHouse Login</div>

    <?php if ($isLoggedIn): ?>
        <p class="success-message">
            Anda sudah login sebagai <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>.
        </p>
        <a href="../index.php">Kembali ke Beranda</a>
    <?php else: ?>
        <h2>Masuk ke Akun</h2>

        <?php if (!empty($loginError)): ?>
            <p class="message"><?php echo htmlspecialchars($loginError); ?></p>
        <?php endif; ?>

        <form method="post" action="">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>

            <input type="submit" value="Login">
        </form>
        <a href="../index.php">⮌ Kembali ke Beranda</a>
    <?php endif; ?>
</div>

</body>
</html>
