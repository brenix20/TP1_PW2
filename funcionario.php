<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (empty($_SESSION['utilizador_autenticado'])) {
  header('Location: login.php');
  exit;
}

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'ipcavnf';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
  die('Connection failed: ' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

function e($value)
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function normalizePerfil($perfil)
{
  $perfil = strtolower(trim((string)$perfil));
  return strtr($perfil, [
    'á' => 'a',
    'à' => 'a',
    'â' => 'a',
    'ã' => 'a',
    'é' => 'e',
    'ê' => 'e',
    'í' => 'i',
    'ó' => 'o',
    'ô' => 'o',
    'õ' => 'o',
    'ú' => 'u',
    'ç' => 'c',
  ]);
}

function redirectWithMessage($section, $type, $message)
{
  $section = urlencode((string)$section);
  $type = urlencode((string)$type);
  $message = urlencode((string)$message);
  header("Location: funcionario.php?section={$section}&type={$type}&message={$message}");
  exit;
}

function anoLetivoAtual()
{
  $anoAtual = (int)date('Y');
  $mesAtual = (int)date('n');

  if ($mesAtual >= 9) {
    return $anoAtual . '/' . ($anoAtual + 1);
  }

  return ($anoAtual - 1) . '/' . $anoAtual;
}

function ensureFuncionarioSchema(mysqli $conn)
{
  $sqlPedidos = "
    CREATE TABLE IF NOT EXISTS pedidos_matricula (
      IdPedido INT AUTO_INCREMENT PRIMARY KEY,
      NomeCandidato VARCHAR(120) NOT NULL,
      Email VARCHAR(150) NULL,
      IdCurso INT NULL,
      Observacoes VARCHAR(255) NULL,
      Estado ENUM('Pendente', 'Validado', 'Rejeitado') NOT NULL DEFAULT 'Pendente',
      ObservacaoDecisao VARCHAR(255) NULL,
      DecididoPor VARCHAR(80) NULL,
      DataPedido DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      DataDecisao DATETIME NULL,
      INDEX idx_pedido_estado (Estado),
      INDEX idx_pedido_curso (IdCurso)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";

  $sqlNotas = "
    CREATE TABLE IF NOT EXISTS notas_avaliacao (
      IdNota INT AUTO_INCREMENT PRIMARY KEY,
      IdAluno INT NOT NULL,
      IdDisciplina INT NOT NULL,
      Epoca VARCHAR(20) NOT NULL DEFAULT 'Normal',
      AnoLetivo VARCHAR(9) NOT NULL,
      Nota DECIMAL(4,2) NOT NULL,
      Observacoes VARCHAR(255) NULL,
      AtualizadoPor VARCHAR(80) NULL,
      AtualizadoEm DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_nota (IdAluno, IdDisciplina, Epoca, AnoLetivo),
      INDEX idx_nota_disciplina (IdDisciplina),
      INDEX idx_nota_aluno (IdAluno)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ";

  return $conn->query($sqlPedidos) && $conn->query($sqlNotas);
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
    $result->close();
  }

  return $items;
}

$perfilAtual = (string)($_SESSION['utilizador_perfil'] ?? '');
$perfilNormalizado = normalizePerfil($perfilAtual);
$isGestor = $perfilNormalizado === 'gestor';
$isFuncionario = $perfilNormalizado === 'funcionario';

if (!$isFuncionario && !$isGestor) {
  header('Location: disciplinas.php?type=error&message=' . urlencode('Acesso reservado ao perfil de funcionario.'));
  exit;
}

$schemaReady = ensureFuncionarioSchema($conn);
if (!$schemaReady) {
  $conn->close();
  die('Nao foi possivel preparar as tabelas de apoio para funcionario.');
}

$section = $_GET['section'] ?? 'pedidos';
$allowedSections = ['pedidos', 'notas', 'pautas'];
if (!in_array($section, $allowedSections, true)) {
  $section = 'pedidos';
}

$type = (($_GET['type'] ?? '') === 'success') ? 'success' : 'error';
$message = trim((string)($_GET['message'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postAction = $_POST['post_action'] ?? '';

  if ($postAction === 'decidir_pedido') {
    $idPedido = (int)($_POST['IdPedido'] ?? 0);
    $decisao = (string)($_POST['decisao'] ?? '');
    $observacao = trim((string)($_POST['observacao_decisao'] ?? ''));

    if ($idPedido <= 0 || !in_array($decisao, ['validar', 'rejeitar'], true)) {
      redirectWithMessage('pedidos', 'error', 'Pedido invalido.');
    }

    if (strlen($observacao) > 255) {
      redirectWithMessage('pedidos', 'error', 'A observacao nao pode ultrapassar 255 caracteres.');
    }

    $novoEstado = $decisao === 'validar' ? 'Validado' : 'Rejeitado';
    $utilizadorAtual = (string)($_SESSION['utilizador_nome'] ?? 'funcionario');

    $stmt = $conn->prepare(
      "UPDATE pedidos_matricula
       SET Estado = ?, ObservacaoDecisao = ?, DecididoPor = ?, DataDecisao = NOW()
       WHERE IdPedido = ? AND Estado = 'Pendente'"
    );
    $stmt->bind_param('sssi', $novoEstado, $observacao, $utilizadorAtual, $idPedido);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected > 0) {
      $msg = $novoEstado === 'Validado' ? 'Pedido validado com sucesso.' : 'Pedido rejeitado com sucesso.';
      redirectWithMessage('pedidos', 'success', $msg);
    }

    redirectWithMessage('pedidos', 'error', 'Nao foi possivel atualizar o pedido (pode ja estar decidido).');
  }

  if ($postAction === 'guardar_nota') {
    $idNota = (int)($_POST['IdNota'] ?? 0);
    $idAluno = (int)($_POST['IdAluno'] ?? 0);
    $idDisciplina = (int)($_POST['IdDisciplina'] ?? 0);
    $epoca = trim((string)($_POST['Epoca'] ?? 'Normal'));
    $anoLetivo = trim((string)($_POST['AnoLetivo'] ?? ''));
    $notaRaw = str_replace(',', '.', trim((string)($_POST['Nota'] ?? '')));
    $observacoes = trim((string)($_POST['Observacoes'] ?? ''));

    if ($idAluno <= 0 || $idDisciplina <= 0 || $anoLetivo === '' || $notaRaw === '') {
      redirectWithMessage('notas', 'error', 'Aluno, disciplina, ano letivo e nota sao obrigatorios.');
    }

    if (!in_array($epoca, ['Normal', 'Recurso', 'Especial'], true)) {
      redirectWithMessage('notas', 'error', 'Epoca invalida.');
    }

    if (!preg_match('/^\d{4}\/\d{4}$/', $anoLetivo)) {
      redirectWithMessage('notas', 'error', 'Ano letivo invalido. Usa o formato AAAA/AAAA.');
    }

    $anos = explode('/', $anoLetivo);
    if ((int)$anos[1] !== ((int)$anos[0] + 1)) {
      redirectWithMessage('notas', 'error', 'Ano letivo invalido. O segundo ano deve ser o ano seguinte.');
    }

    if (!is_numeric($notaRaw)) {
      redirectWithMessage('notas', 'error', 'Nota invalida.');
    }

    $nota = (float)$notaRaw;
    if ($nota < 0 || $nota > 20) {
      redirectWithMessage('notas', 'error', 'A nota deve estar entre 0 e 20.');
    }

    if (strlen($observacoes) > 255) {
      redirectWithMessage('notas', 'error', 'As observacoes nao podem ultrapassar 255 caracteres.');
    }

    $utilizadorAtual = (string)($_SESSION['utilizador_nome'] ?? 'funcionario');

    if ($idNota > 0) {
      $stmt = $conn->prepare(
        'UPDATE notas_avaliacao
         SET IdAluno = ?, IdDisciplina = ?, Epoca = ?, AnoLetivo = ?, Nota = ?, Observacoes = ?, AtualizadoPor = ?
         WHERE IdNota = ?'
      );
      $stmt->bind_param('iissdssi', $idAluno, $idDisciplina, $epoca, $anoLetivo, $nota, $observacoes, $utilizadorAtual, $idNota);
      $ok = $stmt->execute();
      $erroCodigo = (int)$stmt->errno;
      $stmt->close();

      if ($ok) {
        redirectWithMessage('notas', 'success', 'Nota atualizada com sucesso.');
      }

      if ($erroCodigo === 1062) {
        redirectWithMessage('notas', 'error', 'Ja existe uma nota para esse aluno/disciplina/epoca/ano letivo.');
      }

      redirectWithMessage('notas', 'error', 'Erro ao atualizar nota.');
    }

    $stmt = $conn->prepare(
      'INSERT INTO notas_avaliacao (IdAluno, IdDisciplina, Epoca, AnoLetivo, Nota, Observacoes, AtualizadoPor)
       VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissdss', $idAluno, $idDisciplina, $epoca, $anoLetivo, $nota, $observacoes, $utilizadorAtual);
    $ok = $stmt->execute();
    $erroCodigo = (int)$stmt->errno;
    $stmt->close();

    if ($ok) {
      redirectWithMessage('notas', 'success', 'Nota registada com sucesso.');
    }

    if ($erroCodigo === 1062) {
      redirectWithMessage('notas', 'error', 'Ja existe uma nota para esse aluno/disciplina/epoca/ano letivo.');
    }

    redirectWithMessage('notas', 'error', 'Erro ao registar nota.');
  }
}

$cursos = fetchLookup($conn, 'cursos', 'IdCurso', 'Curso');
$disciplinas = fetchLookup($conn, 'disciplina', 'IdDisciplina', 'Disciplina');

$alunos = [];
$resultAlunos = $conn->query('SELECT IdAluno, Nome FROM matriculas ORDER BY Nome');
if ($resultAlunos) {
  while ($rowAluno = $resultAlunos->fetch_assoc()) {
    $alunos[] = $rowAluno;
  }
  $resultAlunos->close();
}

$mapaDisciplinas = [];
foreach ($disciplinas as $itemDisciplina) {
  $mapaDisciplinas[(int)$itemDisciplina['IdDisciplina']] = (string)$itemDisciplina['Disciplina'];
}

$notaEdit = null;
$editId = (int)($_GET['id_nota'] ?? 0);
$viewAction = $_GET['action'] ?? 'list';
if ($section === 'notas' && $viewAction === 'edit' && $editId > 0) {
  $stmtEdit = $conn->prepare('SELECT * FROM notas_avaliacao WHERE IdNota = ? LIMIT 1');
  $stmtEdit->bind_param('i', $editId);
  $stmtEdit->execute();
  $resEdit = $stmtEdit->get_result();
  $notaEdit = $resEdit ? $resEdit->fetch_assoc() : null;
  $stmtEdit->close();
}

$pedidosEstado = $_GET['estado'] ?? 'todos';
if (!in_array($pedidosEstado, ['todos', 'Pendente', 'Validado', 'Rejeitado'], true)) {
  $pedidosEstado = 'todos';
}
$pedidosQ = trim((string)($_GET['q'] ?? ''));

$sqlPedidos =
  'SELECT pm.IdPedido, pm.NomeCandidato, pm.Email, pm.Estado, pm.Observacoes, pm.ObservacaoDecisao, pm.DecididoPor, pm.DataPedido, pm.DataDecisao, c.Curso
   FROM pedidos_matricula pm
   LEFT JOIN cursos c ON c.IdCurso = pm.IdCurso
   WHERE 1 = 1';

if ($pedidosEstado !== 'todos') {
  $estadoEscaped = $conn->real_escape_string($pedidosEstado);
  $sqlPedidos .= " AND pm.Estado = '{$estadoEscaped}'";
}

if ($pedidosQ !== '') {
  $qEscaped = $conn->real_escape_string('%' . $pedidosQ . '%');
  $sqlPedidos .= " AND (pm.NomeCandidato LIKE '{$qEscaped}' OR CAST(pm.IdPedido AS CHAR) LIKE '{$qEscaped}' OR IFNULL(c.Curso, '') LIKE '{$qEscaped}')";
}

$sqlPedidos .= ' ORDER BY pm.DataPedido DESC';

$rowsPedidos = [];
$resultPedidos = $conn->query($sqlPedidos);
if ($resultPedidos) {
  while ($rowPedido = $resultPedidos->fetch_assoc()) {
    $rowsPedidos[] = $rowPedido;
  }
  $resultPedidos->close();
}

$notasQ = trim((string)($_GET['q_nota'] ?? ''));
$notasDisciplinaFiltro = (int)($_GET['f_disciplina'] ?? 0);

$sqlNotas =
  'SELECT n.IdNota, n.IdAluno, n.IdDisciplina, n.Epoca, n.AnoLetivo, n.Nota, n.Observacoes, n.AtualizadoEm,
          m.Nome AS NomeAluno, d.Disciplina, c.Curso
   FROM notas_avaliacao n
   JOIN matriculas m ON m.IdAluno = n.IdAluno
   JOIN disciplina d ON d.IdDisciplina = n.IdDisciplina
   LEFT JOIN cursos c ON c.IdCurso = m.IdCurso
   WHERE 1 = 1';

if ($notasDisciplinaFiltro > 0) {
  $sqlNotas .= ' AND n.IdDisciplina = ' . $notasDisciplinaFiltro;
}

if ($notasQ !== '') {
  $qNotaEscaped = $conn->real_escape_string('%' . $notasQ . '%');
  $sqlNotas .= " AND (m.Nome LIKE '{$qNotaEscaped}' OR CAST(n.IdAluno AS CHAR) LIKE '{$qNotaEscaped}' OR d.Disciplina LIKE '{$qNotaEscaped}')";
}

$sqlNotas .= ' ORDER BY n.AtualizadoEm DESC';

$rowsNotas = [];
$resultNotas = $conn->query($sqlNotas);
if ($resultNotas) {
  while ($rowNota = $resultNotas->fetch_assoc()) {
    $rowsNotas[] = $rowNota;
  }
  $resultNotas->close();
}

$pautaDisciplinaId = (int)($_GET['disciplina_id'] ?? 0);
$pautaEpoca = trim((string)($_GET['epoca'] ?? 'Normal'));
$pautaAnoLetivo = trim((string)($_GET['ano_letivo'] ?? anoLetivoAtual()));

if (!in_array($pautaEpoca, ['Normal', 'Recurso', 'Especial'], true)) {
  $pautaEpoca = 'Normal';
}

if (!preg_match('/^\d{4}\/\d{4}$/', $pautaAnoLetivo)) {
  $pautaAnoLetivo = anoLetivoAtual();
}

$rowsPauta = [];
if ($section === 'pautas' && $pautaDisciplinaId > 0) {
  $stmtPauta = $conn->prepare(
    'SELECT n.IdAluno, m.Nome AS NomeAluno, d.Disciplina, d.Sigla, c.Curso, n.Nota, n.Epoca, n.AnoLetivo,
            CASE WHEN n.Nota >= 9.5 THEN "Aprovado" ELSE "Reprovado" END AS Resultado
     FROM notas_avaliacao n
     JOIN matriculas m ON m.IdAluno = n.IdAluno
     JOIN disciplina d ON d.IdDisciplina = n.IdDisciplina
     LEFT JOIN cursos c ON c.IdCurso = m.IdCurso
     WHERE n.IdDisciplina = ? AND n.Epoca = ? AND n.AnoLetivo = ?
     ORDER BY m.Nome ASC'
  );
  $stmtPauta->bind_param('iss', $pautaDisciplinaId, $pautaEpoca, $pautaAnoLetivo);
  $stmtPauta->execute();
  $resPauta = $stmtPauta->get_result();
  while ($resPauta && ($rowPauta = $resPauta->fetch_assoc())) {
    $rowsPauta[] = $rowPauta;
  }
  $stmtPauta->close();
}

$stylesVersion = (string)(@filemtime(__DIR__ . '/styles.css') ?: time());
$stylesHref = 'styles.css?v=' . rawurlencode($stylesVersion);
$printMode = $section === 'pautas' && (($_GET['print'] ?? '') === '1');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Acoes de Funcionario - IPCAVNF</title>
  <link rel="stylesheet" href="<?php echo e($stylesHref); ?>">
  <style>
    .status-pill {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 800;
      border: 1px solid transparent;
    }

    .status-pendente {
      color: #805b00;
      background: #fff7dd;
      border-color: #f4ddb0;
    }

    .status-validado {
      color: #11643f;
      background: #e9faef;
      border-color: #c4ebd3;
    }

    .status-rejeitado {
      color: #8d1d34;
      background: #ffeef2;
      border-color: #f6c8d3;
    }

    .decision-form {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      align-items: center;
    }

    .decision-form input[type="text"] {
      max-width: 220px;
      margin: 0;
      padding: 7px 9px;
      font-size: 12px;
    }

    .decision-form button {
      font-size: 12px;
      padding: 7px 10px;
      box-shadow: none;
    }

    .decision-form .btn-reject {
      background: linear-gradient(135deg, #d64563 0%, #b4233c 100%);
    }

    .stats-row {
      margin: 10px 0 0;
      color: #2b475d;
      font-size: 13px;
      font-weight: 700;
    }

    .print-header {
      margin-bottom: 14px;
      border-bottom: 1px solid #d7e2ee;
      padding-bottom: 10px;
    }

    .print-header h2 {
      margin: 0;
    }
  </style>
</head>
<body class="app-page<?php echo $printMode ? ' document-page' : ''; ?>">
<?php if ($printMode): ?>
  <div class="document-shell">
    <div class="print-header">
      <h2>Pauta de Avaliacao</h2>
      <p class="subtitle">
        Disciplina: <strong><?php echo e($mapaDisciplinas[$pautaDisciplinaId] ?? 'N/A'); ?></strong>
        | Epoca: <strong><?php echo e($pautaEpoca); ?></strong>
        | Ano letivo: <strong><?php echo e($pautaAnoLetivo); ?></strong>
      </p>
    </div>

    <table>
      <tr>
        <th>ID Aluno</th>
        <th>Aluno</th>
        <th>Curso</th>
        <th>Nota</th>
        <th>Resultado</th>
      </tr>
      <?php foreach ($rowsPauta as $rowPauta): ?>
        <tr>
          <td><?php echo e($rowPauta['IdAluno']); ?></td>
          <td><?php echo e($rowPauta['NomeAluno']); ?></td>
          <td><?php echo e($rowPauta['Curso'] ?? ''); ?></td>
          <td><?php echo e(number_format((float)$rowPauta['Nota'], 1)); ?></td>
          <td><?php echo e($rowPauta['Resultado']); ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($rowsPauta)): ?>
        <tr>
          <td colspan="5">Sem dados para os filtros selecionados.</td>
        </tr>
      <?php endif; ?>
    </table>
  </div>
  <script>
    window.print();
  </script>
<?php else: ?>
  <div class="app-shell">
    <div class="top-bar">
      <div>
        <h1>Area do Funcionario</h1>
        <p class="subtitle">Consulta e decisao de pedidos, registo de notas e geracao de pautas.</p>
      </div>
      <p class="user-meta">
        Utilizador: <strong><?php echo e($_SESSION['utilizador_nome'] ?? ''); ?></strong>
        <?php if (!empty($_SESSION['utilizador_perfil'])): ?>
          (<?php echo e($_SESSION['utilizador_perfil']); ?>)
        <?php endif; ?>
        | <a class="section-link" href="disciplinas.php">Voltar ao painel</a>
        | <a class="logout-link" href="logout.php">Terminar sessao</a>
      </p>
    </div>

    <nav>
      <a class="<?php echo $section === 'pedidos' ? 'active' : ''; ?>" href="?section=pedidos">Pedidos de Matricula</a>
      <a class="<?php echo $section === 'notas' ? 'active' : ''; ?>" href="?section=notas">Registo de Notas</a>
      <a class="<?php echo $section === 'pautas' ? 'active' : ''; ?>" href="?section=pautas">Pautas de Avaliacao</a>
    </nav>

    <?php if ($message !== ''): ?>
      <div class="message <?php echo e($type); ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($section === 'pedidos'): ?>
      <h2>Pedidos de Matricula</h2>

      <div class="form-box">
        <h3>Filtros</h3>
        <form method="get" class="filters-row">
          <input type="hidden" name="section" value="pedidos">

          <div>
            <label for="estado">Estado</label><br>
            <select id="estado" name="estado">
              <option value="todos" <?php echo $pedidosEstado === 'todos' ? 'selected' : ''; ?>>Todos</option>
              <option value="Pendente" <?php echo $pedidosEstado === 'Pendente' ? 'selected' : ''; ?>>Pendente</option>
              <option value="Validado" <?php echo $pedidosEstado === 'Validado' ? 'selected' : ''; ?>>Validado</option>
              <option value="Rejeitado" <?php echo $pedidosEstado === 'Rejeitado' ? 'selected' : ''; ?>>Rejeitado</option>
            </select>
          </div>

          <div>
            <label for="q">Pesquisar</label><br>
            <input id="q" type="text" name="q" placeholder="ID, nome ou curso" value="<?php echo e($pedidosQ); ?>">
          </div>

          <div>
            <button type="submit">Aplicar</button>
            <a class="cancel-link" href="?section=pedidos">Limpar</a>
          </div>
        </form>
      </div>

      <p class="stats-row">Total de pedidos: <?php echo e(count($rowsPedidos)); ?></p>

      <table>
        <tr>
          <th>ID</th>
          <th>Candidato</th>
          <th>Email</th>
          <th>Curso</th>
          <th>Data pedido</th>
          <th>Estado</th>
          <th>Acao</th>
        </tr>
        <?php foreach ($rowsPedidos as $rowPedido): ?>
          <?php
            $estadoAtual = (string)($rowPedido['Estado'] ?? 'Pendente');
            $statusClass = strtolower($estadoAtual);
            $statusClass = $statusClass === 'validado' ? 'status-validado' : ($statusClass === 'rejeitado' ? 'status-rejeitado' : 'status-pendente');
          ?>
          <tr>
            <td><?php echo e($rowPedido['IdPedido']); ?></td>
            <td>
              <strong><?php echo e($rowPedido['NomeCandidato']); ?></strong><br>
              <small><?php echo e($rowPedido['Observacoes'] ?? ''); ?></small>
            </td>
            <td><?php echo e($rowPedido['Email'] ?? ''); ?></td>
            <td><?php echo e($rowPedido['Curso'] ?? 'Sem curso'); ?></td>
            <td><?php echo e($rowPedido['DataPedido']); ?></td>
            <td><span class="status-pill <?php echo e($statusClass); ?>"><?php echo e($estadoAtual); ?></span></td>
            <td>
              <?php if ($estadoAtual === 'Pendente'): ?>
                <form method="post" class="decision-form">
                  <input type="hidden" name="post_action" value="decidir_pedido">
                  <input type="hidden" name="IdPedido" value="<?php echo e($rowPedido['IdPedido']); ?>">
                  <input type="text" name="observacao_decisao" maxlength="255" placeholder="Obs. decisao (opcional)">
                  <button type="submit" name="decisao" value="validar">Validar</button>
                  <button class="btn-reject" type="submit" name="decisao" value="rejeitar">Rejeitar</button>
                </form>
              <?php else: ?>
                <small>
                  <?php echo e($rowPedido['DecididoPor'] ?? '-'); ?>
                  <?php if (!empty($rowPedido['ObservacaoDecisao'])): ?>
                    | <?php echo e($rowPedido['ObservacaoDecisao']); ?>
                  <?php endif; ?>
                </small>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rowsPedidos)): ?>
          <tr>
            <td colspan="7">Nao existem pedidos para os filtros selecionados.</td>
          </tr>
        <?php endif; ?>
      </table>
    <?php endif; ?>

    <?php if ($section === 'notas'): ?>
      <h2>Registo e Edicao de Notas</h2>

      <div class="form-box">
        <h3><?php echo $notaEdit ? 'Editar nota' : 'Registar nova nota'; ?></h3>
        <form method="post">
          <input type="hidden" name="post_action" value="guardar_nota">
          <input type="hidden" name="IdNota" value="<?php echo e($notaEdit['IdNota'] ?? 0); ?>">

          <label for="IdAluno">Aluno</label><br>
          <select id="IdAluno" name="IdAluno" required>
            <option value="">Selecionar aluno</option>
            <?php foreach ($alunos as $aluno): ?>
              <option value="<?php echo e($aluno['IdAluno']); ?>" <?php echo ((int)($notaEdit['IdAluno'] ?? 0) === (int)$aluno['IdAluno']) ? 'selected' : ''; ?>>
                <?php echo e($aluno['IdAluno'] . ' - ' . $aluno['Nome']); ?>
              </option>
            <?php endforeach; ?>
          </select><br>

          <label for="IdDisciplina">Disciplina</label><br>
          <select id="IdDisciplina" name="IdDisciplina" required>
            <option value="">Selecionar disciplina</option>
            <?php foreach ($disciplinas as $disciplina): ?>
              <option value="<?php echo e($disciplina['IdDisciplina']); ?>" <?php echo ((int)($notaEdit['IdDisciplina'] ?? 0) === (int)$disciplina['IdDisciplina']) ? 'selected' : ''; ?>>
                <?php echo e($disciplina['Disciplina']); ?>
              </option>
            <?php endforeach; ?>
          </select><br>

          <label for="Epoca">Epoca</label><br>
          <select id="Epoca" name="Epoca" required>
            <?php $epocaAtual = (string)($notaEdit['Epoca'] ?? 'Normal'); ?>
            <option value="Normal" <?php echo $epocaAtual === 'Normal' ? 'selected' : ''; ?>>Normal</option>
            <option value="Recurso" <?php echo $epocaAtual === 'Recurso' ? 'selected' : ''; ?>>Recurso</option>
            <option value="Especial" <?php echo $epocaAtual === 'Especial' ? 'selected' : ''; ?>>Especial</option>
          </select><br>

          <label for="AnoLetivo">Ano letivo</label><br>
          <input id="AnoLetivo" name="AnoLetivo" type="text" pattern="\d{4}/\d{4}" required value="<?php echo e($notaEdit['AnoLetivo'] ?? anoLetivoAtual()); ?>"><br>

          <label for="Nota">Nota (0-20)</label><br>
          <input id="Nota" name="Nota" type="number" min="0" max="20" step="0.1" required value="<?php echo e($notaEdit['Nota'] ?? ''); ?>"><br>

          <label for="Observacoes">Observacoes</label><br>
          <input id="Observacoes" name="Observacoes" type="text" maxlength="255" value="<?php echo e($notaEdit['Observacoes'] ?? ''); ?>"><br>

          <button type="submit"><?php echo $notaEdit ? 'Atualizar nota' : 'Registar nota'; ?></button>
          <?php if ($notaEdit): ?>
            <a class="cancel-link" href="?section=notas">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="form-box">
        <h3>Filtros de notas</h3>
        <form method="get" class="filters-row">
          <input type="hidden" name="section" value="notas">

          <div>
            <label for="f_disciplina">Disciplina</label><br>
            <select id="f_disciplina" name="f_disciplina">
              <option value="0">Todas</option>
              <?php foreach ($disciplinas as $disciplina): ?>
                <option value="<?php echo e($disciplina['IdDisciplina']); ?>" <?php echo $notasDisciplinaFiltro === (int)$disciplina['IdDisciplina'] ? 'selected' : ''; ?>>
                  <?php echo e($disciplina['Disciplina']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="q_nota">Pesquisar</label><br>
            <input id="q_nota" type="text" name="q_nota" placeholder="Aluno, ID ou disciplina" value="<?php echo e($notasQ); ?>">
          </div>

          <div>
            <button type="submit">Aplicar</button>
            <a class="cancel-link" href="?section=notas">Limpar</a>
          </div>
        </form>
      </div>

      <table>
        <tr>
          <th>Aluno</th>
          <th>Disciplina</th>
          <th>Curso</th>
          <th>Epoca</th>
          <th>Ano letivo</th>
          <th>Nota</th>
          <th>Resultado</th>
          <th>Acoes</th>
        </tr>
        <?php foreach ($rowsNotas as $rowNota): ?>
          <?php $resultado = ((float)$rowNota['Nota'] >= 9.5) ? 'Aprovado' : 'Reprovado'; ?>
          <tr>
            <td><?php echo e($rowNota['IdAluno'] . ' - ' . $rowNota['NomeAluno']); ?></td>
            <td><?php echo e($rowNota['Disciplina']); ?></td>
            <td><?php echo e($rowNota['Curso'] ?? ''); ?></td>
            <td><?php echo e($rowNota['Epoca']); ?></td>
            <td><?php echo e($rowNota['AnoLetivo']); ?></td>
            <td><?php echo e(number_format((float)$rowNota['Nota'], 1)); ?></td>
            <td><?php echo e($resultado); ?></td>
            <td class="actions">
              <a href="?section=notas&action=edit&id_nota=<?php echo e($rowNota['IdNota']); ?>">Editar</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rowsNotas)): ?>
          <tr>
            <td colspan="8">Nao existem notas para os filtros selecionados.</td>
          </tr>
        <?php endif; ?>
      </table>
    <?php endif; ?>

    <?php if ($section === 'pautas'): ?>
      <h2>Gerar Pauta de Avaliacao</h2>

      <div class="form-box">
        <h3>Filtros da pauta</h3>
        <form method="get" class="filters-row">
          <input type="hidden" name="section" value="pautas">

          <div>
            <label for="disciplina_id">Disciplina</label><br>
            <select id="disciplina_id" name="disciplina_id" required>
              <option value="0">Selecionar disciplina</option>
              <?php foreach ($disciplinas as $disciplina): ?>
                <option value="<?php echo e($disciplina['IdDisciplina']); ?>" <?php echo $pautaDisciplinaId === (int)$disciplina['IdDisciplina'] ? 'selected' : ''; ?>>
                  <?php echo e($disciplina['Disciplina']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label for="epoca">Epoca</label><br>
            <select id="epoca" name="epoca">
              <option value="Normal" <?php echo $pautaEpoca === 'Normal' ? 'selected' : ''; ?>>Normal</option>
              <option value="Recurso" <?php echo $pautaEpoca === 'Recurso' ? 'selected' : ''; ?>>Recurso</option>
              <option value="Especial" <?php echo $pautaEpoca === 'Especial' ? 'selected' : ''; ?>>Especial</option>
            </select>
          </div>

          <div>
            <label for="ano_letivo">Ano letivo</label><br>
            <input id="ano_letivo" type="text" name="ano_letivo" pattern="\d{4}/\d{4}" value="<?php echo e($pautaAnoLetivo); ?>" required>
          </div>

          <div>
            <button type="submit">Gerar pauta</button>
          </div>
        </form>
      </div>

      <?php if ($pautaDisciplinaId > 0): ?>
        <p class="stats-row">Disciplina: <?php echo e($mapaDisciplinas[$pautaDisciplinaId] ?? 'N/A'); ?> | Registos: <?php echo e(count($rowsPauta)); ?></p>

        <p>
          <a class="section-link" target="_blank" rel="noopener" href="?section=pautas&disciplina_id=<?php echo e($pautaDisciplinaId); ?>&epoca=<?php echo e(urlencode($pautaEpoca)); ?>&ano_letivo=<?php echo e(urlencode($pautaAnoLetivo)); ?>&print=1">Imprimir pauta</a>
        </p>

        <table>
          <tr>
            <th>ID Aluno</th>
            <th>Aluno</th>
            <th>Curso</th>
            <th>Nota</th>
            <th>Resultado</th>
          </tr>
          <?php foreach ($rowsPauta as $rowPauta): ?>
            <tr>
              <td><?php echo e($rowPauta['IdAluno']); ?></td>
              <td><?php echo e($rowPauta['NomeAluno']); ?></td>
              <td><?php echo e($rowPauta['Curso'] ?? ''); ?></td>
              <td><?php echo e(number_format((float)$rowPauta['Nota'], 1)); ?></td>
              <td><?php echo e($rowPauta['Resultado']); ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($rowsPauta)): ?>
            <tr>
              <td colspan="5">Sem notas para os filtros selecionados.</td>
            </tr>
          <?php endif; ?>
        </table>
      <?php else: ?>
        <div class="form-box">
          <p>Seleciona uma disciplina para gerar a pauta.</p>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
</body>
</html>
<?php
$conn->close();
?>