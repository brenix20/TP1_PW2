<?php
define('APP_AREA', 'gestor');
require_once __DIR__ . '/common.php';

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

$matriculasSchemaReady = ensureMatriculasExtraFields($conn);

$dataMaximaNascimento = (new DateTimeImmutable('today'))->modify('-13 years')->format('Y-m-d');

$perfilAtual = strtolower(trim((string)($_SESSION['utilizador_perfil'] ?? '')));
$isGestor = $perfilAtual === 'gestor';
$isAluno = $perfilAtual === 'aluno';
$isFuncionario = in_array($perfilAtual, ['funcionario', 'funcionário'], true);
$podeEditarDisciplinasCursos = $isGestor;

$appArea = defined('APP_AREA') ? (string)APP_AREA : '';
if ($appArea === 'aluno' && !$isAluno) {
  header('Location: disciplinas.php?type=error&message=' . urlencode('Acesso restrito à área de aluno.'));
  exit;
}

if ($appArea === 'gestor' && !$isGestor) {
  header('Location: disciplinas.php?type=error&message=' . urlencode('Acesso restrito à área de gestor.'));
  exit;
}

$alunoIdUtilizador = $isAluno ? (int)($_SESSION['utilizador_id'] ?? 0) : 0;
$alunoIdSessao = 0;
if ($isAluno) {
  if ($alunoIdUtilizador > 0) {
    $stmtAlunoSessao = $conn->prepare("SELECT IdAluno FROM matriculas WHERE IdAluno = ? LIMIT 1");
    $stmtAlunoSessao->bind_param('i', $alunoIdUtilizador);
    $stmtAlunoSessao->execute();
    $resultAlunoSessao = $stmtAlunoSessao->get_result();
    $alunoSessao = $resultAlunoSessao ? $resultAlunoSessao->fetch_assoc() : null;
    $stmtAlunoSessao->close();
    if ($alunoSessao) {
      $alunoIdSessao = $alunoIdUtilizador;
    }
  }
}

$allowedTables = [
  'disciplina' => true,
  'cursos' => true,
];

if ($isGestor) {
  $allowedTables['matriculas'] = true;
  $allowedTables['plano_estudos'] = true;
}

if ($isAluno) {
  $allowedTables['matriculas'] = true;
}

$table = $_GET['table'] ?? 'disciplina';
if (!isset($allowedTables[$table])) {
  $table = 'disciplina';
}

$action = $_GET['action'] ?? 'list';
$allowedActions = $isGestor
  ? ['list', 'edit', 'foto', 'ver_disciplinas', 'ficha', 'ficha_print', 'certificado_print', 'print']
  : ($isAluno ? ['list', 'ficha', 'ficha_print', 'certificado_print', 'foto', 'ficha_edit', 'minhas_disciplinas'] : ['list']);

if (!in_array($action, $allowedActions, true)) {
  $action = 'list';
}

if (!$matriculasSchemaReady && $table === 'matriculas') {
  redirectWithMessage('disciplina', 'error', 'Não foi possível preparar a tabela de matrículas. Contacta o administrador da base de dados.');
}

if ($isAluno && $table === 'matriculas' && $action === 'list') {
  $action = 'ficha';
}

if ($action === 'edit' && !$podeEditarDisciplinasCursos) {
  redirectWithMessage($table, 'error', 'Apenas gestores podem editar disciplinas e cursos.');
}

