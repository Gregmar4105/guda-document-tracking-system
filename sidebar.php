<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- SINGLE-DEVICE LOGIN ENFORCEMENT ---
// This check runs on every authenticated page load.
// It ensures that the current session ID matches the one stored in the database.
// If they don't match, it means the user has logged in on another device,
// and this older session should be terminated.
if (isset($_SESSION['user_id']) && isset($conn) && !$conn->connect_error) {
    $validation_stmt = $conn->prepare("SELECT session_token FROM users WHERE user_id = ?");
    $validation_stmt->bind_param("i", $_SESSION['user_id']);
    $validation_stmt->execute();
    $validation_res = $validation_stmt->get_result();
    if ($validation_row = $validation_res->fetch_assoc()) {
        $db_session_token = $validation_row['session_token'];
        if ($db_session_token !== null && $db_session_token !== session_id()) {
            session_unset();
            session_destroy();
            header("Location: login.php?reason=concurrent_login");
            exit();
        }
    }
    $validation_stmt->close();
}
// --- END: SINGLE-DEVICE LOGIN ENFORCEMENT ---

$current_page = basename($_SERVER['PHP_SELF']);

/**
 * Helper to get dynamic sequence safely.
 * This function now relies on the parent script to provide a valid DB connection.
 * @param mysqli|null $db_conn The database connection object.
 * @return array The sequence of signatory roles.
 */
function get_dynamic_sequence($db_conn) {
    $sequence = [];
    if ($db_conn && !$db_conn->connect_error) {
        $res = $db_conn->query("SELECT name FROM departments WHERE is_signatory = 1 AND is_active = 1 ORDER BY name ASC");
        while ($row = $res->fetch_assoc()) {
            $sequence[] = $row['name'];
        }
    }
    return $sequence;
}

// The parent script must define $conn from db_connect.php
$signatory_roles = get_dynamic_sequence($conn ?? null);
$user_role = $_SESSION['role'] ?? 'Guest';
$username = $_SESSION['username'] ?? 'User';
$full_name = $_SESSION['full_name'] ?? 'User';
$is_mis = $user_role === 'Management Information System Office';
$is_acct_head = ($user_role === 'Accounting Office' && ($_SESSION['is_head'] ?? 0) == 1);
$is_hr_head = ($user_role === 'Human Resource Management Services Division' && ($_SESSION['is_head'] ?? 0) == 1);
$is_admin = $is_mis || $is_acct_head; // HR Head is not a system admin for settings page

$is_signatory = in_array($user_role, $signatory_roles);
$notification_count = 0;
$latest_notification_id = 0;

// Fetch notification count if user is logged in and a DB connection is available.
if (isset($_SESSION['user_id']) && isset($conn) && !$conn->connect_error) {
    $current_user_id_for_notif = $_SESSION['user_id'];
    
    // Use the existing connection from the parent script
    $count_stmt = $conn->prepare("SELECT COALESCE(SUM(is_read = 0), 0) AS unread_count, COALESCE(MAX(id), 0) AS latest_notification_id FROM notifications WHERE user_id = ?");
    if ($count_stmt) {
        $count_stmt->bind_param("i", $current_user_id_for_notif);
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $notification_row = $count_result->fetch_assoc();
        $notification_count = (int)($notification_row['unread_count'] ?? 0);
        $latest_notification_id = (int)($notification_row['latest_notification_id'] ?? 0);
        $count_stmt->close();
    }
}

// The user is logged in, so their status is always 'Online'.
$dot_color = '#10b981'; 
?>

<link rel="stylesheet" href="layout.css?v=<?php echo filemtime('layout.css'); ?>">

<button id="mobile-menu-btn" class="mobile-menu-btn">
    <span></span>
    <span></span>
    <span></span>
</button>

<div id="sidebar-overlay" class="sidebar-overlay"></div>

