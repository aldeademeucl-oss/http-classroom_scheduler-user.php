<?php
require __DIR__ . '/config.php';

$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE account_type = 'admin'")->fetchColumn();
if ($adminCount > 0) {
    redirect('index.php');
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || strlen($name) > 120) {
        $errors[] = 'Enter your name (up to 120 characters).';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 12) {
        $errors[] = 'Use a password with at least 12 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'The passwords do not match.';
    }

    if (!$errors) {
        try {
            $statement = $pdo->prepare(
                "INSERT INTO admins (name, email, password_hash, account_type) VALUES (?, ?, ?, 'admin')"
            );
            $statement->execute([$name, strtolower($email), password_hash($password, PASSWORD_DEFAULT)]);
            flash('Administrator account created. Sign in to continue.');
            redirect('index.php');
        } catch (PDOException $exception) {
            $errors[] = 'The administrator account could not be created. Check that the email is unique.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Initial setup · PUCL Campus Space</title>
    <link rel="icon" type="image/png" href="assets/campusspace-mark.png">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand brand-dark" href="index.php"><img class="brand-mark" src="assets/campusspace-mark.png" alt=""> PUCL Campus Space</a>
        <p class="eyebrow">FIRST-TIME SETUP</p>
        <h1>Create your administrator account</h1>
        <p class="muted">This account manages rooms, reservations, and utilization reports.</p>
        <?php foreach ($errors as $error): ?>
            <div class="notice notice-error"><?= h($error) ?></div>
        <?php endforeach; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <label>Full name<input name="name" maxlength="120" required value="<?= h($name) ?>" autocomplete="name"></label>
            <label>Email address<input name="email" type="email" maxlength="190" required value="<?= h($email) ?>" autocomplete="email"></label>
            <label>Password <span class="field-hint">At least 12 characters</span><input name="password" type="password" minlength="12" required autocomplete="new-password"></label>
            <label>Confirm password<input name="confirm_password" type="password" minlength="12" required autocomplete="new-password"></label>
            <button class="button button-primary button-block" type="submit">Create administrator</button>
        </form>
        <p class="auth-footnote">Setup closes automatically after the first account is created.</p>
    </main>
</body>
</html>
