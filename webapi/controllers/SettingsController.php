<?php
require_once __DIR__ . '/BaseController.php';

class SettingsController extends BaseController {
    /**
     * Get all settings
     */
    public function getAll() {
        try {
            $this->verifyToken();
            
            // Only admin can view all settings
            if (!$this->checkRole('admin')) {
                // For non-admin users, return only public settings
                $stmt = $this->db->prepare(
                    "SELECT setting_key, setting_value 
                     FROM settings 
                     WHERE setting_key LIKE 'public.%'"
                );
                $stmt->execute();
            } else {
                // Admin can see all settings
                $stmt = $this->db->prepare("SELECT * FROM settings");
                $stmt->execute();
            }
            
            $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Convert settings array to key-value object
            $settingsObject = [];
            foreach ($settings as $setting) {
                $key = $setting['setting_key'];
                $value = $this->parseSettingValue($setting['setting_value']);
                $settingsObject[$key] = $value;
            }
            
            return $this->sendResponse($settingsObject);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Get setting by key
     */
    public function getByKey($key) {
        try {
            $this->verifyToken();
            
            $stmt = $this->db->prepare("SELECT * FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Setting not found', 404);
            }
            
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if non-admin user is trying to access non-public setting
            if (!$this->checkRole('admin') && !str_starts_with($setting['setting_key'], 'public.')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            $setting['setting_value'] = $this->parseSettingValue($setting['setting_value']);
            
            return $this->sendResponse($setting);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Update settings (admin only)
     */
    public function update() {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $this->validateRequired($this->requestData, ['settings']);
            
            if (!is_array($this->requestData['settings'])) {
                return $this->sendError('Settings must be an array');
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                foreach ($this->requestData['settings'] as $key => $value) {
                    // Prepare value for storage
                    $storedValue = is_array($value) || is_object($value) 
                                 ? json_encode($value) 
                                 : (string)$value;
                    
                    // Check if setting exists
                    $stmt = $this->db->prepare("SELECT id FROM settings WHERE setting_key = ?");
                    $stmt->execute([$key]);
                    
                    if ($stmt->rowCount() > 0) {
                        // Update existing setting
                        $stmt = $this->db->prepare(
                            "UPDATE settings SET setting_value = ? WHERE setting_key = ?"
                        );
                        $stmt->execute([$storedValue, $key]);
                    } else {
                        // Insert new setting
                        $stmt = $this->db->prepare(
                            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)"
                        );
                        $stmt->execute([$key, $storedValue]);
                    }
                }
                
                $this->db->commit();
                
                // Return all settings after update
                return $this->getAll();
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Delete setting (admin only)
     */
    public function delete($key) {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Check if setting exists
            $stmt = $this->db->prepare("SELECT id FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Setting not found', 404);
            }
            
            // Delete setting
            $stmt = $this->db->prepare("DELETE FROM settings WHERE setting_key = ?");
            
            if ($stmt->execute([$key])) {
                return $this->sendResponse(null, 'Setting deleted successfully');
            }
            
            return $this->sendError('Failed to delete setting');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Initialize default settings
     */
    public function initializeDefaults() {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Define default settings
            $defaultSettings = [
                'public.app_name' => 'SmartApp',
                'public.school_name' => 'Your School Name',
                'public.school_address' => 'Your School Address',
                'public.contact_email' => 'contact@school.com',
                'public.contact_phone' => '+1234567890',
                'system.max_file_size' => '5242880', // 5MB in bytes
                'system.allowed_file_types' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
                'system.default_quiz_duration' => '60', // minutes
                'system.maintenance_mode' => false,
                'notification.email_enabled' => true,
                'notification.sms_enabled' => false
            ];
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                foreach ($defaultSettings as $key => $value) {
                    // Prepare value for storage
                    $storedValue = is_array($value) || is_object($value) 
                                 ? json_encode($value) 
                                 : (string)$value;
                    
                    // Check if setting exists
                    $stmt = $this->db->prepare("SELECT id FROM settings WHERE setting_key = ?");
                    $stmt->execute([$key]);
                    
                    if ($stmt->rowCount() === 0) {
                        // Insert only if setting doesn't exist
                        $stmt = $this->db->prepare(
                            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)"
                        );
                        $stmt->execute([$key, $storedValue]);
                    }
                }
                
                $this->db->commit();
                
                // Return all settings after initialization
                return $this->getAll();
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Parse setting value from storage format
     */
    private function parseSettingValue($value) {
        // Try to decode JSON
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        
        // Handle boolean values
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        // Handle numeric values
        if (is_numeric($value)) {
            return strpos($value, '.') !== false ? (float)$value : (int)$value;
        }
        
        // Return as string by default
        return $value;
    }
}
?>