<div class="sidebar" id="main-sidebar">
    <div class="user-profile">
        <div class="avatar-container">
            <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($full_name); ?>&background=F59E0B&color=1E3A8A" alt="Avatar">
            <div class="status-dot" style="background-color: <?php echo $dot_color; ?>;"></div>
        </div>
        <div class="username" style="font-weight: bold; margin-bottom: 5px;"><?php echo htmlspecialchars($full_name); ?></div>
        <div class="role">
            <?php echo htmlspecialchars($user_role); ?>
            <?php if ($_SESSION['is_head'] ?? 0): ?>
                <span class="head-badge-sidebar">(Head)</span>
            <?php endif; ?>
        </div>
    </div>

    <nav>
        <a href="home.php" class="<?php echo ($current_page == 'home.php') ? 'active' : ''; ?>">Dashboard</a>
        <a id="notifications-nav-link" href="notifications.php" data-latest-notification-id="<?php echo $latest_notification_id; ?>" class="<?php echo ($current_page == 'notifications.php') ? 'active' : ''; ?>">
            Notifications
            <span class="notif-badge" id="notif-badge" <?php if ($notification_count <= 0) echo 'style="display: none;"'; ?>><?php echo $notification_count; ?></span>
        </a>

        <div class="nav-section-title">Document Actions</div>
        <a href="request.php" class="<?php echo ($current_page == 'request.php') ? 'active' : ''; ?>">New Request</a>
        <?php if ($is_mis): ?>
            <a href="track.php" class="<?php echo ($current_page == 'track.php') ? 'active' : ''; ?>">Track Document</a>
        <?php endif; ?>

        <?php if ($is_signatory || $is_mis): ?>
            <div class="nav-section-title">Signatory Station</div>
            <a href="receive.php" class="<?php echo ($current_page == 'receive.php') ? 'active' : ''; ?>">Receive Document</a>
            <a href="queue.php" class="<?php echo ($current_page == 'queue.php') ? 'active' : ''; ?>">Approval Queue</a>
        <?php endif; ?>

        <div class="nav-section-title">Records & History</div>
        <a href="records.php" class="<?php echo ($current_page == 'records.php') ? 'active' : ''; ?>">My Document Records</a>
        <?php if ($is_mis): ?>
            <a href="list.php?view=my" class="<?php echo ($current_page == 'list.php' && ($_GET['view'] ?? '') === 'my') ? 'active' : ''; ?>">My Document History</a>
        <?php else: ?>
            <a href="list.php" class="<?php echo ($current_page == 'list.php') ? 'active' : ''; ?>">My Document History</a>
        <?php endif; ?>

    <?php if ($is_mis || $user_role === 'Human Resource Management Services Division' || $is_acct_head): ?>
        <div class="nav-section-title">Monitoring</div>
        <?php if ($is_hr_head): ?>
            <a href="analytics.php" class="<?php echo ($current_page == 'analytics.php') ? 'active' : ''; ?>">System Analytics</a>
        <?php endif; ?>
        <?php if ($is_acct_head): ?>
            <a href="analytics_accounting.php" class="<?php echo ($current_page == 'analytics_accounting.php') ? 'active' : ''; ?>">Financial Analytics</a>
        <?php endif; ?>
    <?php endif; ?>

        <?php if ($is_admin): ?>
            <div class="nav-section-title">Administration</div>
            <a href="settings.php" class="<?php echo ($current_page == 'settings.php') ? 'active' : ''; ?>">System Settings</a>
            <?php if ($is_mis): // Central Repository is MIS-only ?>
                <a href="list.php?view=all" class="<?php echo ($current_page == 'list.php' && ($_GET['view'] ?? 'all') !== 'my') ? 'active' : ''; ?>">Central Repository</a>
            <?php endif; ?>
        <?php endif; ?>

        <a href="logout.php" style="margin-top: 20px; color: #fca5a5; font-weight: bold;">Logout</a>
    </nav>
