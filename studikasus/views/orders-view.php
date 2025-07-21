<?php
session_start();
include '../api/koneksi.php'; // Pastikan path ke file koneksi.php sudah benar

// Initialize feedback message variables
$feedback_message = '';
$feedback_type = ''; // 'success' or 'error'

// Cek login
if (!isset($_SESSION['username'])) {
    // Redirect to login page instead of just echoing an error
    header("Location: ../login.php");
    exit;
}

$username = $_SESSION['username'];
$email = '';

// Ambil email user
$stmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
if ($stmt) {
    $stmt->bind_param("s", $username);
    if ($stmt->execute()) {
        $stmt->bind_result($email);
        $stmt->fetch();
        $stmt->close();
    } else {
        $feedback_message = "Error fetching user email: " . $stmt->error;
        $feedback_type = 'error';
        error_log("Error fetching user email: " . $stmt->error); // Log error for debugging
    }
} else {
    $feedback_message = "Error preparing statement to get user email: " . $conn->error;
    $feedback_type = 'error';
    error_log("Error preparing statement to get user email: " . $conn->error);
}

// Ambil daftar produk
$products = [];
$result = $conn->query("SELECT id, name, description, price, stock FROM products ORDER BY name ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
} else {
    $feedback_message = "Error mengambil produk dari database: " . $conn->error;
    $feedback_type = 'error';
    error_log("Error fetching products: " . $conn->error);
}

// --- Handler untuk menyimpan order baru ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['place_order'])) {
    // Sanitize and validate inputs
    $product_id_from_form = (int)($_POST['product_id'] ?? 0);
    $customer_phone = trim($_POST['customer_phone'] ?? ''); // Use trim for phone number
    $quantity = (int)($_POST['quantity'] ?? 0);
    $status = 'pending'; // Default status for new orders

    // Basic input validation
    if (empty($product_id_from_form) || empty($customer_phone) || $quantity <= 0) {
        $feedback_message = "Semua field wajib diisi dan jumlah harus lebih dari 0.";
        $feedback_type = 'error';
    } else {
        $product_name_fetched = '';
        $price = 0;
        $stock = 0;

        // Fetch product details (name, price, current stock) for the selected product
        $stmt_product = $conn->prepare("SELECT name, price, stock FROM products WHERE id = ?");
        if ($stmt_product) {
            $stmt_product->bind_param("i", $product_id_from_form);
            if ($stmt_product->execute()) {
                $stmt_product->bind_result($product_name_fetched, $price, $stock);
                $stmt_product->fetch();
                $stmt_product->close();
            } else {
                $feedback_message = "Error fetching product details: " . $stmt_product->error;
                $feedback_type = 'error';
                error_log("Error executing product details fetch: " . $stmt_product->error);
            }
        } else {
            $feedback_message = "Error preparing statement for product details: " . $conn->error;
            $feedback_type = 'error';
            error_log("Error preparing statement for product details: " . $conn->error);
        }

        // Proceed only if product found and feedback message is not already set
        if (empty($product_name_fetched) && empty($feedback_message)) {
            $feedback_message = "Produk yang dipilih tidak valid atau tidak ditemukan.";
            $feedback_type = 'error';
        } elseif ($stock < $quantity && empty($feedback_message)) {
            $feedback_message = "Stok tidak mencukupi untuk produk ini. Sisa stok: " . $stock;
            $feedback_type = 'error';
        } else if (empty($feedback_message)) { // If all checks pass and no errors so far
            $total_amount = $price * $quantity;

            // --- Start Transaction ---
            $conn->begin_transaction();
            try {
                // 1. Decrease product stock
                $stmt_update_stock = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                if (!$stmt_update_stock) {
                    throw new Exception("Error preparing stock update: " . $conn->error);
                }
                $stmt_update_stock->bind_param("ii", $quantity, $product_id_from_form);
                if (!$stmt_update_stock->execute()) {
                    throw new Exception("Error executing stock update: " . $stmt_update_stock->error);
                }
                $stmt_update_stock->close();

                // 2. Insert new order
                // Ensure column names match your 'orders' table.
                // Assuming orders table has: product_id, product_name, customer_name, customer_email,
                // customer_phone, quantity, total_amount, status, created_at
                $stmt_insert_order = $conn->prepare("INSERT INTO orders (product_id, product_name, customer_name, customer_email, customer_phone, quantity, total_amount, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                if (!$stmt_insert_order) {
                    throw new Exception("Error preparing order insertion: " . $conn->error);
                }
                // sssisds -> s=string, i=integer, d=double (for total_amount)
                $stmt_insert_order->bind_param("isssisds",
                    $product_id_from_form,
                    $product_name_fetched,
                    $username,
                    $email,
                    $customer_phone,
                    $quantity,
                    $total_amount,
                    $status
                );

                if (!$stmt_insert_order->execute()) {
                    throw new Exception("Error executing order insertion: " . $stmt_insert_order->error);
                }
                $stmt_insert_order->close();

                // --- Commit Transaction ---
                $conn->commit();
                $feedback_message = "Pesanan berhasil dibuat!";
                $feedback_type = 'success';
                // Redirect to prevent form re-submission on refresh (Post/Redirect/Get pattern)
                header("Location: orders-view.php"); // Or to a specific order confirmation page
                exit;

            } catch (Exception $e) {
                // --- Rollback Transaction on Error ---
                $conn->rollback();
                $feedback_message = "Gagal menyimpan pesanan: " . $e->getMessage();
                $feedback_type = 'error';
                error_log("Order placement failed: " . $e->getMessage()); // Log detailed error
            }
        }
    }
}

