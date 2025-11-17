<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuditTrail.php';

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

// Get dashboard KPI data
try {
    // Total Orders
    $totalOrdersQuery = "SELECT COUNT(*) as total_orders FROM orders";
    $totalOrdersStmt = $db->prepare($totalOrdersQuery);
    $totalOrdersStmt->execute();
    $totalOrders = $totalOrdersStmt->fetch()['total_orders'];
    
    // Total Revenue (only completed orders)
    $totalRevenueQuery = "SELECT SUM(total_amount) as total_revenue FROM orders WHERE status = 'completed'";
    $totalRevenueStmt = $db->prepare($totalRevenueQuery);
    $totalRevenueStmt->execute();
    $totalRevenue = $totalRevenueStmt->fetch()['total_revenue'] ?? 0;
    
    // Low Stock Products (based on intelligent reorder thresholds)
    $lowStockQuery = "SELECT COUNT(*) as low_stock_count FROM products WHERE stock_quantity < reorder_threshold AND stock_quantity > 0";
    $lowStockStmt = $db->prepare($lowStockQuery);
    $lowStockStmt->execute();
    $lowStockCount = $lowStockStmt->fetch()['low_stock_count'];
    
    // Monthly Sales (current month) - only completed orders
    $currentMonth = date('Y-m');
    $monthlySalesQuery = "SELECT SUM(total_amount) as monthly_sales FROM orders WHERE DATE_FORMAT(created_at, '%Y-%m') = :current_month AND status = 'completed'";
    $monthlySalesStmt = $db->prepare($monthlySalesQuery);
    $monthlySalesStmt->bindParam(':current_month', $currentMonth);
    $monthlySalesStmt->execute();
    $monthlySales = $monthlySalesStmt->fetch()['monthly_sales'] ?? 0;
    
    // Best Selling Products
    $bestSellingQuery = "SELECT p.name as product_name, SUM(oi.quantity) as total_sold 
                        FROM order_items oi 
                        JOIN products p ON oi.product_id = p.id 
                        JOIN orders o ON oi.order_id = o.id 
                        WHERE o.status != 'cancelled'
                        GROUP BY oi.product_id, p.name 
                        ORDER BY total_sold DESC 
                        LIMIT 3";
    $bestSellingStmt = $db->prepare($bestSellingQuery);
    $bestSellingStmt->execute();
    $bestSellingProducts = $bestSellingStmt->fetchAll();
    
    
    // Order frequency (last 7 days)
    $orderFrequencyQuery = "SELECT DATE(created_at) as order_date, COUNT(*) as order_count 
                           FROM orders 
                           WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) 
                           GROUP BY DATE(created_at) 
                           ORDER BY order_date ASC";
    $orderFrequencyStmt = $db->prepare($orderFrequencyQuery);
    $orderFrequencyStmt->execute();
    $orderFrequency = $orderFrequencyStmt->fetchAll();
    
    
} catch (Exception $e) {
    // Log the error for debugging
    error_log('Dashboard query error: ' . $e->getMessage());
    
    // Set default values if queries fail
    $totalOrders = 0;
    $totalRevenue = 0;
    $lowStockCount = 0;
    $monthlySales = 0;
    $bestSellingProducts = [];
    $orderFrequency = [];
}

// Get notification data separately (after main queries)
require_once 'shared_notifications.php';
$notificationData = getAdminNotifications($db);
extract($notificationData); // Extract variables: $lowStockNotifications, $outOfStockNotifications, etc.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php echo getCSRFTokenMeta(); ?>
    <title>Admin Dashboard - MotoDean System</title>
    <link rel="stylesheet" href="../assets/css/main.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            
            .mobile-toggle {
                display: block;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1001;
                background: #667eea;
                color: white;
                border: none;
                padding: 0.5rem;
                border-radius: 4px;
                cursor: pointer;
            }
        }
        
        .mobile-toggle {
            display: none;
        }
    </style>
