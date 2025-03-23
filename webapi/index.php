<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get request method and URI
$request_method = $_SERVER["REQUEST_METHOD"];
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri_segments = explode('/', trim($request_uri, '/'));

// Remove 'webapi' from segments if present
if ($uri_segments[0] === 'webapi') {
    array_shift($uri_segments);
}

// Get the requested endpoint and action
$endpoint = $uri_segments[0] ?? '';
$action = $uri_segments[1] ?? '';
$id = $uri_segments[2] ?? null;

// Autoload classes
spl_autoload_register(function ($class_name) {
    // Convert namespace to file path
    $file = __DIR__ . '/' . str_replace('\\', '/', $class_name) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize response array
$response = [
    'status' => 'error',
    'message' => 'Invalid request',
    'data' => null
];

try {
    // Route the request to appropriate controller
    switch ($endpoint) {
        case 'auth':
            require_once 'controllers/AuthController.php';
            $controller = new AuthController();
            break;
            
        case 'users':
            require_once 'controllers/UserController.php';
            $controller = new UserController();
            break;
            
        case 'materials':
            require_once 'controllers/MaterialController.php';
            $controller = new MaterialController();
            break;
            
        case 'quizzes':
            require_once 'controllers/QuizController.php';
            $controller = new QuizController();
            break;
            
        case 'subjects':
            require_once 'controllers/SubjectController.php';
            $controller = new SubjectController();
            break;
            
        case 'assignments':
            require_once 'controllers/AssignmentController.php';
            $controller = new AssignmentController();
            break;
            
        case 'settings':
            require_once 'controllers/SettingsController.php';
            $controller = new SettingsController();
            break;
            
        default:
            throw new Exception("Invalid endpoint");
    }

    // Process the request based on HTTP method
    switch ($request_method) {
        case 'GET':
            if ($id) {
                $response = $controller->getById($id);
            } else if ($action) {
                $response = $controller->$action();
            } else {
                $response = $controller->getAll();
            }
            break;

        case 'POST':
            if ($action) {
                $response = $controller->$action();
            } else {
                $response = $controller->create();
            }
            break;

        case 'PUT':
            if (!$id) {
                throw new Exception("ID is required for PUT request");
            }
            $response = $controller->update($id);
            break;

        case 'DELETE':
            if (!$id) {
                throw new Exception("ID is required for DELETE request");
            }
            $response = $controller->delete($id);
            break;

        default:
            throw new Exception("Invalid request method");
    }

} catch (Exception $e) {
    $response = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'data' => null
    ];
    http_response_code(400);
}

// Send response
echo json_encode($response);
?>