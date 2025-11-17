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

// Get date filter parameters
$startDate = isset($_GET['start']) ? $_GET['start'] : null;
$endDate = isset($_GET['end']) ? $_GET['end'] : null;
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'monthly';

// Load CSV data
require_once __DIR__ . '/predictive-analytics/load_csv_data.php';
$csvData = getCSVAnalytics($startDate, $endDate);
$csvAnalytics = $csvData['success'] ? $csvData['data'] : null;

// Get analytics data
try {
    // Daily Sales (Last 30 days)
    $dailySalesQuery = "SELECT DATE(created_at) as sale_date, 
                               COUNT(*) as order_count,
                               SUM(total_amount) as daily_revenue
                        FROM orders 
                        WHERE status IN ('paid', 'shipped', 'completed') 
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                        GROUP BY DATE(created_at) 
                        ORDER BY sale_date DESC";
    
    $dailySalesStmt = $db->prepare($dailySalesQuery);
    $dailySalesStmt->execute();
    $dailySales = $dailySalesStmt->fetchAll();
    
    // Weekly Sales (Last 12 weeks)
    $weeklySalesQuery = "SELECT YEAR(created_at) as year,
                                WEEK(created_at) as week,
                                COUNT(*) as order_count,
                                SUM(total_amount) as weekly_revenue,
                                DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY)) as week_start
                         FROM orders 
                         WHERE status IN ('paid', 'shipped', 'completed') 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
                         GROUP BY YEAR(created_at), WEEK(created_at) 
                         ORDER BY year DESC, week DESC";
    
    $weeklySalesStmt = $db->prepare($weeklySalesQuery);
    $weeklySalesStmt->execute();
    $weeklySales = $weeklySalesStmt->fetchAll();
    
    // Monthly Sales (Last 12 months or filtered by date)
    $monthlySalesQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                                 COUNT(*) as order_count,
                                 SUM(total_amount) as monthly_revenue
                          FROM orders 
                          WHERE status IN ('paid', 'shipped', 'completed')";
    
    if ($activeTab === 'monthly' && ($startDate || $endDate)) {
        if ($startDate && $endDate) {
            // For monthly sales, include entire months that overlap with the date range
            $monthlySalesQuery .= " AND DATE_FORMAT(created_at, '%Y-%m') BETWEEN DATE_FORMAT(:start_date, '%Y-%m') AND DATE_FORMAT(:end_date, '%Y-%m')";
        } elseif ($startDate) {
            $monthlySalesQuery .= " AND DATE_FORMAT(created_at, '%Y-%m') >= DATE_FORMAT(:start_date, '%Y-%m')";
        } elseif ($endDate) {
            $monthlySalesQuery .= " AND DATE_FORMAT(created_at, '%Y-%m') <= DATE_FORMAT(:end_date, '%Y-%m')";
        }
    } else {
        $monthlySalesQuery .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)";
    }
    
    $monthlySalesQuery .= " GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
    
    $monthlySalesStmt = $db->prepare($monthlySalesQuery);
    if ($activeTab === 'monthly' && ($startDate || $endDate)) {
        if ($startDate) {
            $monthlySalesStmt->bindParam(':start_date', $startDate);
        }
        if ($endDate) {
            $monthlySalesStmt->bindParam(':end_date', $endDate);
        }
    }
    $monthlySalesStmt->execute();
    $monthlySales = $monthlySalesStmt->fetchAll();
    
    // Deduplicate monthly sales data by month
    $uniqueMonthlySales = [];
    foreach ($monthlySales as $sale) {
        $month = $sale['month'];
        if (!isset($uniqueMonthlySales[$month])) {
            $uniqueMonthlySales[$month] = $sale;
        } else {
            // If duplicate month exists, combine the data
            $uniqueMonthlySales[$month]['order_count'] += $sale['order_count'];
            $uniqueMonthlySales[$month]['monthly_revenue'] += $sale['monthly_revenue'];
        }
    }
    $monthlySales = array_values($uniqueMonthlySales);
    
    // Debug: Log the monthly sales data
    error_log("Monthly Sales Data Count: " . count($monthlySales));
    error_log("Monthly Sales Query: " . $monthlySalesQuery);
    error_log("Start Date: " . $startDate . ", End Date: " . $endDate . ", Active Tab: " . $activeTab);
    if (count($monthlySales) > 0) {
        error_log("First Monthly Sale: " . json_encode($monthlySales[0]));
        error_log("Last Monthly Sale: " . json_encode($monthlySales[count($monthlySales) - 1]));
    }
    
    // Top Customers (with optional date filter)
    $topCustomersQuery = "SELECT u.first_name, u.last_name, u.email,
                                 COUNT(o.id) as order_count,
                                 SUM(o.total_amount) as total_spent,
                                 MAX(o.created_at) as last_order
                          FROM users u
                          JOIN orders o ON u.id = o.user_id
                          WHERE o.status IN ('paid', 'shipped', 'completed')";
    
    if ($startDate || $endDate) {
        if ($startDate && $endDate) {
            $topCustomersQuery .= " AND o.created_at BETWEEN :start_date AND :end_date";
        } elseif ($startDate) {
            $topCustomersQuery .= " AND o.created_at >= :start_date";
        } elseif ($endDate) {
            $topCustomersQuery .= " AND o.created_at <= :end_date";
        }
    }
    
    $topCustomersQuery .= " GROUP BY u.id ORDER BY total_spent DESC LIMIT 10";
    
    $topCustomersStmt = $db->prepare($topCustomersQuery);
    if ($startDate || $endDate) {
        if ($startDate) {
            $topCustomersStmt->bindParam(':start_date', $startDate);
        }
        if ($endDate) {
            $topCustomersStmt->bindParam(':end_date', $endDate);
        }
    }
    $topCustomersStmt->execute();
    $topCustomers = $topCustomersStmt->fetchAll();
    
    // Debug: Log top customers data
    error_log("Top Customers Count: " . count($topCustomers));
    if (count($topCustomers) > 0) {
        error_log("First Customer: " . json_encode($topCustomers[0]));
    }
    
    // Customer Purchase Trends (with optional date filter)
    $customerTrendsQuery = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                                   COUNT(DISTINCT user_id) as unique_customers,
                                   COUNT(*) as total_orders,
                                   AVG(total_amount) as avg_order_value
                            FROM orders 
                            WHERE status IN ('paid', 'shipped', 'completed')";
    
    if ($activeTab === 'customers' && ($startDate || $endDate)) {
        if ($startDate && $endDate) {
            $customerTrendsQuery .= " AND created_at BETWEEN :start_date AND :end_date";
        } elseif ($startDate) {
            $customerTrendsQuery .= " AND created_at >= :start_date";
        } elseif ($endDate) {
            $customerTrendsQuery .= " AND created_at <= :end_date";
        }
    } else {
        $customerTrendsQuery .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)";
    }
    
    $customerTrendsQuery .= " GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month DESC";
    
    $customerTrendsStmt = $db->prepare($customerTrendsQuery);
    if ($activeTab === 'customers' && ($startDate || $endDate)) {
        if ($startDate) {
            $customerTrendsStmt->bindParam(':start_date', $startDate);
        }
        if ($endDate) {
            $customerTrendsStmt->bindParam(':end_date', $endDate);
        }
    }
    $customerTrendsStmt->execute();
    $customerTrends = $customerTrendsStmt->fetchAll();
    
    // Product Performance (with optional date filter) - TOP 10 by units sold
    $productPerformanceQuery = "SELECT p.name as product_name,
                                       p.category,
                                       SUM(oi.quantity) as total_sold,
                                       SUM(oi.quantity * oi.unit_price) as revenue
                                FROM order_items oi
                                JOIN products p ON oi.product_id = p.id
                                JOIN orders o ON oi.order_id = o.id
                                WHERE o.status IN ('paid', 'shipped', 'completed')";
    
    // Debug: Log the filter parameters
    error_log("Product Performance Filter Debug - activeTab: " . $activeTab . ", startDate: " . $startDate . ", endDate: " . $endDate);
    
    if ($startDate || $endDate) {
        if ($startDate && $endDate) {
            $productPerformanceQuery .= " AND o.created_at BETWEEN :start_date AND :end_date";
        } elseif ($startDate) {
            $productPerformanceQuery .= " AND o.created_at >= :start_date";
        } elseif ($endDate) {
            $productPerformanceQuery .= " AND o.created_at <= :end_date";
        }
        error_log("Product Performance Query with date filter: " . $productPerformanceQuery);
    }
    
    $productPerformanceQuery .= " GROUP BY oi.product_id ORDER BY total_sold DESC LIMIT 50";
    
    $productPerformanceStmt = $db->prepare($productPerformanceQuery);
    if ($startDate || $endDate) {
        if ($startDate) {
            $productPerformanceStmt->bindParam(':start_date', $startDate);
        }
        if ($endDate) {
            $productPerformanceStmt->bindParam(':end_date', $endDate);
        }
    }
    $productPerformanceStmt->execute();
    $productPerformance = $productPerformanceStmt->fetchAll();
    
    // Debug: Log the results
    error_log("Product Performance Results Count: " . count($productPerformance));
    
    
    // Best Selling Products Per Month (Top 5 products over last 12 months)
    $bestSellingPerMonthQuery = "SELECT 
                                    p.name as product_name,
                                    DATE_FORMAT(o.created_at, '%Y-%m') as month,
                                    SUM(oi.quantity) as units_sold
                                 FROM order_items oi
                                 JOIN products p ON oi.product_id = p.id
                                 JOIN orders o ON oi.order_id = o.id
                                 WHERE o.status IN ('paid', 'shipped', 'completed')
                                   AND o.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                                 GROUP BY oi.product_id, DATE_FORMAT(o.created_at, '%Y-%m')
                                 ORDER BY month ASC, units_sold DESC";
    $bestSellingPerMonthStmt = $db->prepare($bestSellingPerMonthQuery);
    $bestSellingPerMonthStmt->execute();
    $bestSellingPerMonth = $bestSellingPerMonthStmt->fetchAll();
    
    // Category Performance (Sales distribution by category)
    $categoryPerformanceQuery = "SELECT 
                                    p.category,
                                    SUM(oi.quantity) as total_sold,
                                    SUM(oi.quantity * oi.unit_price) as revenue
                                 FROM order_items oi
                                 JOIN products p ON oi.product_id = p.id
                                 JOIN orders o ON oi.order_id = o.id
                                 WHERE o.status IN ('paid', 'shipped', 'completed')";
    
    // Add date filtering support
    if ($startDate || $endDate) {
        if ($startDate && $endDate) {
            $categoryPerformanceQuery .= " AND o.created_at BETWEEN :start_date AND :end_date";
        } elseif ($startDate) {
            $categoryPerformanceQuery .= " AND o.created_at >= :start_date";
        } elseif ($endDate) {
            $categoryPerformanceQuery .= " AND o.created_at <= :end_date";
        }
    }
    
    $categoryPerformanceQuery .= " GROUP BY p.category ORDER BY total_sold DESC";
    
    $categoryPerformanceStmt = $db->prepare($categoryPerformanceQuery);
    if ($startDate || $endDate) {
        if ($startDate) {
            $categoryPerformanceStmt->bindParam(':start_date', $startDate);
        }
        if ($endDate) {
            $categoryPerformanceStmt->bindParam(':end_date', $endDate);
        }
    }
    $categoryPerformanceStmt->execute();
    $categoryPerformance = $categoryPerformanceStmt->fetchAll();
    
    // Debug: Log category performance results
    error_log("Category Performance Results Count: " . count($categoryPerformance));
    
    // Create database-only variables for Product Performance tab
    $productPerformanceDB = $productPerformance; // Database only
    $categoryPerformanceDB = $categoryPerformance; // Database only
    
    // Customer Analytics Data (Database Only - No CSV)
    // Purchase Timing by Hour
    $purchaseTimingQuery = "SELECT 
                                HOUR(created_at) as hour,
                                COUNT(*) as order_count
                            FROM orders
                            WHERE status IN ('paid', 'shipped', 'completed')
                            GROUP BY HOUR(created_at)
                            ORDER BY hour";
    $purchaseTimingStmt = $db->prepare($purchaseTimingQuery);
    $purchaseTimingStmt->execute();
    $purchaseTiming = $purchaseTimingStmt->fetchAll();
    
    // Purchase Frequency (orders per customer)
    $purchaseFrequencyQuery = "SELECT 
                                    order_count,
                                    COUNT(*) as customer_count
                                FROM (
                                    SELECT user_id, COUNT(*) as order_count
                                    FROM orders
                                    WHERE status IN ('paid', 'shipped', 'completed')
                                    GROUP BY user_id
                                ) as customer_orders
                                GROUP BY order_count
                                ORDER BY order_count";
    $purchaseFrequencyStmt = $db->prepare($purchaseFrequencyQuery);
    $purchaseFrequencyStmt->execute();
    $purchaseFrequency = $purchaseFrequencyStmt->fetchAll();
    
    // Payment Method Usage
    $paymentMethodQuery = "SELECT 
                                payment_method,
                                COUNT(*) as count
                            FROM orders
                            WHERE status IN ('paid', 'shipped', 'completed')
                              AND payment_method IS NOT NULL
                            GROUP BY payment_method
                            ORDER BY count DESC";
    $paymentMethodStmt = $db->prepare($paymentMethodQuery);
    $paymentMethodStmt->execute();
    $paymentMethods = $paymentMethodStmt->fetchAll();
    
    // Product Category Preferences (from actual orders)
    $customerCategoryQuery = "SELECT 
                                    p.category,
                                    COUNT(DISTINCT o.user_id) as customer_count,
                                    SUM(oi.quantity) as total_quantity
                                FROM order_items oi
                                JOIN products p ON oi.product_id = p.id
                                JOIN orders o ON oi.order_id = o.id
                                WHERE o.status IN ('paid', 'shipped', 'completed')
                                GROUP BY p.category
                                ORDER BY customer_count DESC";
    $customerCategoryStmt = $db->prepare($customerCategoryQuery);
    $customerCategoryStmt->execute();
    $customerCategories = $customerCategoryStmt->fetchAll();
    
    // Sales Summary - Get ALL orders from database (only completed orders for revenue)
    $salesSummaryQuery = "SELECT 
                             COUNT(*) as total_orders,
                             SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as total_revenue,
                             AVG(CASE WHEN status = 'completed' THEN total_amount ELSE NULL END) as avg_order_value,
                             COUNT(DISTINCT user_id) as unique_customers
                          FROM orders";
    
    $salesSummaryStmt = $db->prepare($salesSummaryQuery);
    $salesSummaryStmt->execute();
    $salesSummary = $salesSummaryStmt->fetch();
    
    // Debug: Log CSV analytics data
    error_log("CSV Analytics Available: " . ($csvAnalytics ? 'Yes' : 'No'));
    if ($csvAnalytics) {
        error_log("CSV Monthly Sales Count: " . count($csvAnalytics['monthly_sales']));
        if (count($csvAnalytics['monthly_sales']) > 0) {
            error_log("CSV Monthly Sales Sample: " . json_encode($csvAnalytics['monthly_sales'][0]));
        }
    }
    
    // Merge CSV data with database data
    if ($csvAnalytics) {
        // Merge daily sales
        $dailySales = array_merge($dailySales, $csvAnalytics['daily_sales']);
        
        // Merge weekly sales  
        $weeklySales = array_merge($weeklySales, $csvAnalytics['weekly_sales']);
        
        // Merge monthly sales - combine data instead of just merging arrays
        $csvMonthlySales = $csvAnalytics['monthly_sales'];
        $combinedMonthlySales = [];
        
        // Add database monthly sales
        foreach ($monthlySales as $sale) {
            $month = $sale['month'];
            $combinedMonthlySales[$month] = $sale;
        }
        
        // Add CSV monthly sales, combining if month already exists
        foreach ($csvMonthlySales as $sale) {
            $month = $sale['month'];
            if (isset($combinedMonthlySales[$month])) {
                // Combine data if month already exists
                $combinedMonthlySales[$month]['order_count'] += $sale['order_count'];
                $combinedMonthlySales[$month]['monthly_revenue'] += $sale['monthly_revenue'];
            } else {
                // Add new month
                $combinedMonthlySales[$month] = $sale;
            }
        }
        
        $monthlySales = array_values($combinedMonthlySales);
        
        // Sort monthly sales by month
        usort($monthlySales, function($a, $b) {
            return strcmp($a['month'], $b['month']);
        });
        
        // Merge product performance
        $productPerformance = array_merge($productPerformance, $csvAnalytics['product_performance']);
        
        // Merge category performance from CSV product data
        if (isset($csvAnalytics['product_performance']) && is_array($csvAnalytics['product_performance'])) {
            $csvCategoryData = [];
            
            // Group CSV products by category
            foreach ($csvAnalytics['product_performance'] as $csvProduct) {
                $category = $csvProduct['category'] ?? 'CSV Data';
                if (!isset($csvCategoryData[$category])) {
                    $csvCategoryData[$category] = [
                        'category' => $category,
                        'total_sold' => 0,
                        'revenue' => 0
                    ];
                }
                $csvCategoryData[$category]['total_sold'] += $csvProduct['total_sold'];
                $csvCategoryData[$category]['revenue'] += $csvProduct['revenue'];
            }
            
            // Merge with database category performance
            $combinedCategoryPerformance = [];
            
            // Add database categories
            foreach ($categoryPerformance as $category) {
                $combinedCategoryPerformance[$category['category']] = $category;
            }
            
            // Add/merge CSV categories
            foreach ($csvCategoryData as $category) {
                $categoryName = $category['category'];
                if (isset($combinedCategoryPerformance[$categoryName])) {
                    // Combine data if category already exists
                    $combinedCategoryPerformance[$categoryName]['total_sold'] += $category['total_sold'];
                    $combinedCategoryPerformance[$categoryName]['revenue'] += $category['revenue'];
                } else {
                    // Add new category
                    $combinedCategoryPerformance[$categoryName] = $category;
                }
            }
            
            // Sort by total_sold and convert back to array
            usort($combinedCategoryPerformance, function($a, $b) {
                return $b['total_sold'] - $a['total_sold'];
            });
            
            $categoryPerformance = $combinedCategoryPerformance;
            
            // Debug: Log merged category performance
            error_log("Merged Category Performance Count: " . count($categoryPerformance));
            if (count($categoryPerformance) > 0) {
                error_log("First Merged Category: " . json_encode($categoryPerformance[0]));
            }
        }
        
        // Update summary statistics
        $salesSummary['total_orders'] += $csvAnalytics['summary']['total_orders'];
        $salesSummary['total_revenue'] += $csvAnalytics['summary']['total_revenue'];
        $salesSummary['avg_order_value'] = $salesSummary['total_orders'] > 0 
            ? $salesSummary['total_revenue'] / $salesSummary['total_orders'] 
            : 0;
    }
    
} catch (Exception $e) {
    $dailySales = [];
    $weeklySales = [];
    $monthlySales = [];
    $topCustomers = [];
    $customerTrends = [];
    $productPerformance = [];
    $bestSellingPerMonth = [];
    $categoryPerformance = [];
    $purchaseTiming = [];
    $purchaseFrequency = [];
    $paymentMethods = [];
    $customerCategories = [];
    $salesSummary = [
        'total_orders' => 0,
        'total_revenue' => 0,
        'avg_order_value' => 0,
        'unique_customers' => 0
    ];
    
    // Use CSV data as fallback
    if ($csvAnalytics) {
        $dailySales = $csvAnalytics['daily_sales'];
        $weeklySales = $csvAnalytics['weekly_sales'];
        $monthlySales = $csvAnalytics['monthly_sales'];
        $productPerformance = $csvAnalytics['product_performance'];
        $salesSummary = $csvAnalytics['summary'];
    }
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
    <title>Reports & Analytics - MotoDean Admin</title>
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
        
        .report-tabs {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .tab-buttons {
            display: flex;
            border-bottom: 1px solid #dee2e6;
        }
        
        .tab-button {
            padding: 1rem 2rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #6c757d;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
        }
        
        .tab-button.active {
            color: #667eea;
            border-bottom-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        
        .tab-content {
            display: none;
            padding: 1.5rem;
            max-height: calc(100vh - 300px);
            overflow-y: auto;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .chart-container {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            height: 400px;
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #dee2e6;
        }
        
        .chart-header h3 {
            margin: 0;
            color: #333;
        }

        .date-filter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-date-input {
            font-size: 0.9rem;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .customer-table {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
            max-height: 500px;
        }
        
        .table-wrapper {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .table-header {
            background: #6c757d;
            color: white;
            padding: 1rem 1.5rem;
        }
        
        .table-header h3 {
            margin: 0;
            font-size: 1.1rem;
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
        
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }
        
        tr:hover {
            background: #f8f9fa;
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
            
            .tab-buttons {
                flex-wrap: wrap;
            }
            
            .tab-button {
                flex: 1;
                min-width: 120px;
            }
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

        /* Predictive Analytics Styles */
        .predictive-insights {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .insight-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .insight-header {
            background: #6c757d;
            color: white;
            padding: 1rem;
            font-weight: 600;
        }

        .insight-content {
            padding: 1.5rem;
        }

        .trend-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }

        .trend-item:last-child {
            border-bottom: none;
        }

        .trend-label {
            font-weight: 500;
            color: #333;
        }

        .trend-value {
            font-weight: 600;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            background: #e9ecef;
        }

        .trend-value.positive {
            background: #d4edda;
            color: #155724;
        }

        .predicted-product {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid #eee;
        }

        .predicted-product:last-child {
            border-bottom: none;
        }

        .product-name {
            font-weight: 500;
            color: #333;
        }

        .prediction-score {
            font-weight: 600;
            color: #28a745;
            background: #d4edda;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
        }

        /* Reorder Alerts Styles */
        .alert-section {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .alert-header {
            background: #6c757d;
            color: white;
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .alert-summary {
            display: flex;
            gap: 1.5rem;
            align-items: center;
        }

        .alert-count {
            font-size: 1.5rem;
            font-weight: bold;
            padding: 0.5rem 1rem;
            border-radius: 6px;
        }

        .alert-count.critical {
            background: #dc3545;
            color: white;
        }

        .alert-count.warning {
            background: #ffc107;
            color: black;
        }

        .alert-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .reorder-table {
            margin-top: 1.5rem;
        }

        .critical-alert {
            background: #f8d7da;
        }

        .warning-alert {
            background: #fff3cd;
        }

        .priority {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .priority.critical {
            background: #dc3545;
            color: white;
        }

        .priority.warning {
            background: #ffc107;
            color: black;
        }

        .reorder-recommendations {
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: #f8f9fa;
        }

        .recommendation-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .recommendation-card h4 {
            color: #6c757d;
            margin-bottom: 1rem;
        }

        .recommendation-card ul {
            list-style: none;
            padding: 0;
        }

        .recommendation-card li {
            padding: 0.5rem 0;
            border-bottom: 1px solid #eee;
            position: relative;
            padding-left: 1.5rem;
        }

        .recommendation-card li:before {
            content: "💡";
            position: absolute;
            left: 0;
        }

        .recommendation-card li:last-child {
            border-bottom: none;
        }

        @media (max-width: 768px) {
            .predictive-insights {
                grid-template-columns: 1fr;
            }
            
            .alert-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .alert-summary {
                justify-content: center;
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
                    <a href="analytics.php" class="nav-link active">
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
                    <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
                    <p>Comprehensive sales reports, customer insights, and business analytics</p>
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

            <!-- Content -->
            <div style="padding: 0 2rem 2rem 2rem;">
                <!-- Sales Summary Cards -->
                <div class="stats-grid">
                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #667eea;"><?php echo number_format($salesSummary['total_orders'] ?? 0); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Total Orders</p>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div style="display: flex; align-items: center; gap: 1rem;">
                            <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; padding: 1rem; border-radius: 10px; font-size: 1.5rem;">
                                <i class="fas fa-peso-sign"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 2rem; color: #28a745;">₱<?php echo number_format($salesSummary['total_revenue'] ?? 0, 2); ?></h3>
                                <p style="margin: 0; color: #6c757d;">Total Revenue</p>
                            </div>
                        </div>
                    </div>

                </div>


                <!-- Report Tabs -->
                <div class="report-tabs">
                    <div class="tab-buttons">
                        <button class="tab-button active" onclick="showTab('monthly')">
                            <i class="fas fa-calendar-alt"></i> Monthly Sales
                        </button>
                        <button class="tab-button" onclick="showTab('customers')">
                            <i class="fas fa-users"></i> Customer Analytics
                        </button>
                        <button class="tab-button" onclick="showTab('products')">
                            <i class="fas fa-box"></i> Product Performance
                        </button>
                    </div>

                    <!-- Monthly Sales Tab -->
                    <div id="monthly" class="tab-content active">
                        <div class="chart-container" style="height: 350px;">
                            <div class="chart-header">
                                <h3><i class="fas fa-chart-line"></i> Sales Trend</h3>
                                <div class="date-filter">
                                    <label for="monthlyStartDate" style="margin-right: 0.5rem; font-size: 0.9rem;">From:</label>
                                    <input type="date" id="monthlyStartDate" class="filter-date-input" style="margin-right: 1rem; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;" onchange="filterMonthlySales()">
                                    <label for="monthlyEndDate" style="margin-right: 0.5rem; font-size: 0.9rem;">To:</label>
                                    <input type="date" id="monthlyEndDate" class="filter-date-input" style="margin-right: 1rem; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;" onchange="filterMonthlySales()">
                                    <button onclick="resetMonthlyFilter()" class="btn btn-sm" style="background: #6c757d; color: white; padding: 0.5rem 1rem; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fas fa-redo"></i> Reset
                                    </button>
                            </div>
                            </div>
                            <div style="height: 280px;">
                                <canvas id="monthlySalesChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="customer-table">
                            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center;">
                                <h3>Monthly Sales Data (Last 12 Months)</h3>
                                <button onclick="exportMonthlySales()" class="btn btn-sm" style="background: #28a745; color: white; padding: 0.3rem 0.8rem; border: none; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </div>
                            <div class="table-wrapper">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th>Orders</th>
                                            <th>Revenue</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($monthlySales)): ?>
                                            <tr>
                                                <td colspan="3" style="text-align: center; padding: 2rem; color: #6c757d;">
                                                    No sales data available for the last 12 months.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($monthlySales as $sale): ?>
                                                <tr>
                                                    <td><?php echo date('F Y', strtotime($sale['month'] . '-01')); ?></td>
                                                    <td><?php echo $sale['order_count']; ?></td>
                                                    <td>₱<?php echo number_format($sale['monthly_revenue'], 2); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Customer Analytics Tab -->
                    <div id="customers" class="tab-content">
                        

                        <!-- Chart Grid -->
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                            <!-- Purchase Timing Chart -->
                            <div class="chart-container" style="height: 400px;">
                                <div class="chart-header">
                                    <h3><i class="fas fa-clock"></i> Purchase Timing Analysis</h3>
                                    <p style="margin: 0.5rem 0; color: #666; font-size: 0.9rem;">Peak shopping hours and daily patterns</p>
                                </div>
                                <div style="height: 320px;">
                                    <canvas id="purchaseTimingChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Purchase Frequency Chart -->
                            <div class="chart-container" style="height: 400px;">
                                <div class="chart-header">
                                    <h3><i class="fas fa-chart-bar"></i> Purchase Frequency Distribution</h3>
                                    <p style="margin: 0.5rem 0; color: #666; font-size: 0.9rem;">How often customers make purchases</p>
                                </div>
                                <div style="height: 320px;">
                                    <canvas id="purchaseFrequencyChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                            <!-- Payment Method Usage Chart -->
                            <div class="chart-container" style="height: 400px;">
                                <div class="chart-header">
                                    <h3><i class="fas fa-credit-card"></i> Payment Method Preferences</h3>
                                    <p style="margin: 0.5rem 0; color: #666; font-size: 0.9rem;">Customer payment preferences</p>
                            </div>
                                <div style="height: 320px;">
                                    <canvas id="paymentMethodChart"></canvas>
                                </div>
                            </div>
                            
                            <!-- Product Category Preferences Chart -->
                            <div class="chart-container" style="height: 400px;">
                                <div class="chart-header">
                                    <h3><i class="fas fa-tags"></i> Product Category Preferences</h3>
                                    <p style="margin: 0.5rem 0; color: #666; font-size: 0.9rem;">Most popular product categories</p>
                                </div>
                                <div style="height: 320px;">
                                    <canvas id="productCategoryChart"></canvas>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Customer Analytics Data Table -->
                        <div class="customer-table">
                            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center;">
                                <h3>Customer Analytics Overview</h3>
                                <button onclick="exportCustomerAnalytics()" class="btn btn-sm" style="background: #28a745; color: white; padding: 0.3rem 0.8rem; border: none; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </div>
                            <div class="table-wrapper">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Customer</th>
                                            <th>Orders</th>
                                            <th>Total Spent</th>
                                            <th>Last Order</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($topCustomers)): ?>
                                            <tr>
                                                <td colspan="4" style="text-align: center; padding: 2rem; color: #6c757d;">
                                                    No customer data available.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($topCustomers as $customer): ?>
                                                <tr>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                            <div style="width: 32px; height: 32px; background: #007bff; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold;">
                                                                <?php echo strtoupper(substr($customer['first_name'], 0, 1) . substr($customer['last_name'], 0, 1)); ?>
                                                            </div>
                                                            <div>
                                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($customer['first_name'] . ' ' . $customer['last_name']); ?></div>
                                                                <div style="font-size: 0.8rem; color: #6c757d;"><?php echo htmlspecialchars($customer['email']); ?></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo $customer['order_count']; ?></td>
                                                    <td>₱<?php echo number_format($customer['total_spent'], 2); ?></td>
                                                    <td><?php echo date('M d, Y', strtotime($customer['last_order'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Product Performance Tab -->
                    <div id="products" class="tab-content">
                        
                        <div style="margin-bottom: 2rem;">
                            <h2 style="color: #333; margin-bottom: 0.5rem;">Product Performance Analytics</h2>
                            <p style="color: #666; margin: 0; font-size: 0.95rem;">Best-selling products and sales trends</p>
                        </div>

                        <!-- Chart Grid -->
                        <div style="margin-bottom: 2rem;">
                            <!-- Best Selling Products Chart -->
                            <div class="chart-container" style="height: 400px;">
                                <div class="chart-header">
                                    <h3>Best Selling Products</h3>
                                </div>
                                <div style="height: 340px;">
                                    <canvas id="bestSellingProductsChart"></canvas>
                                </div>
                            </div>
                            
                        </div>
                        
                        <!-- Product Performance Reports Table -->
                        <div class="customer-table">
                            <div class="table-header" style="display: flex; justify-content: space-between; align-items: center;">
                                <h3>Product Performance Reports - All Products</h3>
                                <button onclick="exportProductPerformance()" class="btn btn-sm" style="background: #28a745; color: white; padding: 0.3rem 0.8rem; border: none; border-radius: 4px; cursor: pointer; font-size: 0.8rem;">
                                    <i class="fas fa-download"></i> Export
                                </button>
                            </div>
                            <div class="table-wrapper">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Product Name</th>
                                            <th>Category</th>
                                            <th>Units Sold</th>
                                            <th>Revenue</th>
                                            <th>Performance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($productPerformanceDB)): ?>
                                            <tr>
                                                <td colspan="5" style="text-align: center; padding: 2rem; color: #6c757d;">
                                                    No product performance data available.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php 
                                            // Calculate total units and revenue for percentage calculations
                                            $totalUnits = array_sum(array_column($productPerformanceDB, 'total_sold'));
                                            $totalRevenue = array_sum(array_column($productPerformanceDB, 'revenue'));
                                            
                                            // Sort by units sold descending
                                            usort($productPerformanceDB, function($a, $b) {
                                                return $b['total_sold'] - $a['total_sold'];
                                            });
                                            
                                            foreach ($productPerformanceDB as $index => $product): 
                                                $unitsPercentage = $totalUnits > 0 ? (($product['total_sold'] / $totalUnits) * 100) : 0;
                                                $revenuePercentage = $totalRevenue > 0 ? (($product['revenue'] / $totalRevenue) * 100) : 0;
                                                
                                                // Performance indicator
                                                $performanceClass = '';
                                                $performanceText = '';
                                                if ($index < 3) {
                                                    $performanceClass = 'style="color: #28a745; font-weight: bold;"';
                                                    $performanceText = 'Top Performer';
                                                } elseif ($index < 7) {
                                                    $performanceClass = 'style="color: #ffc107; font-weight: bold;"';
                                                    $performanceText = 'Good';
                                                } else {
                                                    $performanceClass = 'style="color: #6c757d;"';
                                                    $performanceText = 'Average';
                                                }
                                            ?>
                                                <tr>
                                                    <td>
                                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                            <div style="width: 32px; height: 32px; background: #007bff; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: bold;">
                                                                <?php echo $index + 1; ?>
                                                            </div>
                                                            <div>
                                                                <div style="font-weight: 500;"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <span style="padding: 0.25rem 0.5rem; background: #e9ecef; border-radius: 4px; font-size: 0.8rem;">
                                                            <?php echo htmlspecialchars(ucwords(str_replace('-', ' ', $product['category']))); ?>
                                                        </span>
                                                    </td>
                                                    <td style="text-align: center; font-weight: 500;">
                                                        <?php echo number_format($product['total_sold']); ?>
                                                    </td>
                                                    <td style="text-align: right; font-weight: 500;">
                                                        ₱<?php echo number_format($product['revenue'], 2); ?>
                                                    </td>
                                                    <td style="text-align: center;">
                                                        <span <?php echo $performanceClass; ?>>
                                                            <?php echo $performanceText; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        

                    </div>

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

        // Tab functionality
        function showTab(tabName) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.tab-button');
            buttons.forEach(button => button.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked button
            const clickedButton = Array.from(buttons).find(btn => {
                const text = btn.textContent.toLowerCase();
                if (tabName === 'monthly' && text.includes('monthly')) return true;
                if (tabName === 'customers' && text.includes('customer')) return true;
                if (tabName === 'products' && text.includes('product')) return true;
                return false;
            });
            if (clickedButton) {
                clickedButton.classList.add('active');
            } else if (event && event.target) {
            event.target.classList.add('active');
            }
            
            // Initialize charts for the selected tab
            setTimeout(() => initializeCharts(tabName), 100);
        }

        // Initialize charts
        function initializeCharts(tabName = 'monthly') {
            const dailySalesData = <?php echo json_encode(array_reverse($dailySales)); ?>;
            const weeklySalesData = <?php echo json_encode(array_reverse($weeklySales)); ?>;
            const monthlySalesData = <?php echo json_encode($monthlySales); ?>;
            const customerTrendsData = <?php echo json_encode(array_reverse($customerTrends)); ?>;
            const productPerformanceData = <?php echo json_encode($productPerformanceDB ?? []); ?>;
            const bestSellingPerMonthData = <?php echo json_encode($bestSellingPerMonth ?? []); ?>;
            const purchaseTimingData = <?php echo json_encode($purchaseTiming ?? []); ?>;
            const purchaseFrequencyData = <?php echo json_encode($purchaseFrequency ?? []); ?>;
            const paymentMethodsData = <?php echo json_encode($paymentMethods ?? []); ?>;
            const customerCategoriesData = <?php echo json_encode($customerCategories ?? []); ?>;
            
            console.log('Initializing charts for tab:', tabName);
            console.log('Monthly Sales Data:', monthlySalesData);
            console.log('Monthly Sales Data Length:', monthlySalesData.length);
            if (monthlySalesData.length > 0) {
                console.log('First Monthly Sale:', monthlySalesData[0]);
                console.log('Last Monthly Sale:', monthlySalesData[monthlySalesData.length - 1]);
            }
            console.log('Product Performance Data:', productPerformanceData);
            console.log('Product Performance Data Length:', productPerformanceData.length);
            if (productPerformanceData.length > 0) {
                console.log('First Product:', productPerformanceData[0]);
            }

            if (tabName === 'daily' || tabName === 'all') {
                // Daily Sales Chart
                const dailyCanvas = document.getElementById('dailySalesChart');
                if (dailyCanvas) {
                    const dailyCtx = dailyCanvas.getContext('2d');
                    new Chart(dailyCtx, {
                        type: 'line',
                        data: {
                            labels: dailySalesData.map(item => new Date(item.sale_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
                            datasets: [{
                                label: 'Daily Revenue (₱)',
                                data: dailySalesData.map(item => parseFloat(item.daily_revenue) || 0),
                                borderColor: '#667eea',
                                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4
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
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            if (tabName === 'weekly' || tabName === 'all') {
                // Weekly Sales Chart
                const weeklyCanvas = document.getElementById('weeklySalesChart');
                if (weeklyCanvas) {
                    const weeklyCtx = weeklyCanvas.getContext('2d');
                    new Chart(weeklyCtx, {
                        type: 'bar',
                        data: {
                            labels: weeklySalesData.map(item => `Week ${item.week}, ${item.year}`),
                            datasets: [{
                                label: 'Weekly Revenue (₱)',
                                data: weeklySalesData.map(item => parseFloat(item.weekly_revenue) || 0),
                                backgroundColor: 'rgba(40, 167, 69, 0.8)',
                                borderColor: '#28a745',
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
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            if (tabName === 'monthly' || tabName === 'all') {
                // Monthly Sales Chart
                const monthlyCanvas = document.getElementById('monthlySalesChart');
                if (monthlyCanvas) {
                    // Destroy existing chart if it exists
                    if (monthlyCanvas.chart) {
                        monthlyCanvas.chart.destroy();
                    }
                    
                    const monthlyCtx = monthlyCanvas.getContext('2d');
                    monthlyCanvas.chart = new Chart(monthlyCtx, {
                        type: 'line',
                        data: {
                            labels: monthlySalesData.map((item, index) => {
                                console.log(`Processing month ${index}:`, item.month);
                                const [year, month] = item.month.split('-');
                                const date = new Date(parseInt(year), parseInt(month) - 1, 1);
                                const formatted = date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                                console.log(`Formatted label: ${formatted}`);
                                return formatted;
                            }),
                            datasets: [{
                                label: 'Monthly Revenue (₱)',
                                data: monthlySalesData.map(item => parseFloat(item.monthly_revenue) || 0),
                                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                borderColor: '#ffc107',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 4,
                                pointHoverRadius: 6
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
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            if (tabName === 'customers' || tabName === 'all') {
                console.log('Purchase Timing Data:', purchaseTimingData);
                console.log('Purchase Frequency Data:', purchaseFrequencyData);
                console.log('Payment Methods Data:', paymentMethodsData);
                console.log('Customer Categories Data:', customerCategoriesData);
                
                // 1. Purchase Timing Chart - Using Real Data
                const purchaseTimingCanvas = document.getElementById('purchaseTimingChart');
                if (purchaseTimingCanvas) {
                    const purchaseTimingCtx = purchaseTimingCanvas.getContext('2d');
                    // Create 24-hour array
                    const hourlyData = new Array(24).fill(0);
                    let totalOrders = 0;
                    
                    if (purchaseTimingData.length > 0) {
                        purchaseTimingData.forEach(item => {
                            hourlyData[parseInt(item.hour)] = parseInt(item.order_count);
                            totalOrders += parseInt(item.order_count);
                        });
                    }
                    
                    // Group into time periods and calculate percentages
                    const timeLabels = ['12-3 AM', '3-6 AM', '6-9 AM', '9 AM-12 PM', '12-3 PM', '3-6 PM', '6-9 PM', '9 PM-12 AM'];
                    const groupedData = [
                        hourlyData.slice(0, 3).reduce((a, b) => a + b, 0),
                        hourlyData.slice(3, 6).reduce((a, b) => a + b, 0),
                        hourlyData.slice(6, 9).reduce((a, b) => a + b, 0),
                        hourlyData.slice(9, 12).reduce((a, b) => a + b, 0),
                        hourlyData.slice(12, 15).reduce((a, b) => a + b, 0),
                        hourlyData.slice(15, 18).reduce((a, b) => a + b, 0),
                        hourlyData.slice(18, 21).reduce((a, b) => a + b, 0),
                        hourlyData.slice(21, 24).reduce((a, b) => a + b, 0)
                    ];
                    
                    const percentageData = totalOrders > 0 
                        ? groupedData.map(count => ((count / totalOrders) * 100).toFixed(1))
                        : groupedData;
                    
                    new Chart(purchaseTimingCtx, {
                        type: 'bar',
                        data: {
                            labels: timeLabels,
                            datasets: [{
                                label: 'Orders (%)',
                                data: percentageData,
                                backgroundColor: [
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(255, 99, 132, 0.8)',
                                    'rgba(255, 99, 132, 0.8)',
                                    'rgba(54, 162, 235, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(54, 162, 235, 1)'
                                ],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.parsed.y + '% of orders';
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 40,
                                    ticks: {
                                        callback: function(value) {
                                            return value + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // 2. Purchase Frequency Chart - Using Real Data
                const purchaseFrequencyCanvas = document.getElementById('purchaseFrequencyChart');
                if (purchaseFrequencyCanvas) {
                    const purchaseFrequencyCtx = purchaseFrequencyCanvas.getContext('2d');
                    // Group frequency data into ranges
                    const ranges = {'1-2': 0, '3-5': 0, '6-10': 0, '11-20': 0, '21+': 0};
                    let totalCustomers = 0;
                    
                    if (purchaseFrequencyData.length > 0) {
                        purchaseFrequencyData.forEach(item => {
                            const count = parseInt(item.order_count);
                            const customers = parseInt(item.customer_count);
                            totalCustomers += customers;
                            
                            if (count <= 2) ranges['1-2'] += customers;
                            else if (count <= 5) ranges['3-5'] += customers;
                            else if (count <= 10) ranges['6-10'] += customers;
                            else if (count <= 20) ranges['11-20'] += customers;
                            else ranges['21+'] += customers;
                        });
                    }
                    
                    const percentageData = totalCustomers > 0
                        ? Object.values(ranges).map(count => ((count / totalCustomers) * 100).toFixed(1))
                        : [0, 0, 0, 0, 0];
                    
                    new Chart(purchaseFrequencyCtx, {
                        type: 'bar',
                        data: {
                            labels: ['1-2', '3-5', '6-10', '11-20', '21+'],
                            datasets: [{
                                label: 'Customers (%)',
                                data: percentageData,
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.8)',
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(255, 205, 86, 0.8)',
                                    'rgba(75, 192, 192, 0.8)',
                                    'rgba(153, 102, 255, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 205, 86, 1)',
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(153, 102, 255, 1)'
                                ],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.parsed.y + '% of customers';
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    max: 40,
                                    ticks: {
                                        callback: function(value) {
                                            return value + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // 3. Payment Method Usage Chart - Using Real Data
                const paymentMethodCanvas = document.getElementById('paymentMethodChart');
                if (paymentMethodCanvas) {
                    const paymentMethodCtx = paymentMethodCanvas.getContext('2d');
                    const methodLabels = {'gcash': 'GCash', 'paymaya': 'PayMaya', 'cod': 'COD'};
                    let labels = [];
                    let data = [];
                    let totalCount = 0;
                    
                    if (paymentMethodsData.length > 0) {
                        paymentMethodsData.forEach(item => {
                            totalCount += parseInt(item.count);
                        });
                        
                        labels = paymentMethodsData.map(item => methodLabels[item.payment_method] || item.payment_method);
                        data = paymentMethodsData.map(item => {
                            return totalCount > 0 ? ((parseInt(item.count) / totalCount) * 100).toFixed(1) : 0;
                        });
                    } else {
                        labels = ['No data'];
                        data = [100];
                    }
                    
                    new Chart(paymentMethodCtx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: [
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(255, 205, 86, 0.8)',
                                    'rgba(255, 99, 132, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 205, 86, 1)',
                                    'rgba(255, 99, 132, 1)'
                                ],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.label + ': ' + context.parsed + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                // 4. Product Category Preferences Chart - Using Real Data (All 8 Categories)
                const productCategoryCanvas = document.getElementById('productCategoryChart');
                if (productCategoryCanvas) {
                    const productCategoryCtx = productCategoryCanvas.getContext('2d');
                    const categoryLabels = {
                        'tires': 'Tires',
                        'maintenance-cleaning': 'Maintenance & Cleaning',
                        'mirrors-accessories': 'Mirrors & Accessories',
                        'oils-fluids': 'Oils & Fluids',
                        'electrical': 'Electrical',
                        'brakes': 'Brakes',
                        'engine-control-cables': 'Engine & Control Cables',
                        'fasteners-body-bolts': 'Fasteners & Body Bolts'
                    };
                    
                    let labels = [];
                    let data = [];
                    let totalQuantity = 0;
                    
                    if (customerCategoriesData.length > 0) {
                        customerCategoriesData.forEach(item => {
                            totalQuantity += parseInt(item.total_quantity);
                        });
                        
                        labels = customerCategoriesData.map(item => categoryLabels[item.category] || item.category);
                        data = customerCategoriesData.map(item => {
                            return totalQuantity > 0 ? ((parseInt(item.total_quantity) / totalQuantity) * 100).toFixed(1) : 0;
                        });
                    } else {
                        labels = ['No data'];
                        data = [100];
                    }
                    
                    new Chart(productCategoryCtx, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.8)',
                                    'rgba(54, 162, 235, 0.8)',
                                    'rgba(255, 205, 86, 0.8)',
                                    'rgba(75, 192, 192, 0.8)',
                                    'rgba(153, 102, 255, 0.8)',
                                    'rgba(255, 159, 64, 0.8)',
                                    'rgba(199, 199, 199, 0.8)',
                                    'rgba(83, 102, 255, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(255, 99, 132, 1)',
                                    'rgba(54, 162, 235, 1)',
                                    'rgba(255, 205, 86, 1)',
                                    'rgba(75, 192, 192, 1)',
                                    'rgba(153, 102, 255, 1)',
                                    'rgba(255, 159, 64, 1)',
                                    'rgba(199, 199, 199, 1)',
                                    'rgba(83, 102, 255, 1)'
                                ],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.label + ': ' + context.parsed + '%';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            }

            if (tabName === 'products' || tabName === 'all') {
                console.log('Product Performance Data:', productPerformanceData);
                console.log('Best Selling Per Month Data:', bestSellingPerMonthData);
                
                // 1. Best Selling Products Chart - Using Real Data
                const bestSellingCanvas = document.getElementById('bestSellingProductsChart');
                if (bestSellingCanvas) {
                    const bestSellingCtx = bestSellingCanvas.getContext('2d');
                    let topProducts = [];
                    if (productPerformanceData.length > 0) {
                        topProducts = productPerformanceData.slice(0, 8);
                    } else {
                        // Fallback demo data if no real data available
                        topProducts = [
                            {product_name: 'No data available', total_sold: 0}
                        ];
                    }
                    
                    new Chart(bestSellingCtx, {
                        type: 'bar',
                        data: {
                            labels: topProducts.map(p => p.product_name),
                            datasets: [{
                                label: 'Units Sold',
                                data: topProducts.map(p => parseInt(p.total_sold) || 0),
                                backgroundColor: [
                                    'rgba(255, 99, 132, 0.8)', 'rgba(54, 162, 235, 0.8)', 'rgba(255, 205, 86, 0.8)',
                                    'rgba(75, 192, 192, 0.8)', 'rgba(153, 102, 255, 0.8)', 'rgba(255, 159, 64, 0.8)',
                                    'rgba(199, 199, 199, 0.8)', 'rgba(83, 102, 255, 0.8)'
                                ],
                                borderColor: [
                                    'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)', 'rgba(255, 205, 86, 1)',
                                    'rgba(75, 192, 192, 1)', 'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
                                    'rgba(199, 199, 199, 1)', 'rgba(83, 102, 255, 1)'
                                ],
                                borderWidth: 2
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return context.parsed.y + ' units sold';
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return value + ' units';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

            }

            // Predictive Analytics Chart
            if (tabName === 'predictive' || tabName === 'all') {
                const predictiveCanvas = document.getElementById('predictiveChart');
                if (predictiveCanvas) {
                    const predictiveCtx = predictiveCanvas.getContext('2d');
                    // Load predictive data from API
                    loadPredictiveData().then(predictiveData => {
                        new Chart(predictiveCtx, {
                            type: 'line',
                            data: {
                                labels: predictiveData.labels,
                                datasets: [{
                                    label: 'Actual Sales',
                                    data: predictiveData.actual,
                                    borderColor: '#667eea',
                                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4
                                }, {
                                    label: 'Predicted Sales',
                                    data: predictiveData.predicted,
                                    borderColor: '#28a745',
                                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                                    borderWidth: 3,
                                    borderDash: [5, 5],
                                    fill: false,
                                    tension: 0.4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'top',
                                        labels: {
                                            padding: 20,
                                            usePointStyle: true
                                        }
                                    },
                                    tooltip: {
                                        mode: 'index',
                                        intersect: false,
                                        callbacks: {
                                            label: function(context) {
                                                return context.dataset.label + ': ₱' + context.parsed.y.toLocaleString();
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: {
                                            color: 'rgba(0,0,0,0.1)'
                                        },
                                        ticks: {
                                            callback: function(value) {
                                                return '₱' + value.toLocaleString();
                                            }
                                        }
                                    },
                                    x: {
                                        grid: {
                                            color: 'rgba(0,0,0,0.1)'
                                        }
                                    }
                                },
                                interaction: {
                                    mode: 'nearest',
                                    axis: 'x',
                                    intersect: false
                                }
                            }
                        });
                    }).catch(error => {
                        console.error('Error loading predictive data:', error);
                        // Fallback to sample data
                        const sampleData = {
                            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                            actual: [120, 135, 150, 140, 160, 175, 190, 180, 200, 220, 210, 240],
                            predicted: [125, 130, 145, 155, 165, 170, 185, 195, 205, 215, 225, 235]
                        };
                        // Create chart with sample data
                        createPredictiveChart(predictiveCtx, sampleData);
                    });
                }
            }
        }


        // Load predictive data from API
        async function loadPredictiveData() {
            try {
                const response = await fetch('predictive-analytics/get_predictions.php');
                const result = await response.json();
                
                if (result.success) {
                    const data = result.data;
                    
                    // Generate chart data from predictions
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    const currentMonth = new Date().getMonth();
                    const labels = months.slice(Math.max(0, currentMonth - 5), currentMonth + 1);
                    
                    // Sample actual and predicted data (in real implementation, this would come from the API)
                    const actual = [120, 135, 150, 140, 160, 175, 190, 180, 200, 220, 210, 240].slice(-labels.length);
                    const predicted = [125, 130, 145, 155, 165, 170, 185, 195, 205, 215, 225, 235].slice(-labels.length);
                    
                    return {
                        labels: labels,
                        actual: actual,
                        predicted: predicted,
                        predictions: data.predictions,
                        reorderAlerts: data.reorder_alerts,
                        topProducts: data.top_products,
                        trendAnalysis: data.trend_analysis
                    };
                } else {
                    throw new Error(result.message || 'Failed to load predictive data');
                }
            } catch (error) {
                console.error('Error loading predictive data:', error);
                throw error;
            }
        }
        
        // Create predictive chart with data
        function createPredictiveChart(ctx, data) {
            return new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Actual Sales',
                        data: data.actual,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Predicted Sales',
                        data: data.predicted,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        borderWidth: 3,
                        borderDash: [5, 5],
                        fill: false,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                padding: 20,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ₱' + context.parsed.y.toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        },
                        x: {
                            grid: {
                                color: 'rgba(0,0,0,0.1)'
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
        }

        // Date filter functions for Monthly Sales
        function filterMonthlySales() {
            const startDate = document.getElementById('monthlyStartDate').value;
            const endDate = document.getElementById('monthlyEndDate').value;
            
            console.log('Monthly Sales Filter - Start Date:', startDate, 'End Date:', endDate);
            
            // Filter if at least one date is selected
            if (startDate || endDate) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', 'monthly');
                if (startDate) url.searchParams.set('start', startDate);
                if (endDate) url.searchParams.set('end', endDate);
                
                console.log('Redirecting to:', url.toString());
                window.location.href = url.toString();
            } else {
                window.location.href = 'analytics.php?tab=monthly';
            }
        }

        function resetMonthlyFilter() {
            // Reset to default: January 1, 2023 to today
            const today = new Date();
            const startDate = new Date('2023-01-01');
            
            document.getElementById('monthlyStartDate').value = startDate.toISOString().split('T')[0];
            document.getElementById('monthlyEndDate').value = today.toISOString().split('T')[0];
            window.location.href = 'analytics.php?tab=monthly';
        }

        // Customer Analytics functions removed - using static demo data for charts

        // Date filter functions for Product Performance
        function filterProductPerformance() {
            const startDate = document.getElementById('productStartDate').value;
            const endDate = document.getElementById('productEndDate').value;
            
            console.log('Product Performance Filter - Start Date:', startDate, 'End Date:', endDate);
            
            // Filter if at least one date is selected
            if (startDate || endDate) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', 'products');
                if (startDate) url.searchParams.set('start', startDate);
                if (endDate) url.searchParams.set('end', endDate);
                
                console.log('Redirecting to:', url.toString());
                window.location.href = url.toString();
            } else {
                console.log('No dates selected, resetting filter');
                window.location.href = 'analytics.php?tab=products';
            }
        }

        function resetProductFilter() {
            document.getElementById('productStartDate').value = '';
            document.getElementById('productEndDate').value = '';
            window.location.href = 'analytics.php?tab=products';
        }

        // Load Predictive Analytics
        async function loadPredictiveAnalytics() {
            const contentDiv = document.getElementById('predictiveAnalyticsContent');
            contentDiv.innerHTML = '<div style="padding: 2rem; text-align: center;"><i class="fas fa-spinner fa-spin" style="font-size: 2rem; color: #6c757d;"></i><p style="margin-top: 1rem; color: #6c757d;">Generating demand forecasts and stock recommendations... This may take a moment.</p></div>';
            
            try {
                const response = await fetch('predictive-analytics/demand_forecast_api.php?action=forecast');
                const result = await response.json();
                
                if (result.success) {
                    displayPredictiveAnalytics(result.data);
                } else {
                    contentDiv.innerHTML = `<div style="padding: 2rem; text-align: center; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 2rem;"></i><p style="margin-top: 1rem;">${result.message}</p></div>`;
                }
            } catch (error) {
                console.error('Error loading predictive analytics:', error);
                contentDiv.innerHTML = '<div style="padding: 2rem; text-align: center; color: #dc3545;"><i class="fas fa-exclamation-triangle" style="font-size: 2rem;"></i><p style="margin-top: 1rem;">Failed to load predictive analytics. Error: ' + error.message + '</p></div>';
            }
        }

        function displayPredictiveAnalytics(data) {
            const contentDiv = document.getElementById('predictiveAnalyticsContent');
            
            let html = '<div style="padding: 1.5rem;">';
            
            // ML Accuracy
            html += '<div style="margin-bottom: 2rem;">';
            html += '<h4 style="color: #333; margin-bottom: 1rem;"><i class="fas fa-chart-line"></i> ML Model Accuracy</h4>';
            html += '<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">';
            
            for (const [model, accuracy] of Object.entries(data.ml_accuracy)) {
                const percentage = (accuracy * 100).toFixed(2);
                html += `<div style="background: #f8f9fa; padding: 1rem; border-radius: 8px; text-align: center;">
                    <div style="font-size: 0.9rem; color: #6c757d; margin-bottom: 0.5rem;">${model}</div>
                    <div style="font-size: 1.5rem; font-weight: bold; color: #28a745;">${percentage}%</div>
                </div>`;
            }
            html += '</div></div>';
            
            // Stock Alerts
            html += '<div style="margin-bottom: 2rem;">';
            html += '<h4 style="color: #333; margin-bottom: 1rem;"><i class="fas fa-exclamation-triangle"></i> Stock Alerts & Recommendations</h4>';
            
            // Group forecasts by stock status
            const alerts = {
                'out_of_stock': [],
                'low_stock': [],
                'moderate_stock': []
            };
            
            if (data.forecasts && Array.isArray(data.forecasts)) {
                data.forecasts.forEach(forecast => {
                    const status = forecast.stock_status.status;
                    if (alerts[status]) {
                        alerts[status].push(forecast);
                    }
                });
            }
            
            // Display alerts by priority
            ['out_of_stock', 'low_stock', 'moderate_stock'].forEach(status => {
                if (alerts[status].length > 0) {
                    const statusColors = {
                        'out_of_stock': '#dc3545',
                        'low_stock': '#ffc107',
                        'moderate_stock': '#17a2b8'
                    };
                    const statusLabels = {
                        'out_of_stock': 'Out of Stock',
                        'low_stock': 'Low Stock',
                        'moderate_stock': 'Moderate Stock'
                    };
                    
                    html += `<div style="margin-bottom: 1rem; border-left: 4px solid ${statusColors[status]}; padding-left: 1rem;">`;
                    html += `<h5 style="color: ${statusColors[status]}; margin-bottom: 0.5rem;">${statusLabels[status]} (${alerts[status].length} products)</h5>`;
                    html += '<div class="table-wrapper"><table><thead><tr><th>Product</th><th>Current Stock</th><th>Predicted Demand (3 months)</th><th>Recommendation</th></tr></thead><tbody>';
                    
                    alerts[status].forEach(forecast => {
                        const totalDemand = forecast.forecast.reduce((sum, f) => sum + f.predicted_demand, 0);
                        const recommendation = forecast.stock_status.recommended_reorder 
                            ? `Reorder ${forecast.stock_status.reorder_quantity} units`
                            : 'Monitor stock levels';
                        
                        html += `<tr>
                            <td>${forecast.product_name}</td>
                            <td>${forecast.current_stock}</td>
                            <td>${totalDemand}</td>
                            <td>${recommendation}</td>
                        </tr>`;
                    });
                    
                    html += '</tbody></table></div></div>';
                }
            });
            
            html += '</div>';
            
            // Top Products
            html += '<div>';
            html += '<h4 style="color: #333; margin-bottom: 1rem;"><i class="fas fa-trophy"></i> Top Performing Products (ML Analysis)</h4>';
            html += '<div class="table-wrapper"><table><thead><tr><th>Rank</th><th>Product</th><th>Performance Score</th></tr></thead><tbody>';
            
            (data.top_products || []).slice(0, 10).forEach((product, index) => {
                const rankColor = index === 0 ? '#FFD700' : (index === 1 ? '#C0C0C0' : (index === 2 ? '#CD7F32' : '#6c757d'));
                html += `<tr>
                    <td><span style="background: ${rankColor}; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: bold;">${index + 1}</span></td>
                    <td>${product}</td>
                    <td><strong>High</strong></td>
                </tr>`;
            });
            
            html += '</tbody></table></div></div>';
            
            // Timestamp
            html += `<div style="margin-top: 2rem; padding: 1rem; background: #e9ecef; border-radius: 8px; text-align: center; color: #6c757d; font-size: 0.9rem;">
                <i class="fas fa-clock"></i> Analysis generated at: ${data.generated_at}
            </div>`;
            
            html += '</div>';
            
            contentDiv.innerHTML = html;
        }


        // Get URL parameters
        function getUrlParameter(name) {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get(name);
        }

        // Initialize charts on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Get filter parameters from URL
            const startDate = getUrlParameter('start');
            const endDate = getUrlParameter('end');
            const activeTab = getUrlParameter('tab') || 'monthly';
            
            // Set default dates (January 1, 2023 to today) if no filters applied
            const today = new Date();
            const startDate2023 = new Date('2023-01-01');
            const lastYear = new Date();
            lastYear.setFullYear(today.getFullYear() - 1);
            
            const todayStr = today.toISOString().split('T')[0];
            const startDate2023Str = startDate2023.toISOString().split('T')[0];
            const lastYearStr = lastYear.toISOString().split('T')[0];
            
            // Restore date values based on active tab
            if (activeTab === 'monthly') {
                document.getElementById('monthlyStartDate').value = startDate || startDate2023Str;
                document.getElementById('monthlyEndDate').value = endDate || todayStr;
            } else if (activeTab === 'customers') {
                document.getElementById('customerStartDate').value = startDate || lastYearStr;
                document.getElementById('customerEndDate').value = endDate || todayStr;
            } else if (activeTab === 'products') {
                document.getElementById('productStartDate').value = startDate || lastYearStr;
                document.getElementById('productEndDate').value = endDate || todayStr;
            }
            
            // Set default values for tabs without active filters
            if (!startDate || !endDate) {
                if (!document.getElementById('monthlyStartDate').value) {
                    document.getElementById('monthlyStartDate').value = startDate2023Str;
                    document.getElementById('monthlyEndDate').value = todayStr;
                }
                if (!document.getElementById('customerStartDate').value) {
                    document.getElementById('customerStartDate').value = lastYearStr;
                    document.getElementById('customerEndDate').value = todayStr;
                }
                if (!document.getElementById('productStartDate').value) {
                    document.getElementById('productStartDate').value = lastYearStr;
                    document.getElementById('productEndDate').value = todayStr;
                }
            }
            
            // Always show Monthly Sales tab first by default
            showTab('monthly');
            
            // Force immediate chart creation for Monthly Sales
            setTimeout(function() {
                console.log('Force creating Monthly Sales chart...');
                const monthlyCanvas = document.getElementById('monthlySalesChart');
                const monthlySalesData = <?php echo json_encode($monthlySales); ?>;
                
                if (monthlyCanvas && monthlySalesData && monthlySalesData.length > 0) {
                    // Destroy existing chart if it exists
                    if (monthlyCanvas.chart) {
                        monthlyCanvas.chart.destroy();
                    }
                    
                    const ctx = monthlyCanvas.getContext('2d');
                    monthlyCanvas.chart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: monthlySalesData.map(item => {
                                const [year, month] = item.month.split('-');
                                const date = new Date(parseInt(year), parseInt(month) - 1, 1);
                                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                            }),
                            datasets: [{
                                label: 'Monthly Revenue (₱)',
                                data: monthlySalesData.map(item => parseFloat(item.monthly_revenue) || 0),
                                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                borderColor: '#ffc107',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 4,
                                pointHoverRadius: 6
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
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                    console.log('Monthly Sales chart created successfully!');
                }
            }, 200);
            
            // If a different tab was requested via URL, switch to it after a brief delay
            if (activeTab && activeTab !== 'monthly') {
                setTimeout(function() {
                    showTab(activeTab);
                }, 400);
            }
        });
        
        // Fallback initialization on window load
        window.addEventListener('load', function() {
            console.log('Window loaded - checking Monthly Sales chart...');
            const monthlyCanvas = document.getElementById('monthlySalesChart');
            const monthlySalesData = <?php echo json_encode($monthlySales); ?>;
            
            if (monthlyCanvas && monthlySalesData && monthlySalesData.length > 0) {
                if (!monthlyCanvas.chart) {
                    console.log('Window load fallback: Creating Monthly Sales chart...');
                    
                    const ctx = monthlyCanvas.getContext('2d');
                    monthlyCanvas.chart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: monthlySalesData.map(item => {
                                const [year, month] = item.month.split('-');
                                const date = new Date(parseInt(year), parseInt(month) - 1, 1);
                                return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
                            }),
                            datasets: [{
                                label: 'Monthly Revenue (₱)',
                                data: monthlySalesData.map(item => parseFloat(item.monthly_revenue) || 0),
                                backgroundColor: 'rgba(255, 193, 7, 0.1)',
                                borderColor: '#ffc107',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointRadius: 4,
                                pointHoverRadius: 6
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
                                        callback: function(value) {
                                            return '₱' + value.toLocaleString();
                                        }
                                    }
                                }
                            }
                        }
                    });
                    console.log('Window load fallback chart created successfully!');
                } else {
                    console.log('Monthly Sales chart already exists');
                }
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
            if (dropdown && !dropdown.contains(event.target)) {
                dropdown.classList.remove('show');
            }
        });
        // Export Functions
        function exportMonthlySales() {
            try {
                const startDate = document.getElementById('monthlyStartDate').value;
                const endDate = document.getElementById('monthlyEndDate').value;
                
                // Create download link
                const params = new URLSearchParams();
                if (startDate) params.append('start', startDate);
                if (endDate) params.append('end', endDate);
                params.append('export', 'monthly_sales');
                
                // Show loading message
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
                button.disabled = true;
                
                // Open download
                window.open('export-analytics.php?' + params.toString(), '_blank');
                
                // Reset button after a delay
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 2000);
                
            } catch (error) {
                console.error('Export error:', error);
                alert('Error exporting monthly sales data. Please try again.');
            }
        }

        function exportCustomerAnalytics() {
            try {
                // Create download link for customer analytics
                const params = new URLSearchParams();
                params.append('export', 'customer_analytics');
                
                // Show loading message
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
                button.disabled = true;
                
                // Open download
                window.open('export-analytics.php?' + params.toString(), '_blank');
                
                // Reset button after a delay
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 2000);
                
            } catch (error) {
                console.error('Export error:', error);
                alert('Error exporting customer analytics data. Please try again.');
            }
        }

        function exportProductPerformance() {
            try {
                // Create download link for product performance
                const params = new URLSearchParams();
                params.append('export', 'product_performance');
                
                // Show loading message
                const button = event.target;
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
                button.disabled = true;
                
                // Open download
                window.open('export-analytics.php?' + params.toString(), '_blank');
                
                // Reset button after a delay
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 2000);
                
            } catch (error) {
                console.error('Export error:', error);
                alert('Error exporting product performance data. Please try again.');
            }
        }

    </script>
</body>
</html>
