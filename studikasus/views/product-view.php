<?php
session_start(); // ⬅️ WAJIB untuk session

include '../api/koneksi.php';

// 🚫 Cek apakah user sudah login
if (!isset($_SESSION['username'])) {
    header("Location: login-view.php"); // ⬅️ Ganti dengan path login kamu
    exit();
}

$editData = null;

// Daftar kategori valid (sesuai ENUM)
$validCategories = [
    'Arabica', 'Robusta', 'Liberica', 'Excelsa',
    'Blend', 'Decaf', 'Specialty', 'Single Origin',
    'House Blend', 'Mocha', 'Espresso', 'Americano',
    'Latte', 'Cappuccino', 'Macchiato', 'Flat White',
    'Affogato', 'Nitro Coffee', 'Cold Brew'
];

// HANDLE CREATE
if (isset($_POST['create'])) {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category = in_array($_POST['category'], $validCategories) ? $_POST['category'] : null;

    $imageName = '';
    if (!empty($_FILES['image']['name'])) {
        $imageName = time() . '_' . basename($_FILES['image']['name']);
        move_uploaded_file($_FILES['image']['tmp_name'], "../img/$imageName");
    }

    $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock, category, image) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssdiss", $name, $description, $price, $stock, $category, $imageName);
    $stmt->execute();
    header("Location: " . $_SERVER['PHP_SELF']);
}

// HANDLE UPDATE
if (isset($_POST['update'])) {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category = in_array($_POST['category'], $validCategories) ? $_POST['category'] : null;
    $imageName = $_POST['old_image'];

    if (!empty($_FILES['image']['name'])) {
        $imageName = time() . '_' . basename($_FILES['image']['name']);
        move_uploaded_file($_FILES['image']['tmp_name'], "../img/$imageName");
    }

    $stmt = $conn->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, category=?, image=? WHERE id=?");
    $stmt->bind_param("ssdissi", $name, $description, $price, $stock, $category, $imageName, $id);
    $stmt->execute();
    header("Location: " . $_SERVER['PHP_SELF']);
}

// HANDLE DELETE
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $conn->query("DELETE FROM products WHERE id=$id");
    header("Location: " . $_SERVER['PHP_SELF']);
}

// HANDLE EDIT FORM
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $result = $conn->query("SELECT * FROM products WHERE id=$id");
    $editData = $result->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Manajemen Produk - CoffeeHouse</title>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f7f0e8;
            color: #4b2e2e;
        }

        h2 {
            color: #5a3e2b;
        }

        .container {
            max-width: 960px;
            margin: 0 auto;
        }

        .navbar {
            background-color: #8b5e3c;
            padding: 15px 20px;
            border-radius: 8px;
            color: #fff;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar a {
            color: #ffeeda;
            text-decoration: none;
            font-weight: bold;
        }

        form {
            background: #fff;
            border: 1px solid #d6bfa4;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 4px 10px rgba(100, 80, 50, 0.1);
        }

        input[type="text"],
        input[type="number"],
        textarea,
        select {
            width: 100%;
            padding: 10px;
            margin: 10px 0 15px 0;
            border: 1px solid #bcae9e;
            border-radius: 6px;
            background-color: #fffaf5;
        }

        input[type="file"] {
            margin-bottom: 10px;
        }

        button {
            background-color: #6e4a2f;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 6px;
            cursor: pointer;
        }

        button:hover {
            background-color: #5b3c23;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fffdfb;
            box-shadow: 0 4px 8px rgba(100, 80, 50, 0.1);
        }

        th, td {
            padding: 12px 15px;
            border: 1px solid #d1bfa3;
            text-align: left;
        }

        th {
            background-color: #d8c4a0;
            color: #3b2b1c;
        }

        td img {
            border-radius: 6px;
        }

        .action-links a {
            margin-right: 10px;
            text-decoration: none;
            color: #5e2c04;
            font-weight: bold;
        }

        .action-links a:hover {
            text-decoration: underline;
        }

        .back-link {
            display: inline-block;
            margin-top: 20px;
            text-decoration: none;
            color: #6a4225;
        }

        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <span>☕ Halo, <?= htmlspecialchars($_SESSION['username']) ?></span>
        <span><a href="../logout.php">Logout</a></span>
    </div>

    <h2><?= $editData ? '✏️ Edit Produk' : '➕ Tambah Produk' ?></h2>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
        <input type="hidden" name="old_image" value="<?= $editData['image'] ?? '' ?>">

        <label>Nama Produk:</label>
        <input type="text" name="name" value="<?= $editData['name'] ?? '' ?>" required>

        <label>Deskripsi:</label>
        <textarea name="description"><?= $editData['description'] ?? '' ?></textarea>

        <label>Harga (Rp):</label>
        <input type="number" step="0.01" name="price" value="<?= $editData['price'] ?? '' ?>" required>

        <label>Stok:</label>
        <input type="number" name="stock" value="<?= $editData['stock'] ?? '' ?>" required>

        <label>Kategori:</label>
        <select name="category" required>
            <option value="">-- Pilih Kategori --</option>
            <?php foreach ($validCategories as $cat): ?>
                <option value="<?= $cat ?>" <?= (isset($editData['category']) && $editData['category'] === $cat) ? 'selected' : '' ?>>
                    <?= $cat ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Gambar Produk:</label>
        <input type="file" name="image">
        <?php if (!empty($editData['image'])): ?>
            <br><img src="../img/<?= $editData['image'] ?>" width="80"><br>
        <?php endif; ?>

        <br>
        <button type="submit" name="<?= $editData ? 'update' : 'create' ?>">
            <?= $editData ? '💾 Update' : '➕ Tambah' ?>
        </button>
    </form>

    <h2>📦 Daftar Produk</h2>
    <table>
        <tr>
            <th>Nama</th><th>Deskripsi</th><th>Harga</th><th>Stok</th>
            <th>Kategori</th><th>Gambar</th><th>Aksi</th>
        </tr>
        <?php
        $result = $conn->query("SELECT * FROM products ORDER BY id DESC");
        while ($row = $result->fetch_assoc()):
        ?>
        <tr>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['description']) ?></td>
            <td>Rp <?= number_format($row['price'], 0, ',', '.') ?></td>
            <td><?= $row['stock'] ?></td>
            <td><?= $row['category'] ?></td>
            <td>
                <?php if ($row['image']): ?>
                    <img src="../img/<?= $row['image'] ?>" width="50">
                <?php else: ?>
                    <em>-</em>
                <?php endif; ?>
            </td>
            <td class="action-links">
                <a href="?edit=<?= $row['id'] ?>">✏️ Edit</a>
                <a href="?delete=<?= $row['id'] ?>" onclick="return confirm('Yakin ingin menghapus produk ini?')">🗑️ Hapus</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>

    <a href="../index.php" class="back-link">⮌ Kembali ke Beranda</a>
</div>
</body>
</html>
