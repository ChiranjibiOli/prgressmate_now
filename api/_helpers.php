<?php
function jsonResponse($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

function requirePost()
{
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'POST required'], 405);
  }
}

function requireGet()
{
  if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'GET required'], 405);
  }
}

function csrfToken(): string
{
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function verifyCsrf()
{
  $token = $_POST['csrf_token'] ?? '';
  if (!$token || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    jsonResponse(['success' => false, 'error' => 'Invalid CSRF token'], 403);
  }
}
