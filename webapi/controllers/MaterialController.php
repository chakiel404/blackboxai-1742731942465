<?php
require_once __DIR__ . '/BaseController.php';

class MaterialController extends BaseController {
    /**
     * Get all materials with filtering options
     */
    public function getAll() {
        try {
            $this->verifyToken();
            
            // Build base query
            $query = "SELECT m.*, s.name as subject_name, u.full_name as teacher_name 
                     FROM materials m 
                     JOIN subjects s ON m.subject_id = s.id 
                     JOIN users u ON m.teacher_id = u.id 
                     WHERE 1=1";
            $params = [];
            
            // Filter by subject
            if (isset($this->requestData['subject_id'])) {
                $query .= " AND m.subject_id = :subject_id";
                $params[':subject_id'] = $this->requestData['subject_id'];
            }
            
            // Filter by teacher
            if (isset($this->requestData['teacher_id'])) {
                $query .= " AND m.teacher_id = :teacher_id";
                $params[':teacher_id'] = $this->requestData['teacher_id'];
            }
            
            // Search functionality
            if (isset($this->requestData['search'])) {
                $search = '%' . $this->requestData['search'] . '%';
                $query .= " AND (m.title LIKE :search OR m.description LIKE :search)";
                $params[':search'] = $search;
            }
            
            // Pagination
            $page = isset($this->requestData['page']) ? (int)$this->requestData['page'] : 1;
            $limit = isset($this->requestData['limit']) ? (int)$this->requestData['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $countStmt = $this->db->prepare(str_replace('SELECT m.*', 'SELECT COUNT(*)', $query));
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();
            
            // Add sorting and pagination
            $query .= " ORDER BY m.created_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendResponse([
                'materials' => $materials,
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
     * Get material by ID
     */
    public function getById($id) {
        try {
            $this->verifyToken();
            
            $query = "SELECT m.*, s.name as subject_name, u.full_name as teacher_name 
                     FROM materials m 
                     JOIN subjects s ON m.subject_id = s.id 
                     JOIN users u ON m.teacher_id = u.id 
                     WHERE m.id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                $material = $stmt->fetch(PDO::FETCH_ASSOC);
                return $this->sendResponse($material);
            }
            
            return $this->sendError('Material not found', 404);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Upload new material (teachers only)
     */
    public function create() {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $requiredFields = ['title', 'description', 'subject_id'];
            $this->validateRequired($this->requestData, $requiredFields);
            
            // Validate file upload
            if (!isset($_FILES['file'])) {
                return $this->sendError('No file uploaded');
            }
            
            // Get current teacher ID from token
            $teacherId = $this->getCurrentUserId();
            
            // Verify teacher is assigned to the subject
            $stmt = $this->db->prepare("SELECT id FROM teacher_subjects 
                                      WHERE teacher_id = ? AND subject_id = ?");
            $stmt->execute([$teacherId, $this->requestData['subject_id']]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('You are not assigned to this subject');
            }
            
            // Upload file
            $allowedTypes = ['pdf', 'doc', 'docx'];
            $fileName = $this->uploadFile($_FILES['file'], $allowedTypes);
            
            // Insert material record
            $query = "INSERT INTO materials (title, description, file_path, file_type, subject_id, teacher_id) 
                     VALUES (:title, :description, :file_path, :file_type, :subject_id, :teacher_id)";
            
            $stmt = $this->db->prepare($query);
            
            $fileType = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            
            $params = [
                ':title' => $this->requestData['title'],
                ':description' => $this->requestData['description'],
                ':file_path' => $fileName,
                ':file_type' => $fileType,
                ':subject_id' => $this->requestData['subject_id'],
                ':teacher_id' => $teacherId
            ];
            
            if ($stmt->execute($params)) {
                $materialId = $this->db->lastInsertId();
                
                // Get created material
                $query = "SELECT m.*, s.name as subject_name, u.full_name as teacher_name 
                         FROM materials m 
                         JOIN subjects s ON m.subject_id = s.id 
                         JOIN users u ON m.teacher_id = u.id 
                         WHERE m.id = ?";
                
                $stmt = $this->db->prepare($query);
                $stmt->execute([$materialId]);
                $material = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($material, 'Material uploaded successfully', 'success', 201);
            }
            
            return $this->sendError('Failed to upload material');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Update material (teacher only)
     */
    public function update($id) {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Get current teacher ID
            $teacherId = $this->getCurrentUserId();
            
            // Check if material exists and belongs to the teacher
            $stmt = $this->db->prepare("SELECT * FROM materials WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$id, $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Material not found or unauthorized', 404);
            }
            
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Build update query
            $updateFields = [];
            $params = [];
            
            // Handle basic fields
            if (isset($this->requestData['title'])) {
                $updateFields[] = "title = :title";
                $params[':title'] = $this->requestData['title'];
            }
            
            if (isset($this->requestData['description'])) {
                $updateFields[] = "description = :description";
                $params[':description'] = $this->requestData['description'];
            }
            
            // Handle file update if new file is uploaded
            if (isset($_FILES['file'])) {
                $allowedTypes = ['pdf', 'doc', 'docx'];
                $fileName = $this->uploadFile($_FILES['file'], $allowedTypes);
                
                // Delete old file
                if (file_exists(__DIR__ . '/../uploads/' . $material['file_path'])) {
                    unlink(__DIR__ . '/../uploads/' . $material['file_path']);
                }
                
                $updateFields[] = "file_path = :file_path";
                $updateFields[] = "file_type = :file_type";
                $params[':file_path'] = $fileName;
                $params[':file_type'] = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
            }
            
            if (empty($updateFields)) {
                return $this->sendError('No fields to update');
            }
            
            $query = "UPDATE materials SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $params[':id'] = $id;
            
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute($params)) {
                // Get updated material
                $query = "SELECT m.*, s.name as subject_name, u.full_name as teacher_name 
                         FROM materials m 
                         JOIN subjects s ON m.subject_id = s.id 
                         JOIN users u ON m.teacher_id = u.id 
                         WHERE m.id = ?";
                
                $stmt = $this->db->prepare($query);
                $stmt->execute([$id]);
                $material = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($material, 'Material updated successfully');
            }
            
            return $this->sendError('Failed to update material');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Delete material (teacher only)
     */
    public function delete($id) {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Get current teacher ID
            $teacherId = $this->getCurrentUserId();
            
            // Check if material exists and belongs to the teacher
            $stmt = $this->db->prepare("SELECT file_path FROM materials WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$id, $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Material not found or unauthorized', 404);
            }
            
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete file
            if (file_exists(__DIR__ . '/../uploads/' . $material['file_path'])) {
                unlink(__DIR__ . '/../uploads/' . $material['file_path']);
            }
            
            // Delete record
            $stmt = $this->db->prepare("DELETE FROM materials WHERE id = ?");
            
            if ($stmt->execute([$id])) {
                return $this->sendResponse(null, 'Material deleted successfully');
            }
            
            return $this->sendError('Failed to delete material');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Download material
     */
    public function download($id) {
        try {
            $this->verifyToken();
            
            // Get material info
            $stmt = $this->db->prepare("SELECT file_path, file_type, title FROM materials WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Material not found', 404);
            }
            
            $material = $stmt->fetch(PDO::FETCH_ASSOC);
            $filePath = __DIR__ . '/../uploads/' . $material['file_path'];
            
            if (!file_exists($filePath)) {
                return $this->sendError('File not found', 404);
            }
            
            // Set headers for download
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $material['title'] . '.' . $material['file_type'] . '"');
            header('Content-Length: ' . filesize($filePath));
            
            // Output file
            readfile($filePath);
            exit;
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}
?>