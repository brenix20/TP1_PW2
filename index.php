<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['utilizador_autenticado'])) {
  header('Location: login.php');
  exit;
}

$perfilAtual = strtolower(trim((string)($_SESSION['utilizador_perfil'] ?? '')));
$queryString = (string)($_SERVER['QUERY_STRING'] ?? '');

$target = '';
if ($perfilAtual === 'aluno') {
  $target = 'aluno.php';
} elseif ($perfilAtual === 'gestor') {
  $target = 'gestor.php';
} elseif ($perfilAtual === 'funcionario' || $perfilAtual === 'funcionário') {
  $target = 'funcionario.php';
}

if ($target !== '') {
  if ($queryString !== '') {
    $target .= '?' . $queryString;
  }

  header('Location: ' . $target);
  exit;
}

require __DIR__ . '/portal.php';
