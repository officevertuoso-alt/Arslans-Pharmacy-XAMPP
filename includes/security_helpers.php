<?php
/**
 * Security Helpers
 * File: includes/security_helpers.php
 *
 * Functions:
 *  - getSecurityQuestions()          : returns key => label array
 *  - renderSecurityQuestionDropdown() : renders <select> HTML
 *  - generateCSRFToken()             : generate & store CSRF token
 *  - verifyCSRFToken()               : verify submitted token
 *
 * Safe to include multiple times (all functions guarded with function_exists).
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─────────────────────────────────────────────────────────────────────────────
// Security Questions List
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('getSecurityQuestions')) {
    function getSecurityQuestions(): array
    {
        return [
            'phone'    => 'Your Phone Number',
            'nickname' => 'Your Nickname',
            'pet'      => 'Your Favourite Pet',
            'hometown' => 'Your Hometown',
            'dob'      => 'Your Date of Birth',
            'cnic'     => 'Your ID Card Number',
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Render Security Question Dropdown
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('renderSecurityQuestionDropdown')) {
    /**
     * Render a <select> element with security questions.
     *
     * @param string $selected   Currently selected key (optional)
     * @param string $name       Field name attribute (default: 'security_question')
     * @param string $class      CSS classes (default: 'form-select')
     * @param bool   $required   Whether field is required
     * @return string            HTML string
     */
    function renderSecurityQuestionDropdown(
        string $selected = '',
        string $name     = 'security_question',
        string $class    = 'form-select',
        bool   $required = true
    ): string {
        $questions = getSecurityQuestions();
        $req       = $required ? ' required' : '';
        $html      = '<select name="' . htmlspecialchars($name) . '" class="' . htmlspecialchars($class) . '"' . $req . '>';
        $html     .= '<option value="">-- Select a question --</option>';

        foreach ($questions as $key => $label) {
            $sel   = ($key === $selected) ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($key) . '"' . $sel . '>'
                   . htmlspecialchars($label)
                   . '</option>';
        }

        $html .= '</select>';
        return $html;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF Token Helpers
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken(string $token): bool
    {
        $stored = $_SESSION['csrf_token'] ?? '';
        if (empty($stored) || empty($token)) {
            return false;
        }
        return hash_equals($stored, $token);
    }
}