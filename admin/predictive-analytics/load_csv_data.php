<?php
/**
 * Load and process sales_dataset-final.csv for analytics dashboard
 * This file provides CSV data to supplement database analytics
 */

function loadSalesCSVData() {
    $csvFile = __DIR__ . '/sales_dataset-final.csv';
    
    if (!file_exists($csvFile)) {
        return [
            'success' => false,
            'message' => 'CSV file not found',
            'data' => null
        ];
    }
    
    try {
        $data = [];
        $handle = fopen($csvFile, 'r');
        
        // Read header
        $header = fgetcsv($handle);
        if ($header === false) {
            throw new Exception('Failed to read CSV header');
        }
        
        // Normalize header names
        $header = array_map('trim', $header);
        
        // Read all rows
        while (($row = fgetcsv($handle)) !== false) {
            $rowData = array_combine($header, $row);
            $data[] = $rowData;
        }
        
        fclose($handle);
        
        // Process the data
        $processed = processCSVData($data);
        
        return [
            'success' => true,
            'data' => $processed,
            'row_count' => count($data)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null
        ];
    }
}

function mapProductToCategory($productName) {
    // Map CSV product names to their proper categories based on product type
    $productName = strtolower(trim($productName));
    
    // Tire-related products
    if (strpos($productName, 'tire') !== false || 
        strpos($productName, 'interior') !== false || 
        strpos($productName, 'quicktire') !== false) {
        return 'tires';
    }
    
    // Maintenance & Cleaning products
    if (strpos($productName, 'havoline') !== false || 
        strpos($productName, 'castrol') !== false || 
        strpos($productName, 'oil') !== false ||
        strpos($productName, 'gear oil') !== false ||
        strpos($productName, 'cleaning') !== false ||
        strpos($productName, 'maintenance') !== false) {
        return 'maintenance-cleaning';
    }
    
    // Mirrors & Accessories
    if (strpos($productName, 'mirror') !== false || 
        strpos($productName, 'badge') !== false || 
        strpos($productName, 'plate') !== false ||
        strpos($productName, 'accessories') !== false) {
        return 'mirrors-accessories';
    }
    
    // Electrical products
    if (strpos($productName, 'battery') !== false || 
        strpos($productName, 'bulb') !== false || 
        strpos($productName, 'headlight') !== false ||
        strpos($productName, 'electrical') !== false ||
        strpos($productName, 'relay') !== false ||
        strpos($productName, 'switch') !== false ||
        strpos($productName, 'tape') !== false) {
        return 'electrical';
    }
    
    // Engine & Control Cables
    if (strpos($productName, 'cable') !== false || 
        strpos($productName, 'clutch') !== false || 
        strpos($productName, 'throttle') !== false ||
        strpos($productName, 'engine') !== false ||
        strpos($productName, 'control') !== false) {
        return 'engine-control-cables';
    }
    
    // Brakes
    if (strpos($productName, 'brake') !== false) {
        return 'brakes';
    }
    
    // Fasteners & Body Bolts
    if (strpos($productName, 'bolt') !== false || 
        strpos($productName, 'fastener') !== false || 
        strpos($productName, 'nut') !== false ||
        strpos($productName, 'screw') !== false ||
        strpos($productName, 'cap') !== false) {
        return 'fasteners-body-bolts';
    }
    
    // Oils & Fluids
    if (strpos($productName, 'fluid') !== false || 
        strpos($productName, 'grease') !== false || 
        strpos($productName, 'lubricant') !== false) {
        return 'oils-fluids';
    }
    
    // Default category for unmatched products
    return 'maintenance-cleaning';
}