// Handler for deleting order
if (isset($_GET['delete'])) {
    $id_to_delete = (int)$_GET['delete'];

    // Start transaction for deletion to ensure stock restore and order deletion are atomic
    $conn->begin_transaction();
    try {
        // Ambil informasi order yang akan dihapus untuk mengembalikan stok
        $stmt_fetch_order = $conn->prepare("SELECT product_id, quantity, status FROM orders WHERE id = ? AND customer_name = ?");
        if (!$stmt_fetch_order) {
            throw new Exception("Error preparing statement to get order details for deletion: " . $conn->error);
        }
        $stmt_fetch_order->bind_param("is", $id_to_delete, $username);
        $stmt_fetch_order->execute();
        $stmt_fetch_order->bind_result($deleted_product_id, $deleted_quantity, $order_status_on_delete);
        $stmt_fetch_order->fetch();
        $stmt_fetch_order->close();

        if ($deleted_product_id && $deleted_quantity > 0) {
            // Only restore stock if the order status isn't "completed" or "cancelled"
            // This prevents double counting if stock was already handled by other means
            if ($order_status_on_delete !== 'completed' && $order_status_on_delete !== 'cancelled') {
                // Kembalikan stok ke produk menggunakan product_id
                $stmt_restore_stock = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                if (!$stmt_restore_stock) {
                    throw new Exception("Error preparing statement to restore stock: " . $conn->error);
                }
                $stmt_restore_stock->bind_param("ii", $deleted_quantity, $deleted_product_id);
                if (!$stmt_restore_stock->execute()) {
                    throw new Exception("Error executing stock restoration: " . $stmt_restore_stock->error);
                }
                $stmt_restore_stock->close();
            }
        }

        // Hapus order dari database
        $stmt_delete_order = $conn->prepare("DELETE FROM orders WHERE id=? AND customer_name=?");
        if (!$stmt_delete_order) {
            throw new Exception("Error preparing statement to delete order: " . $conn->error);
        }
        $stmt_delete_order->bind_param("is", $id_to_delete, $username);
        if (!$stmt_delete_order->execute()) {
            throw new Exception("Error executing order deletion: " . $stmt_delete_order->error);
        }
        $stmt_delete_order->close();

        $conn->commit();
        $feedback_message = "Order berhasil dihapus dan stok dikembalikan.";
        $feedback_type = 'success';
        header("Location: orders-view.php");
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $feedback_message = "Gagal menghapus order: " . $e->getMessage();
        $feedback_type = 'error';
        error_log("Order deletion failed: " . $e->getMessage());
    }
}

