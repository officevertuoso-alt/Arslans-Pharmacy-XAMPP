<?php
/**
 * ajax/live_search_products.php — DEPRECATED
 * ══════════════════════════════════════════════════════════════════════════════
 * BUG #8 FIX: This file is a duplicate of search_products_live.php.
 * Canonical endpoint: ajax/search_products_live.php
 *
 * This endpoint now redirects all requests to the canonical endpoint.
 * DO NOT add new features here.
 * ══════════════════════════════════════════════════════════════════════════════
 */
/* DEPRECATED - DO NOT USE — redirect to canonical endpoint */
require_once '../config/config.php';
require_once '../includes/auth.php';
requireLogin();
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
$q = $_GET['q'] ?? $_GET['query'] ?? '';
header('Location: ' . BASE_URL . 'ajax/search_products_live.php?q=' . urlencode($q));
exit;
