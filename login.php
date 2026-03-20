<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
	session_start();
}

$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'ipcavnf';

$conn = new mysqli($servername, $username, $password, $dbname);
$baseDadosDisponivel = !$conn->connect_error;
if ($baseDadosDisponivel) {
	$conn->set_charset('utf8mb4');
}

$perfis = [];
if ($baseDadosDisponivel) {
	$resultadoPerfis = $conn->query('SELECT IdPerfis, perfil FROM Perfis ORDER BY perfil');
	if ($resultadoPerfis) {
		while ($perfilRow = $resultadoPerfis->fetch_assoc()) {
			$perfis[] = $perfilRow;
		}
		$resultadoPerfis->close();
	}
}

$mensagem = '';
$tipo = 'error';
$loginInput = '';
$novoUtilizadorInput = '';
$perfilSelecionado = 0;

if (isset($_GET['message']) && is_string($_GET['message'])) {
	$mensagem = $_GET['message'];
	$tipo = ($_GET['type'] ?? 'error') === 'success' ? 'success' : 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$tipo = 'error';
	$acaoFormulario = $_POST['form_action'] ?? 'login';

	if (!$baseDadosDisponivel) {
		$mensagem = 'Não foi possível ligar à base de dados.';
	} elseif ($acaoFormulario === 'register') {
		$novoUtilizador = trim($_POST['novo_utilizador'] ?? '');
		$novaSenha = trim($_POST['nova_senha'] ?? '');
		$confirmarSenha = trim($_POST['confirmar_senha'] ?? '');
		$perfilSelecionado = (int)($_POST['perfil'] ?? 0);
		$novoUtilizadorInput = $novoUtilizador;

		if ($novoUtilizador === '' || $novaSenha === '' || $confirmarSenha === '') {
			$mensagem = 'Preenche todos os campos para criar conta.';
		} elseif (strlen($novoUtilizador) > 40) {
			$mensagem = 'O utilizador não pode ter mais de 40 caracteres.';
		} elseif ($novaSenha !== $confirmarSenha) {
			$mensagem = 'As Passwords não coincidem.';
		} elseif (empty($perfis)) {
			$mensagem = 'Não existem perfis disponíveis para criar conta.';
		} else {
			$perfilValido = false;
			foreach ($perfis as $perfilItem) {
				if ((int)$perfilItem['IdPerfis'] === $perfilSelecionado) {
					$perfilValido = true;
					break;
				}
			}

			if (!$perfilValido) {
				$mensagem = 'Perfil inválido.';
			} else {
				$stmtExiste = $conn->prepare('SELECT IdUser FROM Users WHERE login = ? LIMIT 1');
				$stmtExiste->bind_param('s', $novoUtilizador);
				$stmtExiste->execute();
				$resultadoExiste = $stmtExiste->get_result();
				$jaExiste = $resultadoExiste && $resultadoExiste->fetch_assoc();
				$stmtExiste->close();

				if ($jaExiste) {
					$mensagem = 'Esse utilizador já existe.';
				} else {
					$senhaHash = password_hash($novaSenha, PASSWORD_DEFAULT);
					$stmtCriar = $conn->prepare('INSERT INTO Users (login, password, perfil) VALUES (?, ?, ?)');
					$stmtCriar->bind_param('ssi', $novoUtilizador, $senhaHash, $perfilSelecionado);
					$okCriar = $stmtCriar->execute();
					$stmtCriar->close();

					if ($okCriar) {
						$mensagem = 'Conta criada com sucesso. Agora podes entrar.';
						$tipo = 'success';
						$loginInput = $novoUtilizador;
						$novoUtilizadorInput = '';
						$perfilSelecionado = 0;
					} else {
						$mensagem = 'Erro ao criar conta.';
					}
				}
			}
		}
	} else {
		$utilizador = trim($_POST['utilizador'] ?? '');
		$senha = $_POST['senha'] ?? '';
		$loginInput = $utilizador;

		if ($utilizador === '' || $senha === '') {
			$mensagem = 'Preenche utilizador e Password.';
		} else {
			$stmt = $conn->prepare(
				'SELECT u.IdUser, u.login, u.password, p.perfil AS nomePerfil
				 FROM Users u
				 LEFT JOIN Perfis p ON p.IdPerfis = u.perfil
				 WHERE u.login = ?
				 LIMIT 1'
			);
			$stmt->bind_param('s', $utilizador);
			$stmt->execute();
			$resultado = $stmt->get_result();
			$linha = $resultado ? $resultado->fetch_assoc() : null;
			$stmt->close();

			if ($linha) {
				$senhaGuardada = (string)$linha['password'];
				$infoHash = password_get_info($senhaGuardada);
				$senhaGuardadaEhHash = !empty($infoHash['algo']);
				$autenticado = false;

				if ($senhaGuardadaEhHash) {
					$autenticado = password_verify($senha, $senhaGuardada);
				} else {
					$autenticado = hash_equals($senhaGuardada, $senha);
				}

				if ($autenticado) {
					if (!$senhaGuardadaEhHash || password_needs_rehash($senhaGuardada, PASSWORD_DEFAULT)) {
						$novoHash = password_hash($senha, PASSWORD_DEFAULT);
						$stmtAtualizaHash = $conn->prepare('UPDATE Users SET password = ? WHERE IdUser = ?');
						$stmtAtualizaHash->bind_param('si', $novoHash, $linha['IdUser']);
						$stmtAtualizaHash->execute();
						$stmtAtualizaHash->close();
					}

					session_regenerate_id(true);
					$_SESSION['utilizador_autenticado'] = true;
					$_SESSION['utilizador_id'] = (int)$linha['IdUser'];
					$_SESSION['utilizador_nome'] = $linha['login'];
					$_SESSION['utilizador_perfil'] = $linha['nomePerfil'] ?? '';
					header('Location: index.php');
					exit;
				}
			}

			$mensagem = 'Credenciais inválidas.';
		}
	}
}

