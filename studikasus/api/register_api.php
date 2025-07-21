<?php
require_once '../api/koneksi.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type");

$method = $_SERVER['REQUEST_METHOD'];
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);
$response = ['success' => false];

switch ($method) {
    case 'GET':
        if (isset($query['id'])) {
            $id = intval($query['id']);
            $stmt = $conn->prepare("SELECT id, username, email, created_at FROM users WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res->num_rows > 0) {
                $response['success'] = true;
                $response['user'] = $res->fetch_assoc();
            } else {
                $response['message'] = "User tidak ditemukan";
            }
            $stmt->close();
        } else {
            $result = $conn->query("SELECT id, username, email, created_at FROM users ORDER BY id DESC");
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            $response['success'] = true;
            $response['users'] = $users;
        }
        break;

    case 'POST':
        $data = json_decode(file_get_contents("php://input"), true);
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (strlen($password) < 6) {
            $response['message'] = "Password minimal 6 karakter!";
            break;
        }

        $check = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $response['message'] = "Username atau email sudah terdaftar!";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())");
            $stmt->bind_param("sss", $username, $email, $hashedPassword);
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = "Registrasi berhasil!";
            } else {
                $response['message'] = "Gagal menyimpan data!";
            }
            $stmt->close();
        }
        $check->close();
        break;

    case 'PUT':
        $data = json_decode(file_get_contents("php://input"), true);
        $id = intval($data['id'] ?? 0);
        $username = trim($data['username'] ?? '');
        $email = trim($data['email'] ?? '');

        if (!$id || !$username || !$email) {
            $response['message'] = "Data tidak lengkap!";
            break;
        }

        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $username, $email, $id);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = "Data berhasil diupdate!";
        } else {
            $response['message'] = "Gagal update data!";
        }
        $stmt->close();
        break;

    case 'DELETE':
        parse_str(file_get_contents("php://input"), $data);
        $id = intval($data['id'] ?? 0);
        if (!$id) {
            $response['message'] = "ID tidak ditemukan!";
            break;
        }

        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $response['success'] = true;
            $response['message'] = "User berhasil dihapus!";
        } else {
            $response['message'] = "Gagal menghapus user!";
        }
        $stmt->close();
        break;

    default:
        $response['message'] = "Metode request tidak diizinkan.";
        break;
}

$conn->close();
echo json_encode($response);
