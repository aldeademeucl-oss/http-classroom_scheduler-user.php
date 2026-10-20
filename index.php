<?php
require __DIR__ . '/config.php';

$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE account_type = 'admin'")->fetchColumn();
if ($adminCount === 0) {
    redirect('setup.php');
}
if (!empty($_SESSION['user_id']) && empty($_SESSION['admin_id'])) {
    redirect('user.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $pdo->prepare("SELECT id, name, password_hash FROM admins WHERE email = ? AND account_type = 'admin'");
        $statement->execute([$email]);
        $admin = $statement->fetch();
        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['user_id'], $_SESSION['user_name']);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_name'] = $admin['name'];
            redirect('index.php');
        }
        $errors[] = 'Email or password is incorrect.';
    } elseif ($action === 'logout') {
        require_admin();
        $_SESSION = [];
        session_regenerate_id(true);
        flash('You have been signed out.');
        redirect('index.php');
    } else {
        require_admin();
        try {
            if ($action === 'save_room') {
                $roomId = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT);
                $roomName = trim((string) ($_POST['name'] ?? ''));
                $building = trim((string) ($_POST['building'] ?? ''));
                $roomType = (string) ($_POST['room_type'] ?? '');
                $capacity = filter_var($_POST['capacity'] ?? '', FILTER_VALIDATE_INT);
                $equipment = trim((string) ($_POST['equipment'] ?? ''));

                if ($roomName === '' || strlen($roomName) > 120 || $building === '' || strlen($building) > 120) {
                    throw new InvalidArgumentException('Room name and building are required (up to 120 characters).');
                }
                if (!in_array($roomType, ['classroom', 'computer_lab'], true)) {
                    throw new InvalidArgumentException('Choose a valid room type.');
                }
                if ($capacity === false || $capacity < 1 || $capacity > 65535 || strlen($equipment) > 500) {
                    throw new InvalidArgumentException('Enter a valid capacity and equipment list.');
                }

                if ($roomId) {
                    $statement = $pdo->prepare(
                        'UPDATE rooms SET name = ?, building = ?, room_type = ?, capacity = ?, equipment = ? WHERE id = ?'
                    );
                    $statement->execute([$roomName, $building, $roomType, $capacity, $equipment, $roomId]);
                    flash('Room details updated.');
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO rooms (name, building, room_type, capacity, equipment) VALUES (?, ?, ?, ?, ?)'
                    );
                    $statement->execute([$roomName, $building, $roomType, $capacity, $equipment]);
                    flash('Room added to the directory.');
                }
            } elseif ($action === 'toggle_room') {
                $roomId = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT);
                if (!$roomId) {
                    throw new InvalidArgumentException('Choose a valid room.');
                }
                $statement = $pdo->prepare('UPDATE rooms SET is_active = NOT is_active WHERE id = ?');
                $statement->execute([$roomId]);
                flash('Room availability status updated.');
            } elseif ($action === 'create_reservation') {
                $roomId = filter_var($_POST['room_id'] ?? '', FILTER_VALIDATE_INT);
                $title = trim((string) ($_POST['title'] ?? ''));
                $organizer = trim((string) ($_POST['organizer'] ?? ''));
                $department = trim((string) ($_POST['department'] ?? ''));
                $date = (string) ($_POST['reservation_date'] ?? '');
                $startTime = (string) ($_POST['start_time'] ?? '');
                $endTime = (string) ($_POST['end_time'] ?? '');

                if (!$roomId || $title === '' || strlen($title) > 180 || $organizer === '' || strlen($organizer) > 120 || strlen($department) > 120) {
                    throw new InvalidArgumentException('Complete the reservation details using the allowed field lengths.');
                }
                $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
                $timePattern = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';
                if (!$dateObject || $dateObject->format('Y-m-d') !== $date || !preg_match($timePattern, $startTime) || !preg_match($timePattern, $endTime)) {
                    throw new InvalidArgumentException('Choose a valid date and start/end time.');
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
                    throw new InvalidArgumentException('That room is not active. Refresh the page and choose another room.');
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
                    'INSERT INTO reservations (room_id, title, organizer, department, starts_at, ends_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([
                    $roomId, $title, $organizer, $department, $startsAt, $endsAt, $_SESSION['admin_id'],
                ]);
                $pdo->commit();
                flash('Reservation submitted as pending approval.');
            } elseif ($action === 'reservation_status') {
                $reservationId = filter_var($_POST['reservation_id'] ?? '', FILTER_VALIDATE_INT);
                $newStatus = (string) ($_POST['status'] ?? '');
                if (!$reservationId || !in_array($newStatus, ['approved', 'cancelled'], true)) {
                    throw new InvalidArgumentException('Choose a valid reservation and status.');
                }

                $pdo->beginTransaction();
                $reservationQuery = $pdo->prepare(
                    'SELECT room_id, starts_at, ends_at, status FROM reservations WHERE id = ? FOR UPDATE'
                );
                $reservationQuery->execute([$reservationId]);
                $reservation = $reservationQuery->fetch();
                if (!$reservation) {
                    throw new InvalidArgumentException('Reservation not found.');
                }
                if ($newStatus === 'approved' && $reservation['status'] !== 'approved') {
                    $roomLock = $pdo->prepare('SELECT id FROM rooms WHERE id = ? FOR UPDATE');
                    $roomLock->execute([$reservation['room_id']]);
                    $conflict = $pdo->prepare(
                        "SELECT id FROM reservations
                         WHERE room_id = ? AND id <> ? AND status IN ('pending', 'approved')
                           AND starts_at < ? AND ends_at > ?
                         LIMIT 1"
                    );
                    $conflict->execute([
                        $reservation['room_id'], $reservationId, $reservation['ends_at'], $reservation['starts_at'],
                    ]);
                    if ($conflict->fetch()) {
                        throw new InvalidArgumentException('This reservation now overlaps another active booking and cannot be approved.');
                    }
                }
                $statement = $pdo->prepare('UPDATE reservations SET status = ? WHERE id = ?');
                $statement->execute([$newStatus, $reservationId]);
                $pdo->commit();
                flash($newStatus === 'approved' ? 'Reservation approved.' : 'Reservation cancelled.');
            } else {
                throw new InvalidArgumentException('Unknown action.');
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
            $message = $action === 'save_room'
                ? 'Could not save that room. Room names must be unique.'
                : 'The request could not be saved. Please check the details and try again.';
            flash($message, 'error');
        }
        redirect('index.php?page=' . rawurlencode((string) ($_POST['return_page'] ?? 'dashboard')));
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (empty($_SESSION['admin_id'])):
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · PUCL Campus Space</title>
    <link rel="icon" type="image/png" href="assets/campusspace-mark.png">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand brand-dark" href="index.php"><img class="brand-mark" src="assets/campusspace-mark.png" alt=""> PUCL Campus Space</a>
        <p class="eyebrow">ROOM & LAB SCHEDULING</p>
        <h1>Welcome back</h1>
        <p class="muted">Sign in to manage campus spaces and reservations.</p>
        <?php if ($flash): ?><div class="notice notice-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php foreach ($errors as $error): ?><div class="notice notice-error"><?= h($error) ?></div><?php endforeach; ?>
        <form method="post" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="login">
            <label>Email address<input name="email" type="email" required autocomplete="username"></label>
            <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
            <button class="button button-primary button-block" type="submit">Sign in</button>
        </form>
        <p class="auth-footnote">Requesting a room? <a href="user.php">Sign in or create a user account</a></p>
        <p class="auth-footnote">First time here? <a href="setup.php">Create the administrator account</a></p>
    </main>