</div>
<div id="live-notification-region" class="live-notification-region" aria-live="polite" aria-relevant="additions"></div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const menuBtn = document.getElementById('mobile-menu-btn');
        const sidebar = document.getElementById('main-sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        function toggleMenu() {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
            menuBtn.classList.toggle('open');
        }

        if (menuBtn && sidebar && overlay) {
            menuBtn.addEventListener('click', toggleMenu);
            overlay.addEventListener('click', toggleMenu);
        }

        // --- REAL-TIME NOTIFICATION POLLING ---
        const notifBadge = document.getElementById('notif-badge');
        const notificationsLink = document.getElementById('notifications-nav-link');
        const notificationList = document.querySelector('.notification-list');
        const liveRegion = document.getElementById('live-notification-region');
        let latestNotificationId = parseInt(notificationsLink?.dataset.latestNotificationId || '0', 10);
        let pollingNotifications = false;

        function safeNotificationUrl(link) {
            try {
                const url = new URL(link || 'notifications.php', window.location.href);
                return url.origin === window.location.origin ? url.href : 'notifications.php';
            } catch (error) {
                return 'notifications.php';
            }
        }

        function appendNotification(notification) {
            if (!notificationList) return;

            const emptyState = notificationList.querySelector('.empty-state');
            if (emptyState) emptyState.remove();

            const link = document.createElement('a');
            link.href = safeNotificationUrl(notification.link);
            link.style.textDecoration = 'none';
            link.style.color = 'inherit';

            const item = document.createElement('div');
            item.className = 'notification-item';
            if (!notification.is_read) {
                item.style.backgroundColor = '#eef2ff';
                item.style.borderLeftColor = 'var(--naap-navy)';
            }

            const header = document.createElement('div');
            header.className = 'notification-header';
            const title = document.createElement('strong');
            title.textContent = 'Document Update';
            const timestamp = document.createElement('span');
            timestamp.className = 'notification-timestamp';
            const date = new Date(String(notification.created_at).replace(' ', 'T'));
            timestamp.textContent = Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
            header.append(title, timestamp);

            const message = document.createElement('p');
            message.className = 'notification-remarks';
            message.textContent = notification.message || '';
            item.append(header, message);
            link.appendChild(item);
            notificationList.prepend(link);
        }

        function showNotificationToast(notification) {
            if (!liveRegion) return;

            const toast = document.createElement('div');
            toast.className = 'live-notification-toast';
            toast.setAttribute('role', 'status');

            const link = document.createElement('a');
            link.href = safeNotificationUrl(notification.link);
            link.textContent = notification.message || 'You have a new notification.';

            const close = document.createElement('button');
            close.type = 'button';
            close.className = 'live-notification-close';
            close.setAttribute('aria-label', 'Dismiss notification');
            close.textContent = '×';
            close.addEventListener('click', function () {
                toast.remove();
            });

            toast.append(link, close);
            liveRegion.appendChild(toast);
            window.setTimeout(function () {
                toast.remove();
            }, 10000);
        }

        function checkNotifications() {
            if (pollingNotifications || document.hidden || !notifBadge) return;
            pollingNotifications = true;

            fetch('check_notifications.php?after_id=' + encodeURIComponent(latestNotificationId), {
                cache: 'no-store',
                credentials: 'same-origin'
            })
                .then(function (response) {
                    if (!response.ok) throw new Error('Notification polling returned HTTP ' + response.status);
                    return response.json();
                })
                .then(function (data) {
                    if (!Number.isInteger(data.unread_count) || !Array.isArray(data.notifications)) {
                        throw new Error('Notification polling returned an invalid response.');
                    }
                    notifBadge.textContent = data.unread_count;
                    notifBadge.style.display = data.unread_count > 0 ? 'inline-block' : 'none';

                    data.notifications.forEach(function (notification) {
                        latestNotificationId = Math.max(latestNotificationId, Number(notification.id) || 0);
                        appendNotification(notification);
                        showNotificationToast(notification);
                    });
                    latestNotificationId = Math.max(latestNotificationId, Number(data.next_after_id) || 0);
                    notificationsLink.dataset.latestNotificationId = String(latestNotificationId);
                })
                .catch(function (error) {
                    console.error('Error checking notifications:', error);
                })
                .finally(function () {
                    pollingNotifications = false;
                });
        }

        window.setInterval(checkNotifications, 10000);
    });
</script>
