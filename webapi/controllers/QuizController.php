<?php
require_once __DIR__ . '/BaseController.php';

class QuizController extends BaseController {
    /**
     * Get all quizzes with filtering options
     */
    public function getAll() {
        try {
            $this->verifyToken();
            
            // Build base query
            $query = "SELECT q.*, s.name as subject_name, u.full_name as teacher_name,
                     (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) as question_count 
                     FROM quizzes q 
                     JOIN subjects s ON q.subject_id = s.id 
                     JOIN users u ON q.teacher_id = u.id 
                     WHERE 1=1";
            $params = [];
            
            // Filter by subject
            if (isset($this->requestData['subject_id'])) {
                $query .= " AND q.subject_id = :subject_id";
                $params[':subject_id'] = $this->requestData['subject_id'];
            }
            
            // Filter by teacher
            if (isset($this->requestData['teacher_id'])) {
                $query .= " AND q.teacher_id = :teacher_id";
                $params[':teacher_id'] = $this->requestData['teacher_id'];
            }
            
            // Search functionality
            if (isset($this->requestData['search'])) {
                $search = '%' . $this->requestData['search'] . '%';
                $query .= " AND (q.title LIKE :search OR q.description LIKE :search)";
                $params[':search'] = $search;
            }
            
            // Pagination
            $page = isset($this->requestData['page']) ? (int)$this->requestData['page'] : 1;
            $limit = isset($this->requestData['limit']) ? (int)$this->requestData['limit'] : 10;
            $offset = ($page - 1) * $limit;
            
            // Get total count
            $countStmt = $this->db->prepare(str_replace('SELECT q.*', 'SELECT COUNT(*)', $query));
            $countStmt->execute($params);
            $totalCount = $countStmt->fetchColumn();
            
            // Add sorting and pagination
            $query .= " ORDER BY q.created_at DESC LIMIT :limit OFFSET :offset";
            $params[':limit'] = $limit;
            $params[':offset'] = $offset;
            
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $this->sendResponse([
                'quizzes' => $quizzes,
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
     * Get quiz by ID with questions
     */
    public function getById($id) {
        try {
            $this->verifyToken();
            
            // Get quiz details
            $query = "SELECT q.*, s.name as subject_name, u.full_name as teacher_name 
                     FROM quizzes q 
                     JOIN subjects s ON q.subject_id = s.id 
                     JOIN users u ON q.teacher_id = u.id 
                     WHERE q.id = ?";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Quiz not found', 404);
            }
            
            $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get questions
            $stmt = $this->db->prepare("SELECT * FROM quiz_questions WHERE quiz_id = ?");
            $stmt->execute([$id]);
            $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format questions (decode JSON options for multiple choice)
            foreach ($questions as &$question) {
                if ($question['question_type'] === 'multiple_choice') {
                    $question['options'] = json_decode($question['options'], true);
                }
                // Remove correct answer if user is a student
                if ($this->checkRole('siswa')) {
                    unset($question['correct_answer']);
                }
            }
            
            $quiz['questions'] = $questions;
            
            return $this->sendResponse($quiz);
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Create new quiz (teachers only)
     */
    public function create() {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $requiredFields = ['title', 'description', 'subject_id', 'duration_minutes', 'questions'];
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
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Create quiz
                $query = "INSERT INTO quizzes (title, description, subject_id, teacher_id, duration_minutes) 
                         VALUES (:title, :description, :subject_id, :teacher_id, :duration_minutes)";
                
                $stmt = $this->db->prepare($query);
                $stmt->execute([
                    ':title' => $this->requestData['title'],
                    ':description' => $this->requestData['description'],
                    ':subject_id' => $this->requestData['subject_id'],
                    ':teacher_id' => $teacherId,
                    ':duration_minutes' => $this->requestData['duration_minutes']
                ]);
                
                $quizId = $this->db->lastInsertId();
                
                // Add questions
                $questionQuery = "INSERT INTO quiz_questions 
                                (quiz_id, question_text, question_type, correct_answer, options, points) 
                                VALUES (:quiz_id, :question_text, :question_type, :correct_answer, :options, :points)";
                
                $questionStmt = $this->db->prepare($questionQuery);
                
                foreach ($this->requestData['questions'] as $question) {
                    $params = [
                        ':quiz_id' => $quizId,
                        ':question_text' => $question['question_text'],
                        ':question_type' => $question['question_type'],
                        ':correct_answer' => $question['correct_answer'],
                        ':options' => isset($question['options']) ? json_encode($question['options']) : null,
                        ':points' => $question['points'] ?? 1
                    ];
                    
                    $questionStmt->execute($params);
                }
                
                $this->db->commit();
                
                // Get created quiz with questions
                return $this->getById($quizId);
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Update quiz (teacher only)
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
            
            // Check if quiz exists and belongs to the teacher
            $stmt = $this->db->prepare("SELECT * FROM quizzes WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$id, $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Quiz not found or unauthorized', 404);
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Update quiz details
                $updateFields = [];
                $params = [];
                
                $allowedFields = ['title', 'description', 'duration_minutes'];
                foreach ($allowedFields as $field) {
                    if (isset($this->requestData[$field])) {
                        $updateFields[] = "$field = :$field";
                        $params[":$field"] = $this->requestData[$field];
                    }
                }
                
                if (!empty($updateFields)) {
                    $query = "UPDATE quizzes SET " . implode(', ', $updateFields) . " WHERE id = :id";
                    $params[':id'] = $id;
                    
                    $stmt = $this->db->prepare($query);
                    $stmt->execute($params);
                }
                
                // Update questions if provided
                if (isset($this->requestData['questions'])) {
                    // Delete existing questions
                    $stmt = $this->db->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
                    $stmt->execute([$id]);
                    
                    // Add new questions
                    $questionQuery = "INSERT INTO quiz_questions 
                                    (quiz_id, question_text, question_type, correct_answer, options, points) 
                                    VALUES (:quiz_id, :question_text, :question_type, :correct_answer, :options, :points)";
                    
                    $questionStmt = $this->db->prepare($questionQuery);
                    
                    foreach ($this->requestData['questions'] as $question) {
                        $params = [
                            ':quiz_id' => $id,
                            ':question_text' => $question['question_text'],
                            ':question_type' => $question['question_type'],
                            ':correct_answer' => $question['correct_answer'],
                            ':options' => isset($question['options']) ? json_encode($question['options']) : null,
                            ':points' => $question['points'] ?? 1
                        ];
                        
                        $questionStmt->execute($params);
                    }
                }
                
                $this->db->commit();
                
                // Get updated quiz with questions
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
     * Delete quiz (teacher only)
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
            
            // Check if quiz exists and belongs to the teacher
            $stmt = $this->db->prepare("SELECT id FROM quizzes WHERE id = ? AND teacher_id = ?");
            $stmt->execute([$id, $teacherId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Quiz not found or unauthorized', 404);
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Delete questions first (due to foreign key constraint)
                $stmt = $this->db->prepare("DELETE FROM quiz_questions WHERE quiz_id = ?");
                $stmt->execute([$id]);
                
                // Delete quiz attempts and answers
                $stmt = $this->db->prepare("DELETE FROM quiz_answers WHERE attempt_id IN 
                                          (SELECT id FROM quiz_attempts WHERE quiz_id = ?)");
                $stmt->execute([$id]);
                
                $stmt = $this->db->prepare("DELETE FROM quiz_attempts WHERE quiz_id = ?");
                $stmt->execute([$id]);
                
                // Delete quiz
                $stmt = $this->db->prepare("DELETE FROM quizzes WHERE id = ?");
                $stmt->execute([$id]);
                
                $this->db->commit();
                
                return $this->sendResponse(null, 'Quiz deleted successfully');
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Start quiz attempt (students only)
     */
    public function startAttempt($quizId) {
        try {
            $this->verifyToken();
            
            // Verify student role
            if (!$this->checkRole('siswa')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Get current student ID
            $studentId = $this->getCurrentUserId();
            
            // Check if quiz exists
            $stmt = $this->db->prepare("SELECT * FROM quizzes WHERE id = ?");
            $stmt->execute([$quizId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Quiz not found', 404);
            }
            
            // Check if student already has an ongoing attempt
            $stmt = $this->db->prepare("SELECT id FROM quiz_attempts 
                                      WHERE quiz_id = ? AND student_id = ? AND status = 'in_progress'");
            $stmt->execute([$quizId, $studentId]);
            
            if ($stmt->rowCount() > 0) {
                return $this->sendError('You already have an ongoing attempt for this quiz');
            }
            
            // Create new attempt
            $stmt = $this->db->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, start_time, status) 
                                      VALUES (?, ?, NOW(), 'in_progress')");
            
            if ($stmt->execute([$quizId, $studentId])) {
                $attemptId = $this->db->lastInsertId();
                
                // Get attempt details
                $stmt = $this->db->prepare("SELECT * FROM quiz_attempts WHERE id = ?");
                $stmt->execute([$attemptId]);
                $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
                
                return $this->sendResponse($attempt, 'Quiz attempt started successfully', 'success', 201);
            }
            
            return $this->sendError('Failed to start quiz attempt');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Submit quiz attempt (students only)
     */
    public function submitAttempt($attemptId) {
        try {
            $this->verifyToken();
            
            // Verify student role
            if (!$this->checkRole('siswa')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $this->validateRequired($this->requestData, ['answers']);
            
            // Get current student ID
            $studentId = $this->getCurrentUserId();
            
            // Check if attempt exists and belongs to student
            $stmt = $this->db->prepare("SELECT qa.*, q.duration_minutes 
                                      FROM quiz_attempts qa 
                                      JOIN quizzes q ON qa.quiz_id = q.id 
                                      WHERE qa.id = ? AND qa.student_id = ? AND qa.status = 'in_progress'");
            $stmt->execute([$attemptId, $studentId]);
            
            if ($stmt->rowCount() === 0) {
                return $this->sendError('Quiz attempt not found or already completed', 404);
            }
            
            $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if time limit exceeded
            $startTime = strtotime($attempt['start_time']);
            $timeLimit = $attempt['duration_minutes'] * 60;
            if (time() - $startTime > $timeLimit) {
                return $this->sendError('Time limit exceeded');
            }
            
            // Start transaction
            $this->db->beginTransaction();
            
            try {
                // Insert answers
                $answerQuery = "INSERT INTO quiz_answers (attempt_id, question_id, answer_text) 
                              VALUES (:attempt_id, :question_id, :answer_text)";
                
                $answerStmt = $this->db->prepare($answerQuery);
                
                foreach ($this->requestData['answers'] as $answer) {
                    $params = [
                        ':attempt_id' => $attemptId,
                        ':question_id' => $answer['question_id'],
                        ':answer_text' => $answer['answer_text']
                    ];
                    
                    $answerStmt->execute($params);
                }
                
                // Update attempt status
                $stmt = $this->db->prepare("UPDATE quiz_attempts SET status = 'completed', end_time = NOW() 
                                          WHERE id = ?");
                $stmt->execute([$attemptId]);
                
                // Auto-grade multiple choice questions
                $this->gradeMultipleChoice($attemptId);
                
                $this->db->commit();
                
                return $this->sendResponse(null, 'Quiz submitted successfully');
                
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
    
    /**
     * Grade multiple choice questions automatically
     */
    private function gradeMultipleChoice($attemptId) {
        // Get all multiple choice answers for this attempt
        $query = "SELECT qa.id, qa.answer_text, qq.correct_answer, qq.points 
                 FROM quiz_answers qa 
                 JOIN quiz_questions qq ON qa.question_id = qq.id 
                 WHERE qa.attempt_id = ? AND qq.question_type = 'multiple_choice'";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([$attemptId]);
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalPoints = 0;
        
        // Grade each answer
        foreach ($answers as $answer) {
            $points = $answer['answer_text'] === $answer['correct_answer'] 
                     ? $answer['points'] 
                     : 0;
            
            $totalPoints += $points;
            
            // Update answer with points
            $stmt = $this->db->prepare("UPDATE quiz_answers SET points_earned = ? WHERE id = ?");
            $stmt->execute([$points, $answer['id']]);
        }
        
        // Update attempt with score for multiple choice questions
        $stmt = $this->db->prepare("UPDATE quiz_attempts SET score = ? WHERE id = ?");
        $stmt->execute([$totalPoints, $attemptId]);
    }
    
    /**
     * Grade essay questions (teachers only)
     */
    public function gradeEssay() {
        try {
            $this->verifyToken();
            
            // Verify teacher role
            if (!$this->checkRole('guru')) {
                return $this->sendError('Unauthorized access', 403);
            }
            
            // Validate required fields
            $this->validateRequired($this->requestData, ['answer_id', 'points']);
            
            // Update answer points
            $stmt = $this->db->prepare("UPDATE quiz_answers SET points_earned = ? WHERE id = ?");
            
            if ($stmt->execute([$this->requestData['points'], $this->requestData['answer_id']])) {
                // Recalculate total score for the attempt
                $stmt = $this->db->prepare("UPDATE quiz_attempts SET score = 
                                          (SELECT SUM(points_earned) FROM quiz_answers WHERE attempt_id = quiz_attempts.id) 
                                          WHERE id = (SELECT attempt_id FROM quiz_answers WHERE id = ?)");
                $stmt->execute([$this->requestData['answer_id']]);
                
                return $this->sendResponse(null, 'Answer graded successfully');
            }
            
            return $this->sendError('Failed to grade answer');
            
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}
?>