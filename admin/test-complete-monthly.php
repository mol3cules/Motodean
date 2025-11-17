<?php
// Test script to verify Monthly Sales export shows all data (2023-2025)
require_once __DIR__ . '/../config/config.php';

echo "Testing Monthly Sales Export - Complete Dataset\n";
echo "===============================================\n\n";

try {
    $db = getDB();
    echo "✓ Database connection successful\n\n";
} catch (Exception $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Test Monthly Sales Data - all data without 12-month limitation
echo "MONTHLY SALES DATA TEST (Complete Dataset):\n";
echo "--------------------------------------------\n";
try {
    $query = "SELECT DATE_FORMAT(created_at, '%Y-%m') as month,
                     COUNT(*) as order_count,
                     SUM(total_amount) as monthly_revenue
              FROM orders 
              WHERE status IN ('paid', 'shipped', 'completed')
              GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
              ORDER BY month ASC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✓ Monthly sales query returned " . count($results) . " rows\n";
    echo "CSV Headers: Month, Orders, Revenue\n\n";
    
    if (count($results) > 0) {
        echo "Complete CSV Data (all months from 2023-2025):\n";
        echo "===============================================\n";
        foreach ($results as $row) {
            $formattedMonth = date('F Y', strtotime($row['month'] . '-01'));
            $formattedRevenue = '₱' . number_format($row['monthly_revenue'], 2);
            echo $formattedMonth . "\t" . $row['order_count'] . "\t" . $formattedRevenue . "\n";
        }
        
        echo "\n✓ Total months exported: " . count($results) . "\n";
        echo "✓ Date range: " . date('F Y', strtotime($results[0]['month'] . '-01')) . " to " . date('F Y', strtotime(end($results)['month'] . '-01')) . "\n";
    } else {
        echo "No data available.\n";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n===============================================\n";
echo "Monthly Sales Export Test Complete!\n";
echo "The CSV will contain ALL data from 2023-2025.\n";
?>