</body>
</html>
<?php
exit;
endif;

$page = (string) ($_GET['page'] ?? 'dashboard');
$validPages = ['dashboard', 'rooms', 'reservations', 'availability', 'reports'];
if (!in_array($page, $validPages, true)) {
    $page = 'dashboard';
}

$roomCount = (int) $pdo->query('SELECT COUNT(*) FROM rooms WHERE is_active = 1')->fetchColumn();
$todayCountQuery = $pdo->prepare(
    "SELECT COUNT(*) FROM reservations WHERE DATE(starts_at) = CURDATE() AND status IN ('pending', 'approved')"
);
$todayCountQuery->execute();
$todayCount = (int) $todayCountQuery->fetchColumn();
$pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
$utilizationQuery = $pdo->query(
    "SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, starts_at, ends_at)), 0)
     FROM reservations WHERE status = 'approved'
       AND starts_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
       AND starts_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)"
);
$monthHours = round((int) $utilizationQuery->fetchColumn() / 60, 1);

$roomList = $pdo->query('SELECT * FROM rooms ORDER BY is_active DESC, building, name')->fetchAll();
$upcomingQuery = $pdo->query(
    "SELECT r.*, rooms.name AS room_name, rooms.building
     FROM reservations r JOIN rooms ON rooms.id = r.room_id
     WHERE r.ends_at >= NOW() AND r.status IN ('pending', 'approved')
     ORDER BY r.starts_at ASC LIMIT 8"
);
$upcomingReservations = $upcomingQuery->fetchAll();

