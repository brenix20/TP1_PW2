<?php
if (!defined('IPCAVNF_BOOTSTRAP_LOADED')) {
  define('IPCAVNF_BOOTSTRAP_LOADED', true);

  /**
   * Load key=value pairs from .env into process/env superglobals.
   */
  function loadEnvFile($filePath)
  {
    if (!is_string($filePath) || $filePath === '' || !is_file($filePath) || !is_readable($filePath)) {
      return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
      return;
    }

    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }

      $pos = strpos($line, '=');
      if ($pos === false) {
        continue;
      }

      $key = trim(substr($line, 0, $pos));
      $value = trim(substr($line, $pos + 1));
      if ($key === '') {
        continue;
      }

      if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
        $value = substr($value, 1, -1);
      } elseif (str_starts_with($value, "'") && str_ends_with($value, "'")) {
        $value = substr($value, 1, -1);
      }

      if (getenv($key) === false) {
        putenv($key . '=' . $value);
      }

      if (!isset($_ENV[$key])) {
        $_ENV[$key] = $value;
      }
      if (!isset($_SERVER[$key])) {
        $_SERVER[$key] = $value;
      }
    }
  }

  function envValue($key, $default = '')
  {
    $value = getenv((string)$key);
    if ($value !== false && $value !== '') {
      return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
      return (string)$_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
      return (string)$_SERVER[$key];
    }

    return $default;
  }

  function getDbConfig()
  {
    $envPath = __DIR__ . '/.env';
    loadEnvFile($envPath);

    $host = (string)envValue('IPCAVNF_DB_HOST', 'localhost');
    $port = (int)envValue('IPCAVNF_DB_PORT', '3306');
    if ($port <= 0) {
      $port = 3306;
    }

    return [
      'host' => $host,
      'port' => $port,
      'name' => (string)envValue('IPCAVNF_DB_NAME', 'ipcavnf'),
      'user' => (string)envValue('IPCAVNF_DB_USER', ''),
      'pass' => (string)envValue('IPCAVNF_DB_PASS', ''),
    ];
  }

  function csrfEnsureToken()
  {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
  }

  function csrfTokenIsValid($token)
  {
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }

    $storedToken = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !is_string($storedToken) || $storedToken === '') {
      return false;
    }

    return hash_equals($storedToken, $token);
  }

  function csrfInput()
  {
    $token = csrfEnsureToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
  }
}