function e($valor)
{
	return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$currentScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$scheme = 'https';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$hostSemPorta = 'localhost';
$porta = '';
$partesHost = parse_url($currentScheme . '://' . $httpHost);
if (is_array($partesHost)) {
	$hostParse = trim((string)($partesHost['host'] ?? ''));
	if ($hostParse !== '') {
		$hostSemPorta = $hostParse;
	}
	if (isset($partesHost['port'])) {
		$porta = (string)$partesHost['port'];
	}
}

$hostOriginal = strtolower($hostSemPorta);
$hostParaQr = $hostSemPorta;
if (in_array($hostOriginal, ['localhost', '127.0.0.1', '::1'], true)) {
	$ipCandidatos = [];
	$ipServidor = $_SERVER['SERVER_ADDR'] ?? '';
	if (filter_var($ipServidor, FILTER_VALIDATE_IP)) {
		$ipCandidatos[] = $ipServidor;
	}

	$ipsHostname = gethostbynamel(gethostname()) ?: [];
	foreach ($ipsHostname as $ipHost) {
		if (filter_var($ipHost, FILTER_VALIDATE_IP)) {
			$ipCandidatos[] = $ipHost;
		}
	}

	$ipEscolhido = '';
	foreach ($ipCandidatos as $ip) {
		if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip)) {
			$ipEscolhido = $ip;
			break;
		}
	}

	if ($ipEscolhido === '') {
		foreach ($ipCandidatos as $ip) {
			if (strpos($ip, '127.') !== 0) {
				$ipEscolhido = $ip;
				break;
			}
		}
	}

	if ($ipEscolhido !== '') {
		$hostParaQr = $ipEscolhido;
	}
}

$portaParte = '';
if ($porta !== '') {
	if ($scheme === 'https') {
		if (!in_array($porta, ['', '80', '8080', '443'], true)) {
			$portaParte = ':' . $porta;
		}
	} elseif ($porta !== '80') {
		$portaParte = ':' . $porta;
	}
}
$scriptAtual = $_SERVER['SCRIPT_NAME'] ?? '/Ipca/Aulas/login.php';
$hostParaUrl = $hostParaQr;
if (strpos($hostParaUrl, ':') !== false && strpos($hostParaUrl, '[') !== 0) {
	$hostParaUrl = '[' . $hostParaUrl . ']';
}

$urlAcessoMovel = $scheme . '://' . $hostParaUrl . $portaParte . $scriptAtual;
$usaHostLocal = in_array($hostOriginal, ['localhost', '127.0.0.1', '::1'], true);
$pedidoAtualEmHttp = ($currentScheme !== 'https');
$qrProviders = [
	'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=',
	'https://chart.googleapis.com/chart?chs=240x240&cht=qr&chl=',
	'https://quickchart.io/qr?size=240&text=',
];
$urlQrImagem = $qrProviders[0] . urlencode($urlAcessoMovel);
$qrProvidersJson = json_encode($qrProviders, JSON_UNESCAPED_SLASHES);
$qrProvidersJson = is_string($qrProvidersJson) ? $qrProvidersJson : '[]';
$stylesVersion = (string)(@filemtime(__DIR__ . '/styles.css') ?: time());
$stylesHref = 'styles.css?v=' . rawurlencode($stylesVersion);

