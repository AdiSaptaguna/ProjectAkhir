<?php
session_start(); // Start or resume the session
header('Content-Type: application/json'); // Set content type to JSON

// --- CORS Headers for Development (Crucial for React to PHP communication) ---
// Allow requests from your React development server (e.g., http://localhost:3000)
header("Access-Control-Allow-Origin: http://localhost:3000");
// Allow GET, POST, OPTIONS methods
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
// Allow specific headers like Content-Type
header("Access-Control-Allow-Headers: Content-Type, Authorization");
// Allow credentials (like cookies, for sessions) to be sent
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request (sent by browser before actual GET/POST)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}
// --------------------------------------------------------------------------

include '../api/koneksi.php'; // Ensure this path is correct relative to profile.php

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Check if the user is logged in (username is set in session)
        if (!isset($_SESSION['username'])) {
            $response['message'] = 'You are not logged in.';
            // Set an appropriate HTTP status code for unauthorized access
            http_response_code(401);
            break;
        }

        $username = $_SESSION['username']; // Get username from session
        
        // Prepare and execute a SQL statement to prevent SQL injection
        $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE username = ?");
        
        // Check if statement preparation was successful
        if ($stmt === false) {
            $response['message'] = 'Database error: Unable to prepare statement.';
            error_log('MySQL prepare failed: ' . $conn->error); // Log error for debugging
            http_response_code(500);
            break;
        }

        $stmt->bind_param("s", $username); // Bind the username parameter
        $stmt->execute(); // Execute the query
        $result = $stmt->get_result(); // Get the result set

        if ($user = $result->fetch_assoc()) {
            // User found
            $response['success'] = true;
            $response['message'] = 'Profile data successfully retrieved.';
            // Do not send sensitive info like password hashes!
            $response['data'] = $user; 
            http_response_code(200); // OK
        } else {
            // User not found in database (even if session has username)
            $response['message'] = 'User not found in the database.';
            http_response_code(404); // Not Found
        }
        $stmt->close(); // Close the statement
        break;

    default:
        // Respond to unsupported methods
        $response['message'] = 'Method not supported.';
        http_response_code(405); // Method Not Allowed
        break;
}

$conn->close(); // Close the database connection

echo json_encode($response); // Output the JSON response
?>