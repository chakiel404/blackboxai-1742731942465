<?php
require_once __DIR__ . '/BaseController.php';

class UserController extends BaseController {
    /**
     * Get all users with optional filtering
     */
    public function getAll() {
        try {
            $this->verifyToken();
            
            // Build query based on filters
            $query = "SELECT id, username, role, full_name, email, nis_nisn, nip, created_at, updated_at 
                     FROM users WHERE 1=1";
            $params = [];
            
            // Filter by role if specified
            if (isset($this->requestData['role'])) {
                $query .= " AND role = :role";
                $params[':role'] = $this->requestData['role'];
            }
            
            // Add search functionality
            if (isset($this->requestData['search'])) {
                $search = '%' . $this->requestData['search'] . '%';
                $query .= " AND (username LIKE :search OR full_name LIKE :search OR email LIKE :search 
                           OR nis_nisn LIKE :search OR nip LIKE :search)";
                $params[':search'] = $search;
            }
            
            // Add pagination
            $page = isset($this->requestData['page']) ? (int)$this->requestData['page'] : 1;
            $limit = isset($this->requestData['limit']) ? (int)$this->requestData['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            // Get total count for pagination
            $countStmt = $this->db->prepare(str_replace('SELECT id, username', 'SELECT COUNT(*)', $query));
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();
            
            // Add sorting and pagination to query
            $query .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendResponse([
                'users' => $users,
                'pagination' => [
                    'total' => $totalCount,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => ceil($totalCount / $limit)
                ]
            ]);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Get user by ID
     */
    public function getById($id) {
        try {
            $this->verifyToken();
            
            $stmt = $this->db->prepare("SELECT id, username, role, full_name, email, nis_nisn, nip, 
                                      created_at, updated_at 
                                      FROM users WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                return $this->sendResponse($user);
            }
            
            return $this->sendError('User not found', 404);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Create new user (admin only)
     */
    public function create() {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $requiredFields = ['username', 'password', 'role', 'full_name', 'email'];
            $this->validateRequired($this->requestData, $requiredFields);
            
            // Additional validation based on role
            if ($this->requestData['role'] === 'siswa' && !isset($this->requestData['nis_nisn'])) {
                throw new Exception('NIS/NISN is required for student');
            }
            if ($this->requestData['role'] === 'guru' && !isset($this->requestData['nip'])) {
                throw new Exception('NIP is required for teacher');
            }
            
            // Check username uniqueness
            $stmt = $this->db->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$this->requestData['username']]);
            if ($stmt->rowCount() > 0) {
                return $this->sendError('Username already exists');
            }
            
            // Check email uniqueness
            $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$this->requestData['email']]);
            if ($stmt->rowCount() > 0) {
                return $this->sendError('Email already exists');
            }
            
            // Hash password
            $hashedPassword = password_hash($this->requestData['password'], PASSWORD_DEFAULT);
            
            // Insert new user
            $query = "INSERT INTO users (username, password, role, full_name, email, nis_nisn, nip) 
                     VALUES (:username, :password, :role, :full_name, :email, :nis_nisn, :nip)";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':username', $this->requestData['username']);
            $stmt->bindParam(':password', $hashedPassword);
            $stmt->bindParam(':role', $this->requestData['role']);
            $stmt->bindParam(':full_name', $this->requestData['full_name']);
            $stmt->bindParam(':email', $this->requestData['email']);
            $stmt->bindParam(':nis_nisn', $this->requestData['nis_nisn'] ?? null);
            $stmt->bindParam(':nip', $this->requestData['nip'] ?? null);
            
            if ($stmt->execute()) {
                $userId = $this->db->lastInsertId();
                
                // Get created user
                $stmt = $this->db->prepare("SELECT id, username, role, full_name, email, nis_nisn, nip, 
                                          created_at, updated_at 
                                          FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($user, 'User created successfully', 'success', 201);
            }
            
            return $this->sendError('Failed to create user');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Update user
     */
    public function update($id) {
        try {
            $this->verifyToken();
            
            // Check if user exists
            $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('User not found', 404);
            }
            
            // Build update query dynamically based on provided fields
            $updateFields = [];
            $params = [];
            
            // Handle updateable fields
            $allowedFields = ['full_name', 'email', 'nis_nisn', 'nip'];
            foreach ($allowedFields as $field) {
                if (isset($this->requestData[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[":$field"] = $this->requestData[$field];
                }
            }
            
            // Handle password update if provided
            if (isset($this->requestData['password']) && !empty($this->requestData['password'])) {
                $updateFields[] = "password = :password";
                $params[':password'] = password_hash($this->requestData['password'], PASSWORD_DEFAULT);
            }
            
            if (empty($updateFields)) {
                return $this->sendError('No fields to update');
            }
            
            $query = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $params[':id'] = $id;
            
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute($params)) {
                // Get updated user
                $stmt = $this->db->prepare("SELECT id, username, role, full_name, email, nis_nisn, nip, 
                                          created_at, updated_at 
                                          FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($user, 'User updated successfully');
            }
            
            return $this->sendError('Failed to update user');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Delete user (admin only)
     */
    public function delete($id) {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Check if user exists
            $stmt = $this->db->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('User not found', 404);
            }
            
            // Delete user
            $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
            
            if ($stmt->execute([$id])) {
                return $this->sendResponse(null, 'User deleted successfully');
            }
            
            return $this->sendError('Failed to delete user');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}
?>