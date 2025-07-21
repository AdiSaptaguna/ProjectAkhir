<?php
session_start();
require_once '../api/koneksi.php'; // Pastikan path ke koneksi.php sudah benar

// Cek apakah user sudah login
if (!isset($_SESSION['user_id'])) {
    header("Location: login-view.php"); // Redirect ke halaman login Anda
    exit;
}

$userId = $_SESSION['user_id'];
$message = '';
$user = null; // Inisialisasi variabel user untuk tampilan HTML

// --- Dapatkan Username Saat Ini ---
// Ini penting untuk membandingkan username lama dengan yang baru
$currentUsername = '';
$stmt_get_username = $conn->prepare("SELECT username FROM users WHERE id = ?");
if ($stmt_get_username === false) {
    $message = "Error database: Gagal menyiapkan query username saat ini.";
} else {
    $stmt_get_username->bind_param("i", $userId);
    $stmt_get_username->execute();
    $result_username = $stmt_get_username->get_result();
    if ($row_username = $result_username->fetch_assoc()) {
        $currentUsername = $row_username['username'];
    }
    $stmt_get_username->close();

    if (!$currentUsername) { // Jika username tidak ditemukan, mungkin akun sudah terhapus
        session_destroy();
        header("Location: login-view.php?error=user_not_found");
        exit;
    }
}


// --- DELETE (Menghapus Akun) ---
if (isset($_POST['delete'])) {
    // Mulai transaksi untuk penghapusan atomik
    $conn->begin_transaction();

    try {
        // Matikan foreign key checks sementara untuk memungkinkan penghapusan
        // Ini berisiko tapi diperlukan jika ada RESTRICT dan kamu tidak ingin mengubah DB schema
        $conn->query("SET FOREIGN_KEY_CHECKS = 0;");

        // 1. Hapus entri terkait di tabel 'orders' terlebih dahulu (opsional jika foreign key checks dimatikan,
        // tapi baik untuk menjaga kejelasan dan jika foreign key checks diaktifkan lagi di masa depan)
        $stmt_delete_orders = $conn->prepare("DELETE FROM orders WHERE customer_name = ?");
        if ($stmt_delete_orders === false) {
            throw new Exception("Error menyiapkan penghapusan pesanan: " . $conn->error);
        }
        $stmt_delete_orders->bind_param("s", $currentUsername);
        if (!$stmt_delete_orders->execute()) {
            throw new Exception("Gagal menghapus pesanan terkait: " . $stmt_delete_orders->error);
        }
        $stmt_delete_orders->close();

        // 2. Sekarang, hapus user dari tabel 'users'
        $stmt_delete_user = $conn->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt_delete_user === false) {
            throw new Exception("Error menyiapkan penghapusan user: " . $conn->error);
        }
        $stmt_delete_user->bind_param("i", $userId);
        if ($stmt_delete_user->execute()) {
            $conn->commit(); // Commit transaksi jika semua operasi berhasil
            session_destroy(); // Hancurkan sesi setelah akun dihapus
            header("Location: login-view.php?deleted=1"); // Redirect ke halaman login
            exit;
        } else {
            $conn->rollback(); // Jika penghapusan user gagal, rollback semuanya
            throw new Exception("Gagal menghapus akun pengguna: " . $stmt_delete_user->error);
        }
        $stmt_delete_user->close();

    } catch (Exception $e) {
        $conn->rollback(); // Tangani exception dan rollback transaksi
        $message = "Error menghapus akun: " . $e->getMessage();
    } finally {
        // SELALU aktifkan kembali foreign key checks, bahkan jika ada error!
        $conn->query("SET FOREIGN_KEY_CHECKS = 1;");
    }
}


