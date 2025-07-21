<?php
// Izinkan akses dari semua origin (untuk pengembangan, di produksi harus lebih spesifik)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Sertakan file koneksi database
require_once '../api/koneksi.php'; // Pastikan path ini benar relatif terhadap file products_api.php

$products = []; // Inisialisasi array kosong untuk menampung data produk

// Lakukan query untuk mengambil semua produk
// Hati-hati dengan data sensitif jika ini adalah endpoint publik tanpa autentikasi
$sql = "SELECT id, name, description, price, stock, category, image FROM products ORDER BY id DESC";
$result = $conn->query($sql);

if ($result) {
    if ($result->num_rows > 0) {
        // Ambil setiap baris hasil sebagai associative array
        while ($row = $result->fetch_assoc()) {
            // Sesuaikan path gambar agar bisa diakses oleh frontend React
            // 💡 PENTING: Ganti 'nama_proyek_mu' dengan nama folder proyek kamu yang sebenarnya
            // Contoh: 'http://localhost/CoffeeShopApp/img/'
            // Atau jika deploy ke domain: 'https://yourdomain.com/img/'
            $base_url_for_images = 'http://localhost/img/'; // <-- SESUAIKAN INI
            
            $row['image_url'] = !empty($row['image']) ? $base_url_for_images . $row['image'] : null;

            $products[] = $row;
        }
    }
    $result->free(); // Bebaskan hasil query
} else {
    // Jika ada error pada query
    http_response_code(500); // Internal Server Error
    echo json_encode(["message" => "Error mengambil data produk: " . $conn->error]);
    $conn->close();
    exit();
}

// Tutup koneksi database
$conn->close();

// Kirim data produk dalam format JSON
echo json_encode($products);

?>