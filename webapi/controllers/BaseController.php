<?php
class BaseController {
    protected $db;
    protected $requestData;

    public function __construct() {
        // Initialize database connection
        require_once __DIR__ . '/../config/db.php';
        $database = new Database();
        $this->db = $database->getConnection();
        
        // Get request data
        $this->requestData = $this->getRequestData();
    }

    /**
     * Get request data regardless of request method
     */
    protected function getRequestData() {
        $data = [];
        
        // GET parameters
        if (!empty($_GET)) {
            $data = array_merge($data, $_GET);
        }
        
        // POST/PUT data
        $input = file_get_contents('php://input');
        if (!empty($input)) {
            $postData = json_decode($input, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $data = array_merge($data, $postData);
            }
        }
        
        // POST form data
        if (!empty($_POST)) {
            $data = array_merge($data, $_POST);
        }
        
        // Files
        if (!empty($_FILES)) {
            $data['files'] = $_FILES;
        }
        
        return $data;
    }

    /**
     * Send JSON response
     */
    protected function sendResponse($data = null, $message = 'Success', $status = 'success', $code = 200) {
        $response = [
            'status' => $status,
            'message' => $message,
            'data' => $data
        ];
        
        http_response_code($code);
        return $response;
    }

    /**
     * Send error response
     */
    protected function sendError($message = 'Error occurred', $code = 400) {
        return $this->sendResponse(null, $message, 'error', $code);
    }

    /**
     * Validate required fields
     */
    protected function validateRequired($data, $fields) {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new Exception('Required fields missing: ' . implode(', ', $missing));
        }
        
        return true;
    }

    /**
     * Upload file
     */
    protected function uploadFile($file, $allowedTypes = ['pdf', 'doc', 'docx'], $maxSize = 5242880) {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            throw new Exception('No file uploaded');
        }

        // Validate file size (5MB max by default)
        if ($file['size'] > $maxSize) {
            throw new Exception('File size exceeds limit');
        }

        // Validate file type
        $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($fileType, $allowedTypes)) {
            throw new Exception('Invalid file type');
        }

        // Generate unique filename
        $fileName = uniqid() . '.' . $fileType;
        $uploadPath = __DIR__ . '/../uploads/' . $fileName;

        // Create uploads directory if it doesn't exist
        if (!file_exists(__DIR__ . '/../uploads')) {
            mkdir(__DIR__ . '/../uploads', 0777, true);
        }

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to upload file');
        }

        return $fileName;
    }

    /**
     * Verify JWT token from Authorization header
     */
    protected function verifyToken() {
        $headers = apache_request_headers();
        if (!isset($headers['Authorization'])) {
            throw new Exception('No token provided', 401);
        }

        $token = str_replace('Bearer ', '', $headers['Authorization']);
        
        try {
            // Here you would verify the JWT token
            // For now, we'll just check if it's not empty
            if (empty($token)) {
                throw new Exception('Invalid token');
            }
            
            return true;
        } catch (Exception $e) {
            throw new Exception('Invalid token', 401);
        }
    }

    /**
     * Get current user ID from token
     */
    protected function getCurrentUserId() {
        // This would normally decode the JWT token and get the user ID
        // For now, we'll return a dummy ID
        return 1;
    }

    /**
     * Check if current user has required role
     */
    protected function checkRole($requiredRole) {
        // This would normally check the user's role from the JWT token
        // For now, we'll return true
        return true;
    }

    /**
     * Default CRUD methods that can be overridden by child classes
     */
    public function getAll() {
        return $this->sendError('Method not implemented');
    }

    public function getById($id) {
        return $this->sendError('Method not implemented');
    }

    public function create() {
        return $this->sendError('Method not implemented');
    }

    public function update($id) {
        return $this->sendError('Method not implemented');
    }

    public function delete($id) {
        return $this->sendError('Method not implemented');
    }
}
?>