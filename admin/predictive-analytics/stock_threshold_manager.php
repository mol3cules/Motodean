<?php
/**
 * Stock Threshold Manager
 * Analyzes historical sales data to set intelligent stock thresholds
 */

require_once __DIR__ . '/../../config/config.php';

class StockThresholdManager {
    private $db;
    private $csvData;
    
    public function __construct() {
        $this->db = getDB();
        $this->loadCSVData();
    }
    
    /**
     * Load and process CSV data for analysis
     */
    private function loadCSVData() {
        $csvFile = __DIR__ . '/sales_dataset-final.csv';
        
        if (!file_exists($csvFile)) {
            throw new Exception('CSV file not found');
        }
        
        $this->csvData = [];
        if (($handle = fopen($csvFile, 'r')) !== FALSE) {
            $headers = fgetcsv($handle);
            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($headers) == count($row)) {
                    $this->csvData[] = array_combine($headers, $row);
                }
            }
            fclose($handle);
        }
    }
    
    /**
     * Analyze historical sales data for a product
     */
    public function analyzeProductSales($productName) {
        $salesData = [];
        
        foreach ($this->csvData as $row) {
            if (stripos($row['Item'], $productName) !== false || 
                stripos($productName, $row['Item']) !== false) {
                $date = $row['Date'];
                $month = date('Y-m', strtotime($date));
                $qty = (int)$row['Qty'];
                
                if (!isset($salesData[$month])) {
                    $salesData[$month] = 0;
                }
                $salesData[$month] += $qty;
            }
        }
        
        if (empty($salesData)) {
            return null;
        }
        
        // Calculate statistics
        $quantities = array_values($salesData);
        $avgMonthlySales = array_sum($quantities) / count($quantities);
        $maxMonthlySales = max($quantities);
        $minMonthlySales = min($quantities);
        $stdDev = $this->calculateStandardDeviation($quantities);
        
        // Calculate trend
        $trend = $this->calculateTrend($salesData);
        
        return [
            'product_name' => $productName,
            'total_months' => count($salesData),
            'avg_monthly_sales' => round($avgMonthlySales, 2),
            'max_monthly_sales' => $maxMonthlySales,
            'min_monthly_sales' => $minMonthlySales,
            'std_deviation' => round($stdDev, 2),
            'trend' => $trend,
            'sales_data' => $salesData
        ];
    }
    
    /**
     * Calculate standard deviation
     */
    private function calculateStandardDeviation($values) {
        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values)) / count($values);
        
        return sqrt($variance);
    }
    
    /**
     * Calculate sales trend (increasing, decreasing, stable)
     */
    private function calculateTrend($salesData) {
        $months = array_keys($salesData);
        sort($months);
        
        if (count($months) < 2) {
            return 'insufficient_data';
        }
        
        $recentMonths = array_slice($months, -6); // Last 6 months
        $olderMonths = array_slice($months, -12, 6); // 6 months before recent
        
        if (count($recentMonths) < 2 || count($olderMonths) < 2) {
            return 'insufficient_data';
        }
        
        $recentAvg = array_sum(array_map(function($month) use ($salesData) {
            return $salesData[$month];
        }, $recentMonths)) / count($recentMonths);
        
        $olderAvg = array_sum(array_map(function($month) use ($salesData) {
            return $salesData[$month];
        }, $olderMonths)) / count($olderMonths);
        
        $changePercent = (($recentAvg - $olderAvg) / $olderAvg) * 100;
        
        if ($changePercent > 10) {
            return 'increasing';
        } elseif ($changePercent < -10) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }
    
    /**
     * Calculate intelligent stock threshold for a product
     */
    public function calculateStockThreshold($productName, $currentStock = 0) {
        $analysis = $this->analyzeProductSales($productName);
        
        if (!$analysis) {
            return [
                'product_name' => $productName,
                'threshold' => 10, // Default threshold
                'confidence' => 'low',
                'reason' => 'No historical data available'
            ];
        }
        
        $avgMonthlySales = $analysis['avg_monthly_sales'];
        $stdDev = $analysis['std_deviation'];
        $trend = $analysis['trend'];
        
        // Base threshold calculation
        $baseThreshold = $avgMonthlySales * 1.5; // 1.5 months of average sales
        
        // Adjust for trend
        $trendMultiplier = 1.0;
        switch ($trend) {
            case 'increasing':
                $trendMultiplier = 1.3; // 30% higher for increasing trend
                break;
            case 'decreasing':
                $trendMultiplier = 0.8; // 20% lower for decreasing trend
                break;
            case 'stable':
                $trendMultiplier = 1.0;
                break;
        }
        
        // Adjust for variability (higher std dev = higher threshold)
        $variabilityMultiplier = 1.0 + ($stdDev / $avgMonthlySales) * 0.5;
        
        // Calculate final threshold
        $threshold = $baseThreshold * $trendMultiplier * $variabilityMultiplier;
        
        // Ensure minimum threshold
        $threshold = max($threshold, 5);
        
        // Determine confidence level
        $confidence = 'high';
        if ($analysis['total_months'] < 6) {
            $confidence = 'medium';
        }
        if ($analysis['total_months'] < 3) {
            $confidence = 'low';
        }
        
        // Determine stock status
        $stockStatus = 'adequate';
        if ($currentStock <= 0) {
            $stockStatus = 'out_of_stock';
        } elseif ($currentStock < $threshold) {
            $stockStatus = 'low_stock';
        }
        
        return [
            'product_name' => $productName,
            'current_stock' => $currentStock,
            'threshold' => round($threshold),
            'confidence' => $confidence,
            'trend' => $trend,
            'avg_monthly_sales' => $avgMonthlySales,
            'stock_status' => $stockStatus,
            'recommended_reorder' => $currentStock < $threshold,
            'reorder_quantity' => $currentStock < $threshold ? max(round($threshold * 2), 20) : 0,
            'analysis' => $analysis
        ];
    }
    
    /**
     * Get all products with their calculated thresholds
     */
    public function getAllProductThresholds() {
        $query = "SELECT id, name, stock_quantity FROM products ORDER BY name";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $products = $stmt->fetchAll();
        
        $results = [];
        foreach ($products as $product) {
            $threshold = $this->calculateStockThreshold($product['name'], $product['stock_quantity']);
            $threshold['product_id'] = $product['id'];
            $results[] = $threshold;
        }
        
        return $results;
    }
    
    /**
     * Get low stock alerts based on calculated thresholds
     */
    public function getLowStockAlerts() {
        $thresholds = $this->getAllProductThresholds();
        
        $alerts = [
            'out_of_stock' => [],
            'low_stock' => [],
            'at_threshold' => []
        ];
        
        foreach ($thresholds as $product) {
            if ($product['stock_status'] === 'out_of_stock') {
                $alerts['out_of_stock'][] = $product;
            } elseif ($product['stock_status'] === 'low_stock') {
                $alerts['low_stock'][] = $product;
            } elseif ($product['current_stock'] <= $product['threshold'] * 1.2) {
                $alerts['at_threshold'][] = $product;
            }
        }
        
        return $alerts;
    }
    
    /**
     * Update product thresholds in database (if you want to store them)
     */
    public function updateProductThresholds() {
        $thresholds = $this->getAllProductThresholds();
        
        foreach ($thresholds as $product) {
            $query = "UPDATE products SET 
                     stock_threshold = :threshold,
                     stock_status = :status,
                     last_threshold_update = NOW()
                     WHERE id = :product_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':threshold', $product['threshold']);
            $stmt->bindParam(':status', $product['stock_status']);
            $stmt->bindParam(':product_id', $product['product_id']);
            $stmt->execute();
        }
        
        return count($thresholds);
    }
}

// API endpoint
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $manager = new StockThresholdManager();
        
        switch ($_GET['action']) {
            case 'thresholds':
                $results = $manager->getAllProductThresholds();
                echo json_encode(['success' => true, 'data' => $results]);
                break;
                
            case 'alerts':
                $alerts = $manager->getLowStockAlerts();
                echo json_encode(['success' => true, 'data' => $alerts]);
                break;
                
            case 'update':
                $updated = $manager->updateProductThresholds();
                echo json_encode(['success' => true, 'message' => "Updated {$updated} product thresholds"]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