</head>
<body>
    <!-- Mobile Toggle Button -->
    <button class="mobile-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>

    <div class="admin-layout">
        <!-- Left Sidebar -->
        <aside class="admin-sidebar" id="adminSidebar">
            <!-- Sidebar Header -->
            <div class="sidebar-header">
                <h1>MotoDean </h1>
                <p>Management Inventory</p>
            </div>

            <!-- Navigation Menu -->
            <nav class="sidebar-nav">
                <div class="nav-item">
                    <a href="dashboard.php" class="nav-link active">
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
                    <h1>Dashboard Overview</h1>
                    <p>Welcome back, <?php echo htmlspecialchars($admin['first_name']); ?>! Here's what's happening with your store today.</p>
                </div>
                
                <!-- Notification Dropdown -->
                <div class="notification-dropdown" id="notificationDropdown">
                    <button class="notification-btn" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($totalNotifications > 0): ?>
                            <span class="notification-badge"><?php echo min($totalNotifications, 99); ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <div class="notification-dropdown-content">
                        <div class="notification-header">
                            <h4 style="margin: 0; color: #333;">Notifications</h4>
                            <small style="color: #6c757d;"><?php echo $totalNotifications; ?> new notifications</small>
                        </div>
                        
                        <!-- Out of Stock Products -->
                        <?php if (!empty($outOfStockNotifications)): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">⚠️ Out of Stock</h5>
                            <?php foreach ($outOfStockNotifications as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #dc3545;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">Product is completely out of stock</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Low Stock Products -->
                        <?php if (!empty($lowStockNotifications)): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">📦 Low Stock</h5>
                            <?php foreach ($lowStockNotifications as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #ffc107;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">Only <?php echo $product['stock_quantity']; ?> units remaining</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Pending Orders -->
                        <?php if (!empty($pendingOrderNotifications)): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">⏳ Pending Orders</h5>
                            <?php foreach ($pendingOrderNotifications as $order): ?>
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
                        <?php if (!empty($paidOrderNotifications)): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">💰 Paid Orders</h5>
                            <?php foreach ($paidOrderNotifications as $order): ?>
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
                        
                        <?php if ($totalNotifications === 0): ?>
                        <div class="notification-empty">
                            <i class="fas fa-check-circle" style="font-size: 2rem; color: #28a745; margin-bottom: 0.5rem;"></i>
                            <p>All caught up! No new notifications.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content -->
            <div style="padding: 0 2rem 2rem 2rem;">
                <!-- Key Performance Indicators (KPIs) -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 3rem;">
                    <!-- Low Stock Products KPI (Priority) -->
                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #ffc107;"><?php echo $lowStockCount; ?></h3>
                                <p style="margin: 0; color: #6c757d;">Low-Stock Products</p>
                            </div>
                        </div>
                    </div>

                    <!-- Total Revenue KPI -->
                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-peso-sign"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #28a745;">₱<?php echo number_format($totalRevenue, 2); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Total Revenue</p>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Sales KPI -->
                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #17a2b8 0%, #6f42c1 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #17a2b8;">₱<?php echo number_format($monthlySales, 2); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Monthly Sales</p>
                            </div>
                        </div>
                    </div>

                    <!-- Total Orders KPI -->
                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #667eea;"><?php echo number_format($totalOrders); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Total Orders</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Best Selling Products -->
                <div class="card" style="margin-bottom: 2rem;">
                    <div class="card-header">
                        <h2 class="card-title">Top Selling Products</h2>
                    </div>
                    <div style="padding: 1rem;">
                        <?php if (!empty($bestSellingProducts)): ?>
                            <?php foreach ($bestSellingProducts as $index => $product): ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; padding: 1rem; margin-bottom: 0.5rem; background: #f8f9fa; border-radius: 8px; border-left: 4px solid <?php echo $index === 0 ? '#ffc107' : ($index === 1 ? '#6c757d' : '#cd7f32'); ?>;">
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <div style="font-size: 1.5rem; font-weight: bold; color: <?php echo $index === 0 ? '#ffc107' : ($index === 1 ? '#6c757d' : '#cd7f32'); ?>; min-width: 30px;">
                                            <?php echo $index + 1; ?>
                                        </div>
                                        <div>
                                            <h4 style="margin: 0; color: #333;"><?php echo htmlspecialchars($product['product_name'] ?? ''); ?></h4>
                                        </div>
                                    </div>
                                    <div style="text-align: right;">
                                        <span style="font-size: 1.2rem; font-weight: bold; color: #667eea;"><?php echo $product['total_sold']; ?></span>
                                        <p style="margin: 0; color: #6c757d; font-size: 0.8rem;">units sold</p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 2rem; color: #6c757d;">
                                <i class="fas fa-chart-line" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                <p>No sales data available yet.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Charts & Visuals Section -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 2rem; margin-bottom: 2rem;">

                    <!-- Order Frequency Chart -->
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title"><i class="fas fa-chart-bar" style="color: #667eea;"></i> Order Frequency (Last 7 Days)</h2>
                        </div>
                        <div style="padding: 1rem;">
                            <canvas id="orderFrequencyChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>


                <!-- Quick Actions -->
                <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Quick Actions</h2>
                </div>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                    <a href="orders.php" class="btn" style="background: #6c757d; border-color: #6c757d; color: white; display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 1.5rem;">
                        <i class="fas fa-receipt"></i>
                        Manage Orders
                    </a>
                    <a href="products.php" class="btn" style="background: #6c757d; border-color: #6c757d; color: white; display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 1.5rem;">
                        <i class="fas fa-box"></i>
                        Manage Products
                    </a>
                    <a href="analytics.php" class="btn" style="background: #6c757d; border-color: #6c757d; color: white; display: flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 1.5rem;">
                        <i class="fas fa-chart-bar"></i>
                        View Analytics
                    </a>
                </div>
            </div>


        </main>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            sidebar.classList.toggle('open');
        }

        // Add some admin dashboard interactivity
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stats on page load
            const statNumbers = document.querySelectorAll('.card h3');
            statNumbers.forEach(stat => {
                stat.style.opacity = '0';
                stat.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    stat.style.transition = 'all 0.5s ease';
                    stat.style.opacity = '1';
                    stat.style.transform = 'translateY(0)';
                }, 300);
            });

            // Add hover effects to action buttons
            const actionButtons = document.querySelectorAll('.card .btn');
            actionButtons.forEach(button => {
                button.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-3px)';
                });
                
                button.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });

            // Close sidebar when clicking outside on mobile
            document.addEventListener('click', function(e) {
                const sidebar = document.getElementById('adminSidebar');
                const toggle = document.querySelector('.mobile-toggle');
                
                if (window.innerWidth <= 768 && 
                    !sidebar.contains(e.target) && 
                    !toggle.contains(e.target) && 
                    sidebar.classList.contains('open')) {
                    sidebar.classList.remove('open');
                }
            });

            // Handle window resize
            window.addEventListener('resize', function() {
                const sidebar = document.getElementById('adminSidebar');
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('open');
                }
            });

            // Initialize Charts
            initializeCharts();
        });

        // Chart initialization function
        function initializeCharts() {

            // Order Frequency Chart
            const orderFrequencyCtx = document.getElementById('orderFrequencyChart').getContext('2d');
            const orderFrequencyData = <?php echo json_encode($orderFrequency); ?>;
            
            new Chart(orderFrequencyCtx, {
                type: 'bar',
                data: {
                    labels: orderFrequencyData.map(item => {
                        const date = new Date(item.order_date);
                        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                    }),
                    datasets: [{
                        label: 'Orders',
                        data: orderFrequencyData.map(item => parseInt(item.order_count)),
                        backgroundColor: 'rgba(102, 126, 234, 0.8)',
                        borderColor: '#667eea',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
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
            const button = dropdown.querySelector('.notification-btn');
            
            if (!dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
    </script>
</body>
</html>
