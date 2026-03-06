<?php
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

$allowedTables = [
  'disciplina' => true,
  'cursos' => true,
  'matriculas' => true,
  'plano_estudos' => true,
];

$table = $_GET['table'] ?? 'disciplina';
if (!isset($allowedTables[$table])) {
  $table = 'disciplina';
}

$action = $_GET['action'] ?? 'list';

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
      $uploadError = '';
      $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
      if ($fotoBlob === false) {
        redirectWithMessage('matriculas', 'error', $uploadError);
      }

      $stmt = $conn->prepare("INSERT INTO matriculas (Nome, IdCurso, Foto) VALUES (?, ?, ?)");
      $stmt->bind_param('sis', $nome, $idCurso, $fotoBlob);
      $ok = $stmt->execute();
      $stmt->close();
      redirectWithMessage('matriculas', $ok ? 'success' : 'error', $ok ? 'Matrícula criada com sucesso.' : 'Erro ao criar matrícula.');
    }

    if ($postAction === 'update') {
      $idAluno = (int)($_POST['IdAluno'] ?? 0);
      $nome = trim($_POST['Nome'] ?? '');
      $idCurso = (int)($_POST['IdCurso'] ?? 0);
      $uploadError = '';
      $fotoBlob = getUploadedImageBlob('Foto', $uploadError);
      if ($fotoBlob === false) {
        redirectWithMessage('matriculas', 'error', $uploadError);
      }

      if ($fotoBlob !== null) {
        $stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, IdCurso = ?, Foto = ? WHERE IdAluno = ?");
        $stmt->bind_param('sisi', $nome, $idCurso, $fotoBlob, $idAluno);
      } else {
        $stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, IdCurso = ? WHERE IdAluno = ?");
        $stmt->bind_param('sii', $nome, $idCurso, $idAluno);
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
  echo "<style>body{font-family:Arial,Helvetica,sans-serif;margin:24px}h1{text-align:center}table{width:100%;border-collapse:collapse}th,td{border:1px solid #333;padding:6px;text-align:left}thead{background:#eee}@media print{a#print-note{display:none}}</style>";
  echo "</head><body onload=\"window.print()\">";
  echo "<h1>Plano de Estudos</h1>";
  echo "<table><thead><tr><th>Curso</th><th>Disciplina</th></tr></thead><tbody>";
  while ($row = $rows->fetch_assoc()) {
    echo '<tr><td>' . htmlspecialchars($row['Curso'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($row['Disciplina'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
  }
  echo "</tbody></table></body></html>";
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
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; }
    nav a { margin-right: 12px; text-decoration: none; }
    table { border-collapse: collapse; width: 100%; margin-top: 16px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #f1f1f1; }
    .form-box { margin-top: 20px; padding: 12px; border: 1px solid #ddd; }
    .message { margin: 12px 0; padding: 10px; }
    .success { background: #e7f8e7; border: 1px solid #9ad19a; }
    .error { background: #fdeaea; border: 1px solid #e4a3a3; }
    .actions { display: flex; gap: 8px; }
    input, select { padding: 6px; margin: 6px 0; width: 100%; max-width: 360px; }
    button { padding: 6px 12px; cursor: pointer; }
    form.inline { display: inline; }
  </style>
</head>
<body>
  <h1>CRUD de Todas as Tabelas</h1>

  <nav>
    <a href="?table=disciplina">Disciplinas</a>
    <a href="?table=cursos">Cursos</a>
    <a href="?table=matriculas">Matrículas</a>
    <a href="?table=plano_estudos">Planos de Estudo</a>
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
        <th>Ações</th>
      </tr>
      <?php while ($row = $rows->fetch_assoc()): ?>
        <tr>
          <td><?php echo e($row['Disciplina']); ?></td>
          <td><?php echo e($row['Sigla']); ?></td>
          <td class="actions">
            <a href="?table=disciplina&action=edit&id=<?php echo e($row['IdDisciplina']); ?>">Editar</a>
            <form class="inline" method="post" onsubmit="return confirm('Remover disciplina?');">
              <input type="hidden" name="table" value="disciplina">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="IdDisciplina" value="<?php echo e($row['IdDisciplina']); ?>">
              <button type="submit">Excluir</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>

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
          <a href="?table=disciplina">Cancelar</a>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($table === 'cursos'): ?>
    <h2>Cursos</h2>
    <?php $rows = $conn->query("SELECT * FROM cursos ORDER BY IdCurso DESC"); ?>
    <table>
      <tr>
        <th>Curso</th>
        <th>Sigla</th>
        <th>Ações</th>
      </tr>
      <?php while ($row = $rows->fetch_assoc()): ?>
        <tr>
          <td><?php echo e($row['Curso']); ?></td>
          <td><?php echo e($row['Sigla']); ?></td>
          <td class="actions">
            <a href="?table=cursos&action=edit&id=<?php echo e($row['IdCurso']); ?>">Editar</a>
            <form class="inline" method="post" onsubmit="return confirm('Remover curso?');">
              <input type="hidden" name="table" value="cursos">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="IdCurso" value="<?php echo e($row['IdCurso']); ?>">
              <button type="submit">Excluir</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
    </table>

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
          <a href="?table=cursos">Cancelar</a>
        <?php endif; ?>
      </form>
    </div>
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
    <?php else: ?>
      <h2>Matrículas</h2>
      <?php
        $rows = $conn->query(
          "SELECT m.IdAluno, m.Nome, m.IdCurso, c.Curso
           FROM matriculas m
           JOIN cursos c ON c.IdCurso = m.IdCurso
           ORDER BY m.IdAluno DESC"
        );
      ?>
      <table>
        <tr>
          <th>Nome</th>
          <th>Curso</th>
          <th>Foto</th>
          <th>Ações</th>
        </tr>
        <?php while ($row = $rows->fetch_assoc()): ?>
          <tr>
            <td><?php echo e($row['Nome']); ?></td>
            <td><?php echo e($row['Curso']); ?></td>
            <td>
              <img src="?table=matriculas&action=foto&id_aluno=<?php echo e($row['IdAluno']); ?>" alt="Foto" style="max-width:60px; max-height:60px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span style="display:none;">Sem foto</span>
            </td>
            <td class="actions">
              <a href="?table=matriculas&action=edit&id_aluno=<?php echo e($row['IdAluno']); ?>">Editar</a>
              <a href="?table   =matriculas&action=ver_disciplinas&id_aluno=<?php echo e($row['IdAluno']); ?>">Ver disciplinas</a>
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

          <label>Foto</label><br>
          <input type="file" name="Foto" accept="image/*"><br>

          <button type="submit"><?php echo $editData ? 'Atualizar' : 'Criar'; ?></button>
          <?php if ($editData): ?>
            <a href="?table=matriculas">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($table === 'plano_estudos'): ?>
    <h2>Plano de Estudos</h2>
    <p><a href="?table=plano_estudos&action=print" target="_blank" rel="noopener">Exportar PDF</a></p>
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
          <a href="?table=plano_estudos">Cancelar</a>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>
</body>
</html>
<?php $conn->close(); ?>