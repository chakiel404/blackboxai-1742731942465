<?php
require_once __DIR__ . '/BaseController.php';

class AssignmentController extends BaseController {
    /**
     * Get all assignments with filtering options
     */
    public function getAll() {
        try {
            $this->verifyToken();
            
            // Build base query
            $query = "SELECT a.*, s.name as subject_name, u.full_name as teacher_name,
                     (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) as submission_count 
                     FROM assignments a 
                     JOIN subjects s ON a.subject_id = s.id 
                     JOIN users u ON a.teacher_id = u.id 
                     WHERE 1=1";
            $params = [];
            
            // Filter by subject
            if (isset($this->requestData['subject_id'])) {
                $query .= " AND a.subject_id = :subject_id";
                $params[':subject_id'] = $this->requestData['subject_id'];
            }
            
            // Filter by teacher
            if (isset($this->requestData['teacher_id'])) {
                $query .= " AND a.teacher_id = :teacher_id";
                $params[':teacher_id'] = $this->requestData['teacher_id'];
            }
            
            // Filter by due date
            if (isset($this->requestData['due_date'])) {
                $query .= " AND DATE(a.due_date) = DATE(:due_date)";
                $params[':due_date'] = $this->requestData['due_date'];
            }
            
            // Search functionality
            if (isset($this->requestData['search'])) {
                $search = '%' . $this->requestData['search'] . '%';
                $query .= " AND (a.title LIKE :search OR a.description LIKE :search)";
                $params[':search'] = $search;
            }
            
            // For students, show only their assignments
            if ($this->checkRole('siswa')) {
                $studentId = $this->getCurrentUserId();
                $query .= " AND a.id NOT IN (
                    SELECT assignment_id FROM assignment_submissions 
                    WHERE student_id = :student_id
                )";
                $params[':student_id'] = $studentId;
            }
            
            // Pagination
            $page = isset($this->requestData['page']) ? (int)$this->requestData['page'] : 1;
            $limit = isset($this->requestData['limit']) ? (int)$this->requestData['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $countStmt = $this->db->prepare(str_replace('SELECT a.*', 'SELECT COUNT(*)', $query));
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();
            
            // Add sorting and pagination
            $query .= " ORDER BY a.due_date ASC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendResponse([
                'assignments' => $assignments,
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
     * Get assignment by ID with details
     */
    public function getById($id) {
        try {
            $this->verifyToken();
            
            // Get assignment details
            $query = "SELECT a.*, s.name as subject_name, u.full_name as teacher_name 
                     FROM assignments a 
                     JOIN subjects s ON a.subject_id = s.id 
                     JOIN users u ON a.teacher_id = u.id 
                     WHERE a.id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Assignment not found', 404);
            }
            
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get submission statistics
            $statsQuery = "SELECT 
                          COUNT(*) as total_submissions,
                          COUNT(CASE WHEN grade IS NOT NULL THEN 1 END) as graded_submissions,
                          AVG(grade) as average_grade
                          FROM assignment_submissions 
                          WHERE assignment_id = ?";
            
            $stmt = $this->db->prepare($statsQuery);
            $stmt->execute([$id]);
            $assignment['statistics'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If user is a student, get their submission
            if ($this->checkRole('siswa')) {
                $studentId = $this->getCurrentUserId();
                $stmt = $this->db->prepare(
                    "SELECT * FROM assignment_submissions 
                     WHERE assignment_id = ? AND student_id = ?"
                );
                $stmt->execute([$id, $studentId]);
                $assignment['my_submission'] = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            return $this->sendResponse($assignment);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Create new assignment (teachers only)
     */
    public function create() {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $requiredFields = ['title', 'description', 'subject_id', 'due_date'];
            $this->validateRequired($this->requestData, $requiredFields);
            
            // Get current teacher ID
            $teacherId = $this->getCurrentUserId();
            
            // Verify teacher is assigned to the subject
            $stmt = $this->db->prepare("SELECT id FROM teacher_subjects 
                                      WHERE teacher_id = ? AND subject_id = ?");
            $stmt->execute([$teacherId, $this->requestData['subject_id']]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('You are not assigned to this subject');
            }
            
            // Insert assignment
            $query = "INSERT INTO assignments (title, description, subject_id, teacher_id, due_date) 
                     VALUES (:title, :description, :subject_id, :teacher_id, :due_date)";
            
            $stmt = $this->db->prepare($query);
            
            $params = [
                ':title' => $this->requestData['title'],
                ':description' => $this->requestData['description'],
                ':subject_id' => $this->requestData['subject_id'],
                ':teacher_id' => $teacherId,
                ':due_date' => $this->requestData['due_date']
            ];
            
            if ($stmt->execute($params)) {
                $assignmentId = $this->db->lastInsertId();
                return $this->getById($assignmentId);
            }
            
            return $this->sendError('Failed to create assignment');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Update assignment (teacher only)
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
            
            // Check if assignment exists and belongs to the teacher
            $stmt = $this->db->prepare("SELECT * FROM assignments WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$id, $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Assignment not found or unauthorized', 404);
            }
            
            // Build update query
            $updateFields = [];
            $params = [];
            
            $allowedFields = ['title', 'description', 'due_date'];
            foreach ($allowedFields as $field) {
                if (isset($this->requestData[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[":$field"] = $this->requestData[$field];
                }
            }
            
            if (empty($updateFields)) {
                return $this->sendError('No fields to update');
            }
            
            $query = "UPDATE assignments SET " . implode(', ', $updateFields) . " WHERE id = :id";
            $params[':id'] = $id;
            
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute($params)) {
                return $this->getById($id);
            }
            
            return $this->sendError('Failed to update assignment');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Delete assignment (teacher only)
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
            
            // Check if assignment exists and belongs to the teacher
            $stmt = $this->db->prepare("SELECT * FROM assignments WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$id, $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Assignment not found or unauthorized', 404);
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Delete submissions first
                $stmt = $this->db->prepare("DELETE FROM assignment_submissions WHERE assignment_id = ?");
                $stmt->execute([$id]);
                
                // Delete assignment
                $stmt = $this->db->prepare("DELETE FROM assignments WHERE id = ?");
                $stmt->execute([$id]);
                
                $this->db->commit();
                
                return $this->sendResponse(null, 'Assignment deleted successfully');
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Submit assignment (students only)
     */
    public function submit() {
        try {
            $this->verifyToken();
            
            // Verify student role
            if (!$this->checkRole('siswa')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $this->validateRequired($this->requestData, ['assignment_id']);
            
            // Validate file upload
            if (!isset($_FILES['file'])) {
                return $this->sendError('No file uploaded');
            }
            
            // Get current student ID
            $studentId = $this->getCurrentUserId();
            
            // Check if assignment exists and is not past due
            $stmt = $this->db->prepare("SELECT * FROM assignments WHERE id = ? AND due_date > NOW()");
            $stmt->execute([$this->requestData['assignment_id']]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Assignment not found or past due date');
            }
            
            // Check if student has already submitted
            $stmt = $this->db->prepare(
                "SELECT id FROM assignment_submissions 
                 WHERE assignment_id = ? AND student_id = ?"
            );
            $stmt->execute([$this->requestData['assignment_id'], $studentId]);
            
            if ($stmt->rowCount() > 0) {
                return $this->sendError('You have already submitted this assignment');
            }
            
            // Upload file
            $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            $fileName = $this->uploadFile($_FILES['file'], $allowedTypes);
            
            // Create submission
            $query = "INSERT INTO assignment_submissions 
                     (assignment_id, student_id, file_path) 
                     VALUES (?, ?, ?)";
            
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute([
                $this->requestData['assignment_id'],
                $studentId,
                $fileName
            ])) {
                $submissionId = $this->db->lastInsertId();
                
                // Get submission details
                $stmt = $this->db->prepare(
                    "SELECT * FROM assignment_submissions WHERE id = ?"
                );
                $stmt->execute([$submissionId]);
                $submission = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($submission, 'Assignment submitted successfully');
            }
            
            return $this->sendError('Failed to submit assignment');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Grade submission (teachers only)
     */
    public function grade() {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $requiredFields = ['submission_id', 'grade', 'feedback'];
            $this->validateRequired($this->requestData, $requiredFields);
            
            // Get current teacher ID
            $teacherId = $this->getCurrentUserId();
            
            // Check if submission exists and belongs to teacher's assignment
            $query = "SELECT s.* FROM assignment_submissions s 
                     JOIN assignments a ON s.assignment_id = a.id 
                     WHERE s.id = ? AND a.teacher_id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$this->requestData['submission_id'], $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Submission not found or unauthorized', 404);
            }
            
            // Update submission with grade and feedback
            $query = "UPDATE assignment_submissions 
                     SET grade = ?, feedback = ? 
                     WHERE id = ?";
            
            $stmt = $this->db->prepare($query);
            
            if ($stmt->execute([
                $this->requestData['grade'],
                $this->requestData['feedback'],
                $this->requestData['submission_id']
            ])) {
                // Get updated submission
                $stmt = $this->db->prepare(
                    "SELECT * FROM assignment_submissions WHERE id = ?"
                );
                $stmt->execute([$this->requestData['submission_id']]);
                $submission = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($submission, 'Submission graded successfully');
            }
            
            return $this->sendError('Failed to grade submission');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Get submissions for an assignment (teachers only)
     */
    public function getSubmissions($assignmentId) {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Get current teacher ID
            $teacherId = $this->getCurrentUserId();
            
            // Check if assignment belongs to teacher
            $stmt = $this->db->prepare("SELECT id FROM assignments WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$assignmentId, $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Assignment not found or unauthorized', 404);
            }
            
            // Get submissions with student details
            $query = "SELECT s.*, u.full_name as student_name, u.nis_nisn 
                     FROM assignment_submissions s 
                     JOIN users u ON s.student_id = u.id 
                     WHERE s.assignment_id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$assignmentId]);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendResponse($submissions);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}
?>