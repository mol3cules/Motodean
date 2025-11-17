<?php
/**
 * Demand Forecast API
 * Serves ML predictions for demand forecasting and stock management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/config.php';

class DemandForecastAPI {
    private $db;
    private $csvData;
    
    public function __construct() {
        $this->db = getDB();
        $this->loadCSVData();
    }
    
    /**
     * Load and process CSV data for ML analysis
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
     * Get demand forecast for products
     */
    public function getDemandForecast($productId = null, $months = 3) {
        try {
            // Run ML analysis
            $mlResults = $this->runMLAnalysis();
            
            // Get current product data
            $products = $this->getProductData($productId);
            
            // Generate demand forecasts
            $forecasts = $this->generateForecasts($products, $mlResults, $months);
            
            return [
                'success' => true,
                'data' => [
                    'forecasts' => $forecasts,
                    'ml_accuracy' => $mlResults['model_accuracies'],
                    'top_products' => $mlResults['top10_overall'],
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Run ML analysis using the Python script
     */
    private function runMLAnalysis() {
        $pythonScript = __DIR__ . '/process_sales_ml.py';
        $outputFile = __DIR__ . '/output/ml_results.json';
        
        // Run Python ML script
        $command = "python \"$pythonScript\" 2>&1";
        $output = shell_exec($command);
        
        if (file_exists($outputFile)) {
            $results = json_decode(file_get_contents($outputFile), true);
            return $results;
        } else {
            throw new Exception('ML analysis failed: ' . $output);
        }
    }
    
    /**
     * Get product data from database
     */
    private function getProductData($productId = null) {
        $query = "SELECT id, name, category, stock_quantity, price FROM products";
        if ($productId) {
            $query .= " WHERE id = :product_id";
        }
        $query .= " ORDER BY name";
        
        $stmt = $this->db->prepare($query);
        if ($productId) {
            $stmt->bindParam(':product_id', $productId);
        }
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Generate demand forecasts based on ML results
     */
    private function generateForecasts($products, $mlResults, $months) {
        $forecasts = [];
        
        foreach ($products as $product) {
            // Get historical sales data for this product
            $historicalData = $this->getHistoricalSales($product['name']);
            
            // Calculate demand forecast
            $forecast = $this->calculateDemandForecast($product, $historicalData, $mlResults, $months);
            
            // Determine stock status
            $stockStatus = $this->determineStockStatus($product, $forecast);
            
            $forecasts[] = [
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'category' => $product['category'],
                'current_stock' => $product['stock_quantity'],
                'price' => $product['price'],
                'forecast' => $forecast,
                'stock_status' => $stockStatus,
                'recommended_reorder' => $stockStatus['recommended_reorder'],
                'reorder_quantity' => $stockStatus['reorder_quantity']
            ];
        }
        
        return $forecasts;
    }
    
    /**
     * Get historical sales data for a product
     */
    private function getHistoricalSales($productName) {
        $sales = [];
        
        foreach ($this->csvData as $row) {
            if (stripos($row['Item'], $productName) !== false || 
                stripos($productName, $row['Item']) !== false) {
                $date = $row['Date'];
                $month = date('Y-m', strtotime($date));
                
                if (!isset($sales[$month])) {
                    $sales[$month] = 0;
                }
                $sales[$month] += (int)$row['Qty'];
            }
        }
        
        return $sales;
    }
    
    /**
     * Calculate demand forecast for a product
     */
    private function calculateDemandForecast($product, $historicalData, $mlResults, $months) {
        $forecast = [];
        
        // Get average monthly sales from historical data
        $avgMonthlySales = 0;
        if (!empty($historicalData)) {
            $avgMonthlySales = array_sum($historicalData) / count($historicalData);
        }
        
        // Generate forecast for next N months
        for ($i = 1; $i <= $months; $i++) {
            $forecastMonth = date('Y-m', strtotime("+$i months"));
            
            // Apply seasonal adjustments and trends
            $seasonalFactor = $this->getSeasonalFactor($forecastMonth);
            $trendFactor = $this->getTrendFactor($product, $mlResults);
            
            $predictedDemand = $avgMonthlySales * $seasonalFactor * $trendFactor;
            
            $forecast[] = [
                'month' => $forecastMonth,
                'predicted_demand' => round($predictedDemand),
                'confidence_level' => $this->calculateConfidence($historicalData)
            ];
        }
        
        return $forecast;
    }
    
    /**
     * Get seasonal factor for a month
     */
    private function getSeasonalFactor($month) {
        $monthNum = (int)date('n', strtotime($month . '-01'));
        
        // Seasonal factors based on typical retail patterns
        $seasonalFactors = [
            1 => 0.8,   // January - post-holiday dip
            2 => 0.7,   // February - low season
            3 => 0.9,   // March - spring pickup
            4 => 1.0,   // April - normal
            5 => 1.1,   // May - spring peak
            6 => 1.0,   // June - normal
            7 => 0.9,   // July - summer dip
            8 => 0.8,   // August - summer low
            9 => 1.0,   // September - back to school
            10 => 1.1,  // October - pre-holiday
            11 => 1.2,  // November - holiday buildup
            12 => 1.3   // December - holiday peak
        ];
        
        return $seasonalFactors[$monthNum] ?? 1.0;
    }
    
    /**
     * Get trend factor based on ML results
     */
    private function getTrendFactor($product, $mlResults) {
        $productName = strtolower($product['name']);
        
        // Check if product is in top performers
        foreach ($mlResults['top10_overall'] as $topProduct) {
            if (stripos($topProduct, $productName) !== false) {
                return 1.2; // 20% increase for top performers
            }
        }
        
        return 1.0; // Normal trend
    }
    
    /**
     * Calculate confidence level for forecast
     */
    private function calculateConfidence($historicalData) {
        if (empty($historicalData)) {
            return 0.5; // Low confidence without historical data
        }
        
        $dataPoints = count($historicalData);
        $confidence = min(0.9, 0.5 + ($dataPoints * 0.05)); // More data = higher confidence
        
        return round($confidence, 2);
    }
    
    /**
     * Determine stock status and recommendations
     */
    private function determineStockStatus($product, $forecast) {
        $currentStock = $product['stock_quantity'];
        $totalPredictedDemand = array_sum(array_column($forecast, 'predicted_demand'));
        
        $status = 'adequate';
        $recommendedReorder = false;
        $reorderQuantity = 0;
        
        if ($currentStock <= 0) {
            $status = 'out_of_stock';
            $recommendedReorder = true;
            $reorderQuantity = max(50, $totalPredictedDemand);
        } elseif ($currentStock < $totalPredictedDemand * 0.3) {
            $status = 'low_stock';
            $recommendedReorder = true;
            $reorderQuantity = max(30, $totalPredictedDemand - $currentStock);
        } elseif ($currentStock < $totalPredictedDemand * 0.5) {
            $status = 'moderate_stock';
            $recommendedReorder = false;
        }
        
        return [
            'status' => $status,
            'recommended_reorder' => $recommendedReorder,
            'reorder_quantity' => $reorderQuantity,
            'days_remaining' => ($currentStock > 0 && $totalPredictedDemand > 0) ? round($currentStock / ($totalPredictedDemand / 90)) : 0
        ];
    }
    
    /**
     * Get stock alerts based on forecasts
     */
    public function getStockAlerts() {
        $forecasts = $this->getDemandForecast();
        
        if (!$forecasts['success']) {
            return $forecasts;
        }
        
        $alerts = [
            'out_of_stock' => [],
            'low_stock' => [],
            'moderate_stock' => []
        ];
        
        foreach ($forecasts['data']['forecasts'] as $forecast) {
            $status = $forecast['stock_status']['status'];
            if ($status !== 'adequate') {
                $alerts[$status][] = $forecast;
            }
        }
        
        return [
            'success' => true,
            'data' => $alerts
        ];
    }
}

// Handle API requests
try {
    $api = new DemandForecastAPI();
    
    $action = $_GET['action'] ?? 'forecast';
    
    switch ($action) {
        case 'forecast':
            $productId = $_GET['product_id'] ?? null;
            $months = $_GET['months'] ?? 3;
            $result = $api->getDemandForecast($productId, $months);
            break;
            
        case 'alerts':
            $result = $api->getStockAlerts();
            break;
            
        default:
            $result = [
                'success' => false,
                'message' => 'Invalid action'
            ];
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