$filterDate = (string) ($_GET['date'] ?? date('Y-m-d'));
$filterStart = '09:00';
$filterEnd = '10:00';
$minCapacity = 0;
$filterType = '';
$availabilityRooms = [];
$availabilityError = '';
if ($page === 'availability') {
    $filterStart = (string) ($_GET['start_time'] ?? '09:00');
    $filterEnd = (string) ($_GET['end_time'] ?? '10:00');
    $minCapacity = max(0, (int) ($_GET['capacity'] ?? 0));
    $filterType = (string) ($_GET['room_type'] ?? '');
    $validDate = DateTime::createFromFormat('!Y-m-d', $filterDate);
    if (!$validDate || $validDate->format('Y-m-d') !== $filterDate
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $filterStart)
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $filterEnd)
        || $filterStart >= $filterEnd
        || !in_array($filterType, ['', 'classroom', 'computer_lab'], true)) {
        $availabilityError = 'Choose a valid date, room type, and time range.';
    } else {
        $startAt = $filterDate . ' ' . $filterStart . ':00';
        $endAt = $filterDate . ' ' . $filterEnd . ':00';
        $availabilityStatement = $pdo->prepare(
            "SELECT rooms.* FROM rooms
             WHERE rooms.is_active = 1 AND rooms.capacity >= ?
               AND (? = '' OR rooms.room_type = ?)
               AND NOT EXISTS (
                   SELECT 1 FROM reservations
                   WHERE reservations.room_id = rooms.id
                     AND reservations.status IN ('pending', 'approved')
                     AND reservations.starts_at < ? AND reservations.ends_at > ?
               )
             ORDER BY rooms.building, rooms.name"
        );
        $availabilityStatement->execute([
            $minCapacity, $filterType, $filterType, $endAt, $startAt,
        ]);
        $availabilityRooms = $availabilityStatement->fetchAll();
    }
}

$scheduleDate = (string) ($_GET['date'] ?? date('Y-m-d'));
$monthSchedule = [];
$monthFirst = null;
$monthPrevious = null;
$monthNext = null;
$calendarDays = 0;
$calendarOffset = 0;
$dayReservations = [];
if ($page === 'reservations') {
    $scheduleDateObject = DateTime::createFromFormat('!Y-m-d', $scheduleDate);
    if (!$scheduleDateObject || $scheduleDateObject->format('Y-m-d') !== $scheduleDate) {
        $scheduleDate = date('Y-m-d');
        $scheduleDateObject = new DateTime($scheduleDate);
    }
    $monthFirst = (clone $scheduleDateObject)->modify('first day of this month')->setTime(0, 0);
    $monthPrevious = (clone $monthFirst)->modify('-1 month')->format('Y-m-d');
    $monthNext = (clone $monthFirst)->modify('+1 month')->format('Y-m-d');
    $nextMonthFirst = (clone $monthFirst)->modify('+1 month');
    $calendarDays = (int) $monthFirst->format('t');
    $calendarOffset = (int) $monthFirst->format('N') - 1;
    $monthQuery = $pdo->prepare(
        'SELECT r.id, r.title, r.starts_at, r.status, rooms.name AS room_name
         FROM reservations r JOIN rooms ON rooms.id = r.room_id
         WHERE r.starts_at >= ? AND r.starts_at < ?
         ORDER BY r.starts_at'
    );
    $monthQuery->execute([$monthFirst->format('Y-m-d H:i:s'), $nextMonthFirst->format('Y-m-d H:i:s')]);
    foreach ($monthQuery->fetchAll() as $monthReservation) {
        $dayKey = date('Y-m-d', strtotime($monthReservation['starts_at']));
        $monthSchedule[$dayKey][] = $monthReservation;
    }
    $scheduleQuery = $pdo->prepare(
        'SELECT r.*, rooms.name AS room_name, rooms.building FROM reservations r
         JOIN rooms ON rooms.id = r.room_id
         WHERE DATE(r.starts_at) = ? ORDER BY r.starts_at'
    );
    $scheduleQuery->execute([$scheduleDate]);
    $dayReservations = $scheduleQuery->fetchAll();
}

