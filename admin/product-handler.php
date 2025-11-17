<?php
// Prevent any output before headers
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/AuditTrail.php';

// Clear any output buffer and set headers
ob_end_clean();
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Require admin login
requireAdminLogin();

try {
    $db = getDB();
    $action = $_REQUEST['action'] ?? '';
    
    // Validate CSRF token for state-changing actions
    $stateChangingActions = ['create', 'update', 'delete', 'toggle_availability', 'update_stock'];
    if (in_array($action, $stateChangingActions)) {
        $csrfToken = $_POST['csrf_token'] ?? $_REQUEST['csrf_token'] ?? '';
        if (!validateCSRFToken($csrfToken)) {
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
            exit();
        }
    }
    
    switch ($action) {
        case 'get':
            handleGetProduct($db);
            break;
            
        case 'create':
            handleCreateProduct($db);
            break;
            
        case 'update':
            handleUpdateProduct($db);
            break;
            
        case 'delete':
            handleDeleteProduct($db);
            break;
            
        case 'toggle_availability':
            handleToggleAvailability($db);
            break;
            
        case 'update_stock':
            handleUpdateStock($db);
            break;
            
        case 'get_logs':
            handleGetLogs($db);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    error_log('Product handler error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}

function handleGetProduct($db) {
    $productId = $_GET['id'] ?? '';
    
    if (empty($productId)) {
        echo json_encode(['success' => false, 'message' => 'Product ID required']);
        return;
    }
    
    $query = "SELECT * FROM products WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $productId);
    $stmt->execute();
    
    $product = $stmt->fetch();
    
    if ($product) {
        echo json_encode(['success' => true, 'product' => $product]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
}

function handleCreateProduct($db) {
    $productName = sanitizeInput($_POST['product_name'] ?? '');
    $category = sanitizeInput($_POST['category'] ?? '');
    $brand = sanitizeInput($_POST['brand'] ?? '');
    $unitPrice = floatval($_POST['unit_price'] ?? 0);
    $stockQuantity = intval($_POST['stock_quantity'] ?? 0);
    $description = sanitizeInput($_POST['description'] ?? '');
    $isAvailable = isset($_POST['is_available']) ? 1 : 0;
    
    // Handle image upload
    $imageUrl = '';
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $imageResult = handleImageUpload($_FILES['product_image'], $category);
        if (!$imageResult['success']) {
            echo json_encode(['success' => false, 'message' => $imageResult['message']]);
            return;
        }
        $imageUrl = $imageResult['path'];
    }
    
    // Validate required fields
    if (empty($productName) || empty($category) || $unitPrice <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
        return;
    }
    
    $db->beginTransaction();
    
    try {
        // Insert product (using actual table structure)
        $insertQuery = "INSERT INTO products (name, category, brand, price, stock_quantity, description, image_url, is_available, is_active, created_at, updated_at) 
                       VALUES (:name, :category, :brand, :price, :stock_quantity, :description, :image_url, :is_available, 1, NOW(), NOW())";
        
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':name', $productName);
        $insertStmt->bindParam(':category', $category);
        $insertStmt->bindParam(':brand', $brand);
        $insertStmt->bindParam(':price', $unitPrice);
        $insertStmt->bindParam(':stock_quantity', $stockQuantity);
        $insertStmt->bindParam(':description', $description);
        $insertStmt->bindParam(':image_url', $imageUrl);
        $insertStmt->bindParam(':is_available', $isAvailable);
        
        if ($insertStmt->execute()) {
            $newProductId = $db->lastInsertId();
            
            // Log initial stock if > 0
            if ($stockQuantity > 0) {
                logInventoryChange($db, $newProductId, 'set', 0, $stockQuantity, $stockQuantity, 'initial_stock', 'Initial stock entry', $_SESSION['admin_user_id']);
            }
            
            $db->commit();

            // Audit log
            (new AuditTrail())->logProductChange('create', (int)$newProductId, null, [
                'name' => $productName,
                'category' => $category,
                'brand' => $brand,
                'price' => $unitPrice,
                'stock_quantity' => $stockQuantity,
                'is_available' => (bool)$isAvailable
            ]);
            echo json_encode(['success' => true, 'message' => 'Product created successfully']);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to create product']);
        }
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleUpdateProduct($db) {
    $id = intval($_POST['id'] ?? 0);
    $productName = sanitizeInput($_POST['product_name'] ?? '');
    $category = sanitizeInput($_POST['category'] ?? '');
    $brand = sanitizeInput($_POST['brand'] ?? '');
    $unitPrice = floatval($_POST['unit_price'] ?? 0);
    $stockQuantity = intval($_POST['stock_quantity'] ?? 0);
    $description = sanitizeInput($_POST['description'] ?? '');
    $isAvailable = isset($_POST['is_available']) ? 1 : 0;
    
    if ($id <= 0 || empty($productName) || empty($category) || $unitPrice <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
        return;
    }
    
    // Get current product data
    $currentQuery = "SELECT * FROM products WHERE id = :id";
    $currentStmt = $db->prepare($currentQuery);
    $currentStmt->bindParam(':id', $id);
    $currentStmt->execute();
    $currentProduct = $currentStmt->fetch();
    
    if (!$currentProduct) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        return;
    }
    
    // Handle image upload/removal
    $imageUrl = $currentProduct['image_url'] ?? ''; // Keep current image by default
    $removeImage = isset($_POST['remove_image']) && $_POST['remove_image'] == '1';
    
    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $imageResult = handleImageUpload($_FILES['product_image'], $category);
        if (!$imageResult['success']) {
            echo json_encode(['success' => false, 'message' => $imageResult['message']]);
            return;
        }
        $imageUrl = $imageResult['path'];
    } elseif ($removeImage) {
        $imageUrl = ''; // Set to empty string to remove image
    }
    
    $db->beginTransaction();
    
    try {
        // Update product (using actual table structure)
        $updateQuery = "UPDATE products SET 
                       name = :name, 
                       category = :category, 
                       brand = :brand, 
                       price = :price, 
                       stock_quantity = :stock_quantity, 
                       description = :description, 
                       image_url = :image_url,
                       is_available = :is_available, 
                       updated_at = NOW() 
                       WHERE id = :id";
        
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':name', $productName);
        $updateStmt->bindParam(':category', $category);
        $updateStmt->bindParam(':brand', $brand);
        $updateStmt->bindParam(':price', $unitPrice);
        $updateStmt->bindParam(':stock_quantity', $stockQuantity);
        $updateStmt->bindParam(':description', $description);
        $updateStmt->bindParam(':image_url', $imageUrl);
        $updateStmt->bindParam(':is_available', $isAvailable);
        $updateStmt->bindParam(':id', $id);
        
        if ($updateStmt->execute()) {
            // Log stock change if quantity changed
            if ($currentProduct['stock_quantity'] != $stockQuantity) {
                $quantityChange = $stockQuantity - $currentProduct['stock_quantity'];
                $action = $quantityChange > 0 ? 'increase' : 'decrease';
                $reason = 'product_update';
                $notes = 'Stock updated via product edit';
                
                logInventoryChange($db, $id, $action, $currentProduct['stock_quantity'], abs($quantityChange), $stockQuantity, $reason, $notes, $_SESSION['admin_user_id']);
            }
            
            $db->commit();

            // Audit log with changed fields snapshot
            $oldValues = [
                'name' => $currentProduct['name'],
                'category' => $currentProduct['category'],
                'brand' => $currentProduct['brand'],
                'price' => (float)$currentProduct['price'],
                'stock_quantity' => (int)$currentProduct['stock_quantity'],
                'is_available' => (bool)$currentProduct['is_available']
            ];
            $newValues = [
                'name' => $productName,
                'category' => $category,
                'brand' => $brand,
                'price' => $unitPrice,
                'stock_quantity' => $stockQuantity,
                'is_available' => (bool)$isAvailable
            ];
            (new AuditTrail())->logProductChange('update', (int)$id, $oldValues, $newValues);
            echo json_encode(['success' => true, 'message' => 'Product updated successfully']);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to update product']);
        }
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleDeleteProduct($db) {
    $id = intval($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        return;
    }
    
    // Check if product exists
    $checkQuery = "SELECT * FROM products WHERE id = :id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':id', $id);
    $checkStmt->execute();
    $product = $checkStmt->fetch();
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        return;
    }
    
    // Check if product has orders
    $ordersQuery = "SELECT COUNT(*) as order_count FROM order_items WHERE product_id = :id";
    $ordersStmt = $db->prepare($ordersQuery);
    $ordersStmt->bindParam(':id', $id);
    $ordersStmt->execute();
    $orderCount = $ordersStmt->fetch()['order_count'];
    
    if ($orderCount > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete product with existing orders. Set as unavailable instead.']);
        return;
    }
    
    $db->beginTransaction();
    
    try {
        // Delete inventory logs first
        $deleteLogsQuery = "DELETE FROM inventory_logs WHERE product_id = :id";
        $deleteLogsStmt = $db->prepare($deleteLogsQuery);
        $deleteLogsStmt->bindParam(':id', $id);
        $deleteLogsStmt->execute();
        
        // Delete product
        $deleteQuery = "DELETE FROM products WHERE id = :id";
        $deleteStmt = $db->prepare($deleteQuery);
        $deleteStmt->bindParam(':id', $id);
        
        if ($deleteStmt->execute()) {
            $db->commit();
            // Audit log
            (new AuditTrail())->logProductChange('delete', (int)$id, [
                'name' => $product['name'],
                'category' => $product['category'] ?? null
            ], null);
            echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to delete product']);
        }
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleToggleAvailability($db) {
    $id = intval($_POST['id'] ?? 0);
    $status = $_POST['status'] === 'true' ? 1 : 0;
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        return;
    }
    
    $updateQuery = "UPDATE products SET is_available = :status, updated_at = NOW() WHERE id = :id";
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':status', $status);
    $updateStmt->bindParam(':id', $id);
    
    if ($updateStmt->execute()) {
        $statusText = $status ? 'available' : 'unavailable';
        echo json_encode(['success' => true, 'message' => "Product marked as $statusText"]);
        // Audit log
        (new AuditTrail())->logProductChange('update', (int)$id, ['is_available' => !$status], ['is_available' => (bool)$status]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update availability']);
    }
}

function handleUpdateStock($db) {
    $productId = intval($_POST['product_id'] ?? 0);
    $action = sanitizeInput($_POST['action'] ?? '');
    $quantity = intval($_POST['quantity'] ?? 0);
    $reason = sanitizeInput($_POST['reason'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    if ($productId <= 0 || empty($action) || $quantity <= 0 || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
        return;
    }
    
    // Get current stock
    $currentQuery = "SELECT stock_quantity FROM products WHERE id = :id";
    $currentStmt = $db->prepare($currentQuery);
    $currentStmt->bindParam(':id', $productId);
    $currentStmt->execute();
    $currentStock = $currentStmt->fetch()['stock_quantity'] ?? 0;
    
    // Calculate new stock
    $newStock = $currentStock;
    switch ($action) {
        case 'increase':
            $newStock = $currentStock + $quantity;
            break;
        case 'decrease':
            $newStock = max(0, $currentStock - $quantity);
            break;
        case 'set':
            $newStock = $quantity;
            $quantity = abs($newStock - $currentStock); // For logging purposes
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            return;
    }
    
    $db->beginTransaction();
    
    try {
        // Update product stock
        $updateQuery = "UPDATE products SET stock_quantity = :new_stock, updated_at = NOW() WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':new_stock', $newStock);
        $updateStmt->bindParam(':id', $productId);
        
        if ($updateStmt->execute()) {
            // Log the inventory change
            logInventoryChange($db, $productId, $action, $currentStock, $quantity, $newStock, $reason, $notes, $_SESSION['admin_user_id']);
            // Audit log
            (new AuditTrail())->logProductChange('update', (int)$productId, ['stock_quantity' => (int)$currentStock], ['stock_quantity' => (int)$newStock]);
            
            $db->commit();
            echo json_encode(['success' => true, 'message' => 'Stock updated successfully']);
        } else {
            $db->rollback();
            echo json_encode(['success' => false, 'message' => 'Failed to update stock']);
        }
        
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}

function handleGetLogs($db) {
    $productId = intval($_GET['id'] ?? 0);
    
    if ($productId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
        return;
    }
    
    $logsQuery = "SELECT il.*, u.first_name, u.last_name,
                         CONCAT(u.first_name, ' ', u.last_name) as updated_by_name
                  FROM inventory_logs il
                  LEFT JOIN users u ON il.updated_by = u.id
                  WHERE il.product_id = :product_id
                  ORDER BY il.created_at DESC
                  LIMIT 50";
    
    $logsStmt = $db->prepare($logsQuery);
    $logsStmt->bindParam(':product_id', $productId);
    $logsStmt->execute();
    
    $logs = $logsStmt->fetchAll();
    
    echo json_encode(['success' => true, 'logs' => $logs]);
}

function logInventoryChange($db, $productId, $action, $oldQuantity, $quantityChange, $newQuantity, $reason, $notes, $updatedBy) {
    $logQuery = "INSERT INTO inventory_logs (product_id, action, old_quantity, quantity_change, new_quantity, reason, notes, updated_by, created_at) 
                VALUES (:product_id, :action, :old_quantity, :quantity_change, :new_quantity, :reason, :notes, :updated_by, NOW())";
    
    $logStmt = $db->prepare($logQuery);
    $logStmt->bindParam(':product_id', $productId);
    $logStmt->bindParam(':action', $action);
    $logStmt->bindParam(':old_quantity', $oldQuantity);
    $logStmt->bindParam(':quantity_change', $quantityChange);
    $logStmt->bindParam(':new_quantity', $newQuantity);
    $logStmt->bindParam(':reason', $reason);
    $logStmt->bindParam(':notes', $notes);
    $logStmt->bindParam(':updated_by', $updatedBy);
    
    return $logStmt->execute();
}

function handleImageUpload($file, $category) {
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    // Validate file type
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'message' => 'Invalid file type. Please upload JPG, PNG, or WEBP images only.'];
    }
    
    // Validate file size
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'File size too large. Maximum size is 5MB.'];
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = __DIR__ . '/../assets/Product images/';
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'message' => 'Failed to create upload directory.'];
        }
    }
    
    // Generate unique filename
    $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = uniqid() . '_' . time() . '.' . $fileExtension;
    $filePath = $uploadDir . $fileName;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        // Return relative path for database storage with ../ prefix for public display
        $relativePath = '../assets/Product images/' . $fileName;
        return ['success' => true, 'path' => $relativePath];
    } else {
        return ['success' => false, 'message' => 'Failed to upload image. Please try again.'];
    }
}
?>
