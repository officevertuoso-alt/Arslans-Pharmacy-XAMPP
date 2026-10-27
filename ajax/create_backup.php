<?php
/**
 * ajax/create_backup.php — DEPRECATED
 * ══════════════════════════════════════════════════════════════════════════════
 * BUG #8 FIX: This file is a redundant duplicate of backup_create.php.
 * Use ajax/backup_create.php for all backup operations.
 *
 * This endpoint now redirects all requests to the canonical endpoint.
 * DO NOT add new features here.
 * ══════════════════════════════════════════════════════════════════════════════
 */
/* DEPRECATED - DO NOT USE — use backup_create.php instead */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
// Include and execute canonical backup file
require_once __DIR__ . '/backup_create.php';
