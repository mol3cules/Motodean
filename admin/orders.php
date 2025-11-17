<?php
require_once __DIR__ . '/../config/config.php';

// Require admin login
requireAdminLogin();

// Get current admin user information
try {
    $db = getDB();
    $query = "SELECT * FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['admin_user_id']);
    $stmt->execute();
    
    $admin = $stmt->fetch();
    
    if (!$admin || !in_array($admin['role'], ['admin', 'staff'])) {
        // Clear admin session
        unset($_SESSION['admin_user_id'], $_SESSION['admin_user_email'], $_SESSION['admin_user_name'], 
              $_SESSION['admin_user_role'], $_SESSION['admin_session_token'], $_SESSION['admin_login_time'], 
              $_SESSION['is_admin']);
        header('Location: ../auth/admin-login.php?error=invalid_session');
        exit();
    }
    
} catch (Exception $e) {
    // Clear only admin session variables
    unset($_SESSION['admin_user_id'], $_SESSION['admin_user_email'], $_SESSION['admin_user_name'], 
          $_SESSION['admin_user_role'], $_SESSION['admin_session_token'], $_SESSION['admin_login_time'], 
          $_SESSION['is_admin']);
    header('Location: ../auth/admin-login.php?error=database_error');
    exit();
}

// Get admin's initials for avatar
$initials = strtoupper(substr($admin['first_name'], 0, 1) . substr($admin['last_name'], 0, 1));

// Get orders data with filtering
$status = $_GET['status'] ?? '';
$payment_method = $_GET['payment_method'] ?? '';
$search = $_GET['search'] ?? '';

