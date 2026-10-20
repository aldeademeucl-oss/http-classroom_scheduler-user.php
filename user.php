<?php
require __DIR__ . '/config.php';

if (!empty($_SESSION['admin_id'])) {
    redirect('index.php');
}

$mode = (string) ($_GET['mode'] ?? 'login');
if (!in_array($mode, ['login', 'register'], true)) {
    $mode = 'login';
}
$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'register') {
        $mode = 'register';
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
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
                    "INSERT INTO admins (name, email, password_hash, account_type) VALUES (?, ?, ?, 'user')"
                );
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                session_regenerate_id(true);
                unset($_SESSION['admin_id'], $_SESSION['admin_name']);
                $_SESSION['user_id'] = (int) $pdo->lastInsertId();
                $_SESSION['user_name'] = $name;
                redirect('user.php');
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $errors[] = 'An account with that email already exists.';
            }
        }
    } elseif ($action === 'login') {
        $mode = 'login';
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $pdo->prepare("SELECT id, name, password_hash FROM admins WHERE email = ? AND account_type = 'user'");
        $statement->execute([$email]);
        $user = $statement->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['admin_id'], $_SESSION['admin_name']);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_name'] = $user['name'];
            redirect('user.php');
        }
        $errors[] = 'Email or password is incorrect.';
    } elseif ($action === 'logout') {
        unset($_SESSION['user_id'], $_SESSION['user_name']);
        session_regenerate_id(true);
        flash('You have been signed out.');
        redirect('user.php');
    } elseif ($action === 'create_reservation' || $action === 'cancel_reservation') {
        if (empty($_SESSION['user_id'])) {
            redirect('user.php');
        }

        try {
            if ($action === 'create_reservation') {
                $roomId = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT);
                $title = trim((string) ($_POST['title'] ?? ''));
                $department = trim((string) ($_POST['department'] ?? ''));
                $date = (string) ($_POST['reservation_date'] ?? '');
                $startTime = (string) ($_POST['start_time'] ?? '');
                $endTime = (string) ($_POST['end_time'] ?? '');

                if (!$roomId || $title === '' || strlen($title) > 180 || strlen($department) > 120) {
                    throw new InvalidArgumentException('Enter an event name, choose a room, and check the field lengths.');
                }
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
                if (!$dateObject || $dateObject->format('Y-m-d') !== $date
                    || !preg_match($timePattern, $startTime)
                    || !preg_match($timePattern, $endTime)) {
                    throw new InvalidArgumentException('Choose a valid date and start/end time.');
                }
                if ($date < date('Y-m-d')) {
                    throw new InvalidArgumentException('Choose today or a future date for your reservation.');
                }
                $startsAt = $date . ' ' . $startTime . ':00';
                $endsAt = $date . ' ' . $endTime . ':00';
                if ($startsAt >= $endsAt) {
                    throw new InvalidArgumentException('The end time must be later than the start time.');
                }

                $pdo->beginTransaction();
                $roomLock = $pdo->prepare('SELECT id FROM rooms WHERE id = ? AND is_active = 1 FOR UPDATE');
                $roomLock->execute([$roomId]);
                if (!$roomLock->fetch()) {
                    throw new InvalidArgumentException('That room is not available. Refresh the page and choose another room.');
                }
                $conflict = $pdo->prepare(
                    "SELECT id FROM reservations
                     WHERE room_id = ? AND status IN ('pending', 'approved')
                       AND starts_at < ? AND ends_at > ?
                     LIMIT 1"
                );
                $conflict->execute([$roomId, $endsAt, $startsAt]);
                if ($conflict->fetch()) {
                    throw new InvalidArgumentException('That room already has a pending or approved reservation during this time.');
                }

                $statement = $pdo->prepare(
                    'INSERT INTO reservations
                        (room_id, title, organizer, department, starts_at, ends_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([
                    $roomId, $title, $_SESSION['user_name'], $department,
                    $startsAt, $endsAt, $_SESSION['user_id'],
                ]);
                $pdo->commit();
                flash('Your reservation request was sent to the administrator for approval.');
            } else {
                $reservationId = filter_var($_POST['reservation_id'] ?? '', FILTER_VALIDATE_INT);
                if (!$reservationId) {
                    throw new InvalidArgumentException('Choose a valid reservation.');
                }
                $statement = $pdo->prepare(
                    "UPDATE reservations SET status = 'cancelled'
                     WHERE id = ? AND created_by = ? AND status IN ('pending', 'approved')
                       AND starts_at > NOW()"
                );
                $statement->execute([$reservationId, $_SESSION['user_id']]);
                if ($statement->rowCount() !== 1) {
                    throw new InvalidArgumentException('That reservation cannot be cancelled.');
                }
                flash('Your reservation was cancelled.');
            }
        } catch (InvalidArgumentException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash($exception->getMessage(), 'error');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('The request could not be saved. Please check the details and try again.', 'error');
        }
        redirect('user.php');
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (empty($_SESSION['user_id'])):
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $mode === 'register' ? 'Create an account' : 'User sign in' ?> · PUCL Campus Space</title>
    <link rel="icon" type="image/png" href="assets/campusspace-mark.png">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand brand-dark" href="user.php"><img class="brand-mark" src="assets/campusspace-mark.png" alt=""> PUCL Campus Space</a>
        <p class="eyebrow">STUDENT & STAFF ACCESS</p>
        <h1><?= $mode === 'register' ? 'Create your account' : 'Request a campus space' ?></h1>
        <p class="muted"><?= $mode === 'register' ? 'Register to request classroom and laboratory schedules.' : 'Sign in to request a room and track your reservations.' ?></p>
        <?php if ($flash): ?><div class="notice notice-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="notice notice-error"><?= h($error) ?></div><?php endforeach; ?>
        <?php if ($mode === 'register'): ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="register">
                <label>Full name<input name="name" maxlength="120" required value="<?= h($name) ?>" autocomplete="name"></label>
                <label>Email address<input name="email" type="email" maxlength="190" required value="<?= h($email) ?>" autocomplete="email"></label>
                <label>Password <span class="field-hint">At least 12 characters</span><input name="password" type="password" minlength="12" required autocomplete="new-password"></label>
                <label>Confirm password<input name="confirm_password" type="password" minlength="12" required autocomplete="new-password"></label>
                <button class="button button-primary button-block" type="submit">Create user account</button>
            </form>
            <p class="auth-footnote">Already registered? <a href="user.php">Sign in</a></p>
        <?php else: ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Email address<input name="email" type="email" required value="<?= h($email) ?>" autocomplete="username"></label>
                <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
                <button class="button button-primary button-block" type="submit">Sign in</button>
            </form>
            <p class="auth-footnote">Need an account? <a href="user.php?mode=register">Register here</a></p>
            <p class="auth-footnote">Administrator? <a href="index.php">Sign in to the admin dashboard</a></p>
        <?php endif; ?>
    </main>
</body>
</html>
<?php
exit;
endif;

$activeRooms = $pdo->query('SELECT id, name, building, room_type, capacity FROM rooms WHERE is_active = 1 ORDER BY building, name')->fetchAll();
$reservationsQuery = $pdo->prepare(
    'SELECT reservations.*, rooms.name AS room_name, rooms.building
     FROM reservations JOIN rooms ON rooms.id = reservations.room_id
     WHERE reservations.created_by = ?
     ORDER BY reservations.starts_at DESC LIMIT 30'
);
$reservationsQuery->execute([$_SESSION['user_id']]);
$reservations = $reservationsQuery->fetchAll();

$availability = [];
$availabilityError = '';
$searchDate = (string) ($_GET['date'] ?? date('Y-m-d'));
$searchStart = (string) ($_GET['start_time'] ?? '09:00');
$searchEnd = (string) ($_GET['end_time'] ?? '10:00');
if (isset($_GET['search'])) {
    $dateObject = DateTime::createFromFormat('!Y-m-d', $searchDate);
    $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
    if (!$dateObject || $dateObject->format('Y-m-d') !== $searchDate
        || !preg_match($timePattern, $searchStart)
        || !preg_match($timePattern, $searchEnd)
        || $searchStart >= $searchEnd) {
        $availabilityError = 'Choose a valid date and time range.';
    } else {
        $availabilityQuery = $pdo->prepare(
            "SELECT rooms.* FROM rooms
             WHERE rooms.is_active = 1 AND NOT EXISTS (
                 SELECT 1 FROM reservations
                 WHERE reservations.room_id = rooms.id
                   AND reservations.status IN ('pending', 'approved')
                   AND reservations.starts_at < ? AND reservations.ends_at > ?
             )
             ORDER BY rooms.building, rooms.name"
        );
        $availabilityQuery->execute([
            $searchDate . ' ' . $searchEnd . ':00',
            $searchDate . ' ' . $searchStart . ':00',
        ]);
        $availability = $availabilityQuery->fetchAll();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My requests · PUCL Campus Space</title>
    <link rel="icon" type="image/png" href="assets/campusspace-mark.png">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="app-page">
<aside class="sidebar">
    <a class="brand" href="user.php"><img class="brand-mark" src="assets/campusspace-mark.png" alt=""> PUCL Campus Space</a>
    <div class="side-caption">MY SPACE</div>
    <nav class="side-nav"><a class="active" href="user.php"><span class="nav-icon">▤</span>My requests</a><a href="user.php#find-room"><span class="nav-icon">⌕</span>Find availability</a></nav>
    <div class="sidebar-bottom">
        <div class="profile-row"><span class="avatar"><?= h(strtoupper(substr((string) $_SESSION['user_name'], 0, 1))) ?></span><span><strong><?= h($_SESSION['user_name']) ?></strong><small>Campus user</small></span></div>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="logout-button" type="submit">Sign out</button></form>
    </div>
</aside>
<main class="main-content">
    <header class="topbar"><div><p class="eyebrow">STUDENT & STAFF PORTAL</p><h1>My schedule requests</h1><p class="muted">Find a room, send a request, and track its approval status.</p></div><div class="today-chip"><span class="status-dot"></span><?= h(date('D, M j, Y')) ?></div></header>
    <section class="content-area">
        <?php if ($flash): ?><div class="notice notice-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <div class="two-column booking-layout">
            <section class="panel" id="find-room">
                <div class="panel-heading"><div><h2>Request a room</h2><p>Requests are sent to an administrator for approval.</p></div></div>
                <?php if (!$activeRooms): ?><div class="empty-state"><span class="empty-icon">▦</span><strong>No rooms are available yet</strong><p>Please check back after the administrator adds spaces.</p></div>
                <?php else: ?><form method="post" class="form-stack compact-form">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_reservation">
                    <label>Event or class name<input name="title" maxlength="180" required placeholder="e.g. Intro to Programming"></label>
                    <label>Room<select name="room_id" required><option value="">Choose a space</option><?php foreach ($activeRooms as $room): ?><option value="<?= h($room['id']) ?>"><?= h($room['name']) ?> · <?= h($room['building']) ?> (<?= h($room['capacity']) ?> seats)</option><?php endforeach; ?></select></label>
                    <label>Department <span class="field-hint">Optional</span><input name="department" maxlength="120" placeholder="e.g. Computer Science"></label>
                    <label>Date<input name="reservation_date" type="date" required min="<?= h(date('Y-m-d')) ?>" value="<?= h(date('Y-m-d')) ?>"></label>
                    <div class="form-row"><label>Start time<input name="start_time" type="time" required value="09:00"></label><label>End time<input name="end_time" type="time" required value="10:00"></label></div>
                    <button class="button button-primary button-block" type="submit">Send reservation request</button>
                    <p class="form-note">Pending and approved bookings block the same room and time slot.</p>
                </form><?php endif; ?>
            </section>
            <section class="panel">
                <div class="panel-heading"><div><h2>Find available rooms</h2><p>Search active spaces before making a request.</p></div></div>
                <form method="get" class="form-stack compact-form">
                    <input type="hidden" name="search" value="1">
                    <label>Date<input type="date" name="date" required value="<?= h($searchDate) ?>"></label>
                    <div class="form-row"><label>Starts at<input type="time" name="start_time" required value="<?= h($searchStart) ?>"></label><label>Ends at<input type="time" name="end_time" required value="<?= h($searchEnd) ?>"></label></div>
                    <button class="button button-secondary button-block" type="submit">Search availability</button>
                </form>
                <?php if ($availabilityError): ?><div class="notice notice-error"><?= h($availabilityError) ?></div>
                <?php elseif (isset($_GET['search'])): ?><div class="availability-results"><?php if (!$availability): ?><p class="muted">No rooms available for that time range.</p><?php else: ?><strong><?= count($availability) ?> <?= count($availability) === 1 ? 'room' : 'rooms' ?> available</strong><?php foreach ($availability as $room): ?><div class="availability-room"><span class="room-symbol"><?= $room['room_type'] === 'computer_lab' ? '⌘' : '▦' ?></span><span><strong><?= h($room['name']) ?></strong><small><?= h($room['building']) ?> · <?= h($room['capacity']) ?> seats</small></span></div><?php endforeach; ?><?php endif; ?></div><?php endif; ?>
            </section>
        </div>

        <section class="panel user-reservations">
            <div class="panel-heading"><div><h2>My reservations <span class="heading-count"><?= count($reservations) ?></span></h2><p>Only your own schedule requests are shown here.</p></div></div>
            <?php if (!$reservations): ?><div class="empty-state"><span class="empty-icon">◷</span><strong>You have no reservation requests yet</strong><p>Use the form above to request a classroom or lab.</p></div>
            <?php else: ?><div class="table-wrap"><table><thead><tr><th>EVENT</th><th>ROOM</th><th>DATE & TIME</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
                <?php foreach ($reservations as $reservation): ?><tr>
                    <td><strong><?= h($reservation['title']) ?></strong><small><?= h($reservation['department'] ?: 'No department specified') ?></small></td>
                    <td><?= h($reservation['room_name']) ?><small><?= h($reservation['building']) ?></small></td>
                    <td><?= h(date('M j, Y', strtotime($reservation['starts_at']))) ?><small><?= h(date('g:i A', strtotime($reservation['starts_at']))) ?>–<?= h(date('g:i A', strtotime($reservation['ends_at']))) ?></small></td>
                    <td><span class="badge badge-<?= h($reservation['status']) ?>"><?= h(ucfirst($reservation['status'])) ?></span></td>
                    <td><?php if (in_array($reservation['status'], ['pending', 'approved'], true) && strtotime($reservation['starts_at']) > time()): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="cancel_reservation"><input type="hidden" name="reservation_id" value="<?= h($reservation['id']) ?>"><button class="small-link danger-link" type="submit">Cancel</button></form><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                </tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    </section>
    <footer class="page-footer"><span>PUCL Campus Space · Room & laboratory scheduling</span><a class="small-link" href="index.php">Administrator sign in</a></footer>
</main>
</body>
</html>