if ($table === 'matriculas' && $action === 'foto') {
  $idAlunoFoto = (int)($_GET['id_aluno'] ?? 0);
  if ($isAluno) {
    if ($alunoIdSessao <= 0) {
      http_response_code(404);
      exit;
    }
    $idAlunoFoto = $alunoIdSessao;
  }

  $stmtFoto = $conn->prepare("SELECT Foto FROM matriculas WHERE IdAluno = ?");
  $stmtFoto->bind_param('i', $idAlunoFoto);
  $stmtFoto->execute();
  $resultFoto = $stmtFoto->get_result();
  $fotoRow = $resultFoto->fetch_assoc();
  $stmtFoto->close();

  if (!$fotoRow || $fotoRow['Foto'] === null) {
    http_response_code(404);
    exit;
  }

  $fotoBinaria = $fotoRow['Foto'];
  $mime = 'image/jpeg';
  if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $detectedMime = finfo_buffer($finfo, $fotoBinaria);
      finfo_close($finfo);
      if (is_string($detectedMime) && str_starts_with($detectedMime, 'image/')) {
        $mime = $detectedMime;
      }
    }
  }

  header('Content-Type: ' . $mime);
  header('Content-Length: ' . strlen($fotoBinaria));
  echo $fotoBinaria;
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postAction = $_POST['action'] ?? '';
  $postTable = $_POST['table'] ?? '';

  if (!isset($allowedTables[$postTable])) {
    redirectWithMessage('disciplina', 'error', 'Tabela inválida.');
  }

  // Permission checks: gestores só podem gerir disciplinas/cursos/planos; validação de fichas é feita via set_validation
  if (in_array($postTable, ['disciplina', 'cursos', 'plano_estudos'], true)) {
    if (!$podeEditarDisciplinasCursos) {
      redirectWithMessage($postTable, 'error', 'Apenas gestores podem editar disciplinas, cursos e planos de estudo.');
    }
  } elseif ($postTable === 'matriculas') {
    if ($isGestor) {
      // Gestores apenas podem validar/rejeitar fichas (set_validation)
      if ($postAction !== 'set_validation') {
        redirectWithMessage('matriculas', 'error', 'Gestores apenas podem validar/rejeitar fichas de aluno.');
      }
    } elseif ($isAluno) {
      // Alunos só podem criar/atualizar a sua própria ficha
      $alunoPodeAtualizarFicha = $postAction === 'update_self';
      $alunoPodeCriarFicha = $postAction === 'create_self';
      if (!$alunoPodeAtualizarFicha && !$alunoPodeCriarFicha) {
        redirectWithMessage('matriculas', 'error', 'Ação inválida para aluno.');
      }
    } else {
      redirectWithMessage('matriculas', 'error', 'Acesso inválido.');
    }
  } else {
    redirectWithMessage($postTable, 'error', 'Tabela inválida.');
  }

  if (!in_array($postAction, ['create', 'update', 'delete', 'update_self', 'create_self', 'set_validation'], true)) {
    redirectWithMessage($postTable, 'error', 'Ação inválida.');
  }

  if ($postTable === 'disciplina') {
    if ($postAction === 'create') {
      $disciplina = trim($_POST['Disciplina'] ?? '');
      $sigla = trim($_POST['Sigla'] ?? '');
      $stmt = $conn->prepare("INSERT INTO disciplina (Disciplina, Sigla) VALUES (?, ?)");
      $stmt->bind_param('ss', $disciplina, $sigla);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('disciplina', $ok ? 'success' : 'error', $ok ? 'Disciplina criada com sucesso.' : 'Erro ao criar disciplina.');
    }

    if ($postAction === 'update') {
      $id = (int)($_POST['IdDisciplina'] ?? 0);
      $disciplina = trim($_POST['Disciplina'] ?? '');
      $sigla = trim($_POST['Sigla'] ?? '');
      $stmt = $conn->prepare("UPDATE disciplina SET Disciplina = ?, Sigla = ? WHERE IdDisciplina = ?");
      $stmt->bind_param('ssi', $disciplina, $sigla, $id);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('disciplina', $ok ? 'success' : 'error', $ok ? 'Disciplina atualizada com sucesso.' : 'Erro ao atualizar disciplina.');
    }

    if ($postAction === 'delete') {
      $id = (int)($_POST['IdDisciplina'] ?? 0);
      $stmt = $conn->prepare("DELETE FROM disciplina WHERE IdDisciplina = ?");
      $stmt->bind_param('i', $id);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('disciplina', $ok ? 'success' : 'error', $ok ? 'Disciplina removida com sucesso.' : 'Erro ao remover disciplina.');
    }
  }

  if ($postTable === 'cursos') {
    if ($postAction === 'create') {
      $curso = trim($_POST['Curso'] ?? '');
      $sigla = trim($_POST['Sigla'] ?? '');
      $stmt = $conn->prepare("INSERT INTO cursos (Curso, Sigla) VALUES (?, ?)");
      $stmt->bind_param('ss', $curso, $sigla);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('cursos', $ok ? 'success' : 'error', $ok ? 'Curso criado com sucesso.' : 'Erro ao criar curso.');
    }

    if ($postAction === 'update') {
      $id = (int)($_POST['IdCurso'] ?? 0);
      $curso = trim($_POST['Curso'] ?? '');
      $sigla = trim($_POST['Sigla'] ?? '');
      $stmt = $conn->prepare("UPDATE cursos SET Curso = ?, Sigla = ? WHERE IdCurso = ?");
      $stmt->bind_param('ssi', $curso, $sigla, $id);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('cursos', $ok ? 'success' : 'error', $ok ? 'Curso atualizado com sucesso.' : 'Erro ao atualizar curso.');
    }

    if ($postAction === 'delete') {
      $id = (int)($_POST['IdCurso'] ?? 0);
      $stmt = $conn->prepare("DELETE FROM cursos WHERE IdCurso = ?");
      $stmt->bind_param('i', $id);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('cursos', $ok ? 'success' : 'error', $ok ? 'Curso removido com sucesso.' : 'Erro ao remover curso.');
    }
  }

  if ($postTable === 'matriculas') {
    if (!$matriculasSchemaReady) {
      redirectWithMessage('disciplina', 'error', 'Não foi possível preparar a tabela de matrículas.');
    }

    if ($postAction === 'create_self') {
      if (!$isAluno || $alunoIdUtilizador <= 0) {
        redirectWithMessage('matriculas', 'error', 'Não foi possível identificar o teu utilizador.');
      }

      $idAluno = $alunoIdUtilizador;
      $nome = trim($_POST['Nome'] ?? '');
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $dataNascimento = validarDataNascimento($_POST['DataNascimento'] ?? '', $validationError);
      $morada = trim($_POST['Morada'] ?? '');
      $email = validarEmail($_POST['Email'] ?? '', $validationError);
      $telefone = validarTelefone($_POST['Telefone'] ?? '', $validationError);

      if ($nome === '' || $idCurso <= 0 || $morada === '') {
        redirectWithMessage('matriculas', 'error', 'Nome, curso, data de nascimento, morada, email e contacto telefónico são obrigatórios.');
      }

      $nomeLen = function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome);
      if ($nomeLen > 25) {
        redirectWithMessage('matriculas', 'error', 'O nome não pode ter mais de 25 caracteres.');
      }

      if ($dataNascimento === false || $email === false || $telefone === false) {
        redirectWithMessage('matriculas', 'error', $validationError);
      }

      $moradaLen = function_exists('mb_strlen') ? mb_strlen($morada) : strlen($morada);
      if ($moradaLen > 255) {
        redirectWithMessage('matriculas', 'error', 'A morada não pode ter mais de 255 caracteres.');
      }

      $stmtIdAlunoExiste = $conn->prepare('SELECT IdAluno FROM matriculas WHERE IdAluno = ? LIMIT 1');
      $stmtIdAlunoExiste->bind_param('i', $idAluno);
      $stmtIdAlunoExiste->execute();
      $resultIdAlunoExiste = $stmtIdAlunoExiste->get_result();
      $idAlunoJaExiste = $resultIdAlunoExiste && $resultIdAlunoExiste->fetch_assoc();
      $stmtIdAlunoExiste->close();
      if ($idAlunoJaExiste) {
        redirectWithMessage('matriculas', 'error', 'Já existe uma ficha submetida para o teu utilizador.');
      }

      $uploadError = '';
      $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
      if ($fotoBlob === false) {
        redirectWithMessage('matriculas', 'error', $uploadError);
      }

      if ($fotoBlob !== null) {
        $estadoValidacao = 'Pendente';
        $stmt = $conn->prepare("INSERT INTO matriculas (IdAluno, Nome, IdCurso, DataNascimento, Morada, Email, Telefone, EstadoValidacao, Foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isissssss', $idAluno, $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao, $fotoBlob);
      } else {
        $estadoValidacao = 'Pendente';
        $stmt = $conn->prepare("INSERT INTO matriculas (IdAluno, Nome, IdCurso, DataNascimento, Morada, Email, Telefone, EstadoValidacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isisssss', $idAluno, $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao);
      }

      try {
        $ok = $stmt->execute();
      } catch (mysqli_sql_exception $exception) {
        $stmt->close();
        redirectWithMessage('matriculas', 'error', mensagemErroMatricula($exception));
      }

      $stmt->close();
      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Ficha submetida com sucesso.' : 'Erro ao submeter ficha.');
    }

    if ($postAction === 'update_self') {
      if (!$isAluno || $alunoIdSessao <= 0) {
        redirectWithMessage('matriculas', 'error', 'Não foi possível identificar a tua matrícula.');
      }

      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $morada = trim($_POST['Morada'] ?? '');
      $email = validarEmail($_POST['Email'] ?? '', $validationError);
      $telefone = validarTelefone($_POST['Telefone'] ?? '', $validationError);
      if ($idCurso <= 0 || $morada === '') {
        redirectWithMessage('matriculas', 'error', 'Curso pretendido e morada são obrigatórios.');
      }

      if ($email === false || $telefone === false) {
        redirectWithMessage('matriculas', 'error', $validationError);
      }

      $moradaLen = function_exists('mb_strlen') ? mb_strlen($morada) : strlen($morada);
      if ($moradaLen > 255) {
        redirectWithMessage('matriculas', 'error', 'A morada não pode ter mais de 255 caracteres.');
      }

      $uploadError = '';
      $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
      if ($fotoBlob === false) {
        redirectWithMessage('matriculas', 'error', $uploadError);
      }

      if ($fotoBlob !== null) {
        $estadoValidacao = 'Pendente';
        $stmt = $conn->prepare("UPDATE matriculas SET IdCurso = ?, Morada = ?, Email = ?, Telefone = ?, EstadoValidacao = ?, ObservacoesValidacao = NULL, Foto = ? WHERE IdAluno = ?");
        $stmt->bind_param('isssssi', $idCurso, $morada, $email, $telefone, $estadoValidacao, $fotoBlob, $alunoIdSessao);
      } else {
        $estadoValidacao = 'Pendente';
        $stmt = $conn->prepare("UPDATE matriculas SET IdCurso = ?, Morada = ?, Email = ?, Telefone = ?, EstadoValidacao = ?, ObservacoesValidacao = NULL WHERE IdAluno = ?");
        $stmt->bind_param('issssi', $idCurso, $morada, $email, $telefone, $estadoValidacao, $alunoIdSessao);
      }

      try {
        $ok = $stmt->execute();
      } catch (mysqli_sql_exception $exception) {
        $stmt->close();
        redirectWithMessage('matriculas', 'error', mensagemErroMatricula($exception));
      }

      $stmt->close();
      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Ficha atualizada com sucesso.' : 'Erro ao atualizar ficha.');
    }

    if ($postAction === 'create') {
      $idAluno = (int)($_POST['IdAluno'] ?? 0);
      $nome = trim($_POST['Nome'] ?? '');
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $dataNascimento = validarDataNascimento($_POST['DataNascimento'] ?? '', $validationError);
      $morada = trim($_POST['Morada'] ?? '');
      $email = validarEmail($_POST['Email'] ?? '', $validationError);
      $telefone = validarTelefone($_POST['Telefone'] ?? '', $validationError);
      $estadoValidacao = normalizarEstadoValidacao($_POST['EstadoValidacao'] ?? 'Pendente');

      if ($idAluno <= 0 || $nome === '' || $idCurso <= 0 || $morada === '') {
        redirectWithMessage('matriculas', 'error', 'ID de aluno, nome, curso, data de nascimento, morada, email e contacto telefónico são obrigatórios.');
      }

      $nomeLen = function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome);
      if ($nomeLen > 25) {
        redirectWithMessage('matriculas', 'error', 'O nome não pode ter mais de 25 caracteres.');
      }

      $stmtIdAlunoExiste = $conn->prepare('SELECT IdAluno FROM matriculas WHERE IdAluno = ? LIMIT 1');
      $stmtIdAlunoExiste->bind_param('i', $idAluno);
      $stmtIdAlunoExiste->execute();
      $resultIdAlunoExiste = $stmtIdAlunoExiste->get_result();
      $idAlunoJaExiste = $resultIdAlunoExiste && $resultIdAlunoExiste->fetch_assoc();
      $stmtIdAlunoExiste->close();
      if ($idAlunoJaExiste) {
        redirectWithMessage('matriculas', 'error', 'Já existe uma matrícula para esse ID de aluno.');
      }

      if ($estadoValidacao === false) {
        redirectWithMessage('matriculas', 'error', 'Estado de validação inválido.');
      }

      if ($dataNascimento === false || $email === false || $telefone === false) {
        redirectWithMessage('matriculas', 'error', $validationError);
      }

      $moradaLen = function_exists('mb_strlen') ? mb_strlen($morada) : strlen($morada);
      if ($moradaLen > 255) {
        redirectWithMessage('matriculas', 'error', 'A morada não pode ter mais de 255 caracteres.');
      }

      $uploadError = '';
      $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
      if ($fotoBlob === false) {
        redirectWithMessage('matriculas', 'error', $uploadError);
      }

      if ($fotoBlob !== null) {
        $stmt = $conn->prepare("INSERT INTO matriculas (IdAluno, Nome, IdCurso, DataNascimento, Morada, Email, Telefone, EstadoValidacao, Foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isissssss', $idAluno, $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao, $fotoBlob);
      } else {
        $stmt = $conn->prepare("INSERT INTO matriculas (IdAluno, Nome, IdCurso, DataNascimento, Morada, Email, Telefone, EstadoValidacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isisssss', $idAluno, $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao);
      }
      try {
        $ok = $stmt->execute();
      } catch (mysqli_sql_exception $exception) {
        $stmt->close();
        redirectWithMessage('matriculas', 'error', mensagemErroMatricula($exception));
      }

      $stmt->close();
      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Matrícula criada com sucesso.' : 'Erro ao criar matrícula.');
    }

    if ($postAction === 'update') {
      $idAluno = (int)($_POST['IdAluno'] ?? 0);
      $nome = trim($_POST['Nome'] ?? '');
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $dataNascimento = validarDataNascimento($_POST['DataNascimento'] ?? '', $validationError);
      $morada = trim($_POST['Morada'] ?? '');
      $email = validarEmail($_POST['Email'] ?? '', $validationError);
      $telefone = validarTelefone($_POST['Telefone'] ?? '', $validationError);
      $estadoValidacao = normalizarEstadoValidacao($_POST['EstadoValidacao'] ?? 'Pendente');

      if ($idAluno <= 0 || $nome === '' || $idCurso <= 0 || $morada === '') {
        redirectWithMessage('matriculas', 'error', 'ID de aluno, nome, curso, data de nascimento, morada, email e contacto telefónico são obrigatórios.');
      }

      $nomeLen = function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome);
      if ($nomeLen > 25) {
        redirectWithMessage('matriculas', 'error', 'O nome não pode ter mais de 25 caracteres.');
      }

      if ($estadoValidacao === false) {
        redirectWithMessage('matriculas', 'error', 'Estado de validação inválido.');
      }

      if ($dataNascimento === false || $email === false || $telefone === false) {
        redirectWithMessage('matriculas', 'error', $validationError);
      }

      $moradaLen = function_exists('mb_strlen') ? mb_strlen($morada) : strlen($morada);
      if ($moradaLen > 255) {
        redirectWithMessage('matriculas', 'error', 'A morada não pode ter mais de 255 caracteres.');
      }

      $uploadError = '';
      $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
      if ($fotoBlob === false) {
        redirectWithMessage('matriculas', 'error', $uploadError);
      }

      if ($fotoBlob !== null) {
        $stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, IdCurso = ?, DataNascimento = ?, Morada = ?, Email = ?, Telefone = ?, EstadoValidacao = ?, Foto = ? WHERE IdAluno = ?");
        $stmt->bind_param('sissssssi', $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao, $fotoBlob, $idAluno);
      } else {
        $stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, IdCurso = ?, DataNascimento = ?, Morada = ?, Email = ?, Telefone = ?, EstadoValidacao = ? WHERE IdAluno = ?");
        $stmt->bind_param('sisssssi', $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao, $idAluno);
      }

      try {
        $ok = $stmt->execute();
      } catch (mysqli_sql_exception $exception) {
        $stmt->close();
        redirectWithMessage('matriculas', 'error', mensagemErroMatricula($exception));
      }

      $stmt->close();
      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Matrícula atualizada com sucesso.' : 'Erro ao atualizar matrícula.');
    }

    if ($postAction === 'delete') {
      $idAluno = (int)($_POST['IdAluno'] ?? 0);
      $stmt = $conn->prepare("DELETE FROM matriculas WHERE IdAluno = ?");
      $stmt->bind_param('i', $idAluno);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Matrícula removida com sucesso.' : 'Erro ao remover matrícula.');
    }

    if ($postAction === 'set_validation') {
      $idAluno = (int)($_POST['IdAluno'] ?? 0);
      $estadoValidacao = normalizarEstadoValidacao($_POST['EstadoValidacao'] ?? '');
      $observacoes = trim((string)($_POST['ObservacoesValidacao'] ?? ''));

      if ($idAluno <= 0 || $estadoValidacao === false) {
        redirectWithMessage('matriculas', 'error', 'Estado de validação inválido.');
      }

      if ($observacoes === '') {
        $stmt = $conn->prepare("UPDATE matriculas SET EstadoValidacao = ?, ObservacoesValidacao = NULL WHERE IdAluno = ?");
        $stmt->bind_param('si', $estadoValidacao, $idAluno);
      } else {
        $stmt = $conn->prepare("UPDATE matriculas SET EstadoValidacao = ?, ObservacoesValidacao = ? WHERE IdAluno = ?");
        $stmt->bind_param('ssi', $estadoValidacao, $observacoes, $idAluno);
      }
      $ok = $stmt->execute();
      $stmt->close();

      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Estado de validação atualizado.' : 'Erro ao atualizar estado de validação.');
    }
  }

  if ($postTable === 'plano_estudos') {
    if ($postAction === 'create') {
      $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $stmt = $conn->prepare("INSERT INTO plano_estudos (IdDisciplina, IdCurso) VALUES (?, ?)");
      $stmt->bind_param('ii', $idDisciplina, $idCurso);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('plano_estudos', $ok ? 'success' : 'error', $ok ? 'Ligação criada com sucesso.' : 'Erro ao criar ligação (pode já existir).');
    }

    if ($postAction === 'update') {
      $oldIdDisciplina = (int)($_POST['old_IdDisciplina'] ?? 0);
      $oldIdCurso = (int)($_POST['old_IdCurso'] ?? 0);
      $newIdDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
      $newIdCurso = (int)($_POST['IdCurso'] ?? 0);
      $stmt = $conn->prepare("UPDATE plano_estudos SET IdDisciplina = ?, IdCurso = ? WHERE IdDisciplina = ? AND IdCurso = ?");
      $stmt->bind_param('iiii', $newIdDisciplina, $newIdCurso, $oldIdDisciplina, $oldIdCurso);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('plano_estudos', $ok ? 'success' : 'error', $ok ? 'Ligação atualizada com sucesso.' : 'Erro ao atualizar ligação.');
    }

    if ($postAction === 'delete') {
      $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $stmt = $conn->prepare("DELETE FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ?");
      $stmt->bind_param('ii', $idDisciplina, $idCurso);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('plano_estudos', $ok ? 'success' : 'error', $ok ? 'Ligação removida com sucesso.' : 'Erro ao remover ligação.');
    }
  }
}

