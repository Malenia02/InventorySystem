<?php

class AuthController
{
    public static function login(
        PDO $conn,
        string $table_users,
        string $user_username,
        string $user_password,
        string $user_id,
        string $user_role,
        string $user_status // add this for checking active/inactive
    ) {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($username === '' || $password === '') {
            return "Username and password are required.";
        }

        try {
            $stmt = $conn->prepare(
                "SELECT * FROM {$table_users} WHERE {$user_username} = :username LIMIT 1"
            );
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user[$user_password])) {
                return "Invalid username or password.";
            }

            // Prevent login if staff is inactive
            if (isset($user[$user_status]) && $user[$user_status] !== 'active') {
                return "Your account is inactive. Please contact admin.";
            }

            // Login success
            $_SESSION['user_id']  = $user[$user_id];
            $_SESSION['username'] = $user[$user_username];
            $_SESSION['role']     = $user[$user_role];

            if (!empty($_POST['remember'])) {
                setcookie(
                    'user_id',
                    $user[$user_id],
                    time() + (86400 * 30),
                    "/"
                );
            }

            header("Location: /inventory_system/index.php");
            exit;

        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            return "A database error occurred. Please try again later.";
        }
    }
}
?>