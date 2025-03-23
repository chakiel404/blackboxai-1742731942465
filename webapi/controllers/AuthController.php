<?php
require_once __DIR__ . '/BaseController.php';

class AuthController extends BaseController {
    /**
     * Handle user login
     */
    public function login() {
        try {
            // Validate required fields
            $this->validateRequired($this->requestData, ['username', 'password']);
            
            $username = $this->requestData['username'];
            $password = $this->requestData['password'];
            
            // Prepare query based on username format (NIS/NISN, NIP, or admin username)
            $query = "SELECT id, username, password, role, full_name, email, nis_nisn, nip 
                     FROM users 
                     WHERE username = :username 
                     OR nis_nisn = :username 
                     OR nip = :username 
                     LIMIT 1";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Verify password
                if (password_verify($password, $user['password'])) {
                    // Remove password from response
                    unset($user['password']);
                    
                    // Generate JWT token (in real implementation)
                    $token = $this->generateToken($user);
                    
                    return $this->sendResponse([
                        'token' => $token,
                        'user' => $user
                    ], 'Login successful');
                }
            }
            
            return $this->sendError('Invalid credentials', 401);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Handle user registration
     */
    public function register() {
        try {
            // Validate required fields
            $requiredFields = ['username', 'password', 'role', 'full_name', 'email'];
            $this->validateRequired($this->requestData, $requiredFields);
            
            // Additional validation based on role
            if ($this->requestData['role'] === 'siswa' && !isset($this->requestData['nis_nisn'])) {
                throw new Exception('NIS/NISN is required for student registration');
            }
            if ($this->requestData['role'] === 'guru' && !isset($this->requestData['nip'])) {
                throw new Exception('NIP is required for teacher registration');
            }
            
            // Check if username already exists
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$this->requestData['username']]);
            if ($stmt->rowCount() > 0) {
                return $this->sendError('Username already exists');
            }
            
            // Check if email already exists
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$this->requestData['email']]);
            if ($stmt->rowCount() > 0) {
                return $this->sendError('Email already exists');
            }
            
            // Hash password
            $hashedPassword = password_hash($this->requestData['password'], PASSWORD_DEFAULT);
            
            // Prepare insert query
            $query = "INSERT INTO users (username, password, role, full_name, email, nis_nisn, nip) 
                     VALUES (:username, :password, :role, :full_name, :email, :nis_nisn, :nip)";
            
            $stmt = $this->db->prepare($query);
            
            // Bind parameters
            $stmt->bindParam(':username', $this->requestData['username']);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':role', $this->requestData['role']);
            $stmt->bindParam(':full_name', $this->requestData['full_name']);
            $stmt->bindParam(':email', $this->requestData['email']);
            $stmt->bindParam(':nis_nisn', $this->requestData['nis_nisn'] ?? null);
            $stmt->bindParam(':nip', $this->requestData['nip'] ?? null);
            
            if ($stmt->execute()) {
                $userId = $this->db->lastInsertId();
                
                // Get the created user (without password)
                $stmt = $this->db->prepare("SELECT id, username, role, full_name, email, nis_nisn, nip 
                                          FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($user, 'Registration successful', 'success', 201);
            }
            
            return $this->sendError('Registration failed');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Generate JWT token (simplified version)
     */
    private function generateToken($user) {
        // In a real implementation, you would use a proper JWT library
        // For now, we'll just create a simple encoded string
        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'exp' => time() + (60 * 60 * 24) // 24 hours expiration
        ];
        
        return base64_encode(json_encode($payload));
    }
    
    /**
     * Verify current user's token and return user data
     */
    public function verify() {
        try {
            $this->verifyToken();
            
            // In a real implementation, you would decode the JWT token
            // and fetch fresh user data from the database
            
            return $this->sendResponse([
                'verified' => true
            ], 'Token is valid');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), 401);
        }
    }
    
    /**
     * Handle password reset request
     */
    public function resetPassword() {
        try {
            $this->validateRequired($this->requestData, ['email']);
            
            // Check if email exists
            $stmt = $this->db->prepare("SELECT id, username FROM users WHERE email = ?");
            $stmt->execute([$this->requestData['email']]);
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // In a real implementation, you would:
                // 1. Generate a reset token
                // 2. Save it to the database with an expiration
                // 3. Send an email to the user with a reset link
                
                return $this->sendResponse(null, 'Password reset instructions sent to your email');
            }
            
            return $this->sendError('Email not found');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}
?>