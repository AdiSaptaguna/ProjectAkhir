<?php

// --- 1. Konfigurasi Database ---
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'coffee_shop';

// Buat koneksi database
$conn = new mysqli($host, $user, $pass, $dbname);

// Periksa koneksi
if ($conn->connect_error) {
    error_log("Koneksi database gagal: " . $conn->connect_error);
    http_response_code(500); // Internal Server Error
    echo json_encode(["success" => false, "message" => "Terjadi kesalahan pada server."]);
    exit;
}

// --- 2. Set Header dan Metode Permintaan ---
header("Content-Type: application/json");
$method = $_SERVER['REQUEST_METHOD'];

// --- 3. Ambil Data Input dengan Penanganan Error yang Lebih Baik ---
$data = json_decode(file_get_contents("php://input") ?: '[]', true);

// --- 4. Fungsi Pembantu untuk Respons JSON ---
function sendJsonResponse(bool $success, string $message, array $data = [], int $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit;
}

// --- 5. Routing Berdasarkan Metode HTTP ---

switch ($method) {
    case 'POST': // === CREATE ===
        // Validasi input
        $name = trim($data['name'] ?? ''); // ADDED THIS LINE
        $description = trim($data['description'] ?? '');
        $price = filter_var($data['price'] ?? 0, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($data['stock'] ?? 0, FILTER_VALIDATE_INT);
        $category = trim($data['category'] ?? '');
        $image = $data['image'] ?? null;

        // Periksa apakah input penting tidak kosong
        // Make sure 'name' is also checked
        if (empty($name) || empty($description) || $price === false || $stock === false || empty($category)) {
            sendJsonResponse(false, "Data produk tidak lengkap atau tidak valid.", [], 400); // Bad Request
        }

        // MODIFY THE INSERT STATEMENT TO INCLUDE 'name'
        $stmt = $conn->prepare("INSERT INTO products (name, description, price, stock, category, image) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            error_log("Prepare statement failed: " . $conn->error);
            sendJsonResponse(false, "Terjadi kesalahan internal saat menyiapkan pernyataan.", [], 500);
        }

        // MODIFY BIND_PARAM TO INCLUDE 'name' (s for string)
        $stmt->bind_param("ssdiss", $name, $description, $price, $stock, $category, $image);
        if ($stmt->execute()) {
            sendJsonResponse(true, "Produk berhasil ditambahkan.", ["id" => $conn->insert_id], 201); // Created
        } else {
            error_log("Execute statement failed: " . $stmt->error);
            sendJsonResponse(false, "Gagal menambahkan produk.", [], 500);
        }
        break;

    case 'GET': // === READ ===
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        $rows = [];

        if ($id !== false && $id !== null) { // ID valid
            // Make sure to select 'name' here too
            $stmt = $conn->prepare("SELECT id, name, description, price, stock, category, image FROM products WHERE id = ?");
            if (!$stmt) {
                error_log("Prepare statement failed: " . $conn->error);
                sendJsonResponse(false, "Terjadi kesalahan internal saat menyiapkan pernyataan.", [], 500);
            }
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                error_log("Execute statement failed: " . $stmt->error);
                sendJsonResponse(false, "Gagal mengambil produk.", [], 500);
            }
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if ($row) {
                $rows[] = $row;
            } else {
                sendJsonResponse(false, "Produk tidak ditemukan.", [], 404); // Not Found
            }
        } else { // Ambil semua produk
            // Make sure to select 'name' here too
            $stmt = $conn->prepare("SELECT id, name, description, price, stock, category, image FROM products");
            if (!$stmt) {
                error_log("Prepare statement failed: " . $conn->error);
                sendJsonResponse(false, "Terjadi kesalahan internal saat menyiapkan pernyataan.", [], 500);
            }
            if (!$stmt->execute()) {
                error_log("Execute statement failed: " . $stmt->error);
                sendJsonResponse(false, "Gagal mengambil daftar produk.", [], 500);
            }
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        }
        sendJsonResponse(true, "Data produk berhasil diambil.", $rows);
        break;

    case 'PUT': // === UPDATE ===
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null) {
            sendJsonResponse(false, "ID produk tidak valid atau tidak disediakan.", [], 400);
        }

        // Validate and sanitize inputs, including 'name'
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $price = filter_var($data['price'] ?? null, FILTER_VALIDATE_FLOAT);
        $stock = filter_var($data['stock'] ?? null, FILTER_VALIDATE_INT);
        $category = trim($data['category'] ?? '');
        $image = $data['image'] ?? null;

        // Build UPDATE query dynamically
        $setClauses = [];
        $bindTypes = '';
        $bindValues = [];

        if (!empty($name)) {
            $setClauses[] = "name = ?";
            $bindTypes .= "s";
            $bindValues[] = $name;
        }
        if (!empty($description)) {
            $setClauses[] = "description = ?";
            $bindTypes .= "s";
            $bindValues[] = $description;
        }
        if ($price !== null && $price !== false) {
            $setClauses[] = "price = ?";
            $bindTypes .= "d";
            $bindValues[] = $price;
        }
        if ($stock !== null && $stock !== false) {
            $setClauses[] = "stock = ?";
            $bindTypes .= "i";
            $bindValues[] = $stock;
        }
        if (!empty($category)) {
            $setClauses[] = "category = ?";
            $bindTypes .= "s";
            $bindValues[] = $category;
        }
        // Only update image if provided (can be null)
        if (array_key_exists('image', $data)) {
            $setClauses[] = "image = ?";
            $bindTypes .= "s";
            $bindValues[] = $image;
        }

        if (empty($setClauses)) {
            sendJsonResponse(false, "Tidak ada data yang disediakan untuk diperbarui.", [], 400);
        }

        $query = "UPDATE products SET " . implode(", ", $setClauses) . " WHERE id = ?";
        $bindTypes .= "i"; // Add type for ID
        $bindValues[] = $id; // Add ID to bind values

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare statement failed: " . $conn->error);
            sendJsonResponse(false, "Terjadi kesalahan internal saat menyiapkan pernyataan.", [], 500);
        }

        // --- START FIX FOR bind_param REFERENCE ISSUE ---
        $params = array_merge([$bindTypes], $bindValues); // Combine types and values
        $refs = [];
        foreach($params as $key => $value) {
            $refs[$key] = &$params[$key]; // Create references
        }
        // Call bind_param with references
        call_user_func_array([$stmt, 'bind_param'], $refs);
        // --- END FIX FOR bind_param REFERENCE ISSUE ---

        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                sendJsonResponse(true, "Produk berhasil diperbarui.");
            } else {
                sendJsonResponse(false, "Produk tidak ditemukan atau tidak ada perubahan data.", [], 404);
            }
        } else {
            error_log("Execute statement failed: " . $stmt->error);
            sendJsonResponse(false, "Gagal memperbarui produk.", [], 500);
        }
        break;

    case 'DELETE': // === DELETE ===
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id === null) {
            sendJsonResponse(false, "ID produk tidak valid atau tidak disediakan.", [], 400);
        }

        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        if (!$stmt) {
            error_log("Prepare statement failed: " . $conn->error);
            sendJsonResponse(false, "Terjadi kesalahan internal saat menyiapkan pernyataan.", [], 500);
        }
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                sendJsonResponse(true, "Produk berhasil dihapus.");
            } else {
                sendJsonResponse(false, "Produk tidak ditemukan.", [], 404);
            }
        } else {
            error_log("Execute statement failed: " . $stmt->error);
            sendJsonResponse(false, "Gagal menghapus produk.", [], 500);
        }
        break;

    default: // Metode tidak didukung
        sendJsonResponse(false, "Metode HTTP tidak didukung.", [], 405); // Method Not Allowed
        break;
}

// Tutup koneksi database (opsional karena PHP akan menutupnya secara otomatis setelah skrip selesai)
$conn->close();

?>