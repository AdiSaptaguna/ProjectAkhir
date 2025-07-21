<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Kopi</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f3efea;
            font-family: 'Segoe UI', sans-serif;
            color: #4e342e;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .dashboard-container {
            background-color: #fff;
            padding: 40px 60px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        h1 {
            color: #6d4c41;
            margin-bottom: 30px;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .nav-links a {
            background-color: #8d6e63;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.3s ease;
        }

        .nav-links a:hover {
            background-color: #6d4c41;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <h1>☕ Dashboard Utama</h1>
        <div class="nav-links">
            <a href="views/profile.php">Profile</a>
            <a href="views/login-view.php">Login User</a>
            <a href="views/register-view.php">Registrasi</a>
            <a href="views/product-view.php">Product</a>
            <a href="views/orders-view.php">Order</a>
        </div>
    </div>
</body>
</html>