function processCSVData($rawData) {
    // Find column names (case-insensitive)
    $dateCol = null;
    $productCol = null;
    $qtyCol = null;
    $amountCol = null;
    
    if (!empty($rawData)) {
        $firstRow = $rawData[0];
        foreach ($firstRow as $key => $value) {
            $keyLower = strtolower($key);
            if (strpos($keyLower, 'date') !== false) {
                $dateCol = $key;
            } elseif (strpos($keyLower, 'item') !== false || strpos($keyLower, 'product') !== false) {
                $productCol = $key;
            } elseif (strpos($keyLower, 'qty') !== false || strpos($keyLower, 'quantity') !== false) {
                $qtyCol = $key;
            } elseif (strpos($keyLower, 'amount') !== false || strpos($keyLower, 'price') !== false || strpos($keyLower, 'total') !== false) {
                $amountCol = $key;
            }
        }
    }
    
    // Process data for analytics
    $dailySales = [];
    $weeklySales = [];
    $monthlySales = [];
    $productPerformance = [];
    $customerData = [];
    
    foreach ($rawData as $row) {
        $date = isset($row[$dateCol]) ? $row[$dateCol] : null;
        $product = isset($row[$productCol]) ? $row[$productCol] : 'Unknown';
        $qty = isset($row[$qtyCol]) ? (int)$row[$qtyCol] : 1;
        $amount = isset($row[$amountCol]) ? (float)$row[$amountCol] : 0;
        
        if (!$date) continue;
        
        // Parse date
        $timestamp = strtotime($date);
        if ($timestamp === false) continue;
        
        $dateFormatted = date('Y-m-d', $timestamp);
        $yearMonth = date('Y-m', $timestamp);
        $yearWeek = date('Y-W', $timestamp);
        
        // Daily sales
        if (!isset($dailySales[$dateFormatted])) {
            $dailySales[$dateFormatted] = [
                'sale_date' => $dateFormatted,
                'order_count' => 0,
                'daily_revenue' => 0
            ];
        }
        $dailySales[$dateFormatted]['order_count'] += 1;
        $dailySales[$dateFormatted]['daily_revenue'] += $amount;
        
        // Weekly sales
        if (!isset($weeklySales[$yearWeek])) {
            $weeklySales[$yearWeek] = [
                'year_week' => $yearWeek,
                'order_count' => 0,
                'weekly_revenue' => 0,
                'week_start' => date('Y-m-d', strtotime('monday this week', $timestamp))
            ];
        }
        $weeklySales[$yearWeek]['order_count'] += 1;
        $weeklySales[$yearWeek]['weekly_revenue'] += $amount;
        
        // Monthly sales
        if (!isset($monthlySales[$yearMonth])) {
            $monthlySales[$yearMonth] = [
                'month' => $yearMonth,
                'order_count' => 0,
                'monthly_revenue' => 0
            ];
        }
        $monthlySales[$yearMonth]['order_count'] += 1;
        $monthlySales[$yearMonth]['monthly_revenue'] += $amount;
        
        // Product performance - categorize products properly
        if (!isset($productPerformance[$product])) {
            // Map CSV products to their proper categories
            $category = mapProductToCategory($product);
            
            $productPerformance[$product] = [
                'product_name' => $product,
                'category' => $category,
                'total_sold' => 0,
                'revenue' => 0
            ];
        }
        $productPerformance[$product]['total_sold'] += $qty;
        $productPerformance[$product]['revenue'] += $amount;
    }
    
    // Sort and prepare for output
    uasort($dailySales, function($a, $b) {
        return strcmp($b['sale_date'], $a['sale_date']);
    });
    
    uasort($weeklySales, function($a, $b) {
        return strcmp($b['year_week'], $a['year_week']);
    });
    
    uasort($monthlySales, function($a, $b) {
        return strcmp($b['month'], $a['month']);
    });
    
    // Sort by units sold (total_sold) instead of revenue
    uasort($productPerformance, function($a, $b) {
        return $b['total_sold'] - $a['total_sold'];
    });
    
    return [
        'daily_sales' => array_values($dailySales),
        'weekly_sales' => array_values($weeklySales),
        'monthly_sales' => array_values($monthlySales),
        'product_performance' => array_values(array_slice($productPerformance, 0, 10)), // Top 10 by units sold
        'all_products' => array_values($productPerformance),
        'total_records' => count($rawData),
        'date_range' => [
            'start' => !empty($dailySales) ? min(array_keys($dailySales)) : null,
            'end' => !empty($dailySales) ? max(array_keys($dailySales)) : null
        ]
    ];
}

function getCSVAnalytics($startDate = null, $endDate = null) {
    $result = loadSalesCSVData();
    
    if (!$result['success']) {
        return $result;
    }
    
    $data = $result['data'];
    
    // Filter by date range if provided
    if ($startDate && $endDate) {
        $data['daily_sales'] = array_filter($data['daily_sales'], function($item) use ($startDate, $endDate) {
            return $item['sale_date'] >= $startDate && $item['sale_date'] <= $endDate;
        });
        
        $data['weekly_sales'] = array_filter($data['weekly_sales'], function($item) use ($startDate, $endDate) {
            return $item['week_start'] >= $startDate && $item['week_start'] <= $endDate;
        });
        
        $data['monthly_sales'] = array_filter($data['monthly_sales'], function($item) use ($startDate, $endDate) {
            $monthStart = $item['month'] . '-01';
            $monthEnd = date('Y-m-t', strtotime($monthStart)); // Last day of the month
            
            // Include month if it overlaps with the date range
            return ($monthStart <= $endDate) && ($monthEnd >= $startDate);
        });
        
        // Reindex arrays
        $data['daily_sales'] = array_values($data['daily_sales']);
        $data['weekly_sales'] = array_values($data['weekly_sales']);
        $data['monthly_sales'] = array_values($data['monthly_sales']);
    }
    
    // Calculate summary statistics
    $totalRevenue = array_sum(array_column($data['monthly_sales'], 'monthly_revenue'));
    $totalOrders = array_sum(array_column($data['monthly_sales'], 'order_count'));
    $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
    
    $data['summary'] = [
        'total_orders' => $totalOrders,
        'total_revenue' => $totalRevenue,
        'avg_order_value' => $avgOrderValue,
        'unique_products' => count($data['all_products'])
    ];
    
    return [
        'success' => true,
        'data' => $data,
        'source' => 'CSV',
        'filtered' => ($startDate && $endDate) ? true : false
    ];
}

// API endpoint functionality
if (basename($_SERVER['PHP_SELF']) === 'load_csv_data.php') {
    header('Content-Type: application/json');
    
    $startDate = isset($_GET['start']) ? $_GET['start'] : null;
    $endDate = isset($_GET['end']) ? $_GET['end'] : null;
    
    $result = getCSVAnalytics($startDate, $endDate);
    echo json_encode($result);
}
?>

