<?php
session_start(); // Tetap panggil session_start() jika ada bagian lain aplikasi yang membutuhkannya

include '../api/koneksi.php'; // Pastikan path ke file koneksi.php sudah benar

// --- Set Headers untuk CORS dan JSON Response ---
header("Access-Control-Allow-Origin: *"); // Sesuaikan dengan domain frontend React di produksi
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, OPTIONS"); // Hanya izinkan GET dan OPTIONS
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

$response = ['success' => false, 'message' => ''];

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- Handler untuk metode GET ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Untuk metode GET, kita akan menampilkan semua order tanpa perlu login
    // Jika Anda ingin hanya menampilkan order milik user tertentu TANPA LOGIN,
    // maka Anda perlu parameter lain (misalnya customer_id) dari frontend.
    // Untuk saat ini, kita akan ambil SEMUA order untuk tujuan demo.

    $orders = [];
    // Query untuk mengambil semua order. Hapus klausa WHERE customer_name jika Anda ingin semua order.
    // Jika Anda ingin menampilkan order berdasarkan customer_name tanpa login, Anda perlu mengirim customer_name dari frontend.
    // Contoh: SELECT ... FROM orders WHERE customer_name = ?
    $stmt = $conn->prepare("SELECT id, customer_name, customer_email, customer_phone, total_amount, status, product_name, quantity, created_at, product_id FROM orders ORDER BY created_at DESC");
    
    if ($stmt === false) {
        http_response_code(500);
        $response['message'] = "Database error (gagal menyiapkan query GET orders): " . $conn->error;
    } else {
        // Jika Anda ingin memfilter berdasarkan user yang login, baru tambahkan ini:
        // $username = $_SESSION['username'] ?? null;
        // if ($username) {
        //     $stmt->bind_param("s", $username);
        // } else {
        //     // Handle error if username is required but not found
        //     // http_response_code(401);
        //     // $response['message'] = "Pengguna tidak login.";
        //     // echo json_encode($response);
        //     // exit;
        // }

        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $orders[] = $row;
            }
            $response['success'] = true;
            $response['message'] = 'Orders berhasil diambil.';
            $response['data'] = $orders;
            http_response_code(200); // OK
        } else {
            http_response_code(500);
            $response['message'] = "Error mengambil orders: " . $stmt->error;
        }
        $stmt->close();
    }
} else {
    // Jika metode selain GET (dan OPTIONS sudah ditangani)
    http_response_code(405); // Method Not Allowed
    $response['message'] = "Metode request tidak didukung untuk endpoint ini.";
}

$conn->close();
echo json_encode($response);
exit;
?>