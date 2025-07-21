<?php
require_once '../api/koneksi.php';

$registerError = '';
$registerSuccess = '';
$editMode = false;
$editUser = [
    'id' => '',
    'username' => '',
    'email' => '',
];

// --- Handle Delete ---
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $id = intval($_GET['id']);

    // Fetch the username before deletion, needed for orders table
    $usernameToDelete = '';
    $stmt_get_username = $conn->prepare("SELECT username FROM users WHERE id = ?");
    if ($stmt_get_username) {
        $stmt_get_username->bind_param("i", $id);
        $stmt_get_username->execute();
        $result_username = $stmt_get_username->get_result();
        if ($row = $result_username->fetch_assoc()) {
            $usernameToDelete = $row['username'];
        }
        $stmt_get_username->close();
    }

    $conn->begin_transaction(); // Start transaction

    try {
        // Temporarily disable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0;");

        // 1. Delete associated entries from 'orders' table first (manual cascade)
        if ($usernameToDelete) {
            $stmt_delete_orders = $conn->prepare("DELETE FROM orders WHERE customer_name = ?");
            if ($stmt_delete_orders === false) {
                throw new Exception("Error preparing orders delete statement: " . $conn->error);
            }
            $stmt_delete_orders->bind_param("s", $usernameToDelete);
            if (!$stmt_delete_orders->execute()) {
                throw new Exception("Gagal menghapus pesanan terkait: " . $stmt_delete_orders->error);
            }
            $stmt_delete_orders->close();
        }

        // 2. Now, delete the user from the 'users' table
        $stmt_delete_user = $conn->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt_delete_user === false) {
            throw new Exception("Error preparing user delete statement: " . $conn->error);
        }
        $stmt_delete_user->bind_param("i", $id);
        if ($stmt_delete_user->execute()) {
            $conn->commit(); // Commit transaction
            $registerSuccess = "User berhasil dihapus!";
        } else {
            $conn->rollback(); // Rollback if user deletion fails
            $registerError = "Gagal menghapus user: " . $stmt_delete_user->error;
        }
        $stmt_delete_user->close();

    } catch (Exception $e) {
        $conn->rollback(); // Rollback on any exception
        $registerError = "Error menghapus user: " . $e->getMessage();
    } finally {
        // ALWAYS re-enable foreign key checks, even if there was an error!
        $conn->query("SET FOREIGN_KEY_CHECKS = 1;");
    }
    // Redirect after operation (only if it completes without dying)
    header("Location: " . $_SERVER['PHP_SELF'] . ($registerSuccess ? '?success=' . urlencode($registerSuccess) : ($registerError ? '?error=' . urlencode($registerError) : '')));
    exit;
}

// --- Handle Edit ---
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $editMode = true;
    $id = intval($_GET['id']);
    $stmt_edit = $conn->prepare("SELECT id, username, email FROM users WHERE id = ?");
    if ($stmt_edit === false) {
        $registerError = "Error preparing edit query: " . $conn->error;
    } else {
        $stmt_edit->bind_param("i", $id);
        $stmt_edit->execute();
        $res = $stmt_edit->get_result();
        if ($res->num_rows > 0) {
            $editUser = $res->fetch_assoc();
        } else {
            $registerError = "User tidak ditemukan untuk diedit.";
            $editMode = false; // Turn off edit mode if user not found
        }
        $stmt_edit->close();
    }
}

// --- Handle Update ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update'])) {
    $id = intval($_POST['id']);
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);

    // Fetch the current username before update, needed for orders table
    $currentUsername = '';
    $stmt_get_old_username = $conn->prepare("SELECT username FROM users WHERE id = ?");
    if ($stmt_get_old_username) {
        $stmt_get_old_username->bind_param("i", $id);
        $stmt_get_old_username->execute();
        $result_old_username = $stmt_get_old_username->get_result();
        if ($row = $result_old_username->fetch_assoc()) {
            $currentUsername = $row['username'];
        }
        $stmt_get_old_username->close();
    }

    $conn->begin_transaction(); // Start transaction

    try {
        // Check if the new username already exists for another user (excluding current user)
        $stmt_check_username = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        if ($stmt_check_username === false) {
            throw new Exception("Error preparing username check: " . $conn->error);
        }
        $stmt_check_username->bind_param("si", $username, $id);
        $stmt_check_username->execute();
        $stmt_check_username->store_result();
        if ($stmt_check_username->num_rows > 0) {
            throw new Exception("Username baru sudah digunakan oleh user lain. Pilih username lain.");
        }
        $stmt_check_username->close();

        // Temporarily disable foreign key checks
        $conn->query("SET FOREIGN_KEY_CHECKS = 0;");

        // 1. Update the 'users' table
        $stmt_update_user = $conn->prepare("UPDATE users SET username = ?, email = ?, updated_at = NOW() WHERE id = ?");
        if ($stmt_update_user === false) {
            throw new Exception("Error preparing user update: " . $conn->error);
        }
        $stmt_update_user->bind_param("ssi", $username, $email, $id);
        if (!$stmt_update_user->execute()) {
            throw new Exception("Gagal memperbarui user: " . $stmt_update_user->error);
        }
        $stmt_update_user->close();

        // 2. If username changed, update 'orders' table to match
        if ($username !== $currentUsername) {
            $stmt_update_orders = $conn->prepare("UPDATE orders SET customer_name = ? WHERE customer_name = ?");
            if ($stmt_update_orders === false) {
                throw new Exception("Error preparing orders update: " . $conn->error);
            }
            $stmt_update_orders->bind_param("ss", $username, $currentUsername);
            if (!$stmt_update_orders->execute()) {
                throw new Exception("Gagal memperbarui customer_name di pesanan: " . $stmt_update_orders->error);
            }
            $stmt_update_orders->close();
        }

        $conn->commit(); // Commit transaction
        $registerSuccess = "Data berhasil diupdate!";

    } catch (Exception $e) {
        $conn->rollback(); // Rollback on any exception
        $registerError = "Gagal update data: " . $e->getMessage();
    } finally {
        // ALWAYS re-enable foreign key checks, even if there was an error!
        $conn->query("SET FOREIGN_KEY_CHECKS = 1;");
    }
    // Redirect after operation, passing success/error messages
    header("Location: " . $_SERVER['PHP_SELF'] . ($registerSuccess ? '?success=' . urlencode($registerSuccess) : ($registerError ? '?error=' . urlencode($registerError) : '')));
    exit;
}