// Handler untuk mengubah status order
if (isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['new_status']; // No trim needed for fixed options

    // Basic validation for new_status (optional, but good practice)
    $allowed_statuses = ['pending', 'processing', 'completed', 'cancelled'];
    if (!in_array($new_status, $allowed_statuses)) {
        $feedback_message = "Status yang dipilih tidak valid.";
        $feedback_type = 'error';
    } else {
        // Fetch current status to prevent updates on completed/cancelled orders
        $current_status = '';
        $stmt = $conn->prepare("SELECT status FROM orders WHERE id = ? AND customer_name = ?");
        if ($stmt) {
            $stmt->bind_param("is", $order_id, $username);
            $stmt->execute();
            $stmt->bind_result($current_status);
            $stmt->fetch();
            $stmt->close();
        } else {
            $feedback_message = "Error preparing statement to get current order status: " . $conn->error;
            $feedback_type = 'error';
            error_log("Error preparing statement for status check: " . $conn->error);
        }

        if ($current_status === 'completed' || $current_status === 'cancelled') {
            $feedback_message = "Tidak dapat mengubah status order yang sudah 'completed' atau 'cancelled'.";
            $feedback_type = 'error';
        } elseif ($current_status === $new_status) {
            $feedback_message = "Status order sudah " . htmlspecialchars($new_status) . ".";
            $feedback_type = 'info'; // Use info type for no change
        } else {
            // If the status is changing to 'cancelled' and it wasn't already 'cancelled',
            // or if it was 'completed' and is now 'cancelled', handle stock return logic.
            // This is complex, better to handle cancellation as a separate action or ensure
            // the status flow is strictly defined (e.g., only 'pending' to 'cancelled' returns stock).
            // For simplicity, for this example, stock is returned only on DELETE, not on status change to cancelled.
            // If you want to return stock on status change to 'cancelled', you'll need transaction here.

            $stmt = $conn->prepare("UPDATE orders SET status=? WHERE id=? AND customer_name=?");
            if ($stmt) {
                $stmt->bind_param("sis", $new_status, $order_id, $username);
                if ($stmt->execute()) {
                    $feedback_message = "Status order berhasil diubah menjadi " . htmlspecialchars($new_status) . ".";
                    $feedback_type = 'success';
                    header("Location: orders-view.php");
                    exit;
                } else {
                    $feedback_message = "Error saat update status: " . $stmt->error;
                    $feedback_type = 'error';
                    error_log("Error executing status update: " . $stmt->error);
                }
                $stmt->close();
            } else {
                $feedback_message = "Error preparing statement to update order status: " . $conn->error;
                $feedback_type = 'error';
                error_log("Error preparing statement for status update: " . $conn->error);
            }
        }
    }
}


