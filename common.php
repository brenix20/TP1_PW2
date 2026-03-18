<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['utilizador_autenticado'])) {
  header('Location: login.php');
  exit;
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "ipcavnf";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

if (!function_exists('e')) {
  function e($value)
  {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('formatDatePt')) {
  function formatDatePt($dateValue)
  {
    $dateValue = trim((string)$dateValue);
    if ($dateValue === '') {
      return '';
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateValue);
    if (!$date || $date->format('Y-m-d') !== $dateValue) {
      return $dateValue;
    }

    return $date->format('d/m/Y');
  }
}

if (!function_exists('redirectWithMessage')) {
  function redirectWithMessage($table, $type, $message)
  {
    $table = urlencode($table);
    $type = urlencode($type);
    $message = urlencode($message);
    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    if ($currentScript === 'funcionario.php') {
      header("Location: funcionario.php?section={$table}&type={$type}&message={$message}");
    } else {
      header("Location: ?table={$table}&type={$type}&message={$message}");
    }
    exit;
  }
}

if (!function_exists('fetchLookup')) {
  function fetchLookup(mysqli $conn, $table, $idField, $labelField)
  {
    $sql = "SELECT {$idField}, {$labelField} FROM {$table} ORDER BY {$labelField}";
    $result = $conn->query($sql);
    $items = [];
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $items[] = $row;
      }
      $result->close();
    }
    return $items;
  }
}

if (!function_exists('fetchMatriculasRows')) {
  function fetchMatriculasRows(mysqli $conn, $filtroTexto = '', $filtroCurso = 0)
  {
    $rows = [];

    $sqlBase =
      "SELECT m.IdAluno, m.Nome, m.IdCurso, m.DataNascimento, m.Morada, m.Email, m.Telefone, m.EstadoValidacao, c.Curso
       FROM matriculas m
       JOIN cursos c ON c.IdCurso = m.IdCurso";

    if ($filtroTexto !== '' && $filtroCurso > 0) {
      $sql = $sqlBase . " WHERE (CAST(m.IdAluno AS CHAR) LIKE ? OR m.Nome LIKE ?) AND m.IdCurso = ? ORDER BY m.IdAluno DESC";
      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $likeFiltro = '%' . $filtroTexto . '%';
        $stmt->bind_param('ssi', $likeFiltro, $likeFiltro, $filtroCurso);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
          $rows[] = $row;
        }
        $stmt->close();
      }
      return $rows;
    }

    if ($filtroTexto !== '') {
      $sql = $sqlBase . " WHERE CAST(m.IdAluno AS CHAR) LIKE ? OR m.Nome LIKE ? ORDER BY m.IdAluno DESC";
      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $likeFiltro = '%' . $filtroTexto . '%';
        $stmt->bind_param('ss', $likeFiltro, $likeFiltro);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
          $rows[] = $row;
        }
        $stmt->close();
      }
      return $rows;
    }

    if ($filtroCurso > 0) {
      $sql = $sqlBase . " WHERE m.IdCurso = ? ORDER BY m.IdAluno DESC";
      $stmt = $conn->prepare($sql);
      if ($stmt) {
        $stmt->bind_param('i', $filtroCurso);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($row = $result->fetch_assoc())) {
          $rows[] = $row;
        }
        $stmt->close();
      }
      return $rows;
    }

    $result = $conn->query($sqlBase . " ORDER BY m.IdAluno DESC");
    if ($result) {
      while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
      }
      $result->close();
    }

    return $rows;
  }
}

if (!function_exists('getUploadedImageBlob')) {
  function getUploadedImageBlob($fieldName, &$errorMessage)
  {
    $errorMessage = '';

    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
      return null;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
      $errorMessage = 'Erro no upload da imagem.';
      return false;
    }

    $tmpFile = $_FILES[$fieldName]['tmp_name'];
    $imageInfo = @getimagesize($tmpFile);
    if ($imageInfo === false) {
      $errorMessage = 'O ficheiro enviado não é uma imagem válida.';
      return false;
    }

    $imageData = file_get_contents($tmpFile);
    if ($imageData === false) {
      $errorMessage = 'Não foi possível ler a imagem enviada.';
      return false;
    }

    return $imageData;
  }
}

