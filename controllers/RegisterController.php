<?php

class RegisterController
{
    // ======================
    // REGISTER
    // ======================
    public static function register(
        PDO $conn,
        string $table,
        string $usernameCol,
        string $passwordCol,
        string $roleCol
    ) {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            return "Username and password are required.";
        }

        if (strlen($password) < 6) {
            return "Password must be at least 6 characters.";
        }

        // Check if username already exists
        $check = $conn->prepare(
            "SELECT 1 FROM $table WHERE $usernameCol = :username"
        );
        $check->execute(['username' => $username]);

        if ($check->fetch()) {
            return "Username already exists.";
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        // Insert user
        $stmt = $conn->prepare(
            "INSERT INTO $table ($usernameCol, $passwordCol, $roleCol)
             VALUES (:username, :password, 'staff')"
        );

        $stmt->execute([
            'username' => $username,
            'password' => $hashedPassword
        ]);

        return null; // success
    }
}

?>