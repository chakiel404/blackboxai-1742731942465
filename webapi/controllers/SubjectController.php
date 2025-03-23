<?php
require_once __DIR__ . '/BaseController.php';

class SubjectController extends BaseController {
    /**
     * Get all subjects with filtering options
     */
    public function getAll() {
        try {
            $this->verifyToken();
            
            // Build base query
            $query = "SELECT s.*, 
                     (SELECT COUNT(*) FROM materials WHERE subject_id = s.id) as material_count,
                     (SELECT COUNT(*) FROM quizzes WHERE subject_id = s.id) as quiz_count
                     FROM subjects s WHERE 1=1";
            $params = [];
            
            // Search functionality
            if (isset($this->requestData['search'])) {
                $search = '%' . $this->requestData['search'] . '%';
                $query .= " AND (s.name LIKE :search OR s.description LIKE :search)";
                $params[':search'] = $search;
            }
            
            // For teachers, only show their assigned subjects
            if ($this->checkRole('guru')) {
                $teacherId = $this->getCurrentUserId();
                $query = "SELECT s.*, 
                         (SELECT COUNT(*) FROM materials WHERE subject_id = s.id) as material_count,
                         (SELECT COUNT(*) FROM quizzes WHERE subject_id = s.id) as quiz_count
                         FROM subjects s 
                         JOIN teacher_subjects ts ON s.id = ts.subject_id 
                         WHERE ts.teacher_id = :teacher_id";
                if (isset($params[':search'])) {
                    $query .= " AND (s.name LIKE :search OR s.description LIKE :search)";
                }
                $params[':teacher_id'] = $teacherId;
            }
            
            // Pagination
            $page = isset($this->requestData['page']) ? (int)$this->requestData['page'] : 1;
            $limit = isset($this->requestData['limit']) ? (int)$this->requestData['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $countStmt = $this->db->prepare(str_replace('SELECT s.*', 'SELECT COUNT(*)', $query));
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();
            
            // Add sorting and pagination
            $query .= " ORDER BY s.name ASC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // For each subject, get assigned teachers
            foreach ($subjects as &$subject) {
                $teacherStmt = $this->db->prepare(
                    "SELECT u.id, u.full_name, u.nip 
                     FROM users u 
                     JOIN teacher_subjects ts ON u.id = ts.teacher_id 
                     WHERE ts.subject_id = ?"
                );
                $teacherStmt->execute([$subject['id']]);
                $subject['teachers'] = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $this->sendResponse([
                'subjects' => $subjects,
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
     * Get subject by ID with details
     */
    public function getById($id) {
        try {
            $this->verifyToken();
            
            // Get subject details
            $query = "SELECT s.*, 
                     (SELECT COUNT(*) FROM materials WHERE subject_id = s.id) as material_count,
                     (SELECT COUNT(*) FROM quizzes WHERE subject_id = s.id) as quiz_count
                     FROM subjects s WHERE s.id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Subject not found', 404);
            }
            
            $subject = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get assigned teachers
            $teacherStmt = $this->db->prepare(
                "SELECT u.id, u.full_name, u.nip 
                 FROM users u 
                 JOIN teacher_subjects ts ON u.id = ts.teacher_id 
                 WHERE ts.subject_id = ?"
            );
            $teacherStmt->execute([$id]);
            $subject['teachers'] = $teacherStmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendResponse($subject);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Create new subject (admin only)
     */
    public function create() {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $requiredFields = ['name', 'description'];
            $this->validateRequired($this->requestData, $requiredFields);
            
            // Check if subject name already exists
            $stmt = $this->db->prepare("SELECT id FROM subjects WHERE name = ?");
            $stmt->execute([$this->requestData['name']]);
            
            if ($stmt->rowCount() > 0) {
                return $this->sendError('Subject name already exists');
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Insert subject
                $query = "INSERT INTO subjects (name, description) VALUES (:name, :description)";
                
                $stmt = $this->db->prepare($query);
                $stmt->execute([
                    ':name' => $this->requestData['name'],
                    ':description' => $this->requestData['description']
                ]);
                
                $subjectId = $this->db->lastInsertId();
                
                // Assign teachers if provided
                if (isset($this->requestData['teacher_ids']) && is_array($this->requestData['teacher_ids'])) {
                    $assignQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)";
                    $assignStmt = $this->db->prepare($assignQuery);
                    
                    foreach ($this->requestData['teacher_ids'] as $teacherId) {
                        $assignStmt->execute([$teacherId, $subjectId]);
                    }
                }
                
                $this->db->commit();
                
                // Get created subject with details
                return $this->getById($subjectId);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Update subject (admin only)
     */
    public function update($id) {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Check if subject exists
            $stmt = $this->db->prepare("SELECT id FROM subjects WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Subject not found', 404);
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Update subject details
                $updateFields = [];
                $params = [];
                
                if (isset($this->requestData['name'])) {
                    // Check if new name already exists
                    $stmt = $this->db->prepare("SELECT id FROM subjects WHERE name = ? AND id != ?");
                    $stmt->execute([$this->requestData['name'], $id]);
                    
                    if ($stmt->rowCount() > 0) {
                        return $this->sendError('Subject name already exists');
                    }
                    
                    $updateFields[] = "name = :name";
                    $params[':name'] = $this->requestData['name'];
                }
                
                if (isset($this->requestData['description'])) {
                    $updateFields[] = "description = :description";
                    $params[':description'] = $this->requestData['description'];
                }
                
                if (!empty($updateFields)) {
                    $query = "UPDATE subjects SET " . implode(', ', $updateFields) . " WHERE id = :id";
                    $params[':id'] = $id;
                    
                    $stmt = $this->db->prepare($query);
                    $stmt->execute($params);
                }
                
                // Update teacher assignments if provided
                if (isset($this->requestData['teacher_ids'])) {
                    // Remove existing assignments
                    $stmt = $this->db->prepare("DELETE FROM teacher_subjects WHERE subject_id = ?");
                    $stmt->execute([$id]);
                    
                    // Add new assignments
                    if (!empty($this->requestData['teacher_ids'])) {
                        $assignQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)";
                        $assignStmt = $this->db->prepare($assignQuery);
                        
                        foreach ($this->requestData['teacher_ids'] as $teacherId) {
                            $assignStmt->execute([$teacherId, $id]);
                        }
                    }
                }
                
                $this->db->commit();
                
                // Get updated subject with details
                return $this->getById($id);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Delete subject (admin only)
     */
    public function delete($id) {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Check if subject exists
            $stmt = $this->db->prepare("SELECT id FROM subjects WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Subject not found', 404);
            }
            
            // Check if subject has any materials or quizzes
            $stmt = $this->db->prepare(
                "SELECT 
                    (SELECT COUNT(*) FROM materials WHERE subject_id = ?) as material_count,
                    (SELECT COUNT(*) FROM quizzes WHERE subject_id = ?) as quiz_count"
            );
            $stmt->execute([$id, $id]);
            $counts = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($counts['material_count'] > 0 || $counts['quiz_count'] > 0) {
                return $this->sendError('Cannot delete subject with existing materials or quizzes');
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Remove teacher assignments
                $stmt = $this->db->prepare("DELETE FROM teacher_subjects WHERE subject_id = ?");
                $stmt->execute([$id]);
                
                // Delete subject
                $stmt = $this->db->prepare("DELETE FROM subjects WHERE id = ?");
                $stmt->execute([$id]);
                
                $this->db->commit();
                
                return $this->sendResponse(null, 'Subject deleted successfully');
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Assign teachers to subject (admin only)
     */
    public function assignTeachers() {
        try {
            $this->verifyToken();
            
            // Verify admin role
            if (!$this->checkRole('admin')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $requiredFields = ['subject_id', 'teacher_ids'];
            $this->validateRequired($this->requestData, $requiredFields);
            
            // Check if subject exists
            $stmt = $this->db->prepare("SELECT id FROM subjects WHERE id = ?");
            $stmt->execute([$this->requestData['subject_id']]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Subject not found', 404);
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Remove existing assignments
                $stmt = $this->db->prepare("DELETE FROM teacher_subjects WHERE subject_id = ?");
                $stmt->execute([$this->requestData['subject_id']]);
                
                // Add new assignments
                $assignQuery = "INSERT INTO teacher_subjects (teacher_id, subject_id) VALUES (?, ?)";
                $assignStmt = $this->db->prepare($assignQuery);
                
                foreach ($this->requestData['teacher_ids'] as $teacherId) {
                    $assignStmt->execute([$teacherId, $this->requestData['subject_id']]);
                }
                
                $this->db->commit();
                
                // Get updated subject with details
                return $this->getById($this->requestData['subject_id']);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}
?>