if (!function_exists('ensureMatriculasExtraFields')) {
  function ensureMatriculasExtraFields(mysqli $conn)
  {
    $columns = [
      'DataNascimento' => "ALTER TABLE matriculas ADD COLUMN DataNascimento DATE NULL AFTER IdCurso",
      'Morada' => "ALTER TABLE matriculas ADD COLUMN Morada VARCHAR(255) NULL AFTER DataNascimento",
      'Email' => "ALTER TABLE matriculas ADD COLUMN Email VARCHAR(120) NULL AFTER Morada",
      'Telefone' => "ALTER TABLE matriculas ADD COLUMN Telefone VARCHAR(20) NULL AFTER Email",
      'EstadoValidacao' => "ALTER TABLE matriculas ADD COLUMN EstadoValidacao VARCHAR(20) NOT NULL DEFAULT 'Pendente' AFTER Telefone",
      'ObservacoesValidacao' => "ALTER TABLE matriculas ADD COLUMN ObservacoesValidacao TEXT NULL AFTER EstadoValidacao",
      'Foto' => "ALTER TABLE matriculas ADD COLUMN Foto LONGBLOB NULL AFTER ObservacoesValidacao",
    ];

    foreach ($columns as $columnName => $alterSql) {
      $columnEscaped = $conn->real_escape_string($columnName);
      $result = $conn->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'matriculas'
           AND COLUMN_NAME = '{$columnEscaped}'
         LIMIT 1"
      );

      if (!$result) {
        return false;
      }

      $exists = $result && $result->num_rows > 0;
      $result->close();

      if (!$exists) {
        if (!$conn->query($alterSql)) {
          return false;
        }
      }
    }

    $fotoTypeResult = $conn->query(
      "SELECT DATA_TYPE
       FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'matriculas'
         AND COLUMN_NAME = 'Foto'
       LIMIT 1"
    );

    if (!$fotoTypeResult) {
      return false;
    }

    $fotoTypeRow = $fotoTypeResult->fetch_assoc();
    $fotoTypeResult->close();
    $fotoDataType = strtolower((string)($fotoTypeRow['DATA_TYPE'] ?? ''));
    if ($fotoDataType !== '' && $fotoDataType !== 'longblob') {
      if (!$conn->query("ALTER TABLE matriculas MODIFY COLUMN Foto LONGBLOB NULL")) {
        return false;
      }
    }

    return true;
  }
}

if (!function_exists('validarDataNascimento')) {
  function validarDataNascimento($dataNascimento, &$errorMessage)
  {
    $dataNascimento = trim((string)$dataNascimento);
    if ($dataNascimento === '') {
      $errorMessage = 'A data de nascimento é obrigatória.';
      return false;
    }

    $data = DateTimeImmutable::createFromFormat('Y-m-d', $dataNascimento);
    $dateErrors = DateTimeImmutable::getLastErrors();
    $temErrosData = is_array($dateErrors)
      && (($dateErrors['warning_count'] ?? 0) > 0 || ($dateErrors['error_count'] ?? 0) > 0);

    if (!$data || $temErrosData || $data->format('Y-m-d') !== $dataNascimento) {
      $errorMessage = 'Data de nascimento inválida.';
      return false;
    }

    $hoje = new DateTimeImmutable('today');
    if ($data > $hoje) {
      $errorMessage = 'A data de nascimento não pode ser superior ao dia de hoje.';
      return false;
    }

    $limiteMinimo = $hoje->modify('-13 years');
    if ($data > $limiteMinimo) {
      $errorMessage = 'O aluno tem de ter no mínimo 13 anos.';
      return false;
    }

    return $data->format('Y-m-d');
  }
}

if (!function_exists('validarEmail')) {
  function validarEmail($email, &$errorMessage)
  {
    $email = trim((string)$email);
    if ($email === '') {
      $errorMessage = 'O email é obrigatório.';
      return false;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errorMessage = 'Email inválido.';
      return false;
    }

    $emailLen = function_exists('mb_strlen') ? mb_strlen($email) : strlen($email);
    if ($emailLen > 120) {
      $errorMessage = 'O email não pode ter mais de 120 caracteres.';
      return false;
    }

    return $email;
  }
}

