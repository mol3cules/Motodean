<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/User.php';

// Require admin login for this export functionality
requireAdminLogin();

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="analytics_report_' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Open output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

$db = getDB();
$exportType = $_GET['export'] ?? '';
$startDate = $_GET['start'] ?? null;
$endDate = $_GET['end'] ?? null;

try {
    switch ($exportType) {
        case 'monthly_sales':
            // Write CSV headers matching the table columns exactly (only 3 columns shown in table)
            fputcsv($output, ['Month', 'Orders', 'Revenue']);

            // Load CSV data to match what's shown in the table
            require_once __DIR__ . '/predictive-analytics/load_csv_data.php';
            $csvData = getCSVAnalytics($startDate, $endDate);
            
            if ($csvData['success'] && isset($csvData['data']['monthly_sales'])) {
                $monthlySales = $csvData['data']['monthly_sales'];
                
                // Sort by month ascending to match table display
                usort($monthlySales, function($a, $b) {
                    return strcmp($a['month'], $b['month']);
                });
                
                foreach ($monthlySales as $sale) {
                    // Format the data exactly as shown in the table (only 3 columns)
                    $csvRow = [
                        date('F Y', strtotime($sale['month'] . '-01')), // Format as "January 2023"
                        $sale['order_count'],
                        '₱' . number_format($sale['monthly_revenue'], 2)
                    ];
                    fputcsv($output, $csvRow);
                }
            } else {
                // Fallback to database if CSV not available
                $query = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                                 COUNT(*) as order_count,
                                 SUM(total_amount) as monthly_revenue
                          FROM orders 
                          WHERE status IN ('paid', 'shipped', 'completed')";
                
                $params = [];
                if ($startDate && $endDate) {
                    $query .= " AND DATE_FORMAT(created_at, '%Y-%m') BETWEEN DATE_FORMAT(:start_date, '%Y-%m') AND DATE_FORMAT(:end_date, '%Y-%m')";
                    $params[':start_date'] = $startDate;
                    $params[':end_date'] = $endDate;
                } elseif ($startDate) {
                    $query .= " AND DATE_FORMAT(created_at, '%Y-%m') >= DATE_FORMAT(:start_date, '%Y-%m')";
                    $params[':start_date'] = $startDate;
                } elseif ($endDate) {
                    $query .= " AND DATE_FORMAT(created_at, '%Y-%m') <= DATE_FORMAT(:end_date, '%Y-%m')";
                    $params[':end_date'] = $endDate;
                }

                $query .= " GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC";
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $csvRow = [
                        date('F Y', strtotime($row['month'] . '-01')),
                        $row['order_count'],
                        '₱' . number_format($row['monthly_revenue'], 2)
                    ];
                    fputcsv($output, $csvRow);
                }
            }
            break;

        case 'customer_analytics':
            // Write CSV headers matching the table columns exactly (only 4 columns shown in table)
            fputcsv($output, ['Customer', 'Orders', 'Total Spent', 'Last Order']);

            // Use the same query as the table to get top 10 customers
            $query = "SELECT u.first_name, u.last_name, u.email,
                             COUNT(o.id) as order_count,
                             SUM(o.total_amount) as total_spent,
                             MAX(o.created_at) as last_order
                      FROM users u
                      JOIN orders o ON u.id = o.user_id
                      WHERE o.status IN ('paid', 'shipped', 'completed')";
            
            $params = [];
            if ($startDate && $endDate) {
                $query .= " AND o.created_at BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $startDate;
                $params[':end_date'] = $endDate;
            } elseif ($startDate) {
                $query .= " AND o.created_at >= :start_date";
                $params[':start_date'] = $startDate;
            } elseif ($endDate) {
                $query .= " AND o.created_at <= :end_date";
                $params[':end_date'] = $endDate;
            }
            
            $query .= " GROUP BY u.id ORDER BY total_spent DESC LIMIT 10";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                // Format the data exactly as shown in the table (only 4 columns)
                $csvRow = [
                    $row['first_name'] . ' ' . $row['last_name'] . ' (' . $row['email'] . ')', // Customer name and email
                    $row['order_count'],
                    '₱' . number_format($row['total_spent'], 2),
                    date('M d, Y', strtotime($row['last_order'])) // Format as "Oct 16, 2025"
                ];
                fputcsv($output, $csvRow);
            }
            break;

        case 'product_performance':
            // Write CSV headers matching the table columns exactly (only 5 columns shown in table)
            fputcsv($output, ['Product Name', 'Category', 'Units Sold', 'Revenue', 'Performance']);

            // Use the same query as the table to get top 50 products
            $query = "SELECT p.name as product_name,
                             p.category,
                             SUM(oi.quantity) as total_sold,
                             SUM(oi.quantity * oi.unit_price) as revenue
                      FROM order_items oi
                      JOIN products p ON oi.product_id = p.id
                      JOIN orders o ON oi.order_id = o.id
                      WHERE o.status IN ('paid', 'shipped', 'completed')";
            
            $params = [];
            if ($startDate && $endDate) {
                $query .= " AND o.created_at BETWEEN :start_date AND :end_date";
                $params[':start_date'] = $startDate;
                $params[':end_date'] = $endDate;
            } elseif ($startDate) {
                $query .= " AND o.created_at >= :start_date";
                $params[':start_date'] = $startDate;
            } elseif ($endDate) {
                $query .= " AND o.created_at <= :end_date";
                $params[':end_date'] = $endDate;
            }
            
            $query .= " GROUP BY oi.product_id ORDER BY total_sold DESC LIMIT 50";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Sort by units sold descending (same as table)
            usort($results, function($a, $b) {
                return $b['total_sold'] - $a['total_sold'];
            });
            
            foreach ($results as $index => $row) {
                // Calculate performance indicator (same logic as table)
                $performanceText = '';
                if ($index < 3) {
                    $performanceText = 'Top Performer';
                } elseif ($index < 7) {
                    $performanceText = 'Good';
                } else {
                    $performanceText = 'Average';
                }
                
                // Format the data exactly as shown in the table (only 5 columns)
                $csvRow = [
                    $row['product_name'],
                    ucwords(str_replace('-', ' ', $row['category'])), // Format category
                    number_format($row['total_sold']), // Format units sold
                    '₱' . number_format($row['revenue'], 2), // Format revenue with ₱
                    $performanceText // Performance indicator
                ];
                fputcsv($output, $csvRow);
            }
            break;

        default:
            fputcsv($output, ['Error', 'Invalid export type specified.']);
            break;
    }
} catch (Exception $e) {
    error_log("Export analytics error: " . $e->getMessage());
    fputcsv($output, ['Error', 'An unexpected error occurred: ' . $e->getMessage()]);
} finally {
    fclose($output);
}
?>
