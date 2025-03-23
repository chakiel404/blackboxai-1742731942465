package com.smartapp.util

object Constants {
    // API Configuration
    const val BASE_URL = "http://10.0.2.2:8000/"
    const val DEBUG = true

    // Network Timeouts (in seconds)
    const val CONNECT_TIMEOUT = 30L
    const val READ_TIMEOUT = 30L
    const val WRITE_TIMEOUT = 30L

    // SharedPreferences
    const val PREF_NAME = "smart_app_prefs"
    const val KEY_TOKEN = "auth_token"
    const val KEY_USER = "user_data"
    const val KEY_THEME = "app_theme"
    const val KEY_LANGUAGE = "app_language"

    // File Types
    object FileType {
        const val PDF = "pdf"
        const val DOC = "doc"
        const val DOCX = "docx"
        const val PPT = "ppt"
        const val PPTX = "pptx"
    }

    // Quiz Types
    object Quiz {
        const val TYPE_MULTIPLE_CHOICE = "multiple_choice"
        const val TYPE_ESSAY = "essay"
    }

    // User Roles
    object Role {
        const val STUDENT = "student"
        const val TEACHER = "teacher"
        const val ADMIN = "admin"
    }

    // Permissions
    object Permission {
        const val CREATE_QUIZ = "create_quiz"
        const val EDIT_QUIZ = "edit_quiz"
        const val DELETE_QUIZ = "delete_quiz"
        const val UPLOAD_MATERIAL = "upload_material"
        const val MANAGE_USERS = "manage_users"
    }

    // Validation
    object Validation {
        const val MIN_PASSWORD_LENGTH = 8
        const val MAX_FILE_SIZE = 10 * 1024 * 1024 // 10MB in bytes
        const val MAX_QUIZ_DURATION = 180 // 3 hours in minutes
    }

    // Date Formats
    object DateFormat {
        const val API_DATE_FORMAT = "yyyy-MM-dd'T'HH:mm:ss.SSS'Z'"
        const val DISPLAY_DATE_FORMAT = "MMM dd, yyyy"
        const val DISPLAY_TIME_FORMAT = "HH:mm"
        const val DISPLAY_DATE_TIME_FORMAT = "MMM dd, yyyy HH:mm"
    }

    // Navigation
    object Navigation {
        const val DEEP_LINK_SCHEME = "smartapp"
        const val DEEP_LINK_HOST = "app"
    }

    // Bundle Keys
    object BundleKey {
        const val SUBJECT_ID = "subject_id"
        const val QUIZ_ID = "quiz_id"
        const val MATERIAL_ID = "material_id"
        const val ASSIGNMENT_ID = "assignment_id"
    }

    // Request Codes
    object RequestCode {
        const val FILE_PICKER = 1001
        const val CAMERA = 1002
        const val PERMISSIONS = 1003
    }

    // Error Messages
    object Error {
        const val NETWORK_ERROR = "Network error occurred. Please check your connection."
        const val SERVER_ERROR = "Server error occurred. Please try again later."
        const val UNAUTHORIZED = "Unauthorized access. Please login again."
        const val FILE_TOO_LARGE = "File size exceeds the maximum limit of 10MB."
        const val INVALID_FILE_TYPE = "Invalid file type. Please select a supported file."
    }

    // Success Messages
    object Success {
        const val LOGIN = "Successfully logged in"
        const val LOGOUT = "Successfully logged out"
        const val UPLOAD = "File uploaded successfully"
        const val QUIZ_SUBMIT = "Quiz submitted successfully"
    }
}