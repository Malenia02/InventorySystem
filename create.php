<?php
require $_SERVER['DOCUMENT_ROOT'] . '/inventory_system/config/config.php';

$username = 'admin'; // your admin username
$password = 'admin123'; // your desired password
$role = 'admin'; // user role
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

try {
    $stmt = $conn->prepare("INSERT INTO $table_users ($user_username, $user_password, $user_role) VALUES (:username, :password, :role)");
    $stmt->execute([
        'username' => $username,
        'password' => $hashed_password,
        'role' => $role
    ]);
    echo "Admin user created successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
