<?php
require_once __DIR__ . '/../../config/config.php';

// Require admin login
requireAdminLogin();

header('Content-Type: application/json');

try {
    $pythonScript = __DIR__ . '/process_sales_ml.py';
    $outputFile = __DIR__ . '/output/ml_results.json';
    
    // Check if Python is available
    $pythonCommand = 'python';
    
    // Try to find Python executable
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows
        $pythonPaths = ['python', 'python3', 'py'];
        foreach ($pythonPaths as $pyCmd) {
            exec("where $pyCmd 2>nul", $output, $returnCode);
            if ($returnCode === 0) {
                $pythonCommand = $pyCmd;
                break;
            }
        }
    } else {
        // Linux/Mac
        $pythonPaths = ['python3', 'python'];
        foreach ($pythonPaths as $pyCmd) {
            exec("which $pyCmd 2>/dev/null", $output, $returnCode);
            if ($returnCode === 0) {
                $pythonCommand = $pyCmd;
                break;
            }
        }
    }
    
    // Check if we should force re-run or use cached results
    $forceRun = isset($_GET['force']) && $_GET['force'] === 'true';
    
    // Check if results exist and are recent (less than 1 hour old)
    $resultsExist = file_exists($outputFile);
    $resultsRecent = $resultsExist && (time() - filemtime($outputFile)) < 3600;
    
    if (!$forceRun && $resultsRecent) {
        // Use cached results
        $results = json_decode(file_get_contents($outputFile), true);
        
        echo json_encode([
            'success' => true,
            'cached' => true,
            'data' => $results,
            'message' => 'Using cached ML results (run with ?force=true to regenerate)'
        ]);
        exit();
    }
    
    // Run Python script
    $command = escapeshellcmd("$pythonCommand \"$pythonScript\"");
    $output = [];
    $returnCode = 0;
    
    exec($command . ' 2>&1', $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception("Python script failed with code $returnCode: " . implode("\n", $output));
    }
    
    // Check if output file was created
    if (!file_exists($outputFile)) {
        throw new Exception("ML results file was not created. Output: " . implode("\n", $output));
    }
    
    // Load and return results
    $results = json_decode(file_get_contents($outputFile), true);
    
    if ($results === null) {
        throw new Exception("Failed to parse ML results JSON");
    }
    
    echo json_encode([
        'success' => true,
        'cached' => false,
        'data' => $results,
        'message' => 'ML analysis completed successfully',
        'execution_output' => $output
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
?>
