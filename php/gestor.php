<?php
define('APP_AREA', 'gestor');
require_once __DIR__ . '/common.php';

$matriculasSchemaReady = ensureMatriculasExtraFields($conn);
$planoSchemaReady = ensurePlanoEstudosSchema($conn);

$dataMaximaNascimento = (new DateTimeImmutable('today'))->modify('-13 years')->format('Y-m-d');

$perfilAtual = strtolower(trim((string)($_SESSION['utilizador_perfil'] ?? '')));
$isGestor = $perfilAtual === 'gestor';
$podeEditarDisciplinasCursos = $isGestor;

$appArea = defined('APP_AREA') ? (string)APP_AREA : '';
if ($appArea === 'gestor' && !$isGestor) {
  header('Location: index.php?type=error&message=' . urlencode('Acesso restrito à área de gestor.'));
  exit;
}

$allowedTables = [
  'disciplina' => true,
  'cursos' => true,
];

if ($isGestor) {
  $allowedTables['matriculas'] = true;
  $allowedTables['plano_estudos'] = true;
}

$table = $_GET['table'] ?? 'disciplina';
if (!isset($allowedTables[$table])) {
  $table = 'disciplina';
}

$action = $_GET['action'] ?? 'list';
$allowedActions = ['list', 'edit', 'foto', 'ver_disciplinas', 'ficha', 'ficha_print', 'certificado_print', 'print'];

if (!in_array($action, $allowedActions, true)) {
  $action = 'list';
}

if (!$matriculasSchemaReady && $table === 'matriculas') {
  redirectWithMessage('disciplina', 'error', 'Não foi possível preparar a tabela de matrículas. Contacta o administrador da base de dados.');
}

if (!$planoSchemaReady && $table === 'plano_estudos') {
  redirectWithMessage('plano_estudos', 'error', 'Não foi possível preparar o esquema do plano de estudos. Contacta o administrador da base de dados.');
}

if ($action === 'edit' && !$podeEditarDisciplinasCursos) {
  redirectWithMessage($table, 'error', 'Apenas gestores podem editar disciplinas e cursos.');
}