try {
    // Build query with filters
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if (!empty($status)) {
        $whereClause .= " AND o.status = :status";
        $params[':status'] = $status;
    }
    
    if (!empty($payment_method)) {
        $whereClause .= " AND o.payment_method = :payment_method";
        $params[':payment_method'] = $payment_method;
    }
    
    if (!empty($search)) {
        $whereClause .= " AND (o.id LIKE :search OR o.order_number LIKE :search OR u.email LIKE :search OR CONCAT(u.first_name, ' ', u.last_name) LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    // Get orders with customer information
    $ordersQuery = "SELECT o.*, u.first_name, u.last_name, u.email, u.phone_number,
                           CONCAT(u.first_name, ' ', u.last_name) as customer_name,
                           COALESCE(SUM(oi.quantity), 0) as item_count
                    FROM orders o 
                    LEFT JOIN users u ON o.user_id = u.id 
                    LEFT JOIN order_items oi ON o.id = oi.order_id
                    $whereClause 
                    GROUP BY o.id
                    ORDER BY o.created_at DESC";
    
    $ordersStmt = $db->prepare($ordersQuery);
    foreach ($params as $key => $value) {
        $ordersStmt->bindValue($key, $value);
    }
    $ordersStmt->execute();
    $orders = $ordersStmt->fetchAll();
    
    // Get order statistics
    $statsQuery = "SELECT 
                   COUNT(*) as total_orders,
                   SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                   SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing_orders,
                   SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_orders,
                   SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped_orders,
                   SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                   SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                   SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as total_revenue
                   FROM orders";
    
    $statsStmt = $db->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->fetch();
    
} catch (Exception $e) {
    $orders = [];
    $stats = [
        'total_orders' => 0,
        'pending_orders' => 0,
        'processing_orders' => 0,
        'paid_orders' => 0,
        'shipped_orders' => 0,
        'completed_orders' => 0,
        'total_revenue' => 0
    ];
}

// Get notification data separately
require_once 'shared_notifications.php';
$notificationData = getAdminNotifications($db);
extract($notificationData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo getCSRFTokenMeta(); ?>
    <title>Orders & Payments - MotoDean Admin</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="../assets/js/csrf.js"></script>
    <style>
        body {
            margin: 0;
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .admin-layout {
            display: flex;
            min-height: 100vh;
        }
        
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
        
        .admin-content {
            margin-left: 280px;
            flex: 1;
            min-height: 100vh;
        }
        
        .sidebar-header {
            padding: 2rem 1.5rem;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
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
            width: 20px;
            margin-right: 1rem;
            font-size: 1.1rem;
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
            padding: 1rem;
            background: rgba(255,255,255,0.1);
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        
        .admin-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 1rem;
            color: #333;
        }
        
        .admin-details h4 {
            margin: 0;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .admin-details p {
            margin: 0.2rem 0 0 0;
            font-size: 0.8rem;
            opacity: 0.8;
        }
        
        .logout-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0.8rem;
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid rgba(220, 53, 69, 0.3);
            color: #ff6b6b;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-weight: 500;
        }
        
        .logout-btn:hover {
            background: rgba(220, 53, 69, 0.3);
            border-color: rgba(220, 53, 69, 0.5);
            color: #ff5252;
        }
        
        .logout-btn i {
            margin-left: 0.5rem;
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
        
        .main-header h1 {
            margin: 0;
            color: #333;
            font-size: 2rem;
        }
        
        .main-header p {
            margin: 0.5rem 0 0 0;
            color: #6c757d;
        }
        
        .notification-dropdown {
            position: relative;
            display: inline-block;
        }
        
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
        
        .notification-btn:hover {
            background: #5a67d8;
            transform: scale(1.05);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
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
        
        .notification-dropdown.show .notification-dropdown-content {
            display: block;
        }
        
        .notification-header {
            padding: 1rem;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
        }
        
        .notification-section {
            padding: 0.5rem 0;
        }
        
        .notification-section-title {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            font-weight: bold;
            color: #6c757d;
            text-transform: uppercase;
            background: #f8f9fa;
            margin: 0;
        }
        
        .notification-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f1f3f4;
            transition: background 0.2s ease;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-item-title {
            font-weight: 600;
            margin: 0 0 0.25rem 0;
            font-size: 0.9rem;
        }
        
        .notification-item-desc {
            color: #6c757d;
            font-size: 0.8rem;
            margin: 0;
        }
        
        .notification-empty {
            padding: 1rem;
            text-align: center;
            color: #6c757d;
            font-style: italic;
        }
        
        .filters-section {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: 1fr 200px 200px;
            gap: 1rem;
            align-items: end;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .orders-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table-header {
            background: #6c757d;
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h2 {
            margin: 0;
            font-size: 1.2rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        /* Center Actions column header and content */
        th:nth-child(8), td:nth-child(8) {
            text-align: center;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-processing {
            background: #d4edda;
            color: #155724;
        }
        
        .status-paid {
            background: #cce5ff;
            color: #004085;
        }
        
        .status-shipped {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .status-completed {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }
        
        .payment-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .payment-gcash {
            background: #0066cc;
            color: white;
        }
        
        .payment-paymaya {
            background: #00d4aa;
            color: white;
        }
        
        .payment-cod {
            background: #6c757d;
            color: white;
        }
        
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
        }
        
        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.8rem;
            border-radius: 4px;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 1.5rem;
        }
        
        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        
        .timeline {
            position: relative;
            padding-left: 2rem;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #dee2e6;
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 2rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.75rem;
            top: 0.25rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #dee2e6;
        }
        
        .timeline-item.active::before {
            background: #28a745;
        }
        
        .timeline-item.current::before {
            background: #ffc107;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(255, 193, 7, 0); }
            100% { box-shadow: 0 0 0 0 rgba(255, 193, 7, 0); }
        }
        
        .payment-proof {
            max-width: 100%;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .admin-sidebar {
                width: 250px;
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }
            
            .admin-sidebar.open {
                transform: translateX(0);
            }
            
            .admin-content {
                margin-left: 0;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle Button -->
    <button class="mobile-toggle" onclick="toggleSidebar()" style="display: none; position: fixed; top: 1rem; left: 1rem; z-index: 1001; background: #667eea; color: white; border: none; padding: 0.5rem; border-radius: 4px; cursor: pointer;">
        <i class="fas fa-bars"></i>
    </button>

    <div class="admin-layout">
        <!-- Left Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <h1>MotoDean</h1>
                <p>Management Inventory </p>
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
                    <a href="orders.php" class="nav-link active">
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
                    <a href="audit-trail.php" class="nav-link">
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
                            <img src="<?php echo htmlspecialchars($admin['profile_picture'] ?? ''); ?>" alt="Profile" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                        <?php else: ?>
                            <?php echo $initials; ?>
                        <?php endif; ?>
                    </div>
                    <div class="admin-details">
                        <h4><?php echo htmlspecialchars(($admin['first_name'] ?? '') . ' ' . ($admin['last_name'] ?? '')); ?></h4>
                        <p><?php echo htmlspecialchars($admin['email'] ?? ''); ?></p>
                    </div>
                </div>
                <a href="../auth/admin-logout.php" class="logout-btn">
                    Logout
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="admin-content">
            <!-- Main Header -->
            <div class="main-header">
                <div>
                    <h1><i class="fas fa-receipt"></i> Orders & Payments</h1>
                    <p>Manage customer orders, process payments, and track delivery status</p>
                </div>
                
                <!-- Notification Dropdown -->
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
                            <small style="color: #6c757d;"><?php echo $notificationData['totalNotifications']; ?> new notifications</small>
                        </div>
                        
                        <!-- Out of Stock Products -->
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
                        
                        <!-- Low Stock Products -->
                        <?php if (!empty($notificationData['lowStockNotifications'])): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">📦 Low Stock</h5>
                            <?php foreach ($notificationData['lowStockNotifications'] as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #ffc107;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">Only <?php echo $product['stock_quantity']; ?> units remaining</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Pending Orders -->
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
                        
                        <!-- Paid Orders -->
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
                        
                        <?php if ($notificationData['totalNotifications'] === 0): ?>
                        <div class="notification-empty">
                            <i class="fas fa-check-circle" style="font-size: 2rem; color: #28a745; margin-bottom: 0.5rem;"></i>
                            <p>All caught up! No new notifications.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div style="padding: 0 2rem 2rem 2rem;">
                <!-- Order Statistics -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #667eea;"><?php echo number_format($stats['total_orders'] ?? 0); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Total Orders</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-ban"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #dc3545;"><?php echo $stats['cancelled_orders'] ?? 0; ?></h3>
                                <p style="margin: 0; color: #6c757d;">Cancelled</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #28a745;"><?php echo $stats['completed_orders'] ?? 0; ?></h3>
                                <p style="margin: 0; color: #6c757d;">Completed</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-peso-sign"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #17a2b8;">₱<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Revenue</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="search">Search Orders</label>
                                <input type="text" id="search" name="search" class="form-control" 
                                       placeholder="Search by order number, customer email, or name..." 
                                       value="<?php echo htmlspecialchars($search ?? ''); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="status">Status</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo $status === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                    <option value="shipped" <?php echo $status === 'shipped' ? 'selected' : ''; ?>>Shipped</option>
                                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="payment_method">Payment Method</label>
                                <select id="payment_method" name="payment_method" class="form-control">
                                    <option value="">All Methods</option>
                                    <option value="gcash" <?php echo $payment_method === 'gcash' ? 'selected' : ''; ?>>GCash</option>
                                    <option value="paymaya" <?php echo $payment_method === 'paymaya' ? 'selected' : ''; ?>>PayMaya</option>
                                    <option value="cod" <?php echo $payment_method === 'cod' ? 'selected' : ''; ?>>Cash on Delivery</option>
                                </select>
                            </div>
                            
                        </div>
                    </form>
                </div>

                <!-- Orders Table -->
                <div class="orders-table">
                    <div class="table-header">
                        <h2><i class="fas fa-list"></i> Customer Orders</h2>
                        <span><?php echo count($orders); ?> orders found</span>
                    </div>
                    
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($orders)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 2rem; color: #6c757d;">
                                            <i class="fas fa-receipt" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                            <p>No orders found matching your criteria.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($order['order_number']); ?></strong>
                                                <br>
                                                <small style="color: #6c757d;">ID: <?php echo $order['id']; ?></small>
                                            </td>
                                            <td>
                                                <div>
                                                    <strong><?php echo htmlspecialchars($order['customer_name'] ?? 'Guest'); ?></strong>
                                                    <br>
                                                    <small style="color: #6c757d;"><?php echo htmlspecialchars($order['email'] ?? ''); ?></small>
                                                </div>
                                            </td>
                                            <td>
                                                <span style="background: #e9ecef; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                    <?php echo $order['item_count']; ?> item<?php echo $order['item_count'] != 1 ? 's' : ''; ?>
                                                </span>
                                            </td>
                                            <td><strong>₱<?php echo number_format($order['total_amount'], 2); ?></strong></td>
                                            <td>
                                                <span class="payment-badge payment-<?php echo $order['payment_method']; ?>">
                                                    <?php echo strtoupper($order['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $order['status']; ?>">
                                                    <?php echo ucfirst($order['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($order['created_at'])); ?>
                                                <br>
                                                <small style="color: #6c757d;"><?php echo date('g:i A', strtotime($order['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <!-- View Details - Always show -->
                                                    <button class="btn btn-sm btn-info" onclick="viewOrder(<?php echo $order['id']; ?>)" title="View Details" data-bs-toggle="tooltip">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    
                                                    <!-- Update Status - Handles all status changes -->
                                                    <button class="btn btn-sm" style="background: #6c757d; border-color: #6c757d; color: white;" onclick="showStatusUpdateModal(<?php echo $order['id']; ?>)" title="Update Status" data-bs-toggle="tooltip">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Order Details Modal -->
    <div id="orderModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Order Details</h3>
                <button onclick="closeOrderModal()" style="background: none; border: none; font-size: 1.5rem; color: #6c757d; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body" id="orderDetails">
                <div style="text-align: center; padding: 2rem;">
                    <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #667eea;"></i>
                    <p>Loading order details...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeOrderModal()" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            sidebar.classList.toggle('open');
        }

        // View order details
        function viewOrder(orderId) {
            document.getElementById('modalTitle').textContent = `Order #${orderId} Details`;
            document.getElementById('orderModal').style.display = 'flex';
            
            fetch(`order-status-handler.php?action=get_details&id=${orderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayOrderDetails(data.order, data.items, data.history);
                    } else {
                        document.getElementById('orderDetails').innerHTML = '<p>Error loading order details</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('orderDetails').innerHTML = '<p>Network error loading order details</p>';
                });
        }

        function closeOrderModal() {
            document.getElementById('orderModal').style.display = 'none';
        }

        function displayOrderDetails(order, items, history) {
            const statusOrder = ['pending', 'processing', 'paid', 'shipped', 'completed'];
            const currentIndex = statusOrder.indexOf(order.status);
            
            // Handle cancelled orders separately
            const isCancelled = order.status === 'cancelled';
            
            let html = `
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                    <div>
                        <h4>Customer Information</h4>
                        <p><strong>Name:</strong> ${order.customer_name || 'N/A'}</p>
                        <p><strong>Email:</strong> ${order.email || 'N/A'}</p>
                        <p><strong>Phone:</strong> ${order.phone_number || 'N/A'}</p>
                        <p><strong>Order Date:</strong> ${new Date(order.created_at).toLocaleString()}</p>
                    </div>
                    <div>
                        <h4>Delivery Information</h4>
                        <p><strong>Address:</strong> ${order.shipping_address || 'N/A'}</p>
                        <p><strong>Payment Method:</strong> ${order.payment_method.toUpperCase()}</p>
                        <p><strong>Total Amount:</strong> ₱${parseFloat(order.total_amount).toLocaleString()}</p>
                        ${order.shipping_fee > 0 ? `<p><strong>Shipping Fee:</strong> ₱${parseFloat(order.shipping_fee).toFixed(2)}</p>` : ''}
                    </div>
                </div>

                <div style="margin-bottom: 2rem;">
                    <h4 style="text-align: center; margin-bottom: 1.5rem;">Order Status Timeline</h4>
                    <div style="max-width: 400px; margin: 0 auto; position: relative;">
                        <!-- Vertical Timeline Line -->
                        <div style="position: absolute; left: 20px; top: 0; bottom: 0; width: 2px; background: #dee2e6;"></div>
                        
                        <!-- Progress Line -->
                        ${isCancelled ? 
                            '<div style="position: absolute; left: 20px; top: 0; width: 2px; background: #dc3545; height: 100%; transition: height 0.5s ease;"></div>' :
                            `<div style="position: absolute; left: 20px; top: 0; width: 2px; background: #28a745; height: ${(currentIndex / (statusOrder.length - 1)) * 100}%; transition: height 0.5s ease;"></div>`
                        }
            `;
            
            // Add cancelled status to timeline if order is cancelled
            if (isCancelled) {
                statusOrder.push('cancelled');
            }
            
            statusOrder.forEach((status, index) => {
                const statusLabels = {
                    'pending': 'Order Placed',
                    'processing': 'Processing',
                    'paid': 'Payment Confirmed',
                    'shipped': 'Shipped',
                    'completed': 'Completed',
                    'cancelled': 'Cancelled'
                };
                
                let dotColor = '#dee2e6';
                let bgColor = 'white';
                let textColor = '#6c757d';
                let statusText = 'Pending';
                
                // Handle cancelled status
                if (status === 'cancelled') {
                    dotColor = '#dc3545';
                    bgColor = '#dc3545';
                    textColor = '#dc3545';
                    statusText = statusLabels[status];
                } else if (index < currentIndex) {
                    dotColor = '#28a745';
                    bgColor = '#28a745';
                    textColor = '#28a745';
                    statusText = 'Completed';
                } else if (index === currentIndex) {
                    dotColor = '#ffc107';
                    bgColor = '#ffc107';
                    textColor = '#ffc107';
                    statusText = 'Current';
                }
                
                html += `
                    <div style="display: flex; align-items: center; margin-bottom: ${index === statusOrder.length - 1 ? '0' : '1.5rem'}; position: relative;">
                        <!-- Status Dot -->
                        <div style="width: 42px; height: 42px; border-radius: 50%; background: ${bgColor}; border: 3px solid ${dotColor}; display: flex; align-items: center; justify-content: center; font-weight: bold; color: ${bgColor === 'white' ? dotColor : 'white'}; box-shadow: 0 2px 8px rgba(0,0,0,0.15); z-index: 10; position: relative;">
                            ${index + 1}
                        </div>
                        
                        <!-- Status Content -->
                        <div style="margin-left: 1rem; flex: 1;">
                            <h6 style="margin: 0 0 0.25rem 0; font-size: 0.9rem; color: ${textColor}; font-weight: 600;">
                                ${statusLabels[status]}
                            </h6>
                            <p style="margin: 0; color: #6c757d; font-size: 0.8rem;">
                                ${statusText}
                            </p>
                        </div>
                    </div>
                `;
            });
            
            html += `
                    </div>
                </div>

                <div style="margin-bottom: 2rem;">
                    <h4>Order Items</h4>
                    <div style="border: 1px solid #dee2e6; border-radius: 8px; overflow: hidden;">
            `;
            
            items.forEach(item => {
                html += `
                    <div style="display: flex; align-items: center; padding: 1rem; border-bottom: 1px solid #dee2e6;">
                        <div style="width: 60px; height: 60px; background: #f8f9fa; border-radius: 4px; display: flex; align-items: center; justify-content: center; margin-right: 1rem;">
                            <i class="fas fa-box" style="color: #6c757d;"></i>
                        </div>
                        <div style="flex: 1;">
                            <h6 style="margin: 0 0 0.25rem 0;">${item.product_name}</h6>
                            <p style="margin: 0; color: #6c757d; font-size: 0.9rem;">Qty: ${item.quantity} × ₱${parseFloat(item.unit_price).toFixed(2)}</p>
                        </div>
                        <div>
                            <strong>₱${(item.quantity * item.unit_price).toFixed(2)}</strong>
                        </div>
                    </div>
                `;
            });
            
            html += `
                    </div>
                </div>
            `;
            
            // Add order notes or cancellation reason if they exist
            if (order.notes && order.notes.trim() !== '') {
                const isCancelled = order.status === 'cancelled';
                const title = isCancelled ? 'Cancellation Reason' : 'Order Notes';
                const borderColor = isCancelled ? '#dc3545' : '#667eea';
                const bgColor = isCancelled ? '#f8d7da' : '#f8f9fa';
                const textColor = isCancelled ? '#721c24' : '#333';
                
                html += `
                    <div style="margin-bottom: 2rem;">
                        <h4>${title}</h4>
                        <div style="background: ${bgColor}; padding: 1rem; border-radius: 8px; border-left: 4px solid ${borderColor};">
                            <p style="margin: 0; color: ${textColor}; font-style: italic;">"${order.notes}"</p>
                        </div>
                    </div>
                `;
            }
            
            // Add payment proof if exists and not COD
            if (order.payment_method !== 'cod' && order.payment_proof) {
                html += `
                    <div style="margin-bottom: 2rem;">
                        <h4>Payment Proof</h4>
                        <div style="text-align: center;">
                            <img src="../${order.payment_proof}" alt="Payment Proof" class="payment-proof" style="max-width: 400px;">
                        </div>
                    </div>
                `;
            }
            
            document.getElementById('orderDetails').innerHTML = html;
        }

        // Order status actions
        function approveOrder(orderId) {
            console.log('approveOrder called with orderId:', orderId);
            
            // Get current status and payment method from the row
            const orderRow = document.querySelector(`button[onclick="approveOrder(${orderId})"]`).closest('tr');
            const statusElement = orderRow.querySelector('.status-badge');
            const currentStatus = statusElement.textContent.toLowerCase().trim();
            
            // Get payment method from the payment badge
            const paymentElement = orderRow.querySelector('.payment-badge');
            const paymentMethod = paymentElement.textContent.toLowerCase().trim();
            const isCOD = paymentMethod === 'cod';
            
            console.log('Current status:', currentStatus);
            console.log('Payment method:', paymentMethod, 'Is COD:', isCOD);
            
            let nextStatus, confirmMessage;
            
            switch(currentStatus) {
                case 'pending':
                    nextStatus = 'processing';
                    confirmMessage = 'Move this order to Processing status?';
                    break;
                case 'processing':
                    if (isCOD) {
                        // COD orders skip "paid" and go directly to "shipped"
                        nextStatus = 'shipped';
                        confirmMessage = 'Mark this COD order as Shipped? Stock will be deducted automatically.';
                    } else {
                        // Regular orders go to "paid" first
                        nextStatus = 'paid';
                        confirmMessage = 'Mark this order as Paid? This will deduct stock automatically.';
                    }
                    break;
                case 'paid':
                    nextStatus = 'shipped';
                    confirmMessage = 'Mark this order as Shipped?';
                    break;
                case 'shipped':
                    nextStatus = 'completed';
                    confirmMessage = 'Mark this order as Completed?';
                    break;
                case 'completed':
                    alert('Status is already completed');
                    return;
                case 'cancelled':
                    alert('Status is already completed');
                    return;
                default:
                    alert('Status is already completed');
                    return;
            }
            
            if (confirm(confirmMessage)) {
                console.log(`User confirmed: ${currentStatus} -> ${nextStatus} for order:`, orderId);
                updateOrderStatus(orderId, nextStatus);
            } else {
                console.log('User cancelled approval for order:', orderId);
            }
        }

        function rejectOrder(orderId) {
            console.log('rejectOrder called with orderId:', orderId);
            
            // Get current status from the row
            const orderRow = document.querySelector(`button[onclick="rejectOrder(${orderId})"]`).closest('tr');
            const statusElement = orderRow.querySelector('.status-badge');
            const currentStatus = statusElement.textContent.toLowerCase().trim();
            
            console.log('Current status for rejection:', currentStatus);
            
            // Check if order is already completed
            if (currentStatus === 'completed' || currentStatus === 'cancelled') {
                alert('Status is already completed');
                return;
            }
            
            if (confirm('Reject this order and set back to Pending? Customer will be notified.')) {
                console.log('User confirmed rejection for order:', orderId);
                updateOrderStatus(orderId, 'pending');
            } else {
                console.log('User cancelled rejection for order:', orderId);
            }
        }

        // markShipped and markCompleted functions removed - now handled by approveOrder workflow

        // Admin cancel order function (kept for backward compatibility)
        function adminCancelOrder(orderId, orderNumber) {
            if (confirm(`Are you sure you want to cancel order #${orderNumber}?`)) {
                console.log('Admin cancelling order:', orderId);
                updateOrderStatus(orderId, 'cancelled');
            }
        }

        // Update order status function - shows status selection modal
        function showStatusUpdateModal(orderId) {
            // First, get the current order status
            fetch(`order-status-handler.php?action=get_details&id=${orderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const currentStatus = data.order.status;
                        
                        const statuses = [
                            { value: 'pending', label: 'Pending' },
                            { value: 'processing', label: 'Processing' },
                            { value: 'paid', label: 'Paid' },
                            { value: 'shipped', label: 'Shipped' },
                            { value: 'completed', label: 'Completed' },
                            { value: 'cancelled', label: 'Cancelled' }
                        ];
                        
                        // Filter out 'cancelled' option if current status is 'completed'
                        const filteredStatuses = currentStatus === 'completed' 
                            ? statuses.filter(status => status.value !== 'cancelled')
                            : statuses;
                        
                        let statusOptions = filteredStatuses.map(status => 
                            `<option value="${status.value}" ${status.value === currentStatus ? 'selected' : ''}>${status.label}</option>`
                        ).join('');
                        
                        const modal = `
                            <div id="statusModal" style="display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
                                <div style="background: white; padding: 2rem; border-radius: 8px; max-width: 400px; width: 90%;">
                                    <h3 style="margin-top: 0;">Update Order Status</h3>
                                    <p>Select new status for Order #${data.order.order_number}:</p>
                                    <select id="newStatus" class="form-control" style="margin-bottom: 1rem;">
                                        ${statusOptions}
                                    </select>
                                    <div style="text-align: right;">
                                        <button onclick="closeStatusModal()" class="btn btn-secondary" style="margin-right: 0.5rem;">Cancel</button>
                                        <button onclick="confirmStatusUpdate(${orderId})" class="btn" style="background: #6c757d; border-color: #6c757d; color: white;">Update Status</button>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        document.body.insertAdjacentHTML('beforeend', modal);
                    } else {
                        alert('Failed to load order details');
                    }
                })
                .catch(error => {
                    console.error('Error loading order status:', error);
                    alert('Error loading order status');
                });
        }

        function closeStatusModal() {
            const modal = document.getElementById('statusModal');
            if (modal) {
                modal.remove();
            }
        }

        function confirmStatusUpdate(orderId) {
            const newStatus = document.getElementById('newStatus').value;
            if (newStatus) {
                closeStatusModal();
                updateOrderStatusDirect(orderId, newStatus);
            }
        }

        function updateOrderStatusDirect(orderId, newStatus) {
            console.log('Updating order:', orderId, 'to status:', newStatus);
            
            fetch('order-status-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=update_status&id=${orderId}&status=${newStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message || 'Operation failed', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Network error. Please try again.', 'danger');
            });
        }

        function updateOrderStatus(orderId, newStatus) {
            console.log('Updating order:', orderId, 'to status:', newStatus);
            
            fetch('order-status-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=update_status&id=${orderId}&status=${newStatus}`
            })
            .then(response => {
                console.log('Response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Response data:', data);
                if (data.success) {
                    showAlert(data.message, 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message || 'Operation failed', 'danger');
                }
            })
            .catch(error => {
                console.error('Full error details:', error);
                console.error('Error message:', error.message);
                if (error.message.includes('Admin login required')) {
                    showAlert('Session expired. Please login again.', 'danger');
                    setTimeout(() => window.location.href = '../auth/admin-login.php', 2000);
                } else {
                    showAlert('Network error. Please try again. Check console for details.', 'danger');
                }
            });
        }

        // Alert function
        function showAlert(message, type) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type}`;
            alertDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: 4px;
                color: white;
                background: ${type === 'success' ? '#28a745' : '#dc3545'};
                z-index: 3000;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            `;
            alertDiv.textContent = message;
            
            document.body.appendChild(alertDiv);
            
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
        }

        // Close modals when clicking outside
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.style.display = 'none';
            }
        });

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal');
                modals.forEach(modal => {
                    if (modal.style.display === 'flex') {
                        modal.style.display = 'none';
                    }
                });
            }
        });

        // Initialize Bootstrap tooltips (if Bootstrap is loaded)
        if (typeof bootstrap !== 'undefined') {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        }
        
        // Notification dropdown functionality
        function toggleNotifications() {
            const dropdown = document.getElementById('notificationDropdown');
            dropdown.classList.toggle('show');
        }
        
        // Close dropdown when clicking outside
        window.addEventListener('click', function(event) {
            const dropdown = document.getElementById('notificationDropdown');
            if (dropdown && !dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });

        // Automatic filtering functionality
        document.addEventListener('DOMContentLoaded', function() {
            const statusSelect = document.getElementById('status');
            const paymentMethodSelect = document.getElementById('payment_method');
            const searchInput = document.getElementById('search');
            const filterForm = document.querySelector('form[method="GET"]');
            
            // Auto-submit form when status or payment method changes
            if (statusSelect) {
                statusSelect.addEventListener('change', function() {
                    filterForm.submit();
                });
            }
            
            if (paymentMethodSelect) {
                paymentMethodSelect.addEventListener('change', function() {
                    filterForm.submit();
                });
            }
            
            // Auto-submit form when search input changes (with debounce)
            if (searchInput) {
                let searchTimeout;
                searchInput.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(function() {
                        filterForm.submit();
                    }, 500); // Wait 500ms after user stops typing
                });
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
