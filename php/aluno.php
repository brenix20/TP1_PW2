<?php
define('APP_AREA', 'aluno');
require_once __DIR__ . '/common.php';

$matriculasSchemaReady = ensureMatriculasExtraFields($conn);
$pedidosSchemaReady = function_exists('ensurePedidosSchema') ? ensurePedidosSchema($conn) : false;

$dataMaximaNascimento = (new DateTimeImmutable('today'))->modify('-13 years')->format('Y-m-d');

$perfilAtual = strtolower(trim((string)($_SESSION['utilizador_perfil'] ?? '')));
$isGestor = $perfilAtual === 'gestor';
$isAluno = $perfilAtual === 'aluno';
$podeEditarDisciplinasCursos = $isGestor;

$appArea = defined('APP_AREA') ? (string)APP_AREA : '';
if ($appArea === 'aluno' && !$isAluno) {
	header('Location: index.php?type=error&message=' . urlencode('Acesso restrito à área de aluno.'));
	exit;
}

$alunoIdUtilizador = $isAluno ? (int)($_SESSION['utilizador_id'] ?? 0) : 0;
$alunoIdSessao = 0;
$alunoEstadoFicha = '';
$alunoEstadoFichaNormalizado = false;
$alunoPodeCriarPedido = false;
$alunoUltimoPedido = null;
$alunoEstadoPedido = '';
$alunoTemPedidoPendente = false;
$alunoTemPedidoAprovado = false;
$alunoMatriculaEfetivada = false;
if ($isAluno) {
	if ($alunoIdUtilizador > 0) {
		$stmtAlunoSessao = $conn->prepare("SELECT IdAluno, EstadoValidacao FROM matriculas WHERE IdAluno = ? LIMIT 1");
		$stmtAlunoSessao->bind_param('i', $alunoIdUtilizador);
		$stmtAlunoSessao->execute();
		$resultAlunoSessao = $stmtAlunoSessao->get_result();
		$alunoSessao = $resultAlunoSessao ? $resultAlunoSessao->fetch_assoc() : null;
		$stmtAlunoSessao->close();
		if ($alunoSessao) {
			$alunoIdSessao = $alunoIdUtilizador;
			$alunoEstadoFicha = (string)($alunoSessao['EstadoValidacao'] ?? '');
			$alunoEstadoFichaNormalizado = normalizarEstadoValidacao($alunoEstadoFicha);

			if ($pedidosSchemaReady) {
				$stmtPedidoAluno = $conn->prepare(
					"SELECT IdPedido, Estado, ObservacaoDecisao, DecididoPor, DataPedido, DataDecisao, IdCurso
					 FROM pedidos_matricula
					 WHERE IdAluno = ?
					 ORDER BY IdPedido DESC
					 LIMIT 1"
				);
				$stmtPedidoAluno->bind_param('i', $alunoIdSessao);
				$stmtPedidoAluno->execute();
				$resultPedidoAluno = $stmtPedidoAluno->get_result();
				$alunoUltimoPedido = $resultPedidoAluno ? $resultPedidoAluno->fetch_assoc() : null;
				$stmtPedidoAluno->close();

				$alunoEstadoPedido = (string)($alunoUltimoPedido['Estado'] ?? '');
				$alunoTemPedidoPendente = $alunoEstadoPedido === 'Pendente';
				$alunoTemPedidoAprovado = $alunoEstadoPedido === 'Aprovado';
			}

			$alunoPodeCriarPedido = ($alunoEstadoFichaNormalizado === 'Aprovada') && !$alunoTemPedidoPendente && !$alunoTemPedidoAprovado;
			$alunoMatriculaEfetivada = ($alunoEstadoFichaNormalizado === 'Aprovada') && $alunoTemPedidoAprovado;
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
	$allowedTables['pedidos'] = true;
}

$table = $_GET['table'] ?? ($isAluno ? 'matriculas' : 'disciplina');
if (!isset($allowedTables[$table])) {
	$table = 'disciplina';
}

$action = $_GET['action'] ?? 'list';
$allowedActions = $isGestor
	? ['list', 'edit', 'foto', 'ver_disciplinas', 'ficha', 'ficha_print', 'certificado_print', 'print']
	: ($isAluno ? ['list', 'ficha', 'ficha_print', 'certificado_print', 'foto', 'ficha_edit', 'minhas_disciplinas', 'minha_turma'] : ['list']);

if (!in_array($action, $allowedActions, true)) {
	$action = 'list';
}

if (!$matriculasSchemaReady && $table === 'matriculas') {
	redirectWithMessage('disciplina', 'error', 'Não foi possível preparar a tabela de matrículas. Contacta o administrador da base de dados.');
}

if ($isAluno && $table === 'matriculas' && $action === 'list') {
	$action = 'ficha';
}

if ($isAluno && $table === 'matriculas' && $action === 'minhas_disciplinas' && !$alunoMatriculaEfetivada) {
	redirectWithMessage('matriculas', 'error', 'A opção Minhas Disciplinas fica disponível apenas após o funcionário aprovar o teu pedido de matrícula.');
}

if ($isAluno && $table === 'matriculas' && $action === 'minha_turma' && !$alunoMatriculaEfetivada) {
	redirectWithMessage('matriculas', 'error', 'A opção Minha Turma fica disponível apenas após o funcionário aprovar o teu pedido de matrícula.');
}

if ($isAluno && $table === 'matriculas' && $action === 'ficha_print' && $alunoEstadoFichaNormalizado !== 'Aprovada') {
	redirectWithMessage('matriculas', 'error', 'Só podes imprimir a tua ficha quando o estado de validação estiver Aprovada.');
}

if ($isAluno && $table === 'matriculas' && $action === 'certificado_print' && !$alunoMatriculaEfetivada) {
	redirectWithMessage('matriculas', 'error', 'Só podes imprimir o teu certificado após o funcionário aprovar o teu pedido de matrícula.');
}

if ($isAluno && $table === 'pedidos' && !$pedidosSchemaReady) {
	redirectWithMessage('matriculas', 'error', 'Não foi possível preparar pedidos de matrícula. Contacta o administrador.');
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

	if (!csrfTokenIsValid($_POST['csrf_token'] ?? '')) {
		redirectWithMessage($postTable, 'error', 'Sessão expirada. Atualiza a página e tenta novamente.');
	}

	$alunoPodeAtualizarFicha = $isAluno && $postTable === 'matriculas' && $postAction === 'update_self';
	$alunoPodeCriarFicha = $isAluno && $postTable === 'matriculas' && $postAction === 'create_self';
	$alunoAExecutarPedido = $isAluno && $postTable === 'pedidos' && $postAction === 'create_self';
	if (!$podeEditarDisciplinasCursos && !$alunoPodeAtualizarFicha && !$alunoPodeCriarFicha) {
		if (!($alunoAExecutarPedido)) {
			redirectWithMessage($postTable, 'error', 'Apenas gestores podem editar disciplinas e cursos.');
		}
	}

	if (!in_array($postAction, ['create', 'update', 'delete', 'update_self', 'create_self', 'set_validation'], true)) {
		redirectWithMessage($postTable, 'error', 'Ação inválida.');
	}

	// Aluno: criar pedido de matrícula (pedidos_matricula) — trata aqui antes do bloco de matriculas
	if ($postTable === 'pedidos') {
		if ($postAction === 'create_self') {
			if (!$isAluno) {
				redirectWithMessage('pedidos', 'error', 'Apenas alunos podem submeter pedidos.');
			}

			if (!$pedidosSchemaReady) {
				redirectWithMessage('pedidos', 'error', 'Não foi possível preparar pedidos de matrícula. Contacta o administrador.');
			}

			// O aluno só pode submeter um pedido se tiver uma ficha existente e essa ficha estiver aceite
			if ($alunoIdSessao <= 0) {
				redirectWithMessage('pedidos', 'error', 'Tem de preencher a ficha de aluno antes de submeter um pedido de matrícula.');
			}

			$stmtFichaPedido = $conn->prepare('SELECT Nome, IdCurso, EstadoValidacao FROM matriculas WHERE IdAluno = ? LIMIT 1');
			$stmtFichaPedido->bind_param('i', $alunoIdSessao);
			$stmtFichaPedido->execute();
			$resultFichaPedido = $stmtFichaPedido->get_result();
			$fichaPedido = $resultFichaPedido ? $resultFichaPedido->fetch_assoc() : null;
			$stmtFichaPedido->close();

			if (!$fichaPedido) {
				redirectWithMessage('pedidos', 'error', 'Tem de preencher a ficha de aluno antes de submeter um pedido de matrícula.');
			}

			$estadoFichaPedido = normalizarEstadoValidacao((string)($fichaPedido['EstadoValidacao'] ?? ''));
			if ($estadoFichaPedido !== 'Aprovada') {
				redirectWithMessage('pedidos', 'error', 'A tua ficha precisa de ser aceite pelo gestor antes de solicitar matrícula.');
			}

			$stmtPedidoAtivo = $conn->prepare(
				"SELECT Estado
				 FROM pedidos_matricula
				 WHERE IdAluno = ?
				   AND Estado IN ('Pendente', 'Aprovado')
				 ORDER BY IdPedido DESC
				 LIMIT 1"
			);
			$stmtPedidoAtivo->bind_param('i', $alunoIdSessao);
			$stmtPedidoAtivo->execute();
			$resultPedidoAtivo = $stmtPedidoAtivo->get_result();
			$pedidoAtivo = $resultPedidoAtivo ? $resultPedidoAtivo->fetch_assoc() : null;
			$stmtPedidoAtivo->close();

			if ($pedidoAtivo) {
				$estadoAtivo = (string)($pedidoAtivo['Estado'] ?? '');
				if ($estadoAtivo === 'Pendente') {
					redirectWithMessage('pedidos', 'error', 'Já tens um pedido pendente. Aguarda a decisão do funcionário.');
				}
				if ($estadoAtivo === 'Aprovado') {
					redirectWithMessage('pedidos', 'success', 'A tua matrícula já foi aprovada pelo funcionário.');
				}
			}

			$nome = trim((string)($_POST['Nome'] ?? ($fichaPedido['Nome'] ?? $_SESSION['utilizador_nome'] ?? '')));
			$idCurso = (int)($_POST['IdCurso'] ?? ($fichaPedido['IdCurso'] ?? 0));

			if ($nome === '' || $idCurso <= 0) {
				redirectWithMessage('pedidos', 'error', 'Nome e curso são obrigatórios.');
			}

			if (function_exists('mb_strlen')) {
				if (mb_strlen($nome) > 120) {
					redirectWithMessage('pedidos', 'error', 'Alguns campos excedem o tamanho máximo permitido.');
				}
			} else {
				if (strlen($nome) > 120) {
					redirectWithMessage('pedidos', 'error', 'Alguns campos excedem o tamanho máximo permitido.');
				}
			}

			$stmt = $conn->prepare('INSERT INTO pedidos_matricula (IdAluno, NomeCandidato, IdCurso) VALUES (?, ?, ?)');
			$stmt->bind_param('isi', $alunoIdSessao, $nome, $idCurso);
			try {
				$ok = $stmt->execute();
			} catch (mysqli_sql_exception $ex) {
				$stmt->close();
				redirectWithMessage('pedidos', 'error', 'Erro ao submeter pedido.');
			}
			$stmt->close();
			redirectWithMessage('pedidos', $ok ? 'success' : 'error', $ok ? 'Pedido submetido com sucesso.' : 'Erro ao submeter pedido.');
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
				redirectWithMessage('matriculas', 'error', 'Nome, curso, data de nascimento, morada, email, contacto telefónico e foto são obrigatórios.');
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
			// Foto é obrigatória para criar a ficha
			if ($fotoBlob === null) {
				redirectWithMessage('matriculas', 'error', 'A foto é obrigatória.');
			}

			$estadoValidacao = 'Pendente';
			$stmt = $conn->prepare("INSERT INTO matriculas (IdAluno, Nome, IdCurso, DataNascimento, Morada, Email, Telefone, EstadoValidacao, Foto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$stmt->bind_param('isissssss', $idAluno, $nome, $idCurso, $dataNascimento, $morada, $email, $telefone, $estadoValidacao, $fotoBlob);

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

			// Accept name and birthdate on student update so rejected fichas can be corrected
			$nome = trim($_POST['Nome'] ?? '');
			$dataNascimento = validarDataNascimento($_POST['DataNascimento'] ?? '', $validationError);
			$idCurso = (int)($_POST['IdCurso'] ?? 0);
			$morada = trim($_POST['Morada'] ?? '');
			$email = validarEmail($_POST['Email'] ?? '', $validationError);
			$telefone = validarTelefone($_POST['Telefone'] ?? '', $validationError);

			if ($nome === '' || $dataNascimento === false || $idCurso <= 0 || $morada === '') {
				redirectWithMessage('matriculas', 'error', 'Nome, curso, data de nascimento e morada são obrigatórios.');
			}

			$nomeLen = function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome);
			if ($nomeLen > 25) {
				redirectWithMessage('matriculas', 'error', 'O nome não pode ter mais de 25 caracteres.');
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

			$estadoValidacao = 'Pendente';

			// Clear ObservacoesValidacao when student resubmits
			if ($fotoBlob !== null) {
				$stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, DataNascimento = ?, IdCurso = ?, Morada = ?, Email = ?, Telefone = ?, EstadoValidacao = ?, ObservacoesValidacao = NULL, Foto = ? WHERE IdAluno = ?");
				$stmt->bind_param('ssisssssi', $nome, $dataNascimento, $idCurso, $morada, $email, $telefone, $estadoValidacao, $fotoBlob, $alunoIdSessao);
			} else {
				$stmt = $conn->prepare("UPDATE matriculas SET Nome = ?, DataNascimento = ?, IdCurso = ?, Morada = ?, Email = ?, Telefone = ?, EstadoValidacao = ?, ObservacoesValidacao = NULL WHERE IdAluno = ?");
				$stmt->bind_param('ssissssi', $nome, $dataNascimento, $idCurso, $morada, $email, $telefone, $estadoValidacao, $alunoIdSessao);
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

			// pedidos handling moved earlier to avoid duplication
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
$turmaColegas = [];
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

if ($table === 'matriculas' && in_array($action, ['ficha', 'ficha_print', 'certificado_print', 'ficha_edit', 'minhas_disciplinas', 'minha_turma'], true)) {
	if ($isAluno) {
		$alunoIdSelecionado = $alunoIdSessao;
	} else {
		$alunoIdSelecionado = (int)($_GET['id_aluno'] ?? 0);
	}

	$stmtFicha = $conn->prepare(
			"SELECT m.IdAluno, m.Nome, m.IdCurso, m.DataNascimento, m.Morada, m.Email, m.Telefone, m.EstadoValidacao, m.ObservacoesValidacao, m.ValidadoPor, m.DataValidacao, c.Curso, c.Sigla AS SiglaCurso
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

		if ($isAluno && $action === 'minha_turma') {
			$stmtTurma = $conn->prepare(
				"SELECT m.IdAluno, m.Nome
				 FROM matriculas m
				 LEFT JOIN (
				 	SELECT p.IdAluno, p.Estado
				 	FROM pedidos_matricula p
				 	JOIN (
				 		SELECT IdAluno, MAX(IdPedido) AS UltimoPedido
				 		FROM pedidos_matricula
				 		WHERE IdAluno IS NOT NULL
				 		GROUP BY IdAluno
				 	) ult ON ult.IdAluno = p.IdAluno AND ult.UltimoPedido = p.IdPedido
				 ) pedidoAtual ON pedidoAtual.IdAluno = m.IdAluno
				 WHERE m.IdCurso = ?
				   AND (
				     (m.EstadoValidacao = 'Aprovada' AND pedidoAtual.Estado = 'Aprovado')
				     OR CAST(m.IdAluno AS CHAR) LIKE '9900%'
				   )
				 ORDER BY CASE WHEN m.IdAluno = ? THEN 0 ELSE 1 END, m.Nome ASC"
			);

			if ($stmtTurma) {
				$idCursoTurma = (int)($fichaAluno['IdCurso'] ?? 0);
				$stmtTurma->bind_param('ii', $idCursoTurma, $alunoIdSessao);
				$stmtTurma->execute();
				$resultTurma = $stmtTurma->get_result();
				while ($resultTurma && ($rowTurma = $resultTurma->fetch_assoc())) {
					$turmaColegas[] = $rowTurma;
				}
				$stmtTurma->close();
			}
		}
	}
}

$disciplinasLookup = fetchLookup($conn, 'disciplina', 'IdDisciplina', 'Disciplina');
$cursosLookup = fetchLookup($conn, 'cursos', 'IdCurso', 'Curso');

// reuse the same printable views and student UI from portal.php
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
	<title>Área do Aluno - IPCAVNF</title>
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
	<link rel="stylesheet" href="<?php echo e($stylesHref); ?>">
</head>
<body class="app-page">
	<div class="app-shell">
	<div class="top-bar">
		<div>
			<h1>Área do Aluno</h1>
			<p class="subtitle">Consulta de Disciplinas, Cursos e da tua Ficha de Aluno</p>
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
		<a class="nav-link <?php echo ($table === 'matriculas' && in_array($action, ['ficha', 'ficha_print', 'certificado_print', 'ficha_edit'], true)) ? 'active' : ''; ?>" href="?table=matriculas&action=ficha"><?php echo $alunoMatriculaEfetivada ? 'Ficha de aluno/matrícula' : 'Minha Ficha'; ?></a>
		<?php if ($alunoEstadoFichaNormalizado === 'Aprovada'): ?>
			<a class="nav-link <?php echo $table === 'pedidos' ? 'active' : ''; ?>" href="?table=pedidos">Pedido de Matrícula</a>
		<?php endif; ?>
		<?php if ($alunoMatriculaEfetivada): ?>
			<a class="nav-link <?php echo ($table === 'matriculas' && $action === 'minhas_disciplinas') ? 'active' : ''; ?>" href="?table=matriculas&action=minhas_disciplinas">Minhas Disciplinas</a>
			<a class="nav-link <?php echo ($table === 'matriculas' && $action === 'minha_turma') ? 'active' : ''; ?>" href="?table=matriculas&action=minha_turma">Minha Turma</a>
		<?php endif; ?>
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
				</tr>
				<?php while ($row = $rows->fetch_assoc()): ?>
					<tr>
						<td><?php echo e($row['Disciplina']); ?></td>
						<td><?php echo e($row['Sigla']); ?></td>
					</tr>
				<?php endwhile; ?>
			</table>
		</div>
	<?php endif; ?>

	<?php if ($table === 'cursos'): ?>
		<h2>Cursos</h2>
		<?php $rows = $conn->query("SELECT * FROM cursos ORDER BY IdCurso DESC"); ?>
		<div class="table-scroll" aria-label="Lista de cursos com scroll">
			<table>
				<tr>
					<th>Curso</th>
					<th>Sigla</th>
				</tr>
				<?php while ($row = $rows->fetch_assoc()): ?>
					<tr>
						<td><?php echo e($row['Curso']); ?></td>
						<td><?php echo e($row['Sigla']); ?></td>
					</tr>
				<?php endwhile; ?>
			</table>
		</div>
	<?php endif; ?>

	<?php if ($table === 'pedidos'): ?>
		<h2>Pedido de Matrícula</h2>
		<div class="form-box">
			<?php if ($alunoIdSessao <= 0): ?>
				<p>Antes de pedires matrícula tens de criar a tua ficha de aluno.</p>
				<p><a class="section-link" href="?table=matriculas&action=ficha">Criar ficha de aluno</a></p>
			<?php elseif ($alunoEstadoFichaNormalizado !== 'Aprovada'): ?>
				<?php $estadoAtualFicha = $alunoEstadoFichaNormalizado !== false ? $alunoEstadoFichaNormalizado : ((string)$alunoEstadoFicha !== '' ? (string)$alunoEstadoFicha : 'Pendente'); ?>
				<p>O pedido de matrícula só fica disponível depois de a tua ficha ser aceite pelo gestor pedagógico.</p>
				<p><strong>Estado atual da ficha:</strong> <?php echo e($estadoAtualFicha); ?></p>
				<?php if ($estadoAtualFicha === 'Rejeitada'): ?>
					<p><a class="section-link" href="?table=matriculas&action=ficha_edit">Atualizar ficha para nova validação</a></p>
				<?php else: ?>
					<p><a class="section-link" href="?table=matriculas&action=ficha">Ver ficha de aluno</a></p>
				<?php endif; ?>
			<?php elseif ($alunoTemPedidoAprovado): ?>
				<p>O teu pedido de matrícula já foi aprovado pelo funcionário.</p>
				<?php if ($alunoUltimoPedido): ?>
					<p><strong>ID do pedido:</strong> <?php echo e($alunoUltimoPedido['IdPedido'] ?? ''); ?></p>
				<?php endif; ?>
				<p><a class="section-link" href="?table=matriculas&action=minhas_disciplinas">Aceder às minhas disciplinas</a></p>
			<?php elseif ($alunoTemPedidoPendente): ?>
				<p>Já tens um pedido de matrícula pendente. Aguarda a decisão do funcionário.</p>
				<?php if ($alunoUltimoPedido): ?>
					<p><strong>ID do pedido:</strong> <?php echo e($alunoUltimoPedido['IdPedido'] ?? ''); ?></p>
					<p><strong>Data do pedido:</strong> <?php echo e($alunoUltimoPedido['DataPedido'] ?? ''); ?></p>
				<?php endif; ?>
			<?php else: ?>
				<p>Submete aqui o pedido de matrícula/inscrição. O pedido será analisado pelos serviços.</p>
				<?php if ($alunoUltimoPedido && (($alunoUltimoPedido['Estado'] ?? '') === 'Rejeitado')): ?>
					<p><strong>Pedido anterior rejeitado.</strong> Podes submeter um novo pedido.</p>
					<?php if (!empty($alunoUltimoPedido['ObservacaoDecisao'])): ?>
						<p><strong>Observação do funcionário:</strong> <?php echo e($alunoUltimoPedido['ObservacaoDecisao']); ?></p>
					<?php endif; ?>
				<?php endif; ?>
				<form method="post">
                  <?php echo csrfInput(); ?>
					<input type="hidden" name="table" value="pedidos">
					<input type="hidden" name="action" value="create_self">

					<label>Nome completo</label><br>
					<input type="text" name="Nome" maxlength="120" required value="<?php echo e($_SESSION['utilizador_nome'] ?? ''); ?>"><br>

					<label>Curso pretendido</label><br>
					<select name="IdCurso" required>
						<option value="">Selecione</option>
						<?php foreach ($cursosLookup as $item): ?>
							<option value="<?php echo e($item['IdCurso']); ?>"><?php echo e($item['Curso']); ?></option>
						<?php endforeach; ?>
					</select><br>

					<button type="submit">Submeter pedido</button>
				</form>
			<?php endif; ?>
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
		<?php elseif ($action === 'ficha'): ?>
			<h2><?php echo ($isAluno && $alunoMatriculaEfetivada) ? 'Ficha de aluno/matrícula' : 'Ficha do Aluno'; ?></h2>
			<?php if ($fichaAluno): ?>
				<div class="form-box">
					<h3><?php echo e($fichaAluno['Nome']); ?></h3>
					<?php
						$estadoFichaDetalhe = normalizarEstadoValidacao($fichaAluno['EstadoValidacao'] ?? 'Pendente');
						$decididoPorFicha = trim((string)($fichaAluno['ValidadoPor'] ?? ''));
						$dataDecisaoFichaRaw = trim((string)($fichaAluno['DataValidacao'] ?? ''));
						$dataDecisaoFichaTs = $dataDecisaoFichaRaw !== '' ? strtotime($dataDecisaoFichaRaw) : false;
						$dataDecisaoFicha = $dataDecisaoFichaTs ? date('d/m/Y H:i', $dataDecisaoFichaTs) : ($dataDecisaoFichaRaw !== '' ? $dataDecisaoFichaRaw : '-');
						$rotuloDecisaoFicha = $estadoFichaDetalhe === 'Rejeitada' ? 'Rejeitada por' : 'Aprovada por';
					?>
					<div class="ficha-resumo-layout">
						<div>
							<p class="ficha-linha"><strong>Nº Aluno:</strong> <?php echo e($fichaAluno['IdAluno']); ?></p>
							<p class="ficha-linha"><strong>Data de nascimento:</strong> <?php echo e(formatDatePt($fichaAluno['DataNascimento'] ?? '')); ?></p>
							<p class="ficha-linha"><strong>Morada:</strong> <?php echo e($fichaAluno['Morada'] ?? ''); ?></p>
							<p class="ficha-linha"><strong>Email:</strong> <?php echo e($fichaAluno['Email'] ?? ''); ?></p>
							<p class="ficha-linha"><strong>Contacto:</strong> <?php echo e($fichaAluno['Telefone'] ?? ''); ?></p>
							<p class="ficha-linha"><strong>Curso pretendido:</strong> <?php echo e($fichaAluno['Curso']); ?> (<?php echo e($fichaAluno['SiglaCurso']); ?>)</p>
							<p class="ficha-linha"><strong>Estado de validação:</strong> <?php echo e($fichaAluno['EstadoValidacao'] ?? 'Pendente'); ?></p>
							<p class="ficha-linha"><img class="aluno-foto" src="?table=matriculas&action=foto&id_aluno=<?php echo e($fichaAluno['IdAluno']); ?>" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';"><span class="sem-foto">Sem foto</span></p>
						</div>
						<aside class="ficha-observacoes-box">
							<h4>Observações:</h4>
							<?php if (!empty($fichaAluno['ObservacoesValidacao'])): ?>
								<p><?php echo e($fichaAluno['ObservacoesValidacao']); ?></p>
							<?php endif; ?>
							<?php if ($estadoFichaDetalhe !== 'Pendente'): ?>
								<p><strong><?php echo e($rotuloDecisaoFicha); ?>:</strong> <?php echo e($decididoPorFicha !== '' ? $decididoPorFicha : '-'); ?></p>
								<p><strong>Data da decisão:</strong> <?php echo e($dataDecisaoFicha); ?></p>
							<?php endif; ?>
						</aside>
					</div>

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
						<?php if (!$isAluno || $alunoEstadoFichaNormalizado === 'Aprovada'): ?>
							<a class="section-link" href="?table=matriculas&action=ficha_print&id_aluno=<?php echo e($fichaAluno['IdAluno']); ?>" target="_blank" rel="noopener">Imprimir ficha</a>
							<?php if ($alunoMatriculaEfetivada): ?>
								| <a class="section-link" href="?table=matriculas&action=certificado_print&id_aluno=<?php echo e($fichaAluno['IdAluno']); ?>" target="_blank" rel="noopener">Imprimir certificado de matrícula</a>
							<?php elseif ($isAluno): ?>
								| <a class="section-link" href="?table=pedidos">Submeter/acompanhar pedido de matrícula</a>
							<?php endif; ?>
						<?php elseif ($isAluno): ?>
							<span>Impressão disponível após validação aprovada.</span>
						<?php endif; ?>
						<?php if ($isAluno): ?>
							| <a class="section-link" href="?table=matriculas&action=ficha_edit">Atualizar contactos/foto</a>
						<?php endif; ?>
					</p>
				</div>
			<?php elseif ($isAluno): ?>
				<div class="form-box">
					<h3>Submeter ficha de aluno</h3>
					<p>Preenche os teus dados pessoais e de contacto para criares a tua ficha.</p>
					<form method="post" enctype="multipart/form-data">
                  <?php echo csrfInput(); ?>
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
						<input type="text" name="Telefone" maxlength="9" required placeholder="Ex.: 912345678"><br>

						<label>Foto</label><br>
						<input type="file" name="Foto" accept=".jpg,.png" required><br>

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
		<?php elseif ($action === 'minha_turma'): ?>
			<h2>Minha Turma</h2>
			<?php if ($fichaAluno): ?>
				<div class="form-box">
					<?php
						$totalTurma = count($turmaColegas);
						$totalColegas = 0;
						foreach ($turmaColegas as $colegaTurma) {
							if ((int)($colegaTurma['IdAluno'] ?? 0) !== (int)$alunoIdSessao) {
								$totalColegas += 1;
							}
						}
					?>
					<h3><?php echo e($fichaAluno['Curso']); ?></h3>
					<p><strong>Total de alunos matriculados:</strong> <?php echo e($totalTurma); ?></p>
					<p><strong>Colegas na tua turma:</strong> <?php echo e($totalColegas); ?></p>
					<?php if (!empty($turmaColegas)): ?>
						<div class="table-scroll" aria-label="Lista da minha turma">
							<table>
								<tr>
									<th>Nº Aluno</th>
									<th>Nome</th>
									<th>Observação</th>
								</tr>
								<?php foreach ($turmaColegas as $colegaTurma): ?>
									<?php $isEu = (int)($colegaTurma['IdAluno'] ?? 0) === (int)$alunoIdSessao; ?>
									<tr>
										<td><?php echo e($colegaTurma['IdAluno']); ?></td>
										<td><?php echo e($colegaTurma['Nome']); ?></td>
										<td><?php echo $isEu ? 'Tu' : '-'; ?></td>
									</tr>
								<?php endforeach; ?>
							</table>
						</div>
					<?php else: ?>
						<p>Ainda não existem alunos matriculados nesta turma.</p>
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
                  <?php echo csrfInput(); ?>
						<input type="hidden" name="table" value="matriculas">
						<input type="hidden" name="action" value="update_self">

						<label>Nome completo</label><br>
						<input type="text" name="Nome" maxlength="25" required value="<?php echo e($fichaAluno['Nome'] ?? ''); ?>"><br>

						<label>Data de nascimento</label><br>
						<input type="date" name="DataNascimento" required max="<?php echo e($dataMaximaNascimento); ?>" value="<?php echo e($fichaAluno['DataNascimento'] ?? ''); ?>"><br>

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
						<input type="file" name="Foto" accept=".jpg,.png"><br>

						<button type="submit">Guardar Alterações</button>
						<a class="cancel-link" href="?table=matriculas&action=ficha">Cancelar</a>
					</form>
				</div>
			<?php else: ?>
				<div class="form-box">
					<p>Não foi encontrada uma matrícula associada ao teu utilizador.</p>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	<?php endif; ?>

	</div>
</body>
</html>
