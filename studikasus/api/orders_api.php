<?php
session_start();
include '../api/koneksi.php'; // Pastikan path ke file koneksi.php sudah benar

header('Content-Type: application/json'); // Selalu set header untuk JSON response
$response = ['success' => false, 'message' => ''];

// Cek login untuk semua operasi API
if (!isset($_SESSION['username'])) {
    $response['message'] = "Anda harus login terlebih dahulu untuk mengakses API.";
    echo json_encode($response);
    exit;
}

$username = $_SESSION['username'];
$email = ''; // Inisialisasi email

// Ambil email user yang sedang login
$stmt = $conn->prepare("SELECT email FROM users WHERE username = ?");
if ($stmt) {
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->bind_result($email);
    $stmt->fetch();
    $stmt->close();
} else {
    $response['message'] = "Database error (gagal mengambil email user): " . $conn->error;
    echo json_encode($response);
    exit;
}

// Handler untuk setiap metode HTTP (CRUD)
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Mendapatkan semua order untuk user yang login
        $orders = [];
        $stmt = $conn->prepare("SELECT id, customer_name, customer_email, customer_phone, total_amount, status, product_name, quantity, created_at FROM orders WHERE customer_name=? ORDER BY created_at DESC");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $orders[] = $row;
                }
                $response['success'] = true;
                $response['message'] = 'Orders berhasil diambil.';
                $response['data'] = $orders;
            } else {
                $response['message'] = "Error mengambil orders: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['message'] = "Database error (gagal prepare get orders): " . $conn->error;
        }
        break;

    case 'POST':
        // Membuat order baru
        // Membaca input dari $_POST (untuk form-urlencoded) atau php://input (untuk raw JSON)
        $input = [];
        if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            $input = $_POST;
        }

        $product_id_from_form = (int)($input['product_id'] ?? 0);
        $customer_phone = trim($input['customer_phone'] ?? '');
        $quantity = (int)($input['quantity'] ?? 0);
        $status = 'pending';

        if (empty($product_id_from_form) || empty($customer_phone) || empty($quantity)) {
            $response['message'] = "Semua field (product_id, customer_phone, quantity) wajib diisi untuk membuat order.";
            break;
        }

        // Ambil detail produk (nama, harga, stok) berdasarkan product_id
        $stmt = $conn->prepare("SELECT name, price, stock FROM products WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $product_id_from_form);
            $stmt->execute();
            $stmt->bind_result($product_name_fetched, $price, $stock);
            $stmt->fetch();
            $stmt->close();
        } else {
            $response['message'] = "Database error (gagal prepare ambil produk): " . $conn->error;
            break;
        }

        if (!$product_name_fetched) {
            $response['message'] = "Produk dengan ID '" . $product_id_from_form . "' tidak ditemukan di tabel 'products'.";
        } elseif ($quantity <= 0) {
            $response['message'] = "Jumlah pembelian harus lebih dari 0.";
        } elseif ($stock < $quantity) {
            $response['message'] = "Stok tidak mencukupi untuk produk ini (Sisa stok: " . $stock . ").";
        } else {
            $total_amount = $price * $quantity;

            // Kurangi stok
            $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $quantity, $product_id_from_form);
                if (!$stmt->execute()) {
                    $response['message'] = "Error saat mengurangi stok: " . $stmt->error;
                    break;
                }
                $stmt->close();
            } else {
                $response['message'] = "Database error (gagal prepare update stok): " . $conn->error;
                break;
            }

            // Simpan order baru
            $stmt = $conn->prepare("INSERT INTO orders (customer_name, customer_email, product_id, product_name, customer_phone, total_amount, quantity, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
            if ($stmt) {
                $stmt->bind_param("ssisdiss", $username, $email, $product_id_from_form, $product_name_fetched, $customer_phone, $total_amount, $quantity, $status);
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Order berhasil disimpan.";
                    $response['order_id'] = $stmt->insert_id;
                } else {
                    $response['message'] = "Error saat menyimpan order: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = "Database error (gagal prepare insert order): " . $conn->error;
            }
        }
        break;

    case 'PUT':
    case 'PATCH':
        // Memperbarui order (status atau kuantitas/telepon)
        $input = json_decode(file_get_contents('php://input'), true);

        $order_id = (int)($input['order_id'] ?? 0);
        $new_quantity = (int)($input['new_quantity'] ?? 0);
        $new_customer_phone = trim($input['new_customer_phone'] ?? '');
        $new_status = trim($input['new_status'] ?? '');

        if (empty($order_id)) {
            $response['message'] = "ID Order wajib diisi untuk update.";
            break;
        }

        // Dapatkan status order saat ini
        $stmt = $conn->prepare("SELECT product_id, quantity, status FROM orders WHERE id = ? AND customer_name = ?");
        if ($stmt) {
            $stmt->bind_param("is", $order_id, $username);
            $stmt->execute();
            $stmt->bind_result($product_id_old, $quantity_old, $current_status);
            $stmt->fetch();
            $stmt->close();
        } else {
            $response['message'] = "Database error (gagal prepare cek status order): " . $conn->error;
            break;
        }

        if (!$product_id_old) {
            $response['message'] = "Order tidak ditemukan atau Anda tidak memiliki izin untuk mengubahnya.";
            break;
        }
        if ($current_status === 'completed' || $current_status === 'cancelled') {
            $response['message'] = "Order dengan status 'completed' atau 'cancelled' tidak dapat diubah.";
            break;
        }

        // Logika update status
        if (!empty($new_status)) {
            $stmt = $conn->prepare("UPDATE orders SET status=? WHERE id=? AND customer_name=?");
            if ($stmt) {
                $stmt->bind_param("sis", $new_status, $order_id, $username);
                if ($stmt->execute()) {
                    $response['success'] = true;
                    $response['message'] = "Status order berhasil diupdate.";
                } else {
                    $response['message'] = "Error saat update status order: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $response['message'] = "Database error (gagal prepare update status): " . $conn->error;
            }
        }
        // Logika update kuantitas dan telepon
        elseif (!empty($new_quantity) || !empty($new_customer_phone)) {
            // Dapatkan harga dan stok produk saat ini
            $stmt = $conn->prepare("SELECT price, stock FROM products WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $product_id_old);
                $stmt->execute();
                $stmt->bind_result($product_price, $product_stock);
                $stmt->fetch();
                $stmt->close();
            } else {
                $response['message'] = "Database error (gagal prepare ambil harga/stok produk): " . $conn->error;
                break;
            }

            if (!$product_price) {
                $response['message'] = "Produk terkait order tidak ditemukan.";
            } elseif ($new_quantity <= 0) {
                $response['message'] = "Kuantitas harus lebih dari 0.";
            } else {
                $stock_change = $new_quantity - $quantity_old;
                if ($product_stock - $stock_change < 0) {
                    $response['message'] = "Stok tidak cukup untuk perubahan kuantitas ini (Sisa stok: " . $product_stock . ").";
                } else {
                    // Update stok produk
                    $stmt = $conn->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("ii", $stock_change, $product_id_old);
                        if (!$stmt->execute()) {
                            $response['message'] = "Error saat update stok: " . $stmt->error;
                            break;
                        }
                        $stmt->close();
                    } else {
                        $response['message'] = "Database error (gagal prepare update stok saat edit): " . $conn->error;
                        break;
                    }

                    // Hitung ulang total amount
                    $new_total_amount = $product_price * $new_quantity;

                    // Update data order
                    $stmt = $conn->prepare("UPDATE orders SET quantity = ?, customer_phone = ?, total_amount = ? WHERE id = ? AND customer_name = ?");
                    if ($stmt) {
                        $stmt->bind_param("isdis", $new_quantity, $new_customer_phone, $new_total_amount, $order_id, $username);
                        if ($stmt->execute()) {
                            $response['success'] = true;
                            $response['message'] = "Order berhasil diupdate.";
                        } else {
                            $response['message'] = "Error saat update order: " . $stmt->error;
                        }
                        $stmt->close();
                    } else {
                        $response['message'] = "Database error (gagal prepare update order): " . $conn->error;
                    }
                }
            }
        } else {
            $response['message'] = "Tidak ada data yang valid untuk diupdate (new_status, new_quantity, atau new_customer_phone harus diisi).";
        }
        break;

    case 'DELETE':
        // Menghapus order
        $input = json_decode(file_get_contents('php://input'), true);
        $id_to_delete = (int)($input['order_id'] ?? 0);

        if (empty($id_to_delete)) {
            $response['message'] = "ID Order wajib diisi untuk dihapus.";
            break;
        }

        // Ambil info order untuk mengembalikan stok
        $stmt = $conn->prepare("SELECT product_id, quantity FROM orders WHERE id = ? AND customer_name = ?");
        if ($stmt) {
            $stmt->bind_param("is", $id_to_delete, $username);
            $stmt->execute();
            $stmt->bind_result($deleted_product_id, $deleted_quantity);
            $stmt->fetch();
            $stmt->close();
        } else {
            $response['message'] = "Database error (gagal prepare ambil order untuk hapus): " . $conn->error;
            break;
        }

        if ($deleted_product_id && $deleted_quantity > 0) {
            // Kembalikan stok
            $stmt = $conn->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("ii", $deleted_quantity, $deleted_product_id);
                if (!$stmt->execute()) {
                    $response['message'] = "Error saat mengembalikan stok: " . $stmt->error;
                    break;
                }
                $stmt->close();
            } else {
                $response['message'] = "Database error (gagal prepare kembalikan stok): " . $conn->error;
                break;
            }
        }

        // Hapus order dari database
        $stmt = $conn->prepare("DELETE FROM orders WHERE id=? AND customer_name=?");
        if ($stmt) {
            $stmt->bind_param("is", $id_to_delete, $username);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Order berhasil dihapus.";
            } else {
                $response['message'] = "Error saat menghapus order: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $response['message'] = "Database error (gagal prepare hapus order): " . $conn->error;
        }
        break;

    default:
        $response['message'] = "Metode request tidak didukung.";
        break;
}

$conn->close();
echo json_encode($response);
exit;