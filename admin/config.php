<?php
session_start();

class Database {
    private $host = "localhost";
    private $db_name = "vinmel_business";
    private $username = "root";
    private $password = "";
    public $conn;

    public function getConnection() {
        try {
            $this->conn = new mysqli(
                $this->host,
                $this->username,
                $this->password,
                $this->db_name
            );

            if ($this->conn->connect_error) {
                throw new Exception("Connection failed: " . $this->conn->connect_error);
            }

            return $this->conn;
        } catch (Exception $e) {
            error_log("Database connection error: " . $e->getMessage());
            return null;
        }
    }
}

// Check if user is logged in
function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }
}

// Get current user info (MySQLi version)
function getCurrentUser() {
    if (isset($_SESSION['user_id'])) {
        $database = new Database();
        $db = $database->getConnection();

        if (!$db) {
            return null;
        }

        $query = "SELECT * FROM users WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();

        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return null;
}

// Define requireLogin() function, it just calls checkAuth()
function requireLogin() {
    checkAuth();
}

// =============================================================================
// TIME PERIOD FUNCTIONS - ADDING THE MISSING getCurrentTimePeriod() FUNCTION
// =============================================================================

/**
 * Get the currently active time period (FIXED VERSION)
 */
function getCurrentTimePeriod($db) {
    try {
        if (!$db || !is_object($db)) {
            error_log("Invalid database connection provided to getCurrentTimePeriod");
            return null;
        }
        
        $sql = "SELECT * FROM time_periods WHERE is_active = 1 ORDER BY year DESC, month DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error getting current time period: " . $e->getMessage());
        return null;
    }
}

// NEW FUNCTION: Get current time period for specific user (explicit)
function getUserCurrentTimePeriod($db, $user_id) {
    return getCurrentTimePeriod($db, $user_id);
}

// Helper function to get all time periods (backward compatible)
function getAllTimePeriods($db, $user_id = null) {
    $periods = [];
    try {
        if (!$db || !is_object($db)) {
            error_log("Invalid database connection provided to getAllTimePeriods");
            return $periods;
        }
        
        if ($user_id) {
            $query = "SELECT * FROM time_periods WHERE created_by = ? ORDER BY year DESC, month DESC";
            $stmt = $db->prepare($query);
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
            }
        } else {
            $query = "SELECT * FROM time_periods ORDER BY year DESC, month DESC";
            $stmt = $db->prepare($query);
        }
        
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $periods[] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error getting time periods: " . $e->getMessage());
    }
    return $periods;
}

// Helper function to get time period by ID
function getTimePeriodById($db, $period_id) {
    try {
        if (!$db || !is_object($db)) {
            error_log("Invalid database connection provided to getTimePeriodById");
            return null;
        }
        
        $query = "SELECT * FROM time_periods WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error getting time period by ID: " . $e->getMessage());
        return null;
    }
}

// Helper function to validate time period
function isValidTimePeriod($db, $period_id) {
    try {
        if (!$db || !is_object($db)) {
            return false;
        }
        
        $query = "SELECT id FROM time_periods WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $stmt->store_result();
        return $stmt->num_rows > 0;
    } catch (Exception $e) {
        error_log("Error validating time period: " . $e->getMessage());
        return false;
    }
}

// Helper function to check if period is locked
function isPeriodLocked($db, $period_id) {
    try {
        if (!$db || !is_object($db)) {
            return true; // Assume locked if no DB connection
        }
        
        $query = "SELECT is_locked FROM time_periods WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return true;
        }
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $period = $result->fetch_assoc();
        
        return $period && $period['is_locked'] == 1;
    } catch (Exception $e) {
        error_log("Error checking period lock status: " . $e->getMessage());
        return true;
    }
}

function checkAndCreateNewPeriod($user_id, $db) {
    try {
        if (!$db || !$user_id) {
            return;
        }
        
        // Get current period
        $current_period = getCurrentTimePeriod($db);
        
        if (!$current_period) {
            // No current period, create one for current month
            $year = date('Y');
            $month = date('n');
            createTimePeriod($user_id, $year, $month, $db);
            return;
        }
        
        // Check if current period has ended
        $current_date = date('Y-m-d');
        if ($current_date > $current_period['end_date']) {
            // Auto-lock the ended period
            lockTimePeriod($current_period['id'], $db);
            
            // Create new period for next month
            $next_month = date('n', strtotime('+1 month'));
            $next_year = date('Y', strtotime('+1 month'));
            createTimePeriod($user_id, $next_year, $next_month, $db);
        }
    } catch (Exception $e) {
        error_log("Error in checkAndCreateNewPeriod: " . $e->getMessage());
    }
}

