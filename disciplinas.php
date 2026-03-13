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

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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

function redirectWithMessage($table, $type, $message)
{
  $table = urlencode($table);
  $type = urlencode($type);
  $message = urlencode($message);
  header("Location: ?table={$table}&type={$type}&message={$message}");
  exit;
}

function fetchLookup(mysqli $conn, $table, $idField, $labelField)
{
  $sql = "SELECT {$idField}, {$labelField} FROM {$table} ORDER BY {$labelField}";
  $result = $conn->query($sql);
  $items = [];
  if ($result) {
    while ($row = $result->fetch_assoc()) {
      $items[] = $row;
    }
  }
  return $items;
}

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

function ensureMatriculasExtraFields(mysqli $conn)
{
  $columns = [
    'DataNascimento' => "ALTER TABLE matriculas ADD COLUMN DataNascimento DATE NULL AFTER IdCurso",
    'Morada' => "ALTER TABLE matriculas ADD COLUMN Morada VARCHAR(255) NULL AFTER DataNascimento",
  ];

  foreach ($columns as $columnName => $alterSql) {
    $stmt = $conn->prepare('SHOW COLUMNS FROM matriculas LIKE ?');
    if (!$stmt) {
      return false;
    }

    $stmt->bind_param('s', $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();

    if (!$exists) {
      if (!$conn->query($alterSql)) {
        return false;
      }
    }
  }

  return true;
}

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

if (!ensureMatriculasExtraFields($conn)) {
  die('Erro ao preparar a estrutura da tabela de matrículas.');
}

$dataMaximaNascimento = (new DateTimeImmutable('today'))->modify('-13 years')->format('Y-m-d');

$perfilAtual = strtolower(trim((string)($_SESSION['utilizador_perfil'] ?? '')));
$isGestor = $perfilAtual === 'gestor';
$isAluno = $perfilAtual === 'aluno';
$podeEditarDisciplinasCursos = $isGestor;

$alunoIdSessao = 0;
if ($isAluno) {
  $nomeSessao = trim((string)($_SESSION['utilizador_nome'] ?? ''));
  if ($nomeSessao !== '') {
    $stmtAlunoSessao = $conn->prepare("SELECT IdAluno FROM matriculas WHERE LOWER(Nome) = LOWER(?) LIMIT 1");
    $stmtAlunoSessao->bind_param('s', $nomeSessao);
    $stmtAlunoSessao->execute();
    $resultAlunoSessao = $stmtAlunoSessao->get_result();
    $alunoSessao = $resultAlunoSessao ? $resultAlunoSessao->fetch_assoc() : null;
    $stmtAlunoSessao->close();
    if ($alunoSessao) {
      $alunoIdSessao = (int)$alunoSessao['IdAluno'];
    }
  }

  if ($alunoIdSessao <= 0) {
    $resultadoPrimeiroAluno = $conn->query("SELECT IdAluno FROM matriculas ORDER BY IdAluno LIMIT 1");
    if ($resultadoPrimeiroAluno) {
      $primeiroAluno = $resultadoPrimeiroAluno->fetch_assoc();
      $alunoIdSessao = (int)($primeiroAluno['IdAluno'] ?? 0);
      $resultadoPrimeiroAluno->close();
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
  ? ['list', 'edit', 'foto', 'ver_disciplinas', 'ficha', 'ficha_print', 'print']
  : ($isAluno ? ['list', 'ficha', 'ficha_print', 'foto'] : ['list']);

if (!in_array($action, $allowedActions, true)) {
  $action = 'list';
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

  if (!$podeEditarDisciplinasCursos) {
    redirectWithMessage($postTable, 'error', 'Apenas gestores podem editar disciplinas e cursos.');
  }

  if (!in_array($postAction, ['create', 'update', 'delete'], true)) {
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
    if ($postAction === 'create') {
      $nome = trim($_POST['Nome'] ?? '');
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $dataNascimento = validarDataNascimento($_POST['DataNascimento'] ?? '', $validationError);
      $morada = trim($_POST['Morada'] ?? '');

      if ($nome === '' || $idCurso <= 0 || $morada === '') {
        redirectWithMessage('matriculas', 'error', 'Nome, curso, data de nascimento e morada são obrigatórios.');
      }

      if ($dataNascimento === false) {
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

      if ($fotoBlob === null) {
        redirectWithMessage('matriculas', 'error', 'A foto é obrigatória para criar matrícula.');
      }

      $stmt = $conn->prepare("INSERT INTO matriculas (Nome, IdCurso, DataNascimento, Morada, Foto) VALUES (?, ?, ?, ?, ?)");
      $stmt->bind_param('sisss', $nome, $idCurso, $dataNascimento, $morada, $fotoBlob);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Matrícula criada com sucesso.' : 'Erro ao criar matrícula.');
    }

    if ($postAction === 'update') {
      $idAluno = (int)($_POST['IdAluno'] ?? 0);
      $nome = trim($_POST['Nome'] ?? '');
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $dataNascimento = validarDataNascimento($_POST['DataNascimento'] ?? '', $validationError);
      $morada = trim($_POST['Morada'] ?? '');

      if ($nome === '' || $idCurso <= 0 || $morada === '') {
        redirectWithMessage('matriculas', 'error', 'Nome, curso, data de nascimento e morada são obrigatórios.');
      }

      if ($dataNascimento === false) {
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
        $stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, IdCurso = ?, DataNascimento = ?, Morada = ?, Foto = ? WHERE IdAluno = ?");
        $stmt->bind_param('sisssi', $nome, $idCurso, $dataNascimento, $morada, $fotoBlob, $idAluno);
      } else {
        $stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, IdCurso = ?, DataNascimento = ?, Morada = ? WHERE IdAluno = ?");
        $stmt->bind_param('sissi', $nome, $idCurso, $dataNascimento, $morada, $idAluno);
      }

      $ok = $stmt->execute();
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

if ($table === 'matriculas' && ($action === 'ficha' || $action === 'ficha_print')) {
  if ($isAluno) {
    $alunoIdSelecionado = $alunoIdSessao;
  } else {
    $alunoIdSelecionado = (int)($_GET['id_aluno'] ?? 0);
  }

  $stmtFicha = $conn->prepare(
    "SELECT m.IdAluno, m.Nome, m.IdCurso, m.DataNascimento, m.Morada, c.Curso, c.Sigla AS SiglaCurso
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

  // Minimal printable HTML
  echo "<!doctype html><html lang=\"pt\"><head><meta charset=\"utf-8\"><title>Plano de Estudos</title>";
  echo "<link rel=\"stylesheet\" href=\"styles.css\">";
  echo "</head><body class=\"print-page\" onload=\"window.print()\">";
  echo "<h1>Plano de Estudos</h1>";
  echo "<table><thead><tr><th>Curso</th><th>Disciplina</th></tr></thead><tbody>";
  while ($row = $rows->fetch_assoc()) {
    echo '<tr><td>' . htmlspecialchars($row['Curso'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($row['Disciplina'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
  }
  echo "</tbody></table></body></html>";
  $conn->close();
  exit;
}

if ($table === 'matriculas' && $action === 'ficha_print') {
  if (!$fichaAluno) {
    http_response_code(404);
    $conn->close();
    exit;
  }

  echo "<!doctype html><html lang=\"pt\"><head><meta charset=\"utf-8\"><title>Ficha do Aluno</title>";
  echo "<link rel=\"stylesheet\" href=\"styles.css\">";
  echo "</head><body class=\"print-page\" onload=\"window.print()\">";
  echo "<h1>Ficha do Aluno</h1>";
  echo '<p><strong>Nº Aluno:</strong> ' . e($fichaAluno['IdAluno']) . '</p>';
  echo '<p><strong>Nome:</strong> ' . e($fichaAluno['Nome']) . '</p>';
  echo '<p><strong>Data de nascimento:</strong> ' . e(formatDatePt($fichaAluno['DataNascimento'] ?? '')) . '</p>';
  echo '<p><strong>Morada:</strong> ' . e($fichaAluno['Morada'] ?? '') . '</p>';
  echo '<p><strong>Curso:</strong> ' . e($fichaAluno['Curso']) . ' (' . e($fichaAluno['SiglaCurso']) . ')</p>';
  echo '<h3>Disciplinas do Curso</h3>';

  if (!empty($fichaDisciplinas)) {
    echo "<table><thead><tr><th>Disciplina</th><th>Sigla</th></tr></thead><tbody>";
    foreach ($fichaDisciplinas as $disciplinaFicha) {
      echo '<tr><td>' . e($disciplinaFicha['Disciplina']) . '</td><td>' . e($disciplinaFicha['Sigla']) . '</td></tr>';
    }
    echo "</tbody></table>";
  } else {
    echo '<p>Não existem disciplinas associadas ao curso deste aluno.</p>';
  }

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
  <link rel="stylesheet" href="styles.css">
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
            : 'Consulta de Disciplinas e Cursos disponíveis'); ?>
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
      <a class="<?php echo ($table === 'matriculas' && in_array($action, ['ficha', 'ficha_print'], true)) ? 'active' : ''; ?>" href="?table=matriculas&action=ficha">Minha Ficha</a>
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
          <p class="ficha-linha"><strong>Curso:</strong> <?php echo e($fichaAluno['Curso']); ?> (<?php echo e($fichaAluno['SiglaCurso']); ?>)</p>
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
      <h2>Matrículas</h2>
      <?php
        $rows = $conn->query(
          "SELECT m.IdAluno, m.Nome, m.IdCurso, m.DataNascimento, m.Morada, c.Curso
           FROM matriculas m
           JOIN cursos c ON c.IdCurso = m.IdCurso
           ORDER BY m.IdAluno DESC"
        );
      ?>
      <table>
        <tr>
          <th>Nome</th>
          <th>Data de Nascimento</th>
          <th>Morada</th>
          <th>Curso</th>
          <th>Foto</th>
          <th>Ações</th>
        </tr>
        <?php while ($row = $rows->fetch_assoc()): ?>
          <tr>
            <td><?php echo e($row['Nome']); ?></td>
            <td><?php echo e(formatDatePt($row['DataNascimento'] ?? '')); ?></td>
            <td><?php echo e($row['Morada'] ?? ''); ?></td>
            <td><?php echo e($row['Curso']); ?></td>
            <td>
              <img class="aluno-foto" src="?table=matriculas&action=foto&id_aluno=<?php echo e($row['IdAluno']); ?>" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span class="sem-foto">Sem foto</span>
            </td>
            <td class="actions">
              <a href="?table=matriculas&action=edit&id_aluno=<?php echo e($row['IdAluno']); ?>">Editar</a>
              <a href="?table=matriculas&action=ficha&id_aluno=<?php echo e($row['IdAluno']); ?>">Ver ficha</a>
              <a href="?table=matriculas&action=ver_disciplinas&id_aluno=<?php echo e($row['IdAluno']); ?>">Ver disciplinas</a>
              <form class="inline" method="post" onsubmit="return confirm('Remover matrícula?');">
                <input type="hidden" name="table" value="matriculas">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="IdAluno" value="<?php echo e($row['IdAluno']); ?>">
                <button type="submit">Excluir</button>
              </form>
            </td>
          </tr>
        <?php endwhile; ?>
      </table>

      <div class="form-box">
        <h3><?php echo $editData ? 'Editar Matrícula' : 'Nova Matrícula'; ?></h3>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="table" value="matriculas">
          <input type="hidden" name="action" value="<?php echo $editData ? 'update' : 'create'; ?>">
          <?php if ($editData): ?>
            <input type="hidden" name="IdAluno" value="<?php echo e($editData['IdAluno']); ?>">
          <?php endif; ?>

          <label>Nome</label><br>
          <input type="text" name="Nome" maxlength="25" required value="<?php echo e($editData['Nome'] ?? ''); ?>"><br>

          <label>Curso</label><br>
          <select name="IdCurso" required>
            <option value="">Selecione</option>
            <?php foreach ($cursosLookup as $item): ?>
              <option value="<?php echo e($item['IdCurso']); ?>" <?php echo (($editData['IdCurso'] ?? '') == $item['IdCurso']) ? 'selected' : ''; ?>>
                <?php echo e($item['Curso']); ?>
              </option>
            <?php endforeach; ?>
          </select><br>

          <label>Data de nascimento</label><br>
          <input type="date" name="DataNascimento" required max="<?php echo e($dataMaximaNascimento); ?>" value="<?php echo e($editData['DataNascimento'] ?? ''); ?>"><br>

          <label>Morada</label><br>
          <input type="text" name="Morada" maxlength="255" required value="<?php echo e($editData['Morada'] ?? ''); ?>"><br>

          <label>Foto</label><br>
          <input type="file" name="Foto" accept="image/*" <?php echo $editData ? '' : 'required'; ?>><br>

          <button type="submit"><?php echo $editData ? 'Atualizar' : 'Criar'; ?></button>
          <?php if ($editData): ?>
            <a class="cancel-link" href="?table=matriculas">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
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