// Handler untuk mengubah order (kuantitas dan phone)
if (isset($_POST['edit_order'])) {
    $order_id = (int)$_POST['order_id'];
    $new_quantity = (int)$_POST['new_quantity'];
    $new_customer_phone = trim($_POST['new_customer_phone']);

    if ($new_quantity <= 0) {
        $feedback_message = "Kuantitas harus lebih dari 0.";
        $feedback_type = 'error';
    } else {
        // Ambil data order lama dan produk terkait untuk perhitungan stok dan total
        $product_id_old = null;
        $quantity_old = 0;
        $product_price = 0;
        $product_stock_current = 0;
        $current_order_status = '';

        $stmt = $conn->prepare("SELECT product_id, quantity, status FROM orders WHERE id = ? AND customer_name = ?");
        if ($stmt) {
            $stmt->bind_param("is", $order_id, $username);
            $stmt->execute();
            $stmt->bind_result($product_id_old, $quantity_old, $current_order_status);
            $stmt->fetch();
            $stmt->close();
        } else {
            $feedback_message = "Error preparing statement to get old order details for edit: " . $conn->error;
            $feedback_type = 'error';
            error_log("Error preparing statement for edit fetch: " . $conn->error);
        }

        if (!$product_id_old) { // Order not found or not owned by user
            $feedback_message = "Order tidak ditemukan atau Anda tidak memiliki izin untuk mengeditnya.";
            $feedback_type = 'error';
        } elseif ($current_order_status === 'completed' || $current_order_status === 'cancelled') {
            $feedback_message = "Tidak dapat mengedit order yang sudah 'completed' atau 'cancelled'.";
            $feedback_type = 'error';
        } else {
            // Ambil harga dan stok produk saat ini menggunakan product_id
            $stmt = $conn->prepare("SELECT price, stock FROM products WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $product_id_old);
                $stmt->execute();
                $stmt->bind_result($product_price, $product_stock_current);
                $stmt->fetch();
                $stmt->close();
            } else {
                $feedback_message = "Error preparing statement to get product price/stock for edit: " . $conn->error;
                $feedback_type = 'error';
                error_log("Error preparing statement for product price/stock fetch: " . $conn->error);
            }

            if (!$product_price) { // Product not found
                $feedback_message = "Produk terkait order tidak ditemukan (mungkin sudah dihapus dari daftar produk).";
                $feedback_type = 'error';
            } else {
                $stock_difference = $new_quantity - $quantity_old; // How much stock needs to change

                if ($product_stock_current - $stock_difference < 0) {
                    $feedback_message = "Stok tidak cukup untuk perubahan kuantitas ini. Stok tersedia: " . $product_stock_current;
                    $feedback_type = 'error';
                } else {
                    // --- Start Transaction for Edit Order ---
                    $conn->begin_transaction();
                    try {
                        // 1. Update product stock
                        $stmt_update_stock_edit = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                        if (!$stmt_update_stock_edit) {
                            throw new Exception("Error preparing stock update during edit: " . $conn->error);
                        }
                        $stmt_update_stock_edit->bind_param("ii", $stock_difference, $product_id_old);
                        if (!$stmt_update_stock_edit->execute()) {
                            throw new Exception("Error executing stock update during edit: " . $stmt_update_stock_edit->error);
                        }
                        $stmt_update_stock_edit->close();

                        // 2. Calculate new total amount
                        $new_total_amount = $product_price * $new_quantity;

                        // 3. Update order details
                        $stmt_update_order = $conn->prepare("UPDATE orders SET quantity = ?, customer_phone = ?, total_amount = ? WHERE id = ? AND customer_name = ?");
                        if (!$stmt_update_order) {
                            throw new Exception("Error preparing order update: " . $conn->error);
                        }
                        $stmt_update_order->bind_param("isdis", $new_quantity, $new_customer_phone, $new_total_amount, $order_id, $username);
                        if (!$stmt_update_order->execute()) {
                            throw new Exception("Error executing order update: " . $stmt_update_order->error);
                        }
                        $stmt_update_order->close();

                        $conn->commit();
                        $feedback_message = "Order berhasil diubah.";
                        $feedback_type = 'success';
                        header("Location: orders-view.php");
                        exit;

                    } catch (Exception $e) {
                        $conn->rollback();
                        $feedback_message = "Gagal mengubah order: " . $e->getMessage();
                        $feedback_type = 'error';
                        error_log("Order edit failed: " . $e->getMessage());
                    }
                }
            }
        }
    }
}


// Ambil data order user setelah semua operasi POST/GET
$orders = [];
$stmt = $conn->prepare("SELECT id, customer_name, customer_email, customer_phone, total_amount, status, product_name, quantity, created_at FROM orders WHERE customer_name=? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("s", $username);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $orders[] = $row;
            }
        } else {
            $feedback_message = "Error mengambil order dari database: " . $conn->error;
            $feedback_type = 'error';
            error_log("Error getting result set for orders: " . $conn->error);
        }
    } else {
        $feedback_message = "Error mengeksekusi statement untuk mengambil order: " . $stmt->error;
        $feedback_type = 'error';
        error_log("Error executing statement for orders: " . $stmt->error);
    }
    $stmt->close();
} else {
    $feedback_message = "Error preparing statement untuk mengambil order: " . $conn->error;
    $feedback_type = 'error';
    error_log("Error preparing statement for orders: " . $conn->error);
}

