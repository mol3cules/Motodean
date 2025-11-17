<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuditTrail.php';
require_once __DIR__ . '/shared_notifications.php';

// Require admin login
requireAdminLogin();

// Only admins and staff can view audit trail
if (!in_array($_SESSION['admin_user_role'], ['admin', 'staff'])) {
    header('Location: dashboard.php?message=access_denied');
    exit();
}

// Get current admin user information
try {
    $db = getDB();
    $query = "SELECT * FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['admin_user_id']);
    $stmt->execute();
    
    $admin = $stmt->fetch();
    
    if (!$admin || !in_array($admin['role'], ['admin', 'staff'])) {
        clearAdminSession();
        header('Location: ../auth/login.php?error=invalid_session');
        exit();
    }
    
} catch (Exception $e) {
    clearAdminSession();
    header('Location: ../auth/login.php?error=database_error');
    exit();
}
$notificationData = getAdminNotifications($db);

// Initialize AuditTrail
$auditTrail = new AuditTrail();

// Handle filters
$filters = [];
if (!empty($_GET['action_type'])) {
    $filters['action_type'] = $_GET['action_type'];
}
if (!empty($_GET['entity_type'])) {
    $filters['entity_type'] = $_GET['entity_type'];
}
if (!empty($_GET['date_from'])) {
    $filters['date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $filters['date_to'] = $_GET['date_to'];
}
if (!empty($_GET['user_email'])) {
    $filters['user_email'] = $_GET['user_email'];
}
if (!empty($_GET['search'])) {
    $filters['search'] = $_GET['search'];
}

// Get audit logs
$auditLogs = $auditTrail->getAuditLogs($filters);

// Get audit statistics
$auditStats = $auditTrail->getAuditStats();

// Get admin's initials for avatar
$initials = strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Audit Trail - MotoDean Admin</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="../assets/js/csrf.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
            line-height: 1.6;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar Styles */
        .admin-sidebar {
            width: 280px;
            background: #495057;
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header h1 {
            font-size: 1.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }

        .sidebar-header p {
            margin: 0.5rem 0 0 0;
            opacity: 0.8;
            font-size: 0.9rem;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.5rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            border-left-color: #FFD700;
            color: white;
        }

        .nav-link.active {
            background: rgba(255,255,255,0.15);
            border-left-color: #FFD700;
            color: white;
        }

        .nav-link i {
            margin-right: 1rem;
            width: 20px;
            text-align: center;
        }

        .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 1rem;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .admin-info {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }

        .admin-avatar {
            width: 40px;
            height: 40px;
            background: #FFD700;
            color: #2c3e50;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 1rem;
        }

        .admin-details h4 {
            font-size: 0.9rem;
            margin-bottom: 0.2rem;
        }

        .admin-details p {
            font-size: 0.8rem;
            opacity: 0.8;
        }

        .logout-btn {
            width: 100%;
            padding: 0.8rem;
            background: rgba(220, 53, 69, 0.15);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ff6b6b;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: block;
            text-align: center;
        }

        .logout-btn:hover {
            background: rgba(220, 53, 69, 0.3);
            border-color: rgba(220, 53, 69, 0.5);
            color: #ff5252;
            text-decoration: none;
        }

        /* Main Content */
        .admin-content {
            margin-left: 280px;
            flex: 1;
            min-height: 100vh;
        }

        .main-header {
            background: white;
            padding: 1.5rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .page-title {
            font-size: 2rem;
            color: #2c3e50;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: #6c757d;
            font-size: 1.1rem;
        }

        /* Notification Dropdown - minimal behavior styles */
        .notification-dropdown { position: relative; display: inline-block; }
        .notification-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background: white;
            min-width: 400px;
            max-width: 500px;
            border-radius: 8px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 1px solid #e9ecef;
            max-height: 500px;
            overflow-y: auto;
        }
        .notification-dropdown.show .notification-dropdown-content { display: block; }

        /* Filters Section */
        .filters-section {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }

        .filters-title {
            font-size: 1.2rem;
            color: #2c3e50;
            margin-bottom: 1rem;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 0.5rem;
        }

        .filter-input {
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9rem;
        }

        .filter-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,0.25);
        }

        .filter-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background: #0056b3;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #545b62;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #1e7e34;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* Audit Table */
        .audit-table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .table-header {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-title {
            font-size: 1.2rem;
            color: #2c3e50;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
        }

        .audit-table th {
            background: #f8f9fa;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 1px solid #dee2e6;
        }

        .audit-table td {
            padding: 1rem;
            border-bottom: 1px solid #eee;
            vertical-align: top;
        }

        .audit-table tr:hover {
            background: #f8f9fa;
        }

        .action-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .action-badge i {
            margin-right: 0.3rem;
        }

        .text-success { color: #28a745; }
        .text-danger { color: #dc3545; }
        .text-warning { color: #ffc107; }
        .text-info { color: #17a2b8; }
        .text-secondary { color: #6c757d; }

        .time-ago {
            font-size: 0.9rem;
            color: #6c757d;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background: #007bff;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
            margin-right: 0.5rem;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 500;
            font-size: 0.9rem;
        }

        .user-email {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .entity-info {
            display: flex;
            flex-direction: column;
        }

        .entity-type {
            font-weight: 500;
            font-size: 0.9rem;
        }

        .entity-id {
            font-size: 0.8rem;
            color: #6c757d;
        }

        .details-text {
            font-size: 0.9rem;
            line-height: 1.4;
        }

        .ip-address {
            font-family: monospace;
            font-size: 0.8rem;
            color: #6c757d;
        }

        /* Notification Dropdown */
        .notification-dropdown { position: relative; display: inline-block; }
        .notification-btn {
            background: #667eea;
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 1.2rem;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }
        .notification-btn:hover { background: #5a67d8; transform: scale(1.05); }
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff4757;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }
        .notification-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            background: white;
            min-width: 400px;
            max-width: 500px;
            border-radius: 8px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 1px solid #e9ecef;
            max-height: 500px;
            overflow-y: auto;
        }
        .notification-dropdown.show .notification-dropdown-content { display: block; }
        .notification-header { padding: 1rem; border-bottom: 1px solid #e9ecef; background: #f8f9fa; border-radius: 8px 8px 0 0; }
        .notification-section { padding: 0.5rem 0; }
        .notification-section-title { padding: 0.5rem 1rem; font-size: 0.9rem; font-weight: bold; color: #6c757d; text-transform: uppercase; }
        .notification-item { padding: 0.75rem 1rem; border-bottom: 1px solid #f1f3f4; transition: background 0.2s ease; }
        .notification-item:hover { background: #f8f9fa; }
        .notification-item:last-child { border-bottom: none; }
        .notification-item-title { font-weight: 600; margin: 0 0 0.25rem 0; font-size: 0.9rem; }
        .notification-item-desc { color: #6c757d; font-size: 0.85rem; margin: 0; }
        .notification-empty { padding: 1rem; text-align: center; color: #6c757d; font-style: italic; }

        /* Responsive Design */
        @media (max-width: 768px) {
            .admin-sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .admin-sidebar.open {
                transform: translateX(0);
            }

            .admin-content {
                margin-left: 0;
                padding: 1rem;
            }

            .filters-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .table-wrapper {
                font-size: 0.8rem;
            }

            .audit-table th,
            .audit-table td {
                padding: 0.5rem;
            }
        }

        /* Loading States */
        .loading {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .loading i {
            font-size: 2rem;
            margin-bottom: 1rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .empty-state h3 {
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <div class="sidebar-header">
                <h1>MotoDean </h1>
                <p>Management Inventory</p>
            </div>

            <!-- Navigation Menu -->
            <nav class="sidebar-nav">
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt"></i>
                        Dashboard
                    </a>
                </div>
                <div class="nav-item">
                    <a href="products.php" class="nav-link">
                        <i class="fas fa-box"></i>
                        Product Management
                    </a>
                </div>
                <div class="nav-item">
                    <a href="orders.php" class="nav-link">
                        <i class="fas fa-receipt"></i>
                        Orders & Payment
                    </a>
                </div>
                <div class="nav-item">
                    <a href="analytics.php" class="nav-link">
                        <i class="fas fa-chart-bar"></i>
                        Reports & Analytics
                    </a>
                </div>
                <div class="nav-item">
                    <a href="audit-trail.php" class="nav-link active">
                        <i class="fas fa-clipboard-list"></i>
                        Audit Trail
                    </a>
                </div>
            </nav>

            <!-- Sidebar Footer with Admin Info and Logout -->
            <div class="sidebar-footer">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <?php if ($admin['profile_picture']): ?>
                            <img src="<?php echo htmlspecialchars($admin['profile_picture']); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div class="admin-details">
                        <h4><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></h4>
                        <p><?php echo htmlspecialchars($admin['email']); ?></p>
                    </div>
                </div>
                <a href="../auth/admin-logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="admin-content">
            <div class="main-header">
                <div>
                    <h1><i class="fas fa-clipboard-list"></i> Staff Audit Trail</h1>
                    <p>Track all staff activities and system changes</p>
                </div>
                <div class="notification-dropdown" id="notificationDropdown">
                    <button class="notification-btn" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($notificationData['totalNotifications'] > 0): ?>
                            <span class="notification-badge"><?php echo min($notificationData['totalNotifications'], 99); ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-dropdown-content">
                        <div class="notification-header">
                            <h4 style="margin: 0; color: #333;">Notifications</h4>
                            <small style="color: #6c757d;">&nbsp;<?php echo $notificationData['totalNotifications']; ?> new notifications</small>
                        </div>
                        <?php if (!empty($notificationData['outOfStockNotifications'])): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">⚠️ Out of Stock</h5>
                            <?php foreach ($notificationData['outOfStockNotifications'] as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #dc3545;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">Product is completely out of stock</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($notificationData['unavailableProductNotifications'])): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">🚫 Unavailable Products</h5>
                            <?php foreach ($notificationData['unavailableProductNotifications'] as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #6c757d;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">Product is set as unavailable (Stock: <?php echo (int)$product['stock_quantity']; ?>)</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($notificationData['lowStockNotifications'])): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">📦 Low Stock</h5>
                            <?php foreach ($notificationData['lowStockNotifications'] as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #ffc107;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">Only <?php echo (int)$product['stock_quantity']; ?> units remaining</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($notificationData['pendingOrderNotifications'])): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">⏳ Pending Orders</h5>
                            <?php foreach ($notificationData['pendingOrderNotifications'] as $order): ?>
                            <div class="notification-item">
                                <p class="notification-item-title"><?php echo htmlspecialchars($order['order_number']); ?></p>
                                <p class="notification-item-desc">
                                    <?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?> - ₱<?php echo number_format($order['total_amount'], 2); ?>
                                    <br><small><?php echo date('M j, g:i A', strtotime($order['created_at'])); ?></small>
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($notificationData['paidOrderNotifications'])): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">💰 Paid Orders</h5>
                            <?php foreach ($notificationData['paidOrderNotifications'] as $order): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #28a745;"><?php echo htmlspecialchars($order['order_number']); ?></p>
                                <p class="notification-item-desc">
                                    <?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?> - ₱<?php echo number_format($order['total_amount'], 2); ?>
                                    <br><small><?php echo date('M j, g:i A', strtotime($order['created_at'])); ?></small>
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($notificationData['totalNotifications'] == 0): ?>
                        <div class="notification-empty">
                            <i class="fas fa-check-circle" style="font-size: 2rem; color: #28a745; margin-bottom: 0.5rem;"></i>
                            <p>All caught up! No new notifications.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($auditStats['total_activities'] ?? 0); ?></div>
                    <div class="stat-label">Total Activities</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($auditStats['recent_activities'] ?? 0); ?></div>
                    <div class="stat-label">Last 24 Hours</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($auditStats['by_user'] ?? []); ?></div>
                    <div class="stat-label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($auditStats['by_action_type'] ?? []); ?></div>
                    <div class="stat-label">Action Types</div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <h3 class="filters-title">Filter Audit Logs</h3>
                <form method="GET" action="audit-trail.php">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label class="filter-label">Action Type</label>
                            <select name="action_type" class="filter-input" onchange="this.form.submit()">
                                <option value="">All Actions</option>
                                <option value="login" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] === 'login') ? 'selected' : ''; ?>>Login</option>
                                <option value="logout" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] === 'logout') ? 'selected' : ''; ?>>Logout</option>
                                <option value="create" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] === 'create') ? 'selected' : ''; ?>>Create</option>
                                <option value="update" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] === 'update') ? 'selected' : ''; ?>>Update</option>
                                <option value="delete" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] === 'delete') ? 'selected' : ''; ?>>Delete</option>
                                <option value="view" <?php echo (isset($_GET['action_type']) && $_GET['action_type'] === 'view') ? 'selected' : ''; ?>>View</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Entity Type</label>
                            <select name="entity_type" class="filter-input" onchange="this.form.submit()">
                                <option value="">All Entities</option>
                                <option value="product" <?php echo (isset($_GET['entity_type']) && $_GET['entity_type'] === 'product') ? 'selected' : ''; ?>>Products</option>
                                <option value="order" <?php echo (isset($_GET['entity_type']) && $_GET['entity_type'] === 'order') ? 'selected' : ''; ?>>Orders</option>
                                <option value="customer" <?php echo (isset($_GET['entity_type']) && $_GET['entity_type'] === 'customer') ? 'selected' : ''; ?>>Customers</option>
                                <option value="user" <?php echo (isset($_GET['entity_type']) && $_GET['entity_type'] === 'user') ? 'selected' : ''; ?>>Users</option>
                                <option value="system" <?php echo (isset($_GET['entity_type']) && $_GET['entity_type'] === 'system') ? 'selected' : ''; ?>>System</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Date From</label>
                            <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($_GET['date_from'] ?? ''); ?>" onchange="this.form.submit()">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Date To</label>
                            <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($_GET['date_to'] ?? ''); ?>" onchange="this.form.submit()">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">User Email</label>
                            <input type="text" name="user_email" class="filter-input" placeholder="Search by email" value="<?php echo htmlspecialchars($_GET['user_email'] ?? ''); ?>" oninput="debouncedSubmit(this.form)">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" name="search" class="filter-input" placeholder="Search in details" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" oninput="debouncedSubmit(this.form)">
                        </div>
                    </div>
                    <div class="filter-buttons">
                        <a href="audit-trail.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Clear Filters
                        </a>
                        <a href="export-audit.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success">
                            <i class="fas fa-download"></i> Export CSV
                        </a>
                    </div>
                </form>
            </div>

            <!-- Audit Table -->
            <div class="audit-table-container">
                <div class="table-header">
                    <h3 class="table-title">Audit Logs</h3>
                    <span class="text-muted"><?php echo count($auditLogs); ?> records found</span>
                </div>
                <div class="table-wrapper">
                    <?php if (empty($auditLogs)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-list"></i>
                            <h3>No audit logs found</h3>
                            <p>No activities match your current filters. Try adjusting your search criteria.</p>
                        </div>
                    <?php else: ?>
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Entity</th>
                                    <th>Details</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditLogs as $log): ?>
                                    <tr>
                                        <td>
                                            <div class="time-ago">
                                                <?php echo $auditTrail->getFormattedTime($log['timestamp']); ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: #6c757d;">
                                                <?php echo date('M d, Y H:i:s', strtotime($log['timestamp'])); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($log['user_email'], 0, 1)); ?>
                                                </div>
                                                <div class="user-details">
                                                    <div class="user-name"><?php echo htmlspecialchars($log['user_email']); ?></div>
                                                    <div class="user-email"><?php echo ucfirst($log['user_role']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="action-badge <?php echo $auditTrail->getActionColorClass($log['action_type']); ?>">
                                                <i class="<?php echo $auditTrail->getActionIcon($log['action_type']); ?>"></i>
                                                <?php echo ucfirst($log['action_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="entity-info">
                                                <div class="entity-type"><?php echo ucfirst($log['entity_type']); ?></div>
                                                <?php if ($log['entity_id']): ?>
                                                    <div class="entity-id">ID: <?php echo $log['entity_id']; ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="details-text">
                                                <?php echo htmlspecialchars($log['additional_info'] ?? 'No details'); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="ip-address"><?php echo htmlspecialchars($log['ip_address']); ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            var el = document.getElementById('adminSidebar');
            if (el) { el.classList.toggle('open'); }
        }

        // Auto-refresh every 30 seconds
        setInterval(function() {
            if (document.visibilityState === 'visible') {
                location.reload();
            }
        }, 30000);

        // Add loading states to buttons
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('click', function() {
                if (this.type === 'submit') {
                    this.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
                    this.disabled = true;
                }
            });
        });

        // Notification dropdown toggle and outside click close
        function toggleNotifications() {
            var dropdown = document.getElementById('notificationDropdown');
            if (dropdown) { dropdown.classList.toggle('show'); }
        }
        window.addEventListener('click', function(event) {
            var dropdown = document.getElementById('notificationDropdown');
            if (dropdown && !dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Debounced submit for text inputs
        let __filterSubmitTimer;
        function debouncedSubmit(form) {
            clearTimeout(__filterSubmitTimer);
            __filterSubmitTimer = setTimeout(function(){ form.submit(); }, 400);
        }
    </script>
</body>
</html>