// Create a new time period
function createTimePeriod($user_id, $year, $month, $db) {
    try {
        if (!$db || !$user_id) {
            return false;
        }
        
        // Check if period already exists
        $check_query = "SELECT id FROM time_periods WHERE year = ? AND month = ?";
        $check_stmt = $db->prepare($check_query);
        if (!$check_stmt) {
            return false;
        }
        $check_stmt->bind_param("ii", $year, $month);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            return false;
        }
        
        // Calculate start and end dates
        $start_date = date('Y-m-01', strtotime("$year-$month-01"));
        $end_date = date('Y-m-t', strtotime("$year-$month-01"));
        $period_name = date('F Y', strtotime("$year-$month-01"));
        
        // Insert new period
        $insert_query = "INSERT INTO time_periods (year, month, period_name, start_date, end_date, created_by, is_active) 
                         VALUES (?, ?, ?, ?, ?, ?, 1)";
        $insert_stmt = $db->prepare($insert_query);
        if (!$insert_stmt) {
            return false;
        }
        $insert_stmt->bind_param("iisssi", $year, $month, $period_name, $start_date, $end_date, $user_id);
        
        return $insert_stmt->execute();
    } catch (Exception $e) {
        error_log("Error creating time period: " . $e->getMessage());
        return false;
    }
}

// Lock a time period
function lockTimePeriod($period_id, $db) {
    try {
        if (!$db) {
            return false;
        }
        
        $query = "UPDATE time_periods SET is_locked = 1, locked_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $period_id);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error locking time period: " . $e->getMessage());
        return false;
    }
}

// Function to automatically deactivate all periods except the specified one
function deactivateAllPeriodsExcept($db, $active_period_id = null) {
    try {
        if (!$db) {
            return false;
        }
        
        if ($active_period_id) {
            $sql = "UPDATE time_periods SET is_active = 0 WHERE id != ?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("i", $active_period_id);
        } else {
            $sql = "UPDATE time_periods SET is_active = 0";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return false;
            }
        }
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error deactivating periods: " . $e->getMessage());
        return false;
    }
}

// Function to get the most recent open period
function getMostRecentOpenPeriod($db) {
    try {
        if (!$db) {
            return null;
        }
        
        $sql = "SELECT * FROM time_periods WHERE is_locked = 0 ORDER BY year DESC, month DESC LIMIT 1";
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    } catch (Exception $e) {
        error_log("Error getting most recent open period: " . $e->getMessage());
        return null;
    }
}

// Function to auto-activate the most recent period
function autoActivateRecentPeriod($db) {
    try {
        // Deactivate all periods first
        deactivateAllPeriodsExcept($db);
        
        // Find the most recent open period
        $recent_period = getMostRecentOpenPeriod($db);
        
        if ($recent_period) {
            $sql = "UPDATE time_periods SET is_active = 1 WHERE id = ?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                return false;
            }
            $stmt->bind_param("i", $recent_period['id']);
            return $stmt->execute();
        }
        return false;
    } catch (Exception $e) {
        error_log("Error auto-activating recent period: " . $e->getMessage());
        return false;
    }
}

// Get all time periods with creator information (for admin view)
function getTimePeriods($user_id, $db) {
    $periods = [];
    try {
        if (!$db) {
            return $periods;
        }
        
        $query = "SELECT tp.*, u.name as creator_name 
                  FROM time_periods tp 
                  LEFT JOIN users u ON tp.created_by = u.id 
                  ORDER BY tp.year DESC, tp.month DESC";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return $periods;
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $periods[] = $row;
        }
    } catch (Exception $e) {
        error_log("Error getting time periods: " . $e->getMessage());
    }
    return $periods;
}

// =============================================================================
// EXPENSE CLASS AND OTHER EXISTING FUNCTIONS
// =============================================================================

// Expense class for database operations
class Expense {
    private $conn;
    private $table_name = "expenses";
    
    // Object properties
    public $id;
    public $date;
    public $category;
    public $description;
    public $amount;
    public $tax;
    public $fees;
    public $net_amount;
    public $notes;
    public $created_by;
    public $time_period_id;
    public $created_at;

    public function __construct($db) {
        $this->conn = $db;
    }