$message = $_GET['message'] ?? '';
$type = $_GET['type'] ?? '';
$matriculasFiltroTexto = trim((string)($_GET['q'] ?? ''));
$matriculasFiltroCurso = (int)($_GET['curso'] ?? 0);
$stylesVersion = (string)(@filemtime(__DIR__ . '/styles.css') ?: time());
$stylesHref = 'styles.css?v=' . rawurlencode($stylesVersion);

$editData = null;
$alunoDisciplinas = [];
$alunoNome = '';
$alunoIdSelecionado = 0;
$fichaAluno = null;
$fichaDisciplinas = [];
if ($action === 'edit') {
  if ($table === 'disciplina') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM disciplina WHERE IdDisciplina = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $editData = $result->fetch_assoc();
    $stmt->close();
  }

  if ($table === 'cursos') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM cursos WHERE IdCurso = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $editData = $result->fetch_assoc();
    $stmt->close();
  }

  if ($table === 'matriculas') {
    $idAluno = (int)($_GET['id_aluno'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM matriculas WHERE IdAluno = ?");
    $stmt->bind_param('i', $idAluno);
    $stmt->execute();
    $result = $stmt->get_result();
    $editData = $result->fetch_assoc();
    $stmt->close();
  }

  if ($table === 'plano_estudos') {
    $idDisciplina = (int)($_GET['id_disciplina'] ?? 0);
    $idCurso = (int)($_GET['id_curso'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ?");
    $stmt->bind_param('ii', $idDisciplina, $idCurso);
    $stmt->execute();
    $result = $stmt->get_result();
    $editData = $result->fetch_assoc();
    $stmt->close();
  }
}

if ($table === 'matriculas' && $action === 'ver_disciplinas') {
  $alunoIdSelecionado = (int)($_GET['id_aluno'] ?? 0);
  $stmt = $conn->prepare(
    "SELECT disciplina.Disciplina, matriculas.Nome
     FROM disciplina, matriculas, cursos, plano_estudos
     WHERE matriculas.IdCurso = cursos.IdCurso
       AND plano_estudos.IdDisciplina = disciplina.IdDisciplina
       AND plano_estudos.IdCurso = cursos.IdCurso
       AND matriculas.IdAluno = ?"
  );
  $stmt->bind_param('i', $alunoIdSelecionado);
  $stmt->execute();
  $result = $stmt->get_result();
  while ($row = $result->fetch_assoc()) {
    $alunoDisciplinas[] = $row;
  }
  $stmt->close();

  if (!empty($alunoDisciplinas)) {
    $alunoNome = $alunoDisciplinas[0]['Nome'];
  } else {
    $stmtNome = $conn->prepare("SELECT Nome FROM matriculas WHERE IdAluno = ?");
    $stmtNome->bind_param('i', $alunoIdSelecionado);
    $stmtNome->execute();
    $resultNome = $stmtNome->get_result();
    $aluno = $resultNome->fetch_assoc();
    $alunoNome = $aluno['Nome'] ?? '';
    $stmtNome->close();
  }
}

if ($table === 'matriculas' && in_array($action, ['ficha', 'ficha_print', 'certificado_print', 'ficha_edit', 'minhas_disciplinas'], true)) {
  if ($isAluno) {
    $alunoIdSelecionado = $alunoIdSessao;
  } else {
    $alunoIdSelecionado = (int)($_GET['id_aluno'] ?? 0);
  }

  $stmtFicha = $conn->prepare(
      "SELECT m.IdAluno, m.Nome, m.IdCurso, m.DataNascimento, m.Morada, m.Email, m.Telefone, m.EstadoValidacao, c.Curso, c.Sigla AS SiglaCurso
     FROM matriculas m
     JOIN cursos c ON c.IdCurso = m.IdCurso
     WHERE m.IdAluno = ?"
  );
  $stmtFicha->bind_param('i', $alunoIdSelecionado);
  $stmtFicha->execute();
  $resultFicha = $stmtFicha->get_result();
  $fichaAluno = $resultFicha->fetch_assoc();
  $stmtFicha->close();

  if ($fichaAluno) {
    $stmtFichaDisciplinas = $conn->prepare(
      "SELECT d.Disciplina, d.Sigla
       FROM plano_estudos pe
       JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina
       WHERE pe.IdCurso = ?
       ORDER BY d.Disciplina"
    );
    $stmtFichaDisciplinas->bind_param('i', $fichaAluno['IdCurso']);
    $stmtFichaDisciplinas->execute();
    $resultFichaDisciplinas = $stmtFichaDisciplinas->get_result();
    while ($row = $resultFichaDisciplinas->fetch_assoc()) {
      $fichaDisciplinas[] = $row;
    }
    $stmtFichaDisciplinas->close();
  }
}

$disciplinasLookup = fetchLookup($conn, 'disciplina', 'IdDisciplina', 'Disciplina');
$cursosLookup = fetchLookup($conn, 'cursos', 'IdCurso', 'Curso');

// printable view for plano_estudos (user can Print -> Save as PDF)
if ($table === 'plano_estudos' && $action === 'print') {
  $rows = $conn->query(
    "SELECT pe.IdDisciplina, pe.IdCurso, d.Disciplina, c.Curso
     FROM plano_estudos pe
     JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina
     JOIN cursos c ON c.IdCurso = pe.IdCurso
     ORDER BY c.Curso, d.Disciplina"
  );

  $logoDoc = getCertificadoLogoPath();
  $dataAtual = (new DateTimeImmutable('today'))->format('d/m/Y');

  echo "<!doctype html><html lang=\"pt\"><head><meta charset=\"utf-8\"><title>Plano de Estudos</title>";
  echo "<link rel=\"stylesheet\" href=\"" . e($stylesHref) . "\">";
  echo "</head><body class=\"document-page\" onload=\"window.print()\">";
  echo '<div class="document-shell">';
  echo '<div class="document-header">';
  if ($logoDoc !== '') {
    echo '<img class="document-logo" src="' . e($logoDoc) . '" alt="Logo institucional">';
  }
  echo '<h1 class="document-title">Plano de Estudos</h1>';
  echo '<p class="document-subtitle">Documento emitido em ' . e($dataAtual) . '</p>';
  echo '</div>';
  echo "<table class=\"document-table\"><thead><tr><th>Curso</th><th>Disciplina</th></tr></thead><tbody>";
  while ($row = $rows->fetch_assoc()) {
    echo '<tr><td>' . htmlspecialchars($row['Curso'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($row['Disciplina'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
  }
  echo '</tbody></table>';
  echo '</div></body></html>';
  $conn->close();
  exit;
}

if ($table === 'matriculas' && $action === 'ficha_print') {
  if (!$fichaAluno) {
    http_response_code(404);
    $conn->close();
    exit;
  }

  $logoDoc = getCertificadoLogoPath();
  $dataAtual = (new DateTimeImmutable('today'))->format('d/m/Y');

  echo "<!doctype html><html lang=\"pt\"><head><meta charset=\"utf-8\"><title>Ficha do Aluno</title>";
  echo "<link rel=\"stylesheet\" href=\"" . e($stylesHref) . "\">";
  echo "</head><body class=\"document-page\" onload=\"window.print()\">";
  echo '<div class="document-shell">';
  echo '<div class="document-header">';
  if ($logoDoc !== '') {
    echo '<img class="document-logo" src="' . e($logoDoc) . '" alt="Logo institucional">';
  }
  echo '<h1 class="document-title">Ficha do Aluno</h1>';
  echo '<p class="document-subtitle">Documento emitido em ' . e($dataAtual) . '</p>';
  echo '</div>';

  echo '<div class="document-grid">';
  echo '<p><strong>Nº Aluno:</strong> ' . e($fichaAluno['IdAluno']) . '</p>';
  echo '<p><strong>Nome:</strong> ' . e($fichaAluno['Nome']) . '</p>';
  echo '<p><strong>Data de nascimento:</strong> ' . e(formatDatePt($fichaAluno['DataNascimento'] ?? '')) . '</p>';
  echo '<p><strong>Morada:</strong> ' . e($fichaAluno['Morada'] ?? '') . '</p>';
  echo '<p><strong>Email:</strong> ' . e($fichaAluno['Email'] ?? '') . '</p>';
  echo '<p><strong>Contacto:</strong> ' . e($fichaAluno['Telefone'] ?? '') . '</p>';
  echo '<p><strong>Curso pretendido:</strong> ' . e($fichaAluno['Curso']) . ' (' . e($fichaAluno['SiglaCurso']) . ')</p>';
  echo '<p><strong>Estado de validação:</strong> ' . e($fichaAluno['EstadoValidacao'] ?? 'Pendente') . '</p>';
  echo '</div>';

  echo '<h3 class="document-section-title">Disciplinas do Curso</h3>';

  if (!empty($fichaDisciplinas)) {
    echo "<table class=\"document-table\"><thead><tr><th>Disciplina</th><th>Sigla</th></tr></thead><tbody>";
    foreach ($fichaDisciplinas as $disciplinaFicha) {
      echo '<tr><td>' . e($disciplinaFicha['Disciplina']) . '</td><td>' . e($disciplinaFicha['Sigla']) . '</td></tr>';
    }
    echo "</tbody></table>";
  } else {
    echo '<p class="document-empty">Não existem disciplinas associadas ao curso deste aluno.</p>';
  }

  echo '</div></body></html>';
  $conn->close();
  exit;
}

if ($table === 'matriculas' && $action === 'certificado_print') {
  if (!$fichaAluno) {
    http_response_code(404);
    $conn->close();
    exit;
  }

  $logoCertificado = getCertificadoLogoPath();
  $dataAtual = (new DateTimeImmutable('today'))->format('d/m/Y');

  echo "<!doctype html><html lang=\"pt\"><head><meta charset=\"utf-8\"><title>Certificado de Matrícula</title>";
  echo "<link rel=\"stylesheet\" href=\"" . e($stylesHref) . "\">";
  echo "</head><body class=\"document-page\" onload=\"window.print()\">";
  echo '<div class="document-shell certificate-shell">';
  echo '<div class="document-header">';
  if ($logoCertificado !== '') {
    echo '<img class="document-logo" src="' . e($logoCertificado) . '" alt="Logo institucional">';
  }
  echo '<h1 class="document-title">Certificado de Matrícula</h1>';
  echo '<p class="document-subtitle">Documento emitido em ' . e($dataAtual) . '</p>';
  echo '</div>';

  echo '<p class="document-text">Certifica-se, para os devidos efeitos, que o(a) aluno(a) abaixo identificado(a) se encontra matriculado(a) nesta instituição.</p>';

  echo '<div class="document-grid">';
  echo '<p><strong>Nº Aluno:</strong> ' . e($fichaAluno['IdAluno']) . '</p>';
  echo '<p><strong>Nome:</strong> ' . e($fichaAluno['Nome']) . '</p>';
  echo '<p><strong>Data de nascimento:</strong> ' . e(formatDatePt($fichaAluno['DataNascimento'] ?? '')) . '</p>';
  echo '<p><strong>Morada:</strong> ' . e($fichaAluno['Morada'] ?? '') . '</p>';
  echo '<p><strong>Email:</strong> ' . e($fichaAluno['Email'] ?? '') . '</p>';
  echo '<p><strong>Contacto:</strong> ' . e($fichaAluno['Telefone'] ?? '') . '</p>';
  echo '<p><strong>Curso pretendido:</strong> ' . e($fichaAluno['Curso']) . ' (' . e($fichaAluno['SiglaCurso']) . ')</p>';
  echo '<p><strong>Estado de validação:</strong> ' . e($fichaAluno['EstadoValidacao'] ?? 'Pendente') . '</p>';
  echo '<p><strong>Data de emissão:</strong> ' . e($dataAtual) . '</p>';
  echo '</div>';

  echo '<div class="document-signature">';
  echo '<p>_______________________________</p>';
  echo '<p>Serviços Académicos</p>';
  echo '</div>';
  echo '</div>';
  echo "</body></html>";
  $conn->close();
  exit;
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CRUD - IPCAVNF</title>
  <link rel="stylesheet" href="<?php echo e($stylesHref); ?>">
</head>
<body class="app-page">
  <div class="app-shell">
  <div class="top-bar">
    <div>
      <h1>Gestão Académica</h1>
      <p class="subtitle">
        <?php echo $isGestor
          ? 'Gestão de Disciplinas, Cursos, Matrículas e Plano de Estudos'
          : ($isAluno
            ? 'Consulta de Disciplinas, Cursos e da tua Ficha de Aluno'
            : ($isFuncionario
              ? 'Gestão de pedidos de matrícula, pautas e notas'
              : 'Consulta de Disciplinas e Cursos disponíveis')); ?>
      </p>
    </div>
    <p class="user-meta">
      Utilizador: <strong><?php echo e($_SESSION['utilizador_nome'] ?? ''); ?></strong>
      <?php if (!empty($_SESSION['utilizador_perfil'])): ?>
        (<?php echo e($_SESSION['utilizador_perfil']); ?>)
      <?php endif; ?>
      | <a class="logout-link" href="logout.php">Terminar sessão</a>
    </p>
  </div>

  <nav>
    <a class="<?php echo $table === 'disciplina' ? 'active' : ''; ?>" href="?table=disciplina">Disciplinas</a>
    <a class="<?php echo $table === 'cursos' ? 'active' : ''; ?>" href="?table=cursos">Cursos</a>
    <?php if ($isGestor): ?>
      <a class="<?php echo $table === 'matriculas' ? 'active' : ''; ?>" href="?table=matriculas">Matrículas</a>
      <a class="<?php echo $table === 'plano_estudos' ? 'active' : ''; ?>" href="?table=plano_estudos">Planos de Estudo</a>
    <?php elseif ($isAluno): ?>
      <a class="<?php echo ($table === 'matriculas' && in_array($action, ['ficha', 'ficha_print', 'ficha_edit'], true)) ? 'active' : ''; ?>" href="?table=matriculas&action=ficha">Minha Ficha</a>
      <a class="<?php echo ($table === 'matriculas' && $action === 'minhas_disciplinas') ? 'active' : ''; ?>" href="?table=matriculas&action=minhas_disciplinas">Minhas Disciplinas</a>
    <?php endif; ?>
    <?php if ($isGestor || $isFuncionario): ?>
      <a href="funcionario.php">Área de Funcionário</a>
    <?php endif; ?>
  </nav>

  <?php if ($message !== ''): ?>
    <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
  <?php endif; ?>

  <?php if ($table === 'disciplina'): ?>
    <h2>Disciplinas</h2>
    <?php $rows = $conn->query("SELECT * FROM disciplina ORDER BY IdDisciplina DESC"); ?>
    <table>
      <tr>
        <th>Disciplina</th>
        <th>Sigla</th>
        <?php if ($podeEditarDisciplinasCursos): ?>
          <th>Ações</th>
        <?php endif; ?>
      </tr>
      <?php while ($row = $rows->fetch_assoc()): ?>
        <tr>
          <td><?php echo e($row['Disciplina']); ?></td>
          <td><?php echo e($row['Sigla']); ?></td>
          <?php if ($podeEditarDisciplinasCursos): ?>
            <td class="actions">
              <a href="?table=disciplina&action=edit&id=<?php echo e($row['IdDisciplina']); ?>">Editar</a>
              <form class="inline" method="post" onsubmit="return confirm('Remover disciplina?');">
                <input type="hidden" name="table" value="disciplina">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="IdDisciplina" value="<?php echo e($row['IdDisciplina']); ?>">
                <button type="submit">Excluir</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endwhile; ?>
    </table>

    <?php if ($podeEditarDisciplinasCursos): ?>
      <div class="form-box">
        <h3><?php echo $editData ? 'Editar Disciplina' : 'Nova Disciplina'; ?></h3>
        <form method="post">
          <input type="hidden" name="table" value="disciplina">
          <input type="hidden" name="action" value="<?php echo $editData ? 'update' : 'create'; ?>">
          <?php if ($editData): ?>
            <input type="hidden" name="IdDisciplina" value="<?php echo e($editData['IdDisciplina']); ?>">
          <?php endif; ?>

          <label>Disciplina</label><br>
          <input type="text" name="Disciplina" maxlength="30" required value="<?php echo e($editData['Disciplina'] ?? ''); ?>"><br>

          <label>Sigla</label><br>
          <input type="text" name="Sigla" maxlength="10" required value="<?php echo e($editData['Sigla'] ?? ''); ?>"><br>

          <button type="submit"><?php echo $editData ? 'Atualizar' : 'Criar'; ?></button>
          <?php if ($editData): ?>
            <a class="cancel-link" href="?table=disciplina">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($table === 'cursos'): ?>
    <h2>Cursos</h2>
    <?php $rows = $conn->query("SELECT * FROM cursos ORDER BY IdCurso DESC"); ?>
    <table>
      <tr>
        <th>Curso</th>
        <th>Sigla</th>
        <?php if ($podeEditarDisciplinasCursos): ?>
          <th>Ações</th>
        <?php endif; ?>
      </tr>
      <?php while ($row = $rows->fetch_assoc()): ?>
        <tr>
          <td><?php echo e($row['Curso']); ?></td>
          <td><?php echo e($row['Sigla']); ?></td>
          <?php if ($podeEditarDisciplinasCursos): ?>
            <td class="actions">
              <a href="?table=cursos&action=edit&id=<?php echo e($row['IdCurso']); ?>">Editar</a>
              <form class="inline" method="post" onsubmit="return confirm('Remover curso?');">
                <input type="hidden" name="table" value="cursos">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="IdCurso" value="<?php echo e($row['IdCurso']); ?>">
                <button type="submit">Excluir</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endwhile; ?>
    </table>

    <?php if ($podeEditarDisciplinasCursos): ?>
      <div class="form-box">
        <h3><?php echo $editData ? 'Editar Curso' : 'Novo Curso'; ?></h3>
        <form method="post">
          <input type="hidden" name="table" value="cursos">
          <input type="hidden" name="action" value="<?php echo $editData ? 'update' : 'create'; ?>">
          <?php if ($editData): ?>
            <input type="hidden" name="IdCurso" value="<?php echo e($editData['IdCurso']); ?>">
          <?php endif; ?>

          <label>Curso</label><br>
          <input type="text" name="Curso" maxlength="30" required value="<?php echo e($editData['Curso'] ?? ''); ?>"><br>

          <label>Sigla</label><br>
          <input type="text" name="Sigla" maxlength="10" required value="<?php echo e($editData['Sigla'] ?? ''); ?>"><br>

          <button type="submit"><?php echo $editData ? 'Atualizar' : 'Criar'; ?></button>
          <?php if ($editData): ?>
            <a class="cancel-link" href="?table=cursos">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($table === 'matriculas'): ?>
    <?php if ($action === 'ver_disciplinas'): ?>
      <div class="form-box">
        <h3>Disciplinas do aluno <?php echo e($alunoNome !== '' ? $alunoNome : ('#' . $alunoIdSelecionado)); ?></h3>
        <?php if (!empty($alunoDisciplinas)): ?>
          <ul>
            <?php foreach ($alunoDisciplinas as $disciplinaAluno): ?>
              <li><?php echo e($disciplinaAluno['Disciplina']); ?></li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p>Este aluno não tem disciplinas associadas no plano de estudos do curso.</p>
        <?php endif; ?>
      </div>
    <?php elseif ($action === 'ficha'): ?>
      <h2>Ficha do Aluno</h2>
      <?php if ($fichaAluno): ?>
        <div class="form-box">
          <h3><?php echo e($fichaAluno['Nome']); ?></h3>
          <p class="ficha-linha"><strong>Nº Aluno:</strong> <?php echo e($fichaAluno['IdAluno']); ?></p>
          <p class="ficha-linha"><strong>Data de nascimento:</strong> <?php echo e(formatDatePt($fichaAluno['DataNascimento'] ?? '')); ?></p>
          <p class="ficha-linha"><strong>Morada:</strong> <?php echo e($fichaAluno['Morada'] ?? ''); ?></p>
          <p class="ficha-linha"><strong>Email:</strong> <?php echo e($fichaAluno['Email'] ?? ''); ?></p>
          <p class="ficha-linha"><strong>Contacto:</strong> <?php echo e($fichaAluno['Telefone'] ?? ''); ?></p>
          <p class="ficha-linha"><strong>Curso pretendido:</strong> <?php echo e($fichaAluno['Curso']); ?> (<?php echo e($fichaAluno['SiglaCurso']); ?>)</p>
          <p class="ficha-linha"><strong>Estado de validação:</strong> <?php echo e($fichaAluno['EstadoValidacao'] ?? 'Pendente'); ?></p>
          <p class="ficha-linha"><img class="aluno-foto" src="?table=matriculas&action=foto&id_aluno=<?php echo e($fichaAluno['IdAluno']); ?>" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';"><span class="sem-foto">Sem foto</span></p>

          <h3>Disciplinas do Curso</h3>
          <?php if (!empty($fichaDisciplinas)): ?>
            <table>
              <tr>
                <th>Disciplina</th>
                <th>Sigla</th>
              </tr>
              <?php foreach ($fichaDisciplinas as $disciplinaFicha): ?>
                <tr>
                  <td><?php echo e($disciplinaFicha['Disciplina']); ?></td>
                  <td><?php echo e($disciplinaFicha['Sigla']); ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php else: ?>
            <p>Não existem disciplinas associadas ao curso deste aluno.</p>
          <?php endif; ?>

          <p>
            <a class="section-link" href="?table=matriculas&action=ficha_print&id_aluno=<?php echo e($fichaAluno['IdAluno']); ?>" target="_blank" rel="noopener">Imprimir ficha</a>
            | <a class="section-link" href="?table=matriculas&action=certificado_print&id_aluno=<?php echo e($fichaAluno['IdAluno']); ?>" target="_blank" rel="noopener">Imprimir certificado</a>
            <?php if ($isAluno): ?>
              | <a class="section-link" href="?table=matriculas&action=ficha_edit">Atualizar contactos/foto</a>
              | <a class="section-link" href="?table=matriculas&action=minhas_disciplinas">Ver minhas disciplinas</a>
            <?php endif; ?>
          </p>
        </div>
      <?php elseif ($isAluno): ?>
        <div class="form-box">
          <h3>Submeter ficha de aluno</h3>
          <p>Preenche os teus dados pessoais e de contacto para criares a tua ficha.</p>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="table" value="matriculas">
            <input type="hidden" name="action" value="create_self">

            <label>Nome completo</label><br>
            <input type="text" name="Nome" maxlength="25" required><br>

            <label>Curso pretendido</label><br>
            <select name="IdCurso" required>
              <option value="">Selecione</option>
              <?php foreach ($cursosLookup as $item): ?>
                <option value="<?php echo e($item['IdCurso']); ?>"><?php echo e($item['Curso']); ?></option>
              <?php endforeach; ?>
            </select><br>

            <label>Data de nascimento</label><br>
            <input type="date" name="DataNascimento" required max="<?php echo e($dataMaximaNascimento); ?>"><br>

            <label>Morada</label><br>
            <input type="text" name="Morada" maxlength="255" required><br>

            <label>Email</label><br>
            <input type="email" name="Email" maxlength="120" required><br>

            <label>Contacto telefónico</label><br>
            <input type="text" name="Telefone" maxlength="20" required placeholder="Ex.: 912345678"><br>

            <label>Foto (opcional)</label><br>
            <input type="file" name="Foto" accept="image/*"><br>

            <button type="submit">Submeter ficha para validação</button>
          </form>
        </div>
      <?php else: ?>
        <div class="form-box">
          <p>Aluno não encontrado.</p>
        </div>
      <?php endif; ?>
    <?php elseif ($action === 'minhas_disciplinas'): ?>
      <h2>Minhas Disciplinas</h2>
      <?php if ($fichaAluno): ?>
        <div class="form-box">
          <h3><?php echo e($fichaAluno['Nome']); ?> - <?php echo e($fichaAluno['Curso']); ?></h3>
          <?php if (!empty($fichaDisciplinas)): ?>
            <table>
              <tr>
                <th>Disciplina</th>
                <th>Sigla</th>
              </tr>
              <?php foreach ($fichaDisciplinas as $disciplinaFicha): ?>
                <tr>
                  <td><?php echo e($disciplinaFicha['Disciplina']); ?></td>
                  <td><?php echo e($disciplinaFicha['Sigla']); ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          <?php else: ?>
            <p>Ainda não existem disciplinas associadas ao teu curso.</p>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="form-box">
          <p>Não foi encontrada uma matrícula associada ao teu utilizador.</p>
        </div>
      <?php endif; ?>
    <?php elseif ($action === 'ficha_edit'): ?>
      <h2>Atualizar Minha Ficha</h2>
      <?php if ($fichaAluno): ?>
        <div class="form-box">
          <h3><?php echo e($fichaAluno['Nome']); ?></h3>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="table" value="matriculas">
            <input type="hidden" name="action" value="update_self">

            <label>Curso pretendido</label><br>
            <select name="IdCurso" required>
              <option value="">Selecione</option>
              <?php foreach ($cursosLookup as $item): ?>
                <option value="<?php echo e($item['IdCurso']); ?>" <?php echo ((int)($fichaAluno['IdCurso'] ?? 0) === (int)$item['IdCurso']) ? 'selected' : ''; ?>>
                  <?php echo e($item['Curso']); ?>
                </option>
              <?php endforeach; ?>
            </select><br>

            <label>Morada</label><br>
            <input type="text" name="Morada" maxlength="255" required value="<?php echo e($fichaAluno['Morada'] ?? ''); ?>"><br>

            <label>Email</label><br>
            <input type="email" name="Email" maxlength="120" required value="<?php echo e($fichaAluno['Email'] ?? ''); ?>"><br>

            <label>Contacto telefónico</label><br>
            <input type="text" name="Telefone" maxlength="20" required value="<?php echo e($fichaAluno['Telefone'] ?? ''); ?>"><br>

            <label>Nova Foto (opcional)</label><br>
            <input type="file" name="Foto" accept="image/*"><br>

            <button type="submit">Guardar Alterações</button>
            <a class="cancel-link" href="?table=matriculas&action=ficha">Cancelar</a>
          </form>
        </div>
      <?php else: ?>
        <div class="form-box">
          <p>Não foi encontrada uma matrícula associada ao teu utilizador.</p>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <h2>Matrículas</h2>
      <div class="form-box">
        <h3>Filtros de Pesquisa</h3>
        <form method="get" class="filters-row">
          <input type="hidden" name="table" value="matriculas">
          <input type="hidden" name="action" value="list">

          <label for="filtro_q">ID/Nome</label><br>
          <input id="filtro_q" type="text" name="q" value="<?php echo e($matriculasFiltroTexto); ?>" placeholder="Pesquisar por ID ou nome"><br>

          <label for="filtro_curso">Curso</label><br>
          <select id="filtro_curso" name="curso">
            <option value="0">Todos</option>
            <?php foreach ($cursosLookup as $item): ?>
              <option value="<?php echo e($item['IdCurso']); ?>" <?php echo $matriculasFiltroCurso === (int)$item['IdCurso'] ? 'selected' : ''; ?>>
                <?php echo e($item['Curso']); ?>
              </option>
            <?php endforeach; ?>
          </select><br>

          <button type="submit">Filtrar</button>
          <a class="cancel-link" href="?table=matriculas">Limpar</a>
        </form>
      </div>

      <?php $rowsMatriculas = fetchMatriculasRows($conn, $matriculasFiltroTexto, $matriculasFiltroCurso); ?>
      <table>
        <tr>
          <th>ID Aluno</th>
          <th>Nome</th>
          <th>Data de Nascimento</th>
          <th>Morada</th>
          <th>Email</th>
          <th>Contacto</th>
          <th>Curso</th>
          <th>Validação</th>
          <th>Foto</th>
          <th>Ações</th>
        </tr>
        <?php foreach ($rowsMatriculas as $row): ?>
          <tr>
            <td><?php echo e($row['IdAluno']); ?></td>
            <td><?php echo e($row['Nome']); ?></td>
            <td><?php echo e(formatDatePt($row['DataNascimento'] ?? '')); ?></td>
            <td><?php echo e($row['Morada'] ?? ''); ?></td>
            <td><?php echo e($row['Email'] ?? ''); ?></td>
            <td><?php echo e($row['Telefone'] ?? ''); ?></td>
            <td><?php echo e($row['Curso']); ?></td>
            <td><?php echo e($row['EstadoValidacao'] ?? 'Pendente'); ?></td>
            <td>
              <img class="aluno-foto" src="?table=matriculas&action=foto&id_aluno=<?php echo e($row['IdAluno']); ?>" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span class="sem-foto">Sem foto</span>
            </td>
            <td class="actions">
              <a href="?table=matriculas&action=edit&id_aluno=<?php echo e($row['IdAluno']); ?>">Editar</a>
              <a href="?table=matriculas&action=ficha&id_aluno=<?php echo e($row['IdAluno']); ?>">Ver ficha</a>
              <a href="?table=matriculas&action=certificado_print&id_aluno=<?php echo e($row['IdAluno']); ?>" target="_blank" rel="noopener">Certificado</a>
              <a href="?table=matriculas&action=ver_disciplinas&id_aluno=<?php echo e($row['IdAluno']); ?>">Ver disciplinas</a>
              <form class="inline inline-approve" method="post">
                <input type="hidden" name="table" value="matriculas">
                <input type="hidden" name="action" value="set_validation">
                <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
                <input type="hidden" name="EstadoValidacao" value="Aprovada">
                <input type="text" name="ObservacoesValidacao" maxlength="500" placeholder="Observações (opcional)">
                <button type="submit">Aprovar</button>
              </form>
              <form class="inline" method="post" onsubmit="return confirm('Rejeitar esta ficha?');">
                <input type="hidden" name="table" value="matriculas">
                <input type="hidden" name="action" value="set_validation">
                <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
                <input type="hidden" name="EstadoValidacao" value="Rejeitada">
                <input type="text" name="ObservacoesValidacao" maxlength="500" placeholder="Observações (opcional)">
                <button type="submit">Rejeitar</button>
              </form>
              <?php if (!$isGestor): ?>
                <form class="inline" method="post" onsubmit="return confirm('Remover matrícula?');">
                  <input type="hidden" name="table" value="matriculas">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
                  <button type="submit">Excluir</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rowsMatriculas)): ?>
          <tr>
            <td colspan="10">Sem resultados para os filtros selecionados.</td>
          </tr>
        <?php endif; ?>
      </table>

      <?php if ($editData): ?>
        <?php if ($isGestor): ?>
          <div class="form-box">
            <h3>Validar Ficha - <?php echo e($editData['Nome']); ?></h3>
            <form method="post">
              <input type="hidden" name="table" value="matriculas">
              <input type="hidden" name="action" value="set_validation">
              <input type="hidden" name="IdAluno" value="<?php echo e($editData['IdAluno']); ?>">

              <p><strong>ID Aluno:</strong> <?php echo e($editData['IdAluno']); ?></p>
              <p><strong>Nome:</strong> <?php echo e($editData['Nome']); ?></p>

              <label>Estado de validação</label><br>
              <select name="EstadoValidacao" required>
                <?php $estadoAtual = $editData['EstadoValidacao'] ?? 'Pendente'; ?>
                <option value="Pendente" <?php echo $estadoAtual === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                <option value="Aprovada" <?php echo $estadoAtual === 'Aprovada' ? 'selected' : ''; ?>>Aprovada</option>
                <option value="Rejeitada" <?php echo $estadoAtual === 'Rejeitada' ? 'selected' : ''; ?>>Rejeitada</option>
              </select><br>

              <label>Observações da validação</label><br>
              <textarea name="ObservacoesValidacao" maxlength="2000"><?php echo e($editData['ObservacoesValidacao'] ?? ''); ?></textarea><br>

              <button type="submit">Salvar validação</button>
              <a class="cancel-link" href="?table=matriculas">Cancelar</a>
            </form>
          </div>
        <?php else: ?>
          <div class="form-box">
            <h3>Editar Matrícula</h3>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="table" value="matriculas">
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="IdAluno" value="<?php echo e($editData['IdAluno']); ?>">

              <label>Nome</label><br>
              <input type="text" name="Nome" maxlength="25" required value="<?php echo e($editData['Nome'] ?? ''); ?>"><br>

              <label>Curso pretendido</label><br>
              <select name="IdCurso" required>
                <option value="">Selecione</option>
                <?php foreach ($cursosLookup as $item): ?>
                  <option value="<?php echo e($item['IdCurso']); ?>" <?php echo (($editData['IdCurso'] ?? '') == $item['IdCurso']) ? 'selected' : ''; ?>>
                    <?php echo e($item['Curso']); ?>
                  </option>
                <?php endforeach; ?>
              </select><br>

              <label>Estado de validação</label><br>
              <select name="EstadoValidacao" required>
                <?php $estadoAtual = $editData['EstadoValidacao'] ?? 'Pendente'; ?>
                <option value="Pendente" <?php echo $estadoAtual === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
                <option value="Aprovada" <?php echo $estadoAtual === 'Aprovada' ? 'selected' : ''; ?>>Aprovada</option>
                <option value="Rejeitada" <?php echo $estadoAtual === 'Rejeitada' ? 'selected' : ''; ?>>Rejeitada</option>
              </select><br>

              <label>Data de nascimento</label><br>
              <input type="date" name="DataNascimento" required max="<?php echo e($dataMaximaNascimento); ?>" value="<?php echo e($editData['DataNascimento'] ?? ''); ?>"><br>

              <label>Morada</label><br>
              <input type="text" name="Morada" maxlength="255" required value="<?php echo e($editData['Morada'] ?? ''); ?>"><br>

              <label>Email</label><br>
              <input type="email" name="Email" maxlength="120" required value="<?php echo e($editData['Email'] ?? ''); ?>"><br>

              <label>Contacto telefónico</label><br>
              <input type="text" name="Telefone" maxlength="20" required value="<?php echo e($editData['Telefone'] ?? ''); ?>"><br>

              <label>Foto (opcional)</label><br>
              <input type="file" name="Foto" accept="image/*"><br>

              <button type="submit">Atualizar</button>
              <a class="cancel-link" href="?table=matriculas">Cancelar</a>
            </form>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <?php if (!$isGestor): ?>
          <div class="form-box">
            <h3>Nova Matrícula</h3>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="table" value="matriculas">
              <input type="hidden" name="action" value="create">

              <label>ID Aluno</label><br>
              <input type="number" name="IdAluno" min="1" required value="" ><br>

              <label>Nome</label><br>
              <input type="text" name="Nome" maxlength="25" required value=""><br>

              <label>Curso pretendido</label><br>
              <select name="IdCurso" required>
                <option value="">Selecione</option>
                <?php foreach ($cursosLookup as $item): ?>
                  <option value="<?php echo e($item['IdCurso']); ?>"><?php echo e($item['Curso']); ?></option>
                <?php endforeach; ?>
              </select><br>

              <label>Estado de validação</label><br>
              <select name="EstadoValidacao" required>
                <option value="Pendente" selected>Pendente</option>
                <option value="Aprovada">Aprovada</option>
                <option value="Rejeitada">Rejeitada</option>
              </select><br>

              <label>Data de nascimento</label><br>
              <input type="date" name="DataNascimento" required max="<?php echo e($dataMaximaNascimento); ?>" value=""><br>

              <label>Morada</label><br>
              <input type="text" name="Morada" maxlength="255" required value=""><br>

              <label>Email</label><br>
              <input type="email" name="Email" maxlength="120" required value=""><br>

              <label>Contacto telefónico</label><br>
              <input type="text" name="Telefone" maxlength="20" required value="" placeholder="Ex.: 912345678"><br>

              <label>Foto (opcional)</label><br>
              <input type="file" name="Foto" accept="image/*"><br>

              <button type="submit">Criar</button>
            </form>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($table === 'plano_estudos'): ?>
    <h2>Plano de Estudos</h2>
    <p><a class="section-link" href="?table=plano_estudos&action=print" target="_blank" rel="noopener">Exportar PDF</a></p>
    <?php
      $rows = $conn->query(
        "SELECT pe.IdDisciplina, pe.IdCurso, d.Disciplina, c.Curso
         FROM plano_estudos pe
         JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina
         JOIN cursos c ON c.IdCurso = pe.IdCurso
         ORDER BY c.Curso, d.Disciplina"
      );
    ?>
    <table>
      <tr>
        <th>Disciplina</th>
        <th>Curso</th>
        <th>Ações</th>
      </tr>
      <?php while ($row = $rows->fetch_assoc()): ?>
        <tr>
          <td><?php echo e($row['Disciplina']); ?></td>
          <td><?php echo e($row['Curso']); ?></td>
          <td class="actions">
            <a href="?table=plano_estudos&action=edit&id_disciplina=<?php echo e($row['IdDisciplina']); ?>&id_curso=<?php echo e($row['IdCurso']); ?>">Editar</a>
            <form class="inline" method="post" onsubmit="return confirm('Remover ligação do plano?');">
              <input type="hidden" name="table" value="plano_estudos">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="IdDisciplina" value="<?php echo e($row['IdDisciplina']); ?>">
              <input type="hidden" name="IdCurso" value="<?php echo e($row['IdCurso']); ?>">
              <button type="submit">Excluir</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>

    <div class="form-box">
      <h3><?php echo $editData ? 'Editar Ligação' : 'Nova Ligação'; ?></h3>
      <form method="post">
        <input type="hidden" name="table" value="plano_estudos">
        <input type="hidden" name="action" value="<?php echo $editData ? 'update' : 'create'; ?>">
        <?php if ($editData): ?>
          <input type="hidden" name="old_IdDisciplina" value="<?php echo e($editData['IdDisciplina']); ?>">
          <input type="hidden" name="old_IdCurso" value="<?php echo e($editData['IdCurso']); ?>">
        <?php endif; ?>

        <label>Disciplina</label><br>
        <select name="IdDisciplina" required>
          <option value="">Selecione</option>
          <?php foreach ($disciplinasLookup as $item): ?>
            <option value="<?php echo e($item['IdDisciplina']); ?>" <?php echo (($editData['IdDisciplina'] ?? '') == $item['IdDisciplina']) ? 'selected' : ''; ?>>
              <?php echo e($item['Disciplina']); ?>
            </option>
          <?php endforeach; ?>
        </select><br>

        <label>Curso</label><br>
        <select name="IdCurso" required>
          <option value="">Selecione</option>
          <?php foreach ($cursosLookup as $item): ?>
            <option value="<?php echo e($item['IdCurso']); ?>" <?php echo (($editData['IdCurso'] ?? '') == $item['IdCurso']) ? 'selected' : ''; ?>>
              <?php echo e($item['Curso']); ?>
            </option>
          <?php endforeach; ?>
        </select><br>

        <button type="submit"><?php echo $editData ? 'Atualizar' : 'Criar'; ?></button>
        <?php if ($editData): ?>
          <a class="cancel-link" href="?table=plano_estudos">Cancelar</a>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
<?php $conn->close(); ?>