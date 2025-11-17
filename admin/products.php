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

// Get products data with filtering and pagination
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$availability = $_GET['availability'] ?? '';
$currentPage = max(1, intval($_GET['page'] ?? 1));
$productsPerPage = 30; // Show 30 products per page

try {
    // Build query with filters
    $whereClause = "WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $whereClause .= " AND (name LIKE :search OR id LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    
    if (!empty($category)) {
        $whereClause .= " AND category = :category";
        $params[':category'] = $category;
    }
    
    if ($availability !== '') {
        switch ($availability) {
            case 'low_stock':
                $whereClause .= " AND stock_quantity < reorder_threshold AND stock_quantity > 0 AND is_available = 1";
                break;
            case 'out_of_stock':
                $whereClause .= " AND stock_quantity = 0 AND is_available = 1";
                break;
            case '1':
            case '0':
                $whereClause .= " AND is_available = :availability";
                $params[':availability'] = $availability;
                break;
        }
    }
    
    // Get total count for pagination
    $countQuery = "SELECT COUNT(*) as total FROM products $whereClause";
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalProducts = $countStmt->fetch()['total'];
    
    // Calculate pagination
    $totalPages = ceil($totalProducts / $productsPerPage);
    $offset = ($currentPage - 1) * $productsPerPage;
    
    // Get products for current page
    $productsQuery = "SELECT * FROM products $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
    $productsStmt = $db->prepare($productsQuery);
    foreach ($params as $key => $value) {
        $productsStmt->bindValue($key, $value);
    }
    $productsStmt->bindValue(':limit', $productsPerPage, PDO::PARAM_INT);
    $productsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $productsStmt->execute();
    $products = $productsStmt->fetchAll();
    
    // Get categories for filter dropdown
    $categoriesQuery = "SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category";
    $categoriesStmt = $db->prepare($categoriesQuery);
    $categoriesStmt->execute();
    $categories = $categoriesStmt->fetchAll();
    
    // Get low stock count based on reorder thresholds
    $lowStockQuery = "SELECT COUNT(*) as low_stock_count FROM products WHERE stock_quantity < reorder_threshold AND stock_quantity > 0";
    $lowStockStmt = $db->prepare($lowStockQuery);
    $lowStockStmt->execute();
    $lowStockCount = $lowStockStmt->fetch()['low_stock_count'];
    
    // Get total products count (for dashboard stats)
    $totalProductsAllQuery = "SELECT COUNT(*) as total_products FROM products";
    $totalProductsAllStmt = $db->prepare($totalProductsAllQuery);
    $totalProductsAllStmt->execute();
    $totalProductsAll = $totalProductsAllStmt->fetch()['total_products'];
    
    // Get out of stock count
    $outOfStockQuery = "SELECT COUNT(*) as out_of_stock FROM products WHERE stock_quantity = 0";
    $outOfStockStmt = $db->prepare($outOfStockQuery);
    $outOfStockStmt->execute();
    $outOfStock = $outOfStockStmt->fetch()['out_of_stock'];
    
} catch (Exception $e) {
    $products = [];
    $categories = [];
    $lowStockCount = 0;
    $totalProducts = 0;
    $totalProductsAll = 0;
    $outOfStock = 0;
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
    <title>Product Management - MotoDean Admin</title>
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
            min-width: 420px;
            max-width: 500px;
            border-radius: 8px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            z-index: 1000;
            border: 1px solid #e9ecef;
            max-height: 600px;
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
            grid-template-columns: 1fr 200px 200px auto;
            gap: 1rem;
            align-items: end;
        }
        
        .filter-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #333;
        }
        
        .products-table {
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
        
        
        th, td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
            white-space: nowrap;
        }
        
        td {
            vertical-align: middle;
        }
        
        /* Table layout that fits within container */
        table {
            table-layout: fixed;
            width: 100%;
            border-collapse: collapse;
        }
        
        /* Column alignments - fit within border */
        td:nth-child(1), th:nth-child(1) { /* Product ID */
            width: 8%;
            text-align: left;
            padding: 0.5rem 0.05rem 0.5rem 0.5rem;
        }
        
        td:nth-child(2), th:nth-child(2) { /* Product Name */
            width: 21%;
            text-align: left;
            padding: 0.5rem 0.05rem 0.5rem 0.5rem;
        }
        
        td:nth-child(3), th:nth-child(3) { /* Category */
            width: 14%;
            text-align: left;
            padding: 0.5rem 0.05rem;
        }
        
        td:nth-child(4), th:nth-child(4) { /* Price */
            width: 10%;
            text-align: left;
            padding: 0.5rem 0.05rem;
        }
        
        td:nth-child(5), th:nth-child(5) { /* Stock */
            width: 10%;
            text-align: left;
            padding: 0.5rem 0.05rem;
        }
        
        td:nth-child(6), th:nth-child(6) { /* Status */
            width: 12%;
            text-align: left;
            padding: 0.5rem 0.05rem;
        }
        
        td:nth-child(7), th:nth-child(7) { /* Available */
            width: 10%;
            text-align: left;
            padding: 0.5rem 0.05rem;
        }
        
        td:nth-child(8), th:nth-child(8) { /* Actions */
            width: 15%;
            text-align: center;
            padding: 0.5rem 0.05rem;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .stock-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .stock-high {
            background: #d4edda;
            color: #155724;
        }
        
        .stock-low {
            background: #fff3cd;
            color: #856404;
        }
        
        .stock-out {
            background: #f8d7da;
            color: #721c24;
        }
        
        .availability-toggle {
            position: relative;
            width: 50px;
            height: 25px;
            background: #ccc;
            border-radius: 25px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .availability-toggle.active {
            background: #28a745;
        }
        
        .availability-toggle::before {
            content: '';
            position: absolute;
            width: 21px;
            height: 21px;
            background: white;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
        }
        
        .availability-toggle.active::before {
            transform: translateX(25px);
        }
        
        .action-buttons {
            display: flex;
            gap: 0.25rem;
            justify-content: center;
        }
        
        .btn-sm {
            padding: 0.6rem 0.8rem;
            font-size: 0.85rem;
            border-radius: 4px;
            min-width: auto;
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
            max-width: 600px;
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
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        /* Active page highlighting with grey background */
        .active-page {
            background: #666666 !important;
            border: none !important;
            color: #ffffff !important;
            font-weight: bold !important;
            border-radius: 4px !important;
            box-shadow: 0 2px 4px rgba(102, 102, 102, 0.3) !important;
        }
        
        .active-page:hover {
            background: #666666 !important;
            color: #ffffff !important;
        }
        
        /* Inactive page numbers - light grey text */
        .pagination-inactive {
            color: #999999 !important;
            background: none !important;
            border: none !important;
            padding: 0.5rem 0.75rem !important;
            text-decoration: none !important;
        }
        
        .pagination-inactive:hover {
            color: #666666 !important;
            background: none !important;
        }
        
        /* Navigation arrows - light grey */
        .pagination-nav {
            color: #999999 !important;
            background: none !important;
            border: none !important;
            padding: 0.5rem 1rem !important;
            text-decoration: none !important;
        }
        
        .pagination-nav:hover {
            color: #666666 !important;
            background: none !important;
        }
        
        /* Ellipsis styling */
        .pagination-ellipsis {
            color: #999999;
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
                    <a href="products.php" class="nav-link active">
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
                    <h1><i class="fas fa-box"></i> Product Management</h1>
                    <p>Manage your inventory, track stock levels, and control product availability</p>
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
                        
                        <!-- Stock Summary -->
                        <?php if (!empty($lowStockNotifications) || !empty($outOfStockNotifications)): ?>
                        <div style="padding: 0.75rem 1rem; background: #fff3cd; border-bottom: 1px solid #ffeaa7;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <span style="color: #856404; font-weight: 600;">
                                    <i class="fas fa-exclamation-triangle"></i> Stock Alerts
                                </span>
                                <a href="products.php?availability=low_stock" style="color: #856404; text-decoration: none; font-size: 0.85rem;">
                                    View All <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem;">
                                <?php if (!empty($outOfStockNotifications)): ?>
                                <div style="color: #dc3545;">
                                    <strong><?php echo count($outOfStockNotifications); ?></strong> Out of Stock
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($lowStockNotifications)): ?>
                                <div style="color: #856404;">
                                    <strong><?php echo count($lowStockNotifications); ?></strong> Low Stock
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Out of Stock Products -->
                        <?php if (!empty($outOfStockNotifications)): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">⚠️ Out of Stock (<?php echo count($outOfStockNotifications); ?>)</h5>
                            <?php foreach ($outOfStockNotifications as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #dc3545;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">
                                    Out of stock - Need to reorder <?php echo $product['reorder_threshold'] ?? 10; ?> units
                                </p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Unavailable Products -->
                        <?php if (!empty($unavailableProductNotifications)): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">🚫 Unavailable Products</h5>
                            <?php foreach ($unavailableProductNotifications as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #6c757d;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">Product is set as unavailable (Stock: <?php echo $product['stock_quantity']; ?>)</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Low Stock Products -->
                        <?php if (!empty($lowStockNotifications)): ?>
                        <div class="notification-section">
                            <h5 class="notification-section-title">📦 Low Stock (<?php echo count($lowStockNotifications); ?>)</h5>
                            <?php foreach ($lowStockNotifications as $product): ?>
                            <div class="notification-item">
                                <p class="notification-item-title" style="color: #ffc107;"><?php echo htmlspecialchars($product['name']); ?></p>
                                <p class="notification-item-desc">
                                    Stock: <?php echo $product['stock_quantity']; ?> units 
                                    (Threshold: <?php echo $product['reorder_threshold'] ?? 10; ?>)
                                    <?php 
                                    $deficit = ($product['reorder_threshold'] ?? 10) - $product['stock_quantity'];
                                    echo " - Need $deficit more";
                                    ?>
                                </p>
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

            <!-- Content -->
            <div style="padding: 0 2rem 2rem 2rem;">
                <!-- Product Overview Stats -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem;">
                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-box"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #667eea;"><?php echo number_format($totalProductsAll); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Total Products</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #ffc107 0%, #ff8c00 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-exclamation-triangle"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #ffc107;"><?php echo $lowStockCount; ?></h3>
                                <p style="margin: 0; color: #6c757d;">Low Stock</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #dc3545;"><?php echo $outOfStock; ?></h3>
                                <p style="margin: 0; color: #6c757d;">Out of Stock</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div class="filters-section">
                    <form method="GET" action="">
                        <div class="filters-grid">
                            <div class="filter-group">
                                <label for="search">Search Products</label>
                                <input type="text" id="search" name="search" class="form-control" 
                                       placeholder="Search by name or product ID..." 
                                       value="<?php echo htmlspecialchars($search ?? ''); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label for="category">Category</label>
                                <select id="category" name="category" class="form-control">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat['category'] ?? ''); ?>" 
                                                <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat['category'] ?? ''); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label for="availability">Availability</label>
                                <select id="availability" name="availability" class="form-control">
                                    <option value="">All Products</option>
                                    <option value="1" <?php echo $availability === '1' ? 'selected' : ''; ?>>Available</option>
                                    <option value="0" <?php echo $availability === '0' ? 'selected' : ''; ?>>Unavailable</option>
                                    <option value="low_stock" <?php echo $availability === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
                                    <option value="out_of_stock" <?php echo $availability === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                </select>
                            </div>
                            
                            
                            <div class="filter-group">
                                <button type="button" class="btn" style="background: #6c757d; border-color: #6c757d; color: white;" onclick="openAddProductModal()">
                                    <i class="fas fa-plus"></i> Add Product
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Products Table -->
                <div class="products-table">
                    <div class="table-header">
                        <h2><i class="fas fa-list"></i> Product Inventory</h2>
                    </div>
                    
                    <div>
                        <table>
                            <thead>
                                <tr>
                                    <th>Product ID</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Available</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($products)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center; padding: 2rem; color: #6c757d;">
                                            <i class="fas fa-box-open" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
                                            <p>No products found matching your criteria.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($products as $product): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($product['id'] ?? ''); ?></strong></td>
                                            <td><?php echo htmlspecialchars($product['name'] ?? ''); ?></td>
                                            <td>
                                                <span style="background: #e9ecef; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">
                                                    <?php echo htmlspecialchars($product['category'] ?? 'Uncategorized'); ?>
                                                </span>
                                            </td>
                                            <td><strong>₱<?php echo number_format($product['price'], 2); ?></strong></td>
                                            <td>
                                                <?php
                                                $threshold = $product['reorder_threshold'] ?? 10;
                                                $isLowStock = $product['stock_quantity'] < $threshold;
                                                ?>
                                                <span class="stock-badge <?php 
                                                    if ($product['stock_quantity'] == 0) echo 'stock-out';
                                                    elseif ($isLowStock) echo 'stock-low';
                                                    else echo 'stock-high';
                                                ?>">
                                                    <?php echo $product['stock_quantity']; ?> units
                                                </span>
                                                <?php if ($isLowStock): ?>
                                                <div style="font-size: 0.75rem; color: #856404; margin-top: 0.25rem; font-weight: 600;">
                                                    Reorder: <?php echo $threshold; ?> units
                                                </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($product['stock_quantity'] == 0): ?>
                                                    <span style="color: #dc3545;"><i class="fas fa-times-circle"></i> Out of Stock</span>
                                                <?php elseif ($isLowStock): ?>
                                                    <span style="color: #ffc107;"><i class="fas fa-exclamation-triangle"></i> Low Stock</span>
                                                <?php else: ?>
                                                    <span style="color: #28a745;"><i class="fas fa-check-circle"></i> In Stock</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="availability-toggle <?php echo $product['is_available'] ? 'active' : ''; ?>" 
                                                     onclick="toggleAvailability(<?php echo $product['id']; ?>, <?php echo $product['is_available'] ? 'false' : 'true'; ?>)">
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-sm" style="background: #6c757d; border-color: #6c757d; color: white;" onclick="editProduct(<?php echo $product['id']; ?>)" title="Edit">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-info" onclick="viewLogs(<?php echo $product['id']; ?>)" title="Inventory Log">
                                                        <i class="fas fa-history"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-danger" onclick="deleteProduct(<?php echo $product['id']; ?>)" title="Delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div style="margin-top: 3rem; display: flex; justify-content: center; align-items: center; gap: 1rem; flex-wrap: wrap; width: 100%;">
                            <!-- Previous Button -->
                            <?php if ($currentPage > 1): ?>
                                <?php $prevUrl = '?' . http_build_query(array_merge($_GET, ['page' => (int)$currentPage - 1])); ?>
                                <a href="<?php echo $prevUrl; ?>" class="pagination-nav">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                <?php
                                // Show up to 7 page numbers around current page
                                $startPage = max(1, (int)$currentPage - 3);
                                $endPage = min((int)$totalPages, (int)$currentPage + 3);
                                
                                // Show first page and ellipsis if we're far from page 1
                                if ($startPage > 1):
                                ?>
                                    <?php $firstUrl = '?' . http_build_query(array_merge($_GET, ['page' => 1])); ?>
                                    <a href="<?php echo $firstUrl; ?>" class="pagination-inactive">1</a>
                                    <?php if ($startPage > 2): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <!-- Page range -->
                                <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
                                    <?php $pageUrl = '?' . http_build_query(array_merge($_GET, ['page' => $page])); ?>
                                    <?php if ($page == (int)$currentPage): ?>
                                        <span class="active-page">
                                            <?php echo $page; ?>
                                        </span>
                                    <?php else: ?>
                                        <a href="<?php echo $pageUrl; ?>" class="pagination-inactive">
                                            <?php echo $page; ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <!-- Show last page if not in range -->
                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <span class="pagination-ellipsis">...</span>
                                    <?php endif; ?>
                                    <?php $lastUrl = '?' . http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>
                                    <a href="<?php echo $lastUrl; ?>" class="pagination-inactive"><?php echo $totalPages; ?></a>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Next Button -->
                            <?php if ($currentPage < $totalPages): ?>
                                <?php $nextUrl = '?' . http_build_query(array_merge($_GET, ['page' => (int)$currentPage + 1])); ?>
                                <a href="<?php echo $nextUrl; ?>" class="pagination-nav">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Pagination Info -->
                        <div style="text-align: center; margin-top: 1rem; color: #6c757d;">
                            Showing <?php echo min($offset + 1, $totalProducts); ?> to <?php echo min($offset + $productsPerPage, $totalProducts); ?> of <?php echo $totalProducts; ?> products
                            (Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>)
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Product Modal -->
    <div id="productModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Product</h3>
                <button onclick="closeProductModal()" style="background: none; border: none; font-size: 1.5rem; color: #6c757d; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="productForm" enctype="multipart/form-data">
                    <input type="hidden" id="productId" name="product_id_hidden">
                    
                    <div class="form-group">
                        <label for="product_name" class="form-label">Product Name</label>
                        <input type="text" id="product_name" name="product_name" class="form-control" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="category" class="form-label">Category</label>
                            <select id="modal_category" name="category" class="form-control" required>
                                <option value="">Select Category</option>
                                <option value="tires">Tires</option>
                                <option value="mirrors-accessories">Mirrors & Accessories</option>
                                <option value="maintenance-cleaning">Maintenance & Cleaning</option>
                                <option value="engine-cables">Engine & Control Cables</option>
                                <option value="oils-fluids">Oils & Fluids</option>
                                <option value="electrical">Electrical</option>
                                <option value="fasteners-bolts">Fasteners & Body Bolts</option>
                                <option value="brakes">Brakes</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="unit_price" class="form-label">Unit Price (₱)</label>
                            <input type="number" id="unit_price" name="unit_price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="stock_quantity" class="form-label">Stock Quantity</label>
                            <input type="number" id="stock_quantity" name="stock_quantity" class="form-control" min="0" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="product_image" class="form-label">Product Image</label>
                        <input type="file" id="product_image" name="product_image" class="form-control" accept="image/*" onchange="previewImage(this)">
                        <div id="imagePreview" style="margin-top: 10px; display: none;">
                            <img id="previewImg" src="" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; border: 1px solid #ddd;">
                            <div style="margin-top: 5px;">
                                <button type="button" onclick="removeImage()" class="btn btn-outline" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">
                                    <i class="fas fa-trash"></i> Remove Image
                                </button>
                            </div>
                        </div>
                        <div id="currentImage" style="margin-top: 10px; display: none;">
                            <p style="font-size: 0.9rem; color: #6c757d; margin-bottom: 5px;">Current Image:</p>
                            <img id="currentImg" src="" alt="Current" style="max-width: 200px; max-height: 200px; border-radius: 8px; border: 1px solid #ddd;">
                            <div style="margin-top: 5px;">
                                <button type="button" onclick="removeCurrentImage()" class="btn btn-outline" style="font-size: 0.8rem; padding: 0.25rem 0.5rem; color: #dc3545;">
                                    <i class="fas fa-trash"></i> Remove Current Image
                                </button>
                            </div>
                        </div>
                        <small style="color: #6c757d;">Upload a new image to replace the current one. Supported formats: JPG, PNG, WEBP. Max size: 5MB</small>
                    </div>
                    
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="checkbox" id="is_available" name="is_available" value="1" checked>
                            Product is available for sale
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeProductModal()" class="btn btn-outline" style="background: #6c757d; color: white; border-color: #6c757d;">Cancel</button>
                <button type="button" onclick="saveProduct()" class="btn btn-primary" id="saveBtn" style="background: #495057; color: white; border-color: #495057;">
                    <i class="fas fa-save"></i> Save Product
                </button>
            </div>
        </div>
    </div>


    <!-- Inventory Logs Modal -->
    <div id="logsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Inventory Logs</h3>
                <button onclick="closeLogsModal()" style="background: none; border: none; font-size: 1.5rem; color: #6c757d; cursor: pointer;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="logsContent">
                    <div style="text-align: center; padding: 2rem;">
                        <i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #667eea;"></i>
                        <p>Loading logs...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeLogsModal()" class="btn btn-outline">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('adminSidebar');
            sidebar.classList.toggle('open');
        }

        // Product Modal Functions
        function openAddProductModal() {
            document.getElementById('modalTitle').textContent = 'Add New Product';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            document.getElementById('is_available').checked = true;
            
            // Hide image previews for new products
            document.getElementById('imagePreview').style.display = 'none';
            document.getElementById('currentImage').style.display = 'none';
            
            document.getElementById('productModal').style.display = 'flex';
        }

        function closeProductModal() {
            document.getElementById('productModal').style.display = 'none';
        }

        // Image handling functions
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                // Validate file size (5MB max)
                if (file.size > 5 * 1024 * 1024) {
                    showAlert('File size must be less than 5MB', 'danger');
                    input.value = '';
                    return;
                }
                
                // Validate file type
                const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                if (!allowedTypes.includes(file.type)) {
                    showAlert('Please select a valid image file (JPG, PNG, WEBP)', 'danger');
                    input.value = '';
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                    document.getElementById('imagePreview').style.display = 'block';
                    document.getElementById('currentImage').style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        }

        function removeImage() {
            document.getElementById('product_image').value = '';
            document.getElementById('imagePreview').style.display = 'none';
            document.getElementById('previewImg').src = '';
        }

        function removeCurrentImage() {
            if (confirm('Are you sure you want to remove the current image? This action cannot be undone.')) {
                // Add a hidden input to indicate image removal
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'remove_image';
                hiddenInput.value = '1';
                document.getElementById('productForm').appendChild(hiddenInput);
                
                document.getElementById('currentImage').style.display = 'none';
                showAlert('Current image will be removed when you save the product', 'warning');
            }
        }

        function editProduct(productId) {
            // Fetch product data and populate form
            fetch(`product-handler.php?action=get&id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const product = data.product;
                        document.getElementById('modalTitle').textContent = 'Edit Product';
                        document.getElementById('productId').value = product.id;
                        document.getElementById('product_name').value = product.name;
                        document.getElementById('modal_category').value = product.category;
                        document.getElementById('unit_price').value = product.price;
                        document.getElementById('stock_quantity').value = product.stock_quantity;
                        document.getElementById('description').value = product.description || '';
                        document.getElementById('is_available').checked = product.is_available == 1;
                        
                        // Handle image display
                        if (product.image_url) {
                            document.getElementById('currentImage').style.display = 'block';
                            // Fix image path for admin panel display
                            const imagePath = product.image_url.startsWith('assets/') ? '../' + product.image_url : product.image_url;
                            document.getElementById('currentImg').src = imagePath;
                        } else {
                            document.getElementById('currentImage').style.display = 'none';
                        }
                        
                        // Hide new image preview
                        document.getElementById('imagePreview').style.display = 'none';
                        
                        document.getElementById('productModal').style.display = 'flex';
                    } else {
                        showAlert('Error loading product data', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Network error', 'danger');
                });
        }

        function saveProduct() {
            const form = document.getElementById('productForm');
            const formData = new FormData(form);
            const productId = document.getElementById('productId').value;
            
            formData.append('action', productId ? 'update' : 'create');
            if (productId) {
                formData.append('id', productId);
            }

            const saveBtn = document.getElementById('saveBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            fetch('product-handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(data.message, 'success');
                    closeProductModal();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Network error', 'danger');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save"></i> Save Product';
            });
        }

        function deleteProduct(productId) {
            if (confirm('Are you sure you want to delete this product? This action cannot be undone.')) {
                fetch('product-handler.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `action=delete&id=${productId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showAlert(data.message, 'success');
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        showAlert(data.message, 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showAlert('Network error', 'danger');
                });
            }
        }

        function toggleAvailability(productId, newStatus) {
            fetch('product-handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `action=toggle_availability&id=${productId}&status=${newStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    showAlert(data.message, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Network error', 'danger');
            });
        }


        // Logs Modal Functions
        function viewLogs(productId) {
            document.getElementById('logsModal').style.display = 'flex';
            
            fetch(`product-handler.php?action=get_logs&id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayLogs(data.logs);
                    } else {
                        document.getElementById('logsContent').innerHTML = '<p>Error loading logs</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('logsContent').innerHTML = '<p>Network error loading logs</p>';
                });
        }

        function closeLogsModal() {
            document.getElementById('logsModal').style.display = 'none';
        }

        function displayLogs(logs) {
            const content = document.getElementById('logsContent');
            
            if (logs.length === 0) {
                content.innerHTML = '<p style="text-align: center; color: #6c757d;">No inventory logs found.</p>';
                return;
            }

            let html = '<div style="max-height: 400px; overflow-y: auto;">';
            logs.forEach(log => {
                const date = new Date(log.created_at).toLocaleString();
                const actionColor = log.action === 'increase' ? '#28a745' : (log.action === 'decrease' ? '#dc3545' : '#ffc107');
                const actionIcon = log.action === 'increase' ? 'fa-plus' : (log.action === 'decrease' ? 'fa-minus' : 'fa-edit');
                
                html += `
                    <div style="border-left: 4px solid ${actionColor}; background: #f8f9fa; padding: 1rem; margin-bottom: 1rem; border-radius: 4px;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <strong style="color: ${actionColor};">
                                <i class="fas ${actionIcon}"></i> 
                                ${log.action.charAt(0).toUpperCase() + log.action.slice(1)} Stock
                            </strong>
                            <small style="color: #6c757d;">${date}</small>
                        </div>
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Quantity:</strong> ${log.quantity_change} units
                            <span style="margin-left: 1rem;"><strong>New Total:</strong> ${log.new_quantity} units</span>
                        </div>
                        <div style="margin-bottom: 0.5rem;">
                            <strong>Reason:</strong> ${log.reason}
                        </div>
                        ${log.notes ? `<div style="margin-bottom: 0.5rem;"><strong>Notes:</strong> ${log.notes}</div>` : ''}
                        <div>
                            <strong>Updated by:</strong> ${log.updated_by_name}
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            content.innerHTML = html;
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

        // Automatic filtering functionality
        document.addEventListener('DOMContentLoaded', function() {
            const categorySelect = document.getElementById('category');
            const availabilitySelect = document.getElementById('availability');
            const searchInput = document.getElementById('search');
            const filterForm = document.querySelector('form[method="GET"]');
            
            // Auto-submit form when category or availability changes
            if (categorySelect) {
                categorySelect.addEventListener('change', function() {
                    filterForm.submit();
                });
            }
            
            if (availabilitySelect) {
                availabilitySelect.addEventListener('change', function() {
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
</body>
</html>