if (!function_exists('validarTelefone')) {
  function validarTelefone($telefone, &$errorMessage)
  {
    $telefone = trim((string)$telefone);
    if ($telefone === '') {
      $errorMessage = 'O contacto telefónico é obrigatório.';
      return false;
    }

    $telefoneLen = function_exists('mb_strlen') ? mb_strlen($telefone) : strlen($telefone);
    if ($telefoneLen < 9 || $telefoneLen > 20) {
      $errorMessage = 'O contacto telefónico deve ter entre 9 e 20 caracteres.';
      return false;
    }

    if (!preg_match('/^[0-9+\s()\-]+$/', $telefone)) {
      $errorMessage = 'O contacto telefónico contém caracteres inválidos.';
      return false;
    }

    return $telefone;
  }
}

if (!function_exists('normalizarEstadoValidacao')) {
  function normalizarEstadoValidacao($estado)
  {
    $estado = strtolower(trim((string)$estado));
    if ($estado === 'pendente') {
      return 'Pendente';
    }

    if ($estado === 'aprovada' || $estado === 'aprovado') {
      return 'Aprovada';
    }

    if ($estado === 'rejeitada' || $estado === 'rejeitado') {
      return 'Rejeitada';
    }

    return false;
  }
}

if (!function_exists('mensagemErroMatricula')) {
  function mensagemErroMatricula(mysqli_sql_exception $exception)
  {
    $erro = $exception->getMessage();
    if (stripos($erro, 'Duplicate entry') !== false && stripos($erro, 'IdAluno') !== false) {
      return 'Já existe uma matrícula para esse ID de aluno.';
    }

    if (stripos($erro, "Data too long for column 'Foto'") !== false) {
      return 'A imagem enviada é demasiado grande. Tenta uma foto com tamanho menor.';
    }

    return 'Não foi possível guardar a matrícula. Tenta novamente.';
  }
}

if (!function_exists('getCertificadoLogoPath')) {
  function getCertificadoLogoPath()
  {
    $dirs = [
      ['abs' => __DIR__, 'rel' => ''],
      ['abs' => dirname(__DIR__), 'rel' => '../'],
    ];
    $extPermitidas = ['png', 'jpg', 'jpeg', 'webp', 'svg'];
    $preferidos = [];
    $outros = [];

    foreach ($dirs as $dirInfo) {
      if (!is_dir($dirInfo['abs'])) {
        continue;
      }

      $ficheiros = scandir($dirInfo['abs']);
      if (!is_array($ficheiros)) {
        continue;
      }

      foreach ($ficheiros as $ficheiro) {
        if ($ficheiro === '.' || $ficheiro === '..') {
          continue;
        }

        $absPath = $dirInfo['abs'] . '/' . $ficheiro;
        if (!is_file($absPath)) {
          continue;
        }

        $ext = strtolower((string)pathinfo($ficheiro, PATHINFO_EXTENSION));
        if (!in_array($ext, $extPermitidas, true)) {
          continue;
        }

        $urlPath = $dirInfo['rel'] . rawurlencode($ficheiro);
        $item = [
          'abs' => $absPath,
          'url' => $urlPath,
          'mtime' => (int)@filemtime($absPath),
          'name' => $ficheiro,
        ];

        if (preg_match('/logo|ipca|instituto|escola|certificado/i', $ficheiro)) {
          $preferidos[] = $item;
        } else {
          $outros[] = $item;
        }
      }
    }

    $ordenar = function (&$itens) {
      usort($itens, function ($a, $b) {
        return ($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0);
      });
    };

    $ordenar($preferidos);
    $ordenar($outros);

    $escolhido = null;

    if (!empty($preferidos)) {
      $escolhido = $preferidos[0];
    } elseif (!empty($outros)) {
      $escolhido = $outros[0];
    }

    if (is_array($escolhido) && !empty($escolhido['abs'])) {
      $conteudo = @file_get_contents((string)$escolhido['abs']);
      if ($conteudo !== false) {
        $mime = 'image/png';
        if (function_exists('finfo_open')) {
          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          if ($finfo) {
            $detected = finfo_file($finfo, (string)$escolhido['abs']);
            finfo_close($finfo);
            if (is_string($detected) && str_starts_with($detected, 'image/')) {
              $mime = $detected;
            }
          }
        }

        return 'data:' . $mime . ';base64,' . base64_encode($conteudo);
      }

      return (string)($escolhido['url'] ?? '');
    }

    return '';
  }
}
