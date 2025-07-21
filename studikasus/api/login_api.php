<?php
// api/login.php

session_start(); // Still useful for web-based frontends, but for a pure API, token-based is preferred.
require_once 'koneksi.php'; // Assuming koneksi.php is in the same directory for API

header('Content-Type: application/json'); // Set header to indicate JSON response

$response = ['success' => false, 'message' => ''];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Get JSON input for API, or form data if you prefer
    $input = json_decode(file_get_contents("php://input"), true);

    // Fallback to POST data if JSON parsing fails or isn't used
    $username = trim($input['username'] ?? $_POST['username'] ?? '');
    $password = $input['password'] ?? $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $response['message'] = "Username and password are required.";
        http_response_code(400); // Bad Request
    } else {
        $stmt = null; // Initialize stmt to null
        try {
            $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE username = ?");
            if (!$stmt) {
                throw new Exception("Failed to prepare statement: " . $conn->error);
            }
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();

                if (password_verify($password, $user['password'])) {
                    // Successful login
                    // For an API, you might generate a token here instead of just setting session
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];

                    $response['success'] = true;
                    $response['message'] = "Login successful!";
                    $response['user'] = [
                        'id' => $user['id'],
                        'username' => $user['username']
                    ];
                    http_response_code(200); // OK
                } else {
                    $response['message'] = "Invalid password.";
                    http_response_code(401); // Unauthorized
                }
            } else {
                $response['message'] = "Username not found.";
                http_response_code(404); // Not Found
            }
        } catch (Exception $e) {
            $response['message'] = "Server error: " . $e->getMessage();
            http_response_code(500); // Internal Server Error
        } finally {
            if ($stmt) {
                $stmt->close();
            }
        }
    }
} else {
    $response['message'] = "Invalid request method. Only POST is allowed.";
    http_response_code(405); // Method Not Allowed
}

$conn->close();
echo json_encode($response);
exit;
?>