    // Create new expense
    public function create() {
        if (!$this->conn) {
            return false;
        }
        
        // Calculate net amount first
        $net_amount = (float)$this->amount + (float)$this->tax + (float)$this->fees;
        
        $query = "INSERT INTO " . $this->table_name . " 
                  (date, category, description, amount, tax, fees, net_amount, notes, created_by, time_period_id) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($query);

        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return false;
        }

        // Prepare all values with proper types
        $date = $this->date;
        $category = $this->category;
        $description = $this->description;
        $amount = (float)$this->amount;
        $tax = (float)$this->tax;
        $fees = (float)$this->fees;
        $notes = $this->notes ?? '';
        $created_by = (int)$this->created_by;
        $time_period_id = (int)$this->time_period_id;

        // Bind parameters
        $bound = $stmt->bind_param("sssdddddss", 
            $date,
            $category,
            $description,
            $amount,
            $tax,
            $fees,
            $net_amount,
            $notes,
            $created_by,
            $time_period_id
        );

        if (!$bound) {
            error_log("Bind failed: " . $stmt->error);
            return false;
        }

        if ($stmt->execute()) {
            return true;
        } else {
            error_log("Execute failed: " . $stmt->error);
            return false;
        }
    }

    // Read expenses with optional time period filter
    public function read($time_period_id = null) {
        if (!$this->conn) {
            return null;
        }
        
        $query = "SELECT e.*, u.name as created_by_name 
                  FROM " . $this->table_name . " e 
                  LEFT JOIN users u ON e.created_by = u.id 
                  WHERE 1=1";
        
        if ($time_period_id) {
            $query .= " AND e.time_period_id = ?";
        }
        
        $query .= " ORDER BY e.date DESC";

        $stmt = $this->conn->prepare($query);

        if ($time_period_id) {
            $stmt->bind_param("i", $time_period_id);
        }

        $stmt->execute();
        return $stmt->get_result();
    }

    // Read expenses by specific period
    public function readByPeriod($time_period_id) {
        if (!$this->conn) {
            return null;
        }
        
        $query = "SELECT e.*, u.name as created_by_name 
                  FROM " . $this->table_name . " e 
                  LEFT JOIN users u ON e.created_by = u.id 
                  WHERE e.time_period_id = ? 
                  ORDER BY e.date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $time_period_id);
        $stmt->execute();
        
        return $stmt->get_result();
    }

    // Get expense statistics for a period
    public function getStats($time_period_id = null) {
        if (!$this->conn) {
            return null;
        }
        
        $query = "SELECT 
                    COUNT(*) as total_count,
                    SUM(amount) as total_amount,
                    SUM(tax) as total_tax,
                    SUM(fees) as total_fees,
                    SUM(net_amount) as total_net,
                    AVG(amount) as average_amount,
                    MAX(amount) as max_amount
                  FROM " . $this->table_name . " 
                  WHERE 1=1";
        
        if ($time_period_id) {
            $query .= " AND time_period_id = ?";
        }

        $stmt = $this->conn->prepare($query);
        
        if ($time_period_id) {
            $stmt->bind_param("i", $time_period_id);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->fetch_assoc();
    }

    // Get top categories for a period
    public function getTopCategories($time_period_id = null) {
        if (!$this->conn) {
            return null;
        }
        
        $query = "SELECT category, COUNT(*) as count, SUM(amount) as total_amount
                  FROM " . $this->table_name . " 
                  WHERE 1=1";
        
        if ($time_period_id) {
            $query .= " AND time_period_id = ?";
        }
        
        $query .= " GROUP BY category ORDER BY total_amount DESC LIMIT 5";

        $stmt = $this->conn->prepare($query);
        
        if ($time_period_id) {
            $stmt->bind_param("i", $time_period_id);
        }
        
        $stmt->execute();
        return $stmt->get_result();
    }

    // Update expense
    public function update() {
        if (!$this->conn) {
            return false;
        }
        
        $query = "UPDATE " . $this->table_name . "
                  SET date=?, category=?, description=?, amount=?, tax=?, fees=?, net_amount=?, notes=?
                  WHERE id=?";

        $stmt = $this->conn->prepare($query);

        // Calculate net amount
        $net_amount = (float)$this->amount + (float)$this->tax + (float)$this->fees;

        // Bind all as strings to avoid issues
        $stmt->bind_param("sssddddds", 
            $this->date,
            $this->category,
            $this->description,
            $this->amount,
            $this->tax,
            $this->fees,
            $net_amount,
            $this->notes,
            $this->id
        );

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Delete expense
    public function delete() {
        if (!$this->conn) {
            return false;
        }
        
        $query = "DELETE FROM " . $this->table_name . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->id);

        if ($stmt->execute()) {
            return true;
        }
        return false;
    }

    // Get single expense by ID
    public function readOne() {
        if (!$this->conn) {
            return null;
        }
        
        $query = "SELECT e.*, u.name as created_by_name 
                  FROM " . $this->table_name . " e 
                  LEFT JOIN users u ON e.created_by = u.id 
                  WHERE e.id = ? 
                  LIMIT 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $row = $result->fetch_assoc();
        
        if ($row) {
            $this->date = $row['date'];
            $this->category = $row['category'];
            $this->description = $row['description'];
            $this->amount = $row['amount'];
            $this->tax = $row['tax'];
            $this->fees = $row['fees'];
            $this->net_amount = $row['net_amount'];
            $this->notes = $row['notes'];
            $this->created_by = $row['created_by'];
            $this->time_period_id = $row['time_period_id'];
            $this->created_at = $row['created_at'];
        }
        
        return $row;
    }

    // Check if expense exists
    public function exists() {
        if (!$this->conn) {
            return false;
        }
        
        $query = "SELECT id FROM " . $this->table_name . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        $stmt->store_result();
        
        return $stmt->num_rows > 0;
    }

    // Get expense by ID
    public function getExpenseById($id) {
        if (!$this->conn) {
            return false;
        }
        
        $query = "SELECT * FROM " . $this->table_name . " WHERE id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return false;
    }

    // Also add the readByTimePeriod method that your expenses.php expects
    public function readByTimePeriod($time_period_id) {
        return $this->readByPeriod($time_period_id);
    }
}