// --- Handle Insert (Registration) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $created_at = date("Y-m-d H:i:s");
    $updated_at = $created_at;

    if (strlen($password) < 6) {
        $registerError = "Password minimal 6 karakter!";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        if ($check === false) {
            $registerError = "Error menyiapkan cek username/email: " . $conn->error;
        } else {
            $check->bind_param("ss", $username, $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $registerError = "Username atau email sudah terdaftar!";
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, password, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
                if ($stmt === false) {
                    $registerError = "Error menyiapkan insert user: " . $conn->error;
                } else {
                    $stmt->bind_param("sssss", $username, $email, $hashedPassword, $created_at, $updated_at);
                    if ($stmt->execute()) {
                        $registerSuccess = "Registrasi berhasil!";
                    } else {
                        $registerError = "Terjadi kesalahan saat menyimpan data: " . $stmt->error;
                    }
                    $stmt->close();
                }
            }
            $check->close();
        }
    }
    // Redirect after operation
    header("Location: " . $_SERVER['PHP_SELF'] . ($registerSuccess ? '?success=' . urlencode($registerSuccess) : ($registerError ? '?error=' . urlencode($registerError) : '')));
    exit;
}

// --- Fetch all users for display ---
$users = [];
$result = $conn->query("SELECT id, username, email, created_at FROM users ORDER BY id DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Handle messages from redirection
if (isset($_GET['success'])) {
    $registerSuccess = htmlspecialchars($_GET['success']);
}
if (isset($_GET['error'])) {
    $registerError = htmlspecialchars($_GET['error']);
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>CRUD User</title>
    <style>
        table, th, td {
            border: 1px solid #333;
            border-collapse: collapse;
            padding: 8px;
        }
        th { background-color: #eee; }
        .btn {
            text-decoration: none;
            padding: 4px 8px;
            margin-right: 4px;
            color: white;
            border-radius: 3px;
        }
        .edit { background-color: #4CAF50; }
        .delete { background-color: #f44336; }
        .message-success { color: green; }
        .message-error { color: red; }
    </style>
</head>
<body>
    <h2><?= $editMode ? 'Edit User' : 'Form Registrasi' ?></h2>

    <?php if ($registerError): ?>
        <p class="message-error"><?= $registerError; ?></p>
    <?php endif; ?>

    <?php if ($registerSuccess): ?>
        <p class="message-success"><?= $registerSuccess; ?></p>
    <?php endif; ?>

    <form method="post" action="">
        <?php if ($editMode): ?>
            <input type="hidden" name="id" value="<?= $editUser['id']; ?>">
        <?php endif; ?>
        <label>Username:</label><br>
        <input type="text" name="username" value="<?= htmlspecialchars($editUser['username']); ?>" required><br><br>

        <label>Email:</label><br>
        <input type="email" name="email" value="<?= htmlspecialchars($editUser['email']); ?>" required><br><br>

        <?php if (!$editMode): ?>
            <label>Password:</label><br>
            <input type="password" name="password" required><br><br>
        <?php endif; ?>

        <input type="submit" name="<?= $editMode ? 'update' : 'register'; ?>" value="<?= $editMode ? 'Update' : 'Daftar'; ?>">
        <?php if ($editMode): ?>
            <a href="<?= $_SERVER['PHP_SELF']; ?>">Batal</a>
        <?php endif; ?>
    </form>

    <h3>Daftar User</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Email</th>
                <th>Tanggal Daftar</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr><td colspan="5">Belum ada user.</td></tr>
            <?php else: ?>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= $user['id']; ?></td>
                        <td><?= htmlspecialchars($user['username']); ?></td>
                        <td><?= htmlspecialchars($user['email']); ?></td>
                        <td><?= $user['created_at']; ?></td>
                        <td>
                            <a class="btn edit" href="?action=edit&id=<?= $user['id']; ?>">Edit</a>
                            <a class="btn delete" href="?action=delete&id=<?= $user['id']; ?>" onclick="return confirm('Yakin ingin menghapus user ini? Ini juga akan menghapus semua pesanan terkait!')">Hapus</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <p><a href="../index.php">Kembali</a></p>
</body>
</html>