if ($baseDadosDisponivel) {
	$conn->close();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Login</title>
	<link rel="stylesheet" href="<?php echo e($stylesHref); ?>">
</head>
<body class="login-page">
	<div class="login-card">
		<h1>Autenticação</h1>
		<p class="subtitle">Acede ao sistema de gestão académica</p>

		<?php if ($mensagem !== ''): ?>
			<div class="message <?php echo e($tipo); ?>"><?php echo e($mensagem); ?></div>
		<?php endif; ?>

		<form method="post">
			<input type="hidden" name="form_action" value="login">
			<h2 class="auth-title">Entrar</h2>
			<label for="utilizador">Utilizador</label>
			<input id="utilizador" name="utilizador" type="text" required value="<?php echo e($loginInput); ?>">

			<label for="senha">Password</label>
			<input id="senha" name="senha" type="password" required>

			<button type="submit">Entrar</button>
		</form>

		<div class="auth-divider"><span>ou</span></div>

		<form method="post">
			<input type="hidden" name="form_action" value="register">
			<h2 class="auth-title">Criar conta</h2>

			<label for="novo_utilizador">Novo utilizador</label>
			<input id="novo_utilizador" name="novo_utilizador" type="text" maxlength="40" required value="<?php echo e($novoUtilizadorInput); ?>">

			<label for="nova_senha">Password</label>
			<input id="nova_senha" name="nova_senha" type="password" required>

			<label for="confirmar_senha">Confirmar Password</label>
			<input id="confirmar_senha" name="confirmar_senha" type="password" required>

			<label for="perfil">Tipo de perfil</label>
			<select id="perfil" name="perfil" required>
				<option value="">Selecione</option>
				<?php foreach ($perfis as $perfil): ?>
					<option value="<?php echo e($perfil['IdPerfis']); ?>" <?php echo ((int)$perfilSelecionado === (int)$perfil['IdPerfis']) ? 'selected' : ''; ?>>
						<?php echo e($perfil['perfil']); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<button type="submit">Criar conta</button>
		</form>

		<div class="auth-divider"><span>acesso móvel</span></div>

		<div class="qr-access">
			<h2 class="auth-title">Entrar pelo telemóvel</h2>
			<p class="qr-help">Lê o QR Code para abrir esta página no telemóvel e fazer login ou criar conta.</p>
			<label for="url_qr">Link para QR Code</label>
			<input id="url_qr" type="text" value="<?php echo e($urlAcessoMovel); ?>">

			<button id="botao_qr" class="secondary-button" type="button">Atualizar QR Code</button>
			<p id="estado_qr" class="qr-help" hidden>Não foi possível gerar a imagem de QR Code automaticamente. Usa o link acima no telemóvel.</p>

			<div class="qr-preview">
				<img id="imagem_qr" src="<?php echo e($urlQrImagem); ?>" data-providers="<?php echo e($qrProvidersJson); ?>" alt="QR Code de acesso ao login">
			</div>
		</div>
	</div>

	<script>
		(function () {
			var inputUrl = document.getElementById('url_qr');
			var imagemQr = document.getElementById('imagem_qr');
			var botaoQr = document.getElementById('botao_qr');
			var estadoQr = document.getElementById('estado_qr');
			var providers = [];
			var providerIndex = 0;
			var destinoAtual = '';

			if (!inputUrl || !imagemQr || !botaoQr) {
				return;
			}

			try {
				providers = JSON.parse(imagemQr.getAttribute('data-providers') || '[]');
			} catch (e) {
				providers = [];
			}

			if (!Array.isArray(providers) || providers.length === 0) {
				providers = ['https://api.qrserver.com/v1/create-qr-code/?size=240x240&data='];
			}

			function renderQrComFallback() {
				if (!destinoAtual) {
					return;
				}

				if (providerIndex >= providers.length) {
					if (estadoQr) {
						estadoQr.hidden = false;
					}
					return;
				}

				if (estadoQr) {
					estadoQr.hidden = true;
				}

				imagemQr.src = providers[providerIndex] + encodeURIComponent(destinoAtual) + '&_t=' + Date.now();
			}

			function atualizarQr() {
				destinoAtual = (inputUrl.value || '').trim();
				if (!destinoAtual) {
					return;
				}

				providerIndex = 0;
				renderQrComFallback();
			}

			imagemQr.addEventListener('error', function () {
				providerIndex += 1;
				renderQrComFallback();
			});

			botaoQr.addEventListener('click', atualizarQr);
			inputUrl.addEventListener('change', atualizarQr);
			inputUrl.addEventListener('blur', atualizarQr);
		})();
	</script>
</body>
</html>