// Additional helper functions for financial calculations

// Get total income for a period
function getTotalIncome($db, $period_id) {
    try {
        if (!$db) {
            return 0;
        }
        
        $query = "SELECT SUM(net_amount) as total_income FROM transactions WHERE time_period_id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        return $data['total_income'] ?? 0;
    } catch (Exception $e) {
        error_log("Error getting total income: " . $e->getMessage());
        return 0;
    }
}

// Get total expenses for a period
function getTotalExpenses($db, $period_id) {
    try {
        if (!$db) {
            return 0;
        }
        
        $query = "SELECT SUM(net_amount) as total_expenses FROM expenses WHERE time_period_id = ?";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        return $data['total_expenses'] ?? 0;
    } catch (Exception $e) {
        error_log("Error getting total expenses: " . $e->getMessage());
        return 0;
    }
}

// Get net income for a period
function getNetIncome($db, $period_id) {
    $income = getTotalIncome($db, $period_id);
    $expenses = getTotalExpenses($db, $period_id);
    return $income - $expenses;
}

// Format currency consistently
function formatCurrency($amount) {
    return 'KES ' . number_format($amount, 2);
}

// Check if user has permission for specific action
function hasPermission($required_permission) {
    if (!isset($_SESSION['user_role'])) {
        return false;
    }
    
    $user_role = $_SESSION['user_role'];
    
    // Admin has all permissions
    if ($user_role === 'admin') {
        return true;
    }
    
    // Define role permissions
    $permissions = [
        'view_reports' => ['admin', 'manager', 'user'],
        'manage_users' => ['admin'],
        'manage_periods' => ['admin', 'manager'],
        'add_transactions' => ['admin', 'manager', 'user'],
        'add_expenses' => ['admin', 'manager', 'user'],
    ];
    
    return isset($permissions[$required_permission]) && 
           in_array($user_role, $permissions[$required_permission]);
}

// Log user actions for audit trail
function logAction($db, $user_id, $action, $details = '') {
    try {
        if (!$db) {
            return false;
        }
        
        $query = "INSERT INTO audit_log (user_id, action, details, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($query);
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt->bind_param("issss", $user_id, $action, $details, $ip_address, $user_agent);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error logging action: " . $e->getMessage());
        return false;
    }
}


// Validate date format
function isValidDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

// Get financial year based on date
function getFinancialYear($date = null) {
    $date = $date ?: date('Y-m-d');
    $year = date('Y', strtotime($date));
    $month = date('m', strtotime($date));
    
    if ($month >= 7) {
        return $year . '-' . ($year + 1);
    } else {
        return ($year - 1) . '-' . $year;
    }
}

// Additional utility functions
function redirect($url) {
    header("Location: " . $url);
    exit();
}

function isAjaxRequest() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}
?>