// --- UPDATE (Memperbarui Profil) ---
if (isset($_POST['update'])) {
    $newUsername = trim($_POST['username']);
    $newEmail = trim($_POST['email']);

    if (!empty($newUsername) && !empty($newEmail)) {
        // Mulai transaksi untuk update atomik
        $conn->begin_transaction();

        try {
            // Cek apakah username baru berbeda dari yang lama
            if ($newUsername !== $currentUsername) {
                // 1. Cek apakah username baru sudah digunakan oleh user lain
                $stmt_check_new_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                if ($stmt_check_new_username === false) {
                    throw new Exception("Error menyiapkan cek ketersediaan username: " . $conn->error);
                }
                $stmt_check_new_username->bind_param("si", $newUsername, $userId);
                $stmt_check_new_username->execute();
                $stmt_check_new_username->store_result();
                if ($stmt_check_new_username->num_rows > 0) {
                    throw new Exception("Username baru sudah digunakan oleh user lain. Pilih username lain.");
                }
                $stmt_check_new_username->close();

                // Matikan foreign key checks sementara untuk memungkinkan perubahan
                // Ini adalah kunci untuk melewati ON UPDATE RESTRICT tanpa mengubah DB schema
                $conn->query("SET FOREIGN_KEY_CHECKS = 0;");

                // 2. Lakukan update username dan email di tabel users.
                // Ini akan berhasil karena foreign key checks sementara dimatikan.
                $stmt_update_user = $conn->prepare("UPDATE users SET username = ?, email = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt_update_user === false) {
                    throw new Exception("Error menyiapkan update profil user: " . $conn->error);
                }
                $stmt_update_user->bind_param("ssi", $newUsername, $newEmail, $userId);
                if (!$stmt_update_user->execute()) {
                    throw new Exception("Gagal memperbarui username dan email di tabel users: " . $stmt_update_user->error);
                }
                $stmt_update_user->close();

                // 3. Sekarang, update customer_name di tabel 'orders'
                // Ini penting agar konsisten dengan username yang baru
                $stmt_update_orders = $conn->prepare("UPDATE orders SET customer_name = ? WHERE customer_name = ?");
                if ($stmt_update_orders === false) {
                    throw new Exception("Error menyiapkan update pesanan: " . $conn->error);
                }
                $stmt_update_orders->bind_param("ss", $newUsername, $currentUsername);
                if (!$stmt_update_orders->execute()) {
                    throw new Exception("Gagal memperbarui username di tabel pesanan: " . $stmt_update_orders->error);
                }
                $stmt_update_orders->close();

                // Aktifkan kembali foreign key checks
                $conn->query("SET FOREIGN_KEY_CHECKS = 1;");


                // Update session username dan currentUsername variabel
                $_SESSION['username'] = $newUsername;
                $currentUsername = $newUsername; // Penting agar state PHP konsisten setelah update

            } else {
                // Jika username TIDAK berubah, hanya update email di tabel users
                $stmt_update_email = $conn->prepare("UPDATE users SET email = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt_update_email === false) {
                    throw new Exception("Error menyiapkan update email: " . $conn->error);
                }
                $stmt_update_email->bind_param("si", $newEmail, $userId);
                if (!$stmt_update_email->execute()) {
                    throw new Exception("Gagal memperbarui email: " . $stmt_update_email->error);
                }
                $stmt_update_email->close();
            }

            $conn->commit(); // Commit transaksi jika semua operasi berhasil
            $message = "Profil berhasil diperbarui.";

        } catch (Exception $e) {
            $conn->rollback(); // Tangani exception dan rollback transaksi
            $message = "Error memperbarui profil: " . $e->getMessage();
        } finally {
            // PASTIKAN foreign key checks selalu diaktifkan kembali,
            // bahkan jika ada error saat update (kecuali jika sudah aktifkan di dalam try)
            if ($newUsername !== $currentUsername) { // Hanya jika percobaan update username
                $conn->query("SET FOREIGN_KEY_CHECKS = 1;");
            }
        }
    } else {
        $message = "Username dan Email tidak boleh kosong.";
    }
}

// --- READ (Membaca Data Profil) ---
// Bagian ini selalu dijalankan untuk menampilkan data terbaru di form
// Ini penting setelah operasi UPDATE/DELETE untuk memastikan data terbaru ditampilkan
$stmt_re_read_user = $conn->prepare("SELECT username, email, created_at, updated_at FROM users WHERE id = ?");
if ($stmt_re_read_user === false) {
    $message .= " Database error: Gagal membaca ulang data profil.";
} else {
    $stmt_re_read_user->bind_param("i", $userId);
    $stmt_re_read_user->execute();
    $result_re_read = $stmt_re_read_user->get_result();
    $user = $result_re_read->fetch_assoc();
    $stmt_re_read_user->close();
}

// Tutup koneksi database setelah semua operasi selesai
$conn->close();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Profil Pengguna</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background: #f5efe6;
            color: #3e2c23;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 500px;
            margin: 40px auto;
            background: #fff8f0;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(90, 60, 30, 0.1);
        }
        h2 {
            text-align: center;
            color: #5c4033;
            margin-bottom: 25px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        input[type="text"], input[type="email"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 18px;
            border: 1px solid #c5a880;
            border-radius: 6px;
            background-color: #fffdfb;
        }
        input[type="submit"] {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            background-color: #8d6e63;
            color: white;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
            transition: background-color 0.3s ease;
        }
        input[type="submit"]:hover {
            background-color: #6d4c41;
        }
        .danger {
            background-color: #d32f2f;
            margin-top: 20px;
        }
        .danger:hover {
            background-color: #b71c1c;
        }
        p {
            margin: 10px 0;
        }
        .info {
            background-color: #dce3dc;
            padding: 10px;
            border-left: 4px solid #5c4033;
            margin-bottom: 20px;
            border-radius: 6px;
            color: #3e2c23;
        }
        .info.error {
            background-color: #ffe0b2;
            border-color: #e65100;
            color: #d32f2f;
        }
        a {
            color: #5c4033;
            text-decoration: none;
            margin-right: 10px;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Profil Anda</h2>

        <?php if ($message): ?>
            <div class="info <?php echo (strpos($message, 'Gagal') !== false || strpos($message, 'Error') !== false || strpos($message, 'Tidak dapat') !== false || strpos($message, 'sudah digunakan') !== false || strpos($message, 'tidak ditemukan') !== false) ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($user): ?>
            <form method="post" action="">
                <label>Username:</label>
                <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
                <label>Email:</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>

                <p><strong>Dibuat:</strong> <?php echo $user['created_at']; ?></p>
                <p><strong>Diperbarui:</strong> <?php echo $user['updated_at'] ?? 'Belum pernah diperbarui'; ?></p>

                <input type="submit" name="update" value="Perbarui Profil">
            </form>

            <form method="post" onsubmit="return confirm('Apakah Anda yakin ingin menghapus akun ini? Semua data terkait (termasuk pesanan) akan hilang secara permanen!')">
                <input type="submit" name="delete" value="Hapus Akun" class="danger">
            </form>
        <?php else: ?>
            <div class="info error">Data profil tidak ditemukan atau terjadi kesalahan saat memuat.</div>
        <?php endif; ?>

        <p>
            <a href="../index.php">🏠 Dashboard</a>
            <a href="logout.php">🚪 Logout</a>
        </p>
    </div>
</body>
</html>