// Close connection at the end of the script execution
$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Orders View</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f3efea;
            color: #4e342e;
            padding: 20px;
        }

        h2 {
            color: #5d4037;
            border-bottom: 2px solid #d7ccc8;
            padding-bottom: 5px;
            margin-top: 40px;
        }

        a {
            color: #6d4c41;
            text-decoration: none;
            font-weight: bold;
        }

        a:hover {
            text-decoration: underline;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 15px;
            background-color: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,0.1);
        }

        th {
            background-color: #8d6e63;
            color: white;
            padding: 12px;
            text-align: left;
        }

        td {
            padding: 10px;
            border-bottom: 1px solid #d7ccc8;
        }

        tr:nth-child(even) {
            background-color: #fefaf6;
        }

        tr:hover {
            background-color: #f5eae1;
        }

        form {
            background-color: #fff;
            border: 1px solid #d7ccc8;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            max-width: 500px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        label {
            display: block;
            margin-top: 10px;
            margin-bottom: 5px;
            font-weight: 600;
        }

        input[type="text"],
        input[type="number"],
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #bcae9e;
            border-radius: 5px;
            background-color: #fffdfb;
        }

        button {
            background-color: #6d4c41;
            color: white;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
            margin-right: 5px; /* Added for spacing between buttons */
        }

        button:hover {
            background-color: #4e342e;
        }

        em {
            color: #8e8e8e;
            font-style: italic;
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            color: white;
            font-size: 12px;
        }
        .status-pending { background-color: #ff9800; }
        .status-processing { background-color: #03a9f4; }
        .status-completed { background-color: #4caf50; }
        .status-cancelled { background-color: #f44336; }

        /* Styling for inline edit form */
        .edit-form-inline {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .edit-form-inline input[type="number"],
        .edit-form-inline input[type="text"],
        .edit-form-inline select { /* Juga terapkan ke select */
            width: 120px; /* Adjust as needed */
            padding: 3px;
        }
        .edit-form-inline button {
            padding: 5px 10px;
            font-size: 12px;
            margin-top: 0;
            width: fit-content;
        }
        /* Tambahan CSS untuk tombol Edit (agar terlihat seperti button) */
        a.button {
            display: inline-block;
            background-color: #8d6e63; /* Warna berbeda untuk membedakan */
            color: white;
            padding: 10px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 10px;
            text-decoration: none;
            text-align: center;
        }
        a.button:hover {
            background-color: #6d4c41;
        }

        /* Styles for feedback messages */
        .feedback-message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-weight: bold;
            text-align: center;
            opacity: 0.9;
        }
        .feedback-message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .feedback-message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .feedback-message.info {
            background-color: #cce5ff;
            color: #004085;
            border: 1px solid #b8daff;
        }

    </style>
</head>
<body>

<?php if ($feedback_message): ?>
    <div class="feedback-message <?= $feedback_type ?>">
        <?= htmlspecialchars($feedback_message) ?>
    </div>
<?php endif; ?>

<h2>Daftar Produk</h2>
<table>
    <thead>
        <tr>
            <th>Nama</th>
            <th>Deskripsi</th>
            <th>Harga</th>
            <th>Stok</th>
        </tr>
    </thead>
    <tbody>
        <?php if (!empty($products)): ?>
            <?php foreach ($products as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['name']) ?></td>
                    <td><?= htmlspecialchars($p['description']) ?></td>
                    <td>Rp <?= number_format($p['price'], 2, ',', '.') ?></td>
                    <td><?= htmlspecialchars($p['stock']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="4">Tidak ada produk ditemukan. Pastikan tabel 'products' memiliki data dan kolom 'name' berisi nama produk.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<h2>Form Pemesanan</h2>
<form method="POST">
    <label>Produk:</label>
    <select name="product_id" required>
        <option value="">-- Pilih Produk --</option>
        <?php if (!empty($products)): ?>
            <?php foreach ($products as $p): ?>
                <option value="<?= htmlspecialchars($p['id']) ?>"><?= htmlspecialchars($p['name']) ?> (Stok: <?= htmlspecialchars($p['stock']) ?>)</option>
            <?php endforeach; ?>
        <?php else: ?>
            <option value="" disabled>Tidak ada produk untuk dipesan.</option>
        <?php endif; ?>
    </select>

    <label>No. HP:</label>
    <input type="text" name="customer_phone" required>

    <label>Jumlah Pembelian:</label>
    <input type="number" name="quantity" min="1" required>

    <button type="submit" name="place_order">Pesan Sekarang</button>
</form>

<h2>Data Order Anda</h2>
<table>
    <thead>
        <tr>
            <th>Nama Produk</th>
            <th>Nama Pemesan</th>
            <th>Email</th>
            <th>Phone & Kuantitas</th> <th>Total (Rp)</th>
            <th>Status</th>
            <th>Aksi & Waktu Order</th> </tr>
    </thead>
    <tbody>
        <?php if (count($orders) > 0): ?>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><?= htmlspecialchars($order['product_name']) ?></td>
                    <td><?= htmlspecialchars($order['customer_name']) ?></td>
                    <td><?= htmlspecialchars($order['customer_email']) ?></td>
                    <td>
                        <?php if (isset($_GET['edit_id']) && $_GET['edit_id'] == $order['id'] && ($order['status'] !== 'completed' && $order['status'] !== 'cancelled')): ?>
                            <form method="POST" class="edit-form-inline">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <label for="new_quantity_<?= $order['id'] ?>">Jumlah:</label>
                                <input type="number" id="new_quantity_<?= $order['id'] ?>" name="new_quantity" value="<?= (int)$order['quantity'] ?>" min="1" required>
                                <label for="new_customer_phone_<?= $order['id'] ?>">No. HP:</label>
                                <input type="text" id="new_customer_phone_<?= $order['id'] ?>" name="new_customer_phone" value="<?= htmlspecialchars($order['customer_phone']) ?>" required>
                                <button type="submit" name="edit_order">Simpan</button>
                                <a href="orders-view.php" style="font-size: 12px; margin-top: 5px; background-color: #f44336; color: white; padding: 5px 10px; border-radius: 6px; text-decoration: none; display: inline-block;">Batal</a>
                            </form>
                        <?php else: ?>
                            <?= htmlspecialchars($order['customer_phone']) ?><br>
                            Kuantitas: <?= (int)$order['quantity'] ?>
                        <?php endif; ?>
                    </td>
                    <td>Rp <?= number_format($order['total_amount'], 2, ',', '.') ?></td>
                    <td>
                        <span class="status-badge status-<?= htmlspecialchars($order['status']) ?>">
                            <?= htmlspecialchars(ucfirst($order['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($order['status'] === 'completed' || $order['status'] === 'cancelled'): ?>
                            <em>Tidak bisa diubah</em><br>
                            <a href="?delete=<?= $order['id'] ?>" onclick="return confirm('Yakin ingin menghapus order ini? Ini akan mengembalikan stok jika status bukan completed/cancelled.')" style="color: #f44336; margin-top: 5px;">Hapus</a>
                        <?php else: ?>
                            <?php if (isset($_GET['edit_id']) && $_GET['edit_id'] == $order['id']): ?>
                                <?php else: ?>
                                <form method="POST" style="display:inline-block; margin-bottom: 5px;">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <select name="new_status" style="width: auto;">
                                        <option value="pending" <?= $order['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="processing" <?= $order['status'] == 'processing' ? 'selected' : '' ?>>Processing</option>
                                        <option value="completed" <?= $order['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                                        <option value="cancelled" <?= $order['status'] == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                    </select>
                                    <button type="submit" name="update_status" style="padding: 5px 10px;">Update Status</button>
                                </form>
                                <a href="?edit_id=<?= $order['id'] ?>" class="button" style="margin-top: 5px; padding: 8px 12px;">Edit Order</a>
                                <a href="?delete=<?= $order['id'] ?>" onclick="return confirm('Yakin ingin menghapus order ini? Ini akan mengembalikan stok jika status bukan completed/cancelled.')" style="color: #f44336; margin-top: 5px;">Hapus</a>
                            <?php endif; ?>
                        <?php endif; ?>
                        <br>
                        <small><em><?= htmlspecialchars($order['created_at']) ?></em></small>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="7">Belum ada order.</td></tr>
        <?php endif; ?>
    </tbody>
</table>

<a href="../index.php">⬅ Kembali ke Dashboard</a>

</body>
</html>