if ($table === 'matriculas' && $action === 'foto') {
  $idAlunoFoto = (int)($_GET['id_aluno'] ?? 0);

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

  if (!csrfTokenIsValid($_POST['csrf_token'] ?? '')) {
    redirectWithMessage($postTable, 'error', 'Sessão expirada. Atualiza a página e tenta novamente.');
  }

  // Permission checks: gestores só podem gerir disciplinas/cursos/planos; validação de fichas é feita via set_validation
  if (in_array($postTable, ['disciplina', 'cursos', 'plano_estudos'], true)) {
    if (!$podeEditarDisciplinasCursos) {
      redirectWithMessage($postTable, 'error', 'Apenas gestores podem editar disciplinas, cursos e planos de estudo.');
    }
  } elseif ($postTable === 'matriculas') {
    if ($isGestor) {
      // Gestores podem validar/rejeitar fichas (set_validation) e remover (delete)
      if (!in_array($postAction, ['set_validation', 'delete'], true)) {
        redirectWithMessage('matriculas', 'error', 'Gestores apenas podem validar/rejeitar ou remover fichas de aluno.');
      }
    } else {
      redirectWithMessage('matriculas', 'error', 'Acesso inválido.');
    }
  } else {
    redirectWithMessage($postTable, 'error', 'Tabela inválida.');
  }

  if (!in_array($postAction, ['create', 'update', 'delete', 'set_validation'], true)) {
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
        redirectWithMessage('matriculas', 'error', 'ID de aluno, nome, curso, data de nascimento, morada, email, contacto telefónico e foto são obrigatórios.');
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

      // Foto é obrigatória ao criar a matrícula via gestor
      if ($fotoBlob === null) {
        redirectWithMessage('matriculas', 'error', 'A foto é obrigatória.');
      }

      $stmt = $conn->prepare("INSERT INTO matriculas (IdAluno, Nome, IdCurso, DataNascimento, Morada, Email, Telefone, EstadoValidacao, Foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param('isissssss', $idAluno, $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao, $fotoBlob);
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

      $utilizadorAtual = (string)($_SESSION['utilizador_nome'] ?? '');

      if ($observacoes === '') {
        $stmt = $conn->prepare("UPDATE matriculas SET EstadoValidacao = ?, ObservacoesValidacao = NULL, ValidadoPor = ?, DataValidacao = NOW() WHERE IdAluno = ?");
        $stmt->bind_param('ssi', $estadoValidacao, $utilizadorAtual, $idAluno);
      } else {
        $stmt = $conn->prepare("UPDATE matriculas SET EstadoValidacao = ?, ObservacoesValidacao = ?, ValidadoPor = ?, DataValidacao = NOW() WHERE IdAluno = ?");
        $stmt->bind_param('sssi', $estadoValidacao, $observacoes, $utilizadorAtual, $idAluno);
      }
      $ok = $stmt->execute();
      $stmt->close();

      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Estado de validação atualizado.' : 'Erro ao atualizar estado de validação.');
    }
  }

  if ($postTable === 'plano_estudos') {
    if (!$planoSchemaReady) {
      redirectWithMessage('plano_estudos', 'error', 'Não foi possível preparar o esquema do plano de estudos. Contacta o administrador.');
    }

    if ($postAction === 'create') {
      $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $ano = (int)($_POST['Ano'] ?? 1);
      $semestre = (int)($_POST['Semestre'] ?? 1);

      if ($idDisciplina <= 0 || $idCurso <= 0 || $ano <= 0 || $semestre <= 0) {
        redirectWithMessage('plano_estudos', 'error', 'Disciplina, curso, ano e semestre são obrigatórios.');
      }

      if ($semestre < 1 || $semestre > 2) {
        $semestre = 1;
      }

      if ($ano < 1 || $ano > 10) {
        $ano = max(1, min(10, $ano));
      }

      try {
        $stmt = $conn->prepare("INSERT INTO plano_estudos (IdDisciplina, IdCurso, Ano, Semestre) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('iiii', $idDisciplina, $idCurso, $ano, $semestre);
        $ok = $stmt->execute();
        $stmt->close();
        redirectWithMessage('plano_estudos', $ok ? 'success' : 'error', $ok ? 'Ligação criada com sucesso.' : 'Erro ao criar ligação (pode já existir).');
      } catch (mysqli_sql_exception $ex) {
        $msg = stripos($ex->getMessage(), 'Duplicate entry') !== false ? 'Já existe uma ligação para esta disciplina/curso/ano/semestre.' : 'Erro ao criar ligação.';
        redirectWithMessage('plano_estudos', 'error', $msg);
      }
    }

    if ($postAction === 'update') {
      $oldIdDisciplina = (int)($_POST['old_IdDisciplina'] ?? 0);
      $oldIdCurso = (int)($_POST['old_IdCurso'] ?? 0);
      $oldAno = (int)($_POST['old_Ano'] ?? 0);
      $oldSemestre = (int)($_POST['old_Semestre'] ?? 0);

      $newIdDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
      $newIdCurso = (int)($_POST['IdCurso'] ?? 0);
      $newAno = (int)($_POST['Ano'] ?? 1);
      $newSemestre = (int)($_POST['Semestre'] ?? 1);

      if ($oldIdDisciplina <= 0 || $oldIdCurso <= 0 || $oldAno <= 0 || $oldSemestre <= 0) {
        redirectWithMessage('plano_estudos', 'error', 'Identificadores antigos inválidos.');
      }

      if ($newIdDisciplina <= 0 || $newIdCurso <= 0 || $newAno <= 0 || $newSemestre <= 0) {
        redirectWithMessage('plano_estudos', 'error', 'Disciplina, curso, ano e semestre são obrigatórios.');
      }

      try {
        $stmt = $conn->prepare("UPDATE plano_estudos SET IdDisciplina = ?, IdCurso = ?, Ano = ?, Semestre = ? WHERE IdDisciplina = ? AND IdCurso = ? AND Ano = ? AND Semestre = ?");
        $stmt->bind_param('iiiiiiii', $newIdDisciplina, $newIdCurso, $newAno, $newSemestre, $oldIdDisciplina, $oldIdCurso, $oldAno, $oldSemestre);
        $ok = $stmt->execute();
        $stmt->close();
        redirectWithMessage('plano_estudos', $ok ? 'success' : 'error', $ok ? 'Ligação atualizada com sucesso.' : 'Erro ao atualizar ligação.');
      } catch (mysqli_sql_exception $ex) {
        $msg = stripos($ex->getMessage(), 'Duplicate entry') !== false ? 'Já existe uma ligação igual para esta disciplina/curso/ano/semestre.' : 'Erro ao atualizar ligação.';
        redirectWithMessage('plano_estudos', 'error', $msg);
      }
    }

    if ($postAction === 'delete') {
      $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $ano = (int)($_POST['Ano'] ?? 1);
      $semestre = (int)($_POST['Semestre'] ?? 1);
      $stmt = $conn->prepare("DELETE FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND Ano = ? AND Semestre = ?");
      $stmt->bind_param('iiii', $idDisciplina, $idCurso, $ano, $semestre);
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
$stylesVersion = (string)(@filemtime(dirname(__DIR__) . '/estilos/styles.css') ?: time());
$stylesHref = '../estilos/styles.css?v=' . rawurlencode($stylesVersion);

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
    $idAno = (int)($_GET['id_ano'] ?? 0);
    $idSemestre = (int)($_GET['id_semestre'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM plano_estudos WHERE IdDisciplina = ? AND IdCurso = ? AND Ano = ? AND Semestre = ?");
    $stmt->bind_param('iiii', $idDisciplina, $idCurso, $idAno, $idSemestre);
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

if ($table === 'matriculas' && in_array($action, ['ficha', 'ficha_print', 'certificado_print'], true)) {
  $alunoIdSelecionado = (int)($_GET['id_aluno'] ?? 0);

    $stmtFicha = $conn->prepare(
      "SELECT m.IdAluno, m.Nome, m.IdCurso, m.DataNascimento, m.Morada, m.Email, m.Telefone, m.EstadoValidacao, m.ObservacoesValidacao, c.Curso, c.Sigla AS SiglaCurso
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
    "SELECT pe.IdDisciplina, pe.IdCurso, pe.Ano, pe.Semestre, d.Disciplina, c.Curso
     FROM plano_estudos pe
     JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina
     JOIN cursos c ON c.IdCurso = pe.IdCurso
     ORDER BY c.Curso, pe.Ano, pe.Semestre, d.Disciplina"
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
  echo "<table class=\"document-table\"><thead><tr><th>Curso</th><th>Ano</th><th>Semestre</th><th>Disciplina</th></tr></thead><tbody>";
  while ($row = $rows->fetch_assoc()) {
    echo '<tr><td>' . htmlspecialchars($row['Curso'], ENT_QUOTES, 'UTF-8') . '</td>'
      . '<td>' . htmlspecialchars((string)($row['Ano'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
      . '<td>' . htmlspecialchars((string)($row['Semestre'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
      . '<td>' . htmlspecialchars($row['Disciplina'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
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
  echo '</div>';

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
  echo '<p><strong>Curso:</strong> ' . e($fichaAluno['Curso']) . ' (' . e($fichaAluno['SiglaCurso']) . ')</p>';
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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?php echo e($stylesHref); ?>">
</head>
<body class="app-page">
  <div class="app-shell">
  <div class="top-bar">
    <div>
      <h1>Gestão Académica</h1>
      <p class="subtitle">
        Gestão de Disciplinas, Cursos, Ficha de Aluno e Plano de Estudos
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

  <nav class="menu-nav nav nav-pills flex-wrap align-items-center gap-2">
    <a class="nav-link <?php echo $table === 'disciplina' ? 'active' : ''; ?>" href="?table=disciplina">Disciplinas</a>
    <a class="nav-link <?php echo $table === 'cursos' ? 'active' : ''; ?>" href="?table=cursos">Cursos</a>
    <a class="nav-link <?php echo $table === 'matriculas' ? 'active' : ''; ?>" href="?table=matriculas">Ficha de Aluno</a>
    <a class="nav-link <?php echo $table === 'plano_estudos' ? 'active' : ''; ?>" href="?table=plano_estudos">Planos de Estudo</a>
  </nav>

  <?php if ($message !== ''): ?>
    <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
  <?php endif; ?>

  <?php if ($table === 'disciplina'): ?>
    <h2>Disciplinas</h2>
    <?php $rows = $conn->query("SELECT * FROM disciplina ORDER BY IdDisciplina DESC"); ?>
    <div class="table-scroll" aria-label="Lista de disciplinas com scroll">
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
                <a class="table-action-btn edit-action-btn" href="?table=disciplina&action=edit&id=<?php echo e($row['IdDisciplina']); ?>">Editar</a>
                <form class="inline" method="post" onsubmit="return confirm('Remover disciplina?');">
                  <?php echo csrfInput(); ?>
                  <input type="hidden" name="table" value="disciplina">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="IdDisciplina" value="<?php echo e($row['IdDisciplina']); ?>">
                  <button type="submit" class="danger-button table-action-btn">Excluir</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <?php if ($podeEditarDisciplinasCursos): ?>
      <div class="form-box">
        <h3><?php echo $editData ? 'Editar Disciplina' : 'Nova Disciplina'; ?></h3>
        <form method="post">
                  <?php echo csrfInput(); ?>
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
    <div class="table-scroll" aria-label="Lista de cursos com scroll">
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
                <a class="table-action-btn edit-action-btn" href="?table=cursos&action=edit&id=<?php echo e($row['IdCurso']); ?>">Editar</a>
                <form class="inline" method="post" onsubmit="return confirm('Remover curso?');">
                  <?php echo csrfInput(); ?>
                  <input type="hidden" name="table" value="cursos">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="IdCurso" value="<?php echo e($row['IdCurso']); ?>">
                  <button type="submit" class="danger-button table-action-btn">Excluir</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <?php if ($podeEditarDisciplinasCursos): ?>
      <div class="form-box">
        <h3><?php echo $editData ? 'Editar Curso' : 'Novo Curso'; ?></h3>
        <form method="post">
                  <?php echo csrfInput(); ?>
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
          </p>
        </div>
      <?php else: ?>
        <div class="form-box">
          <p>Aluno não encontrado.</p>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <h2>Ficha de Aluno</h2>
      <div class="form-box">
        <h3>Filtros de Pesquisa</h3>
        <form method="get" class="filters-row">
          <input type="hidden" name="table" value="matriculas">
          <input type="hidden" name="action" value="list">

          <div class="filter-field">
            <label for="filtro_q">ID/Nome</label>
            <input id="filtro_q" type="text" name="q" value="<?php echo e($matriculasFiltroTexto); ?>" placeholder="Pesquisar por ID ou nome">
          </div>

          <div class="filter-field">
            <label for="filtro_curso">Curso</label>
            <select id="filtro_curso" name="curso">
              <option value="0">Todos</option>
              <?php foreach ($cursosLookup as $item): ?>
                <option value="<?php echo e($item['IdCurso']); ?>" <?php echo $matriculasFiltroCurso === (int)$item['IdCurso'] ? 'selected' : ''; ?>>
                  <?php echo e($item['Curso']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="filter-actions">
            <button type="submit">Filtrar</button>
            <a class="cancel-link" href="?table=matriculas">Limpar</a>
          </div>
        </form>
      </div>

      <?php $rowsMatriculas = fetchMatriculasRows($conn, $matriculasFiltroTexto, $matriculasFiltroCurso); ?>
      <div class="table-scroll matriculas-scroll" aria-label="Lista de fichas de aluno com scroll">
      <table class="matriculas-table">
        <tr>
          <th>ID Aluno</th>
          <th>Nome</th>
          <th class="col-nascimento">Nascimento</th>
          <th class="col-morada">Morada</th>
          <th class="col-email">Email</th>
          <th>Contacto</th>
          <th class="col-curso">Curso</th>
          <th>Estado</th>
          <th>Foto</th>
          <th>Ações</th>
        </tr>
        <?php foreach ($rowsMatriculas as $row): ?>
          <tr>
            <td><?php echo e($row['IdAluno']); ?></td>
            <td><?php echo e($row['Nome']); ?></td>
            <td class="col-nascimento"><?php echo e(formatDatePt($row['DataNascimento'] ?? '')); ?></td>
            <td class="col-morada" title="<?php echo e($row['Morada'] ?? ''); ?>"><?php echo e($row['Morada'] ?? ''); ?></td>
            <td class="col-email" title="<?php echo e($row['Email'] ?? ''); ?>"><?php echo e($row['Email'] ?? ''); ?></td>
            <td><?php echo e($row['Telefone'] ?? ''); ?></td>
            <td class="col-curso" title="<?php echo e($row['Curso']); ?>"><?php echo e($row['Curso']); ?></td>
            <td><?php echo e($row['EstadoValidacao'] ?? 'Pendente'); ?></td>
            <td>
              <img class="aluno-foto" src="?table=matriculas&action=foto&id_aluno=<?php echo e($row['IdAluno']); ?>" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span class="sem-foto">Sem foto</span>
            </td>
            <td class="actions">
              <?php $estadoLinha = normalizarEstadoValidacao($row['EstadoValidacao'] ?? 'Pendente'); ?>
              <?php if ($estadoLinha === 'Aprovada'): ?>
                <a class="table-action-btn edit-action-btn" href="?table=matriculas&action=edit&id_aluno=<?php echo e($row['IdAluno']); ?>">Editar</a>
                <?php if ($isGestor): ?>
                  <button type="submit" form="delete-matricula-<?php echo e($row['IdAluno']); ?>" class="danger-button table-action-btn" onclick="return confirm('Remover matrícula?');">Excluir</button>
                <?php endif; ?>
              <?php else: ?>
                <form class="validation-actions" method="post">
                  <?php echo csrfInput(); ?>
                  <input type="hidden" name="table" value="matriculas">
                  <input type="hidden" name="action" value="set_validation">
                  <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">

                  <input id="obs-<?php echo e($row['IdAluno']); ?>" type="text" name="ObservacoesValidacao" maxlength="500" placeholder="Observações para o aluno" aria-label="Observações para o aluno">

                  <div class="validation-buttons">
                    <button type="submit" name="EstadoValidacao" value="Aprovada">Aprovar</button>
                    <button type="submit" name="EstadoValidacao" value="Rejeitada" class="danger-button" onclick="return confirm('Rejeitar esta ficha?');">Rejeitar</button>
                    <?php if ($isGestor): ?>
                      <button type="submit" form="delete-matricula-<?php echo e($row['IdAluno']); ?>" class="danger-button" onclick="return confirm('Remover matrícula?');">Excluir</button>
                    <?php endif; ?>
                  </div>
                </form>
              <?php endif; ?>
              <?php if ($isGestor): ?>
                <form id="delete-matricula-<?php echo e($row['IdAluno']); ?>" class="inline" method="post">
                  <input type="hidden" name="table" value="matriculas">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
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
      </div>

      <?php if ($editData): ?>
        <?php if ($isGestor): ?>
          <div class="form-box">
            <h3>Validar Ficha - <?php echo e($editData['Nome']); ?></h3>
            <form method="post">
                  <?php echo csrfInput(); ?>
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
                  <?php echo csrfInput(); ?>
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

              <label>Foto</label><br>
              <input type="file" name="Foto" accept=".jpg,.png"><br>

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
                  <?php echo csrfInput(); ?>
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
              <input type="file" name="Foto" accept=".jpg,.png"><br>

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
          "SELECT pe.IdDisciplina, pe.IdCurso, pe.Ano, pe.Semestre, d.Disciplina, c.Curso
           FROM plano_estudos pe
           JOIN disciplina d ON d.IdDisciplina = pe.IdDisciplina
           JOIN cursos c ON c.IdCurso = pe.IdCurso
           ORDER BY c.Curso, pe.Ano, pe.Semestre, d.Disciplina"
        );
    ?>
    <div class="table-scroll" aria-label="Lista do plano de estudos com scroll">
      <table>
        <tr>
            <th>Disciplina</th>
            <th>Curso</th>
            <th>Ano</th>
            <th>Semestre</th>
            <th>Ações</th>
        </tr>
        <?php while ($row = $rows->fetch_assoc()): ?>
          <tr>
              <td><?php echo e($row['Disciplina']); ?></td>
              <td><?php echo e($row['Curso']); ?></td>
              <td><?php echo e($row['Ano'] ?? ''); ?></td>
              <td><?php echo e($row['Semestre'] ?? ''); ?></td>
              <td class="actions">
                <a class="action-button table-action-btn" href="?table=plano_estudos&action=edit&id_disciplina=<?php echo e($row['IdDisciplina']); ?>&id_curso=<?php echo e($row['IdCurso']); ?>&id_ano=<?php echo e($row['Ano']); ?>&id_semestre=<?php echo e($row['Semestre']); ?>">Editar</a>
                <form class="inline" method="post" onsubmit="return confirm('Remover ligação do plano?');">
                  <?php echo csrfInput(); ?>
                  <input type="hidden" name="table" value="plano_estudos">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="IdDisciplina" value="<?php echo e($row['IdDisciplina']); ?>">
                  <input type="hidden" name="IdCurso" value="<?php echo e($row['IdCurso']); ?>">
                  <input type="hidden" name="Ano" value="<?php echo e($row['Ano']); ?>">
                  <input type="hidden" name="Semestre" value="<?php echo e($row['Semestre']); ?>">
                  <button type="submit" class="table-action-btn">Excluir</button>
                </form>
              </td>
          </tr>
        <?php endwhile; ?>
      </table>
    </div>

    <div class="form-box">
      <h3><?php echo $editData ? 'Editar Ligação' : 'Nova Ligação'; ?></h3>
      <form method="post">
                  <?php echo csrfInput(); ?>
        <input type="hidden" name="table" value="plano_estudos">
        <input type="hidden" name="action" value="<?php echo $editData ? 'update' : 'create'; ?>">
        <?php if ($editData): ?>
          <input type="hidden" name="old_IdDisciplina" value="<?php echo e($editData['IdDisciplina']); ?>">
          <input type="hidden" name="old_IdCurso" value="<?php echo e($editData['IdCurso']); ?>">
          <input type="hidden" name="old_Ano" value="<?php echo e($editData['Ano'] ?? '1'); ?>">
          <input type="hidden" name="old_Semestre" value="<?php echo e($editData['Semestre'] ?? '1'); ?>">
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

        <label>Ano (ex.: 1,2,...)</label><br>
        <input type="number" name="Ano" min="1" max="10" required value="<?php echo e($editData['Ano'] ?? '1'); ?>"><br>

        <label>Semestre (1 ou 2)</label><br>
        <select name="Semestre" required>
          <option value="1" <?php echo ((int)($editData['Semestre'] ?? 1) === 1) ? 'selected' : ''; ?>>1</option>
          <option value="2" <?php echo ((int)($editData['Semestre'] ?? 1) === 2) ? 'selected' : ''; ?>>2</option>
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