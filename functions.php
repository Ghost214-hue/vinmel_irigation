<?php

function getCurrentTimePeriod($user_id, $db) {
    $sql = "SELECT * FROM time_periods 
            WHERE created_by = ? 
            AND is_active = 1
            ORDER BY id DESC 
            LIMIT 1";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return null;
}

/**
 * Get all time periods for the current user
 */
function getTimePeriods($user_id, $db) {
    $sql = "SELECT * FROM time_periods 
            WHERE created_by = ? 
            ORDER BY year DESC, month DESC, created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Create a new time period
 */
function createTimePeriod($user_id, $year, $month, $db) {
    // Calculate start and end dates for the period
    $start_date = date('Y-m-01', strtotime("$year-$month-01"));
    $end_date = date('Y-m-t', strtotime("$year-$month-01"));
    $period_name = date('F Y', strtotime("$year-$month-01"));
    
    // Check if period already exists
    $check_sql = "SELECT id FROM time_periods 
                  WHERE created_by = ? AND year = ? AND month = ?";
    $check_stmt = $db->prepare($check_sql);
    $check_stmt->bind_param("iii", $user_id, $year, $month);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        return false; // Period already exists
    }
    
    // Deactivate all other periods
    $deactivate_sql = "UPDATE time_periods SET is_active = 0 WHERE created_by = ?";
    $deactivate_stmt = $db->prepare($deactivate_sql);
    $deactivate_stmt->bind_param("i", $user_id);
    $deactivate_stmt->execute();
    
    // Create new period
    $sql = "INSERT INTO time_periods (year, month, period_name, start_date, end_date, is_active, created_by) 
            VALUES (?, ?, ?, ?, ?, 1, ?)";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("iisssi", $year, $month, $period_name, $start_date, $end_date, $user_id);
    return $stmt->execute();
}

/**
 * Check and auto-create new period if current one has ended
 */
function checkAndCreateNewPeriod($user_id, $db) {
    $current_period = getCurrentTimePeriod($user_id, $db);
    
    if (!$current_period) {
        // No active period, create current month
        $current_year = date('Y');
        $current_month = date('n');
        return createTimePeriod($user_id, $current_year, $current_month, $db);
    }
    
    // Check if current period has ended
    $current_date = date('Y-m-d');
    if ($current_date > $current_period['end_date']) {
        // Period has ended, lock it and create new one
        lockTimePeriod($current_period['id'], $db);
        
        // Create new period for current month
        $current_year = date('Y');
        $current_month = date('n');
        return createTimePeriod($user_id, $current_year, $current_month, $db);
    }
    
    return true;
}

/**
 * Lock a time period (prevent modifications)
 */
function lockTimePeriod($period_id, $db) {
    $sql = "UPDATE time_periods 
            SET is_locked = 1, locked_at = NOW(), is_active = 0 
            WHERE id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $period_id);
    return $stmt->execute();
}

/**
 * Check if a period is locked
 */
function isPeriodLocked($period_id, $db) {
    $sql = "SELECT is_locked FROM time_periods WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    return $result['is_locked'] ?? false;
}

/**
 * Carry products forward to new period
 */
function carryProductsToNewPeriod($from_period_id, $to_period_id, $user_id, $db) {
    // Get all products from the previous period with remaining stock
    $sql = "SELECT id, stock_quantity, cost_price, selling_price 
            FROM products 
            WHERE period_id = ? AND created_by = ? AND stock_quantity > 0";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("ii", $from_period_id, $user_id);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    $carried_count = 0;
    
    foreach ($products as $product) {
        // Create a new product entry in the new period
        $carry_sql = "INSERT INTO products 
                     (name, sku, category_id, cost_price, selling_price, 
                      stock_quantity, min_stock, supplier, description, 
                      created_by, period_id, period_year, period_month, 
                      is_carried_forward, carried_from_period_id, added_date, added_by) 
                     SELECT name, CONCAT(sku, '-C'), category_id, cost_price, selling_price,
                            stock_quantity, min_stock, supplier, description,
                            created_by, ?, period_year, period_month,
                            1, ?, CURDATE(), ?
                     FROM products 
                     WHERE id = ?";
        
        $carry_stmt = $db->prepare($carry_sql);
        $carry_stmt->bind_param("iiii", $to_period_id, $from_period_id, $user_id, $product['id']);
        
        if ($carry_stmt->execute()) {
            $carried_count++;
        }
    }
    
    return $carried_count;
}
?>