$reportRows = [];
if ($page === 'reports') {
    $reportRows = $pdo->query(
        "SELECT rooms.id, rooms.name, rooms.building, rooms.room_type, rooms.capacity,
                COUNT(reservations.id) AS booking_count,
                COALESCE(SUM(TIMESTAMPDIFF(MINUTE, reservations.starts_at, reservations.ends_at)), 0) / 60 AS booked_hours
         FROM rooms LEFT JOIN reservations
           ON reservations.room_id = rooms.id AND reservations.status = 'approved'
           AND reservations.starts_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
           AND reservations.starts_at < DATE_ADD(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 1 MONTH)
         GROUP BY rooms.id ORDER BY booked_hours DESC, rooms.name"
    )->fetchAll();
}

$editRoom = null;
if ($page === 'rooms' && isset($_GET['edit'])) {
    $editRoomId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editRoomId) {
        $editStatement = $pdo->prepare('SELECT * FROM rooms WHERE id = ?');
        $editStatement->execute([$editRoomId]);
        $editRoom = $editStatement->fetch() ?: null;
    }
}

$pageTitles = [
    'dashboard' => ['Overview', 'Here is what is happening across your campus spaces.'],
    'rooms' => ['Room directory', 'Manage classrooms and computer laboratories.'],
    'reservations' => ['Reservations', 'Review bookings and see the daily room schedule.'],
    'availability' => ['Find a space', 'Search for rooms available for a specific time and group size.'],
    'reports' => ['Utilization reports', 'Compare approved room bookings for the current month.'],
];
$pageTitle = $pageTitles[$page][0];
$pageDescription = $pageTitles[$page][1];
$navItems = [
    'dashboard' => ['Overview', '⌂'],
    'rooms' => ['Rooms', '▦'],
    'reservations' => ['Reservations', '▤'],
    'availability' => ['Find availability', '⌕'],
    'reports' => ['Utilization reports', '▥'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> · PUCL Campus Space</title>
    <link rel="icon" type="image/png" href="assets/campusspace-mark.png">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="app-page">
<aside class="sidebar">
    <a class="brand" href="index.php"><img class="brand-mark" src="assets/campusspace-mark.png" alt=""> PUCL Campus Space</a>
    <div class="side-caption">WORKSPACE</div>
    <nav class="side-nav">
        <?php foreach ($navItems as $key => $item): ?>
            <a class="<?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= h($key) ?>">
                <span class="nav-icon"><?= h($item[1]) ?></span><?= h($item[0]) ?>
                <?php if ($key === 'reservations' && $pendingCount > 0): ?><span class="nav-count"><?= $pendingCount ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-bottom">
        <div class="profile-row"><span class="avatar"><?= h(strtoupper(substr((string) $_SESSION['admin_name'], 0, 1))) ?></span><span><strong><?= h($_SESSION['admin_name']) ?></strong><small>Administrator</small></span></div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout">
            <button class="logout-button" type="submit">Sign out</button>
        </form>
    </div>
</aside>
<main class="main-content">
    <header class="topbar">
        <div><p class="eyebrow">CAMPUS OPERATIONS</p><h1><?= h($pageTitle) ?></h1><p class="muted"><?= h($pageDescription) ?></p></div>
        <div class="today-chip"><span class="status-dot"></span><?= h(date('D, M j, Y')) ?></div>
    </header>
    <section class="content-area">
        <?php if ($flash): ?><div class="notice notice-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

        <?php if ($page === 'dashboard'): ?>
            <div class="stat-grid">
                <article class="stat-card"><span class="stat-icon icon-violet">▦</span><span class="stat-label">ACTIVE SPACES</span><strong><?= $roomCount ?></strong><small>Classrooms and labs</small></article>
                <article class="stat-card"><span class="stat-icon icon-blue">◷</span><span class="stat-label">TODAY'S BOOKINGS</span><strong><?= $todayCount ?></strong><small>Pending or approved</small></article>
                <article class="stat-card"><span class="stat-icon icon-amber">◇</span><span class="stat-label">AWAITING REVIEW</span><strong><?= $pendingCount ?></strong><small>Reservations to review</small></article>
                <article class="stat-card"><span class="stat-icon icon-green">↗</span><span class="stat-label">BOOKED THIS MONTH</span><strong><?= h(number_format($monthHours, 1)) ?><span class="stat-unit">h</span></strong><small>Approved room hours</small></article>
            </div>
            <div class="panel-grid">
                <section class="panel">
                    <div class="panel-heading"><div><h2>Upcoming reservations</h2><p>Next scheduled use of your spaces</p></div><a class="text-link" href="index.php?page=reservations">View schedule <span>→</span></a></div>
                    <?php if (!$upcomingReservations): ?><div class="empty-state"><span class="empty-icon">◷</span><strong>No upcoming reservations</strong><p>New bookings will appear here.</p></div>
                    <?php else: ?><div class="table-wrap"><table><thead><tr><th>RESERVATION</th><th>ROOM</th><th>DATE & TIME</th><th>STATUS</th></tr></thead><tbody>
                        <?php foreach ($upcomingReservations as $reservation): ?><tr>
                            <td><strong><?= h($reservation['title']) ?></strong><small><?= h($reservation['organizer']) ?></small></td>
                            <td><?= h($reservation['room_name']) ?><small><?= h($reservation['building']) ?></small></td>
                            <td><?= h(date('M j, Y', strtotime($reservation['starts_at']))) ?><small><?= h(date('g:i A', strtotime($reservation['starts_at']))) ?>–<?= h(date('g:i A', strtotime($reservation['ends_at']))) ?></small></td>
                            <td><span class="badge badge-<?= h($reservation['status']) ?>"><?= h(ucfirst($reservation['status'])) ?></span></td>
                        </tr><?php endforeach; ?>
                    </tbody></table></div><?php endif; ?>
                </section>
                <section class="panel quick-panel">
                    <div class="panel-heading"><div><h2>Quick actions</h2><p>Common scheduling tasks</p></div></div>
                    <a class="quick-action" href="index.php?page=reservations#new-reservation"><span class="quick-icon icon-violet">＋</span><span><strong>New reservation</strong><small>Book a room or lab</small></span><b>→</b></a>
                    <a class="quick-action" href="index.php?page=rooms"><span class="quick-icon icon-blue">▦</span><span><strong>Manage spaces</strong><small>Add or update rooms</small></span><b>→</b></a>
                    <a class="quick-action" href="index.php?page=availability"><span class="quick-icon icon-green">⌕</span><span><strong>Find availability</strong><small>Search open time slots</small></span><b>→</b></a>
                    <div class="tip-box"><span>✦</span><p><strong>Conflict protection is on</strong><br>Overlapping pending and approved reservations are blocked automatically.</p></div>
                </section>
            </div>
            <div class="welcome-banner"><div><span class="eyebrow">SPACE PLANNING</span><h2>Make every room work smarter.</h2><p>Check availability before booking and keep campus schedules conflict-free.</p></div><a class="button button-light" href="index.php?page=availability">Search available spaces <span>→</span></a></div>

        <?php elseif ($page === 'rooms'): ?>
            <div class="two-column rooms-layout">
                <section class="panel">
                    <div class="panel-heading"><div><h2><?= $editRoom ? 'Edit space' : 'Add a space' ?></h2><p><?= $editRoom ? 'Update this room in the directory.' : 'Add a classroom or computer lab.' ?></p></div></div>
                    <form method="post" class="form-stack compact-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_room"><input type="hidden" name="return_page" value="rooms">
                        <input type="hidden" name="room_id" value="<?= h($editRoom['id'] ?? '') ?>">
                        <label>Room name<input name="name" maxlength="120" required placeholder="e.g. Science Hall 204" value="<?= h($editRoom['name'] ?? '') ?>"></label>
                        <label>Building<input name="building" maxlength="120" required placeholder="e.g. Science Hall" value="<?= h($editRoom['building'] ?? '') ?>"></label>
                        <div class="form-row">
                            <label>Space type<select name="room_type"><option value="classroom" <?= ($editRoom['room_type'] ?? '') === 'classroom' ? 'selected' : '' ?>>Classroom</option><option value="computer_lab" <?= ($editRoom['room_type'] ?? '') === 'computer_lab' ? 'selected' : '' ?>>Computer lab</option></select></label>
                            <label>Capacity<input type="number" name="capacity" min="1" max="65535" required value="<?= h($editRoom['capacity'] ?? '30') ?>"></label>
                        </div>
                        <label>Equipment <span class="field-hint">Separate items with commas</span><input name="equipment" maxlength="500" placeholder="Projector, whiteboard, 30 PCs" value="<?= h($editRoom['equipment'] ?? '') ?>"></label>
                        <div class="form-actions"><button class="button button-primary" type="submit"><?= $editRoom ? 'Save changes' : 'Add space' ?></button><?php if ($editRoom): ?><a class="button button-ghost" href="index.php?page=rooms">Cancel</a><?php endif; ?></div>
                    </form>
                </section>
                <section class="panel room-list-panel">
                    <div class="panel-heading"><div><h2>Space directory <span class="heading-count"><?= count($roomList) ?></span></h2><p>All registered rooms and laboratories</p></div></div>
                    <?php if (!$roomList): ?><div class="empty-state"><span class="empty-icon">▦</span><strong>Your directory is empty</strong><p>Add your first classroom or lab using the form.</p></div>
                    <?php else: ?><div class="room-list"><?php foreach ($roomList as $room): ?>
                        <article class="room-item">
                            <span class="room-symbol <?= $room['room_type'] === 'computer_lab' ? 'lab-symbol' : '' ?>"><?= $room['room_type'] === 'computer_lab' ? '⌘' : '▦' ?></span>
                            <div class="room-info"><div class="room-title"><?= h($room['name']) ?><?php if (!(int) $room['is_active']): ?> <span class="badge badge-cancelled">Inactive</span><?php endif; ?></div><p><?= h($room['building']) ?> · <?= h(ucfirst(str_replace('_', ' ', $room['room_type']))) ?> · <?= h($room['capacity']) ?> seats</p><small><?= h($room['equipment'] ?: 'No equipment listed') ?></small></div>
                            <div class="room-controls"><a href="index.php?page=rooms&edit=<?= h($room['id']) ?>" class="small-link">Edit</a><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="toggle_room"><input type="hidden" name="room_id" value="<?= h($room['id']) ?>"><input type="hidden" name="return_page" value="rooms"><button class="small-link <?= (int) $room['is_active'] ? 'danger-link' : '' ?>" type="submit"><?= (int) $room['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></div>
                        </article>
                    <?php endforeach; ?></div><?php endif; ?>
                </section>
            </div>

        <?php elseif ($page === 'reservations'): ?>
            <section class="panel calendar-panel">
                <div class="panel-heading calendar-heading">
                    <div><h2>Reservation calendar</h2><p>Select a day to review its schedule and requests.</p></div>
                    <div class="calendar-month"><a href="index.php?page=reservations&date=<?= h($monthPrevious) ?>" aria-label="Previous month">‹</a><strong><?= h($monthFirst->format('F Y')) ?></strong><a href="index.php?page=reservations&date=<?= h($monthNext) ?>" aria-label="Next month">›</a></div>
                </div>
                <div class="calendar-grid">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?><div class="calendar-weekday"><?= h($weekday) ?></div><?php endforeach; ?>
                    <?php for ($blank = 0; $blank < $calendarOffset; $blank++): ?><div class="calendar-cell calendar-empty"></div><?php endfor; ?>
                    <?php for ($day = 1; $day <= $calendarDays; $day++):
                        $calendarDate = $monthFirst->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
                        $dayEvents = $monthSchedule[$calendarDate] ?? [];
                    ?>
                        <a class="calendar-cell <?= $calendarDate === $scheduleDate ? 'calendar-selected' : '' ?> <?= $calendarDate === date('Y-m-d') ? 'calendar-today' : '' ?>" href="index.php?page=reservations&date=<?= h($calendarDate) ?>#daily-schedule">
                            <span class="calendar-day-number"><?= $day ?></span>
                            <?php foreach (array_slice($dayEvents, 0, 2) as $dayEvent): ?><span class="calendar-event event-<?= h($dayEvent['status']) ?>"><?= h(date('g:i', strtotime($dayEvent['starts_at']))) ?> <?= h($dayEvent['title']) ?></span><?php endforeach; ?>
                            <?php if (count($dayEvents) > 2): ?><span class="calendar-more">+<?= count($dayEvents) - 2 ?> more</span><?php endif; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </section>
            <div class="two-column booking-layout">
                <section class="panel" id="new-reservation">
                    <div class="panel-heading"><div><h2>New reservation</h2><p>Requests are created as pending approval.</p></div></div>
                    <form method="post" class="form-stack compact-form">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="create_reservation"><input type="hidden" name="return_page" value="reservations">
                        <label>Event or class name<input name="title" maxlength="180" required placeholder="e.g. Intro to Programming"></label>
                        <label>Room<select name="room_id" required><option value="">Choose a space</option><?php foreach ($roomList as $room): if ((int) $room['is_active']): ?><option value="<?= h($room['id']) ?>"><?= h($room['name']) ?> · <?= h($room['building']) ?> (<?= h($room['capacity']) ?> seats)</option><?php endif; endforeach; ?></select></label>
                        <label>Organizer<input name="organizer" maxlength="120" required placeholder="Instructor or staff name"></label>
                        <label>Department <span class="field-hint">Optional</span><input name="department" maxlength="120" placeholder="e.g. Computer Science"></label>
                        <label>Date<input name="reservation_date" type="date" required min="<?= h(date('Y-m-d')) ?>" value="<?= h(date('Y-m-d')) ?>"></label>
                        <div class="form-row"><label>Start time<input name="start_time" type="time" required value="09:00"></label><label>End time<input name="end_time" type="time" required value="10:00"></label></div>
                        <button class="button button-primary button-block" type="submit">Submit reservation request</button>
                        <p class="form-note">Overlapping pending or approved bookings are automatically rejected.</p>
                    </form>
                </section>
                <section class="panel daily-panel" id="daily-schedule">
                    <div class="panel-heading"><div><h2>Daily schedule</h2><p>Bookings and requests by date</p></div></div>
                    <form method="get" class="date-filter"><input type="hidden" name="page" value="reservations"><input type="date" name="date" value="<?= h($scheduleDate) ?>"><button class="button button-secondary" type="submit">View day</button></form>
                    <?php if (!$dayReservations): ?><div class="empty-state"><span class="empty-icon">◷</span><strong>No bookings for this day</strong><p>Choose another date or create a reservation.</p></div>
                    <?php else: ?><div class="schedule-list"><?php foreach ($dayReservations as $reservation): ?>
                        <article class="schedule-item">
                            <div class="schedule-time"><strong><?= h(date('g:i', strtotime($reservation['starts_at']))) ?></strong><small><?= h(date('A', strtotime($reservation['starts_at']))) ?></small><span></span><small><?= h(date('g:i A', strtotime($reservation['ends_at']))) ?></small></div>
                            <div class="schedule-detail"><div class="schedule-title-row"><strong><?= h($reservation['title']) ?></strong><span class="badge badge-<?= h($reservation['status']) ?>"><?= h(ucfirst($reservation['status'])) ?></span></div><p><?= h($reservation['room_name']) ?> · <?= h($reservation['building']) ?></p><small><?= h($reservation['organizer']) ?><?= $reservation['department'] ? ' · ' . h($reservation['department']) : '' ?></small>
                                <?php if ($reservation['status'] !== 'cancelled'): ?><div class="inline-actions"><?php if ($reservation['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reservation_status"><input type="hidden" name="reservation_id" value="<?= h($reservation['id']) ?>"><input type="hidden" name="status" value="approved"><input type="hidden" name="return_page" value="reservations"><button class="small-link approve-link" type="submit">Approve</button></form><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="reservation_status"><input type="hidden" name="reservation_id" value="<?= h($reservation['id']) ?>"><input type="hidden" name="status" value="cancelled"><input type="hidden" name="return_page" value="reservations"><button class="small-link danger-link" type="submit">Cancel</button></form></div><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?></div><?php endif; ?>
                </section>
            </div>

        <?php elseif ($page === 'availability'): ?>
            <section class="panel search-panel">
                <div class="panel-heading"><div><h2>Search available spaces</h2><p>Only rooms with no pending or approved overlap are shown.</p></div></div>
                <form method="get" class="search-form">
                    <input type="hidden" name="page" value="availability">
                    <label>Date<input type="date" name="date" value="<?= h($filterDate) ?>" required></label>
                    <label>Starts at<input type="time" name="start_time" value="<?= h($filterStart ?? '09:00') ?>" required></label>
                    <label>Ends at<input type="time" name="end_time" value="<?= h($filterEnd ?? '10:00') ?>" required></label>
                    <label>Minimum seats<input type="number" name="capacity" min="0" value="<?= h($minCapacity ?? 0) ?>"></label>
                    <label>Type<select name="room_type"><option value="">All spaces</option><option value="classroom" <?= ($filterType ?? '') === 'classroom' ? 'selected' : '' ?>>Classroom</option><option value="computer_lab" <?= ($filterType ?? '') === 'computer_lab' ? 'selected' : '' ?>>Computer lab</option></select></label>
                    <button class="button button-primary" type="submit">Search spaces <span>→</span></button>
                </form>
            </section>
            <?php if ($availabilityError): ?><div class="notice notice-error"><?= h($availabilityError) ?></div>
            <?php else: ?><div class="result-heading"><div><h2><?= count($availabilityRooms) ?> <?= count($availabilityRooms) === 1 ? 'space' : 'spaces' ?> available</h2><p><?= h(date('l, F j, Y', strtotime($filterDate))) ?> · <?= h(date('g:i A', strtotime($filterStart))) ?>–<?= h(date('g:i A', strtotime($filterEnd))) ?></p></div></div>
                <?php if (!$availabilityRooms): ?><section class="panel empty-state"><span class="empty-icon">⌕</span><strong>No matching spaces found</strong><p>Try a different time, room type, or capacity.</p></section>
                <?php else: ?><div class="available-grid"><?php foreach ($availabilityRooms as $room): ?><article class="available-card"><div class="available-card-top"><span class="room-symbol <?= $room['room_type'] === 'computer_lab' ? 'lab-symbol' : '' ?>"><?= $room['room_type'] === 'computer_lab' ? '⌘' : '▦' ?></span><span class="badge badge-available">Available</span></div><h3><?= h($room['name']) ?></h3><p><?= h($room['building']) ?> · <?= h(ucfirst(str_replace('_', ' ', $room['room_type']))) ?></p><div class="room-facts"><span>♙ <?= h($room['capacity']) ?> seats</span><span><?= h($room['equipment'] ?: 'No equipment listed') ?></span></div><a class="button button-secondary button-block" href="index.php?page=reservations#new-reservation">Reserve this space <span>→</span></a></article><?php endforeach; ?></div><?php endif; ?>
            <?php endif; ?>

        <?php elseif ($page === 'reports'): ?>
            <section class="report-summary"><div><span class="eyebrow">REPORTING PERIOD</span><h2><?= h(date('F Y')) ?></h2><p>Approved reservations only · <?= h($monthHours) ?> total booked hours</p></div><span class="report-icon">▥</span></section>
            <section class="panel report-panel"><div class="panel-heading"><div><h2>Space utilization</h2><p>Room-by-room booking activity this month</p></div></div>
                <?php if (!$reportRows): ?><div class="empty-state"><span class="empty-icon">▥</span><strong>No rooms to report</strong><p>Add spaces to begin tracking utilization.</p></div>
                <?php else: ?><div class="table-wrap"><table><thead><tr><th>SPACE</th><th>TYPE</th><th>CAPACITY</th><th>APPROVED BOOKINGS</th><th>BOOKED HOURS</th><th>MONTHLY SHARE</th></tr></thead><tbody>
                    <?php foreach ($reportRows as $row): $hours = (float) $row['booked_hours']; $share = $monthHours > 0 ? min(100, (int) round(($hours / $monthHours) * 100)) : 0; ?>
                        <tr><td><strong><?= h($row['name']) ?></strong><small><?= h($row['building']) ?></small></td><td><?= h(ucfirst(str_replace('_', ' ', $row['room_type']))) ?></td><td><?= h($row['capacity']) ?> seats</td><td><?= h($row['booking_count']) ?></td><td><strong><?= h(number_format($hours, 1)) ?> h</strong></td><td><div class="progress-wrap"><span class="progress-track"><span style="width:<?= $share ?>%"></span></span><small><?= $share ?>%</small></div></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </section>
            <p class="report-note">Booked hours are calculated from approved reservations starting within the current calendar month.</p>
        <?php endif; ?>
    </section>
    <footer class="page-footer"><span>PUCL Campus Space · Room & laboratory scheduling</span><span>Local campus time: <?= h(date_default_timezone_get()) ?></span></footer>
</main>
</body>
</html>
