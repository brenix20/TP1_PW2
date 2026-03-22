-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Tempo de geração: 22-Mar-2026 às 20:34
-- Versão do servidor: 10.4.28-MariaDB
-- versão do PHP: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `ipcavnf`
--

-- --------------------------------------------------------

--
-- Estrutura da tabela `cursos`
--

CREATE TABLE `cursos` (
  `IdCurso` int(11) NOT NULL,
  `Curso` varchar(150) NOT NULL,
  `Sigla` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `disciplina`
--

CREATE TABLE `disciplina` (
  `IdDisciplina` int(11) NOT NULL,
  `Disciplina` varchar(30) NOT NULL,
  `Sigla` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `matriculas`
--

CREATE TABLE `matriculas` (
  `IdAluno` int(11) NOT NULL,
  `IdUser` int(11) DEFAULT NULL,
  `Nome` varchar(25) NOT NULL,
  `Foto` longblob DEFAULT NULL,
  `IdCurso` int(11) NOT NULL,
  `DataNascimento` date DEFAULT NULL,
  `Morada` varchar(255) DEFAULT NULL,
  `Email` varchar(120) DEFAULT NULL,
  `Telefone` int(9) DEFAULT NULL,
  `EstadoValidacao` varchar(20) NOT NULL DEFAULT 'Pendente',
  `ObservacoesValidacao` text DEFAULT NULL,
  `ValidadoPor` varchar(80) DEFAULT NULL,
  `DataValidacao` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `notas_avaliacao`
--

CREATE TABLE `notas_avaliacao` (
  `IdNota` int(11) NOT NULL,
  `IdAluno` int(11) NOT NULL,
  `IdDisciplina` int(11) NOT NULL,
  `Epoca` varchar(20) NOT NULL DEFAULT 'Normal',
  `AnoLetivo` varchar(9) NOT NULL,
  `Nota` decimal(4,2) NOT NULL,
  `Observacoes` varchar(255) DEFAULT NULL,
  `AtualizadoPor` varchar(80) DEFAULT NULL,
  `AtualizadoEm` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `password_resets`
--

CREATE TABLE `password_resets` (
  `IdReset` int(11) NOT NULL,
  `IdUser` int(11) NOT NULL,
  `Selector` char(16) NOT NULL,
  `TokenHash` char(64) NOT NULL,
  `ExpiresAt` datetime NOT NULL,
  `UsedAt` datetime DEFAULT NULL,
  `RequestedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `RequestedIp` varchar(45) DEFAULT NULL,
  `UserAgent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `pedidos_matricula`
--

CREATE TABLE `pedidos_matricula` (
  `IdPedido` int(11) NOT NULL,
  `IdAluno` int(11) DEFAULT NULL,
  `NomeCandidato` varchar(120) NOT NULL,
  `Email` varchar(150) DEFAULT NULL,
  `IdCurso` int(11) DEFAULT NULL,
  `Observacoes` varchar(255) DEFAULT NULL,
  `Estado` enum('Pendente','Aprovado','Rejeitado') NOT NULL DEFAULT 'Pendente',
  `ObservacaoDecisao` varchar(255) DEFAULT NULL,
  `DecididoPor` varchar(80) DEFAULT NULL,
  `DataPedido` datetime NOT NULL DEFAULT current_timestamp(),
  `DataDecisao` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `Perfis`
--

CREATE TABLE `Perfis` (
  `IdPerfis` int(11) NOT NULL,
  `perfil` varchar(25) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `plano_estudos`
--

CREATE TABLE `plano_estudos` (
  `IdDisciplina` int(11) NOT NULL,
  `IdCurso` int(11) NOT NULL,
  `Ano` tinyint(4) NOT NULL DEFAULT 1,
  `Semestre` tinyint(4) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estrutura da tabela `Users`
--

CREATE TABLE `Users` (
  `IdUser` int(11) NOT NULL,
  `login` varchar(40) NOT NULL,
  `password` varchar(255) NOT NULL,
  `perfil` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tabelas despejadas
--

--
-- Índices para tabela `cursos`
--
ALTER TABLE `cursos`
  ADD PRIMARY KEY (`IdCurso`);

--
-- Índices para tabela `disciplina`
--
ALTER TABLE `disciplina`
  ADD PRIMARY KEY (`IdDisciplina`);

--
-- Índices para tabela `matriculas`
--
ALTER TABLE `matriculas`
  ADD PRIMARY KEY (`IdAluno`),
  ADD KEY `fk_curso_aluno` (`IdCurso`),
  ADD KEY `idx_matriculas_iduser` (`IdUser`);

--
-- Índices para tabela `notas_avaliacao`
--
ALTER TABLE `notas_avaliacao`
  ADD PRIMARY KEY (`IdNota`),
  ADD UNIQUE KEY `uniq_nota` (`IdAluno`,`IdDisciplina`,`Epoca`,`AnoLetivo`),
  ADD KEY `idx_nota_disciplina` (`IdDisciplina`),
  ADD KEY `idx_nota_aluno` (`IdAluno`);

--
-- Índices para tabela `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`IdReset`),
  ADD UNIQUE KEY `ux_password_resets_selector` (`Selector`),
  ADD KEY `idx_password_resets_user` (`IdUser`),
  ADD KEY `idx_password_resets_expires` (`ExpiresAt`);

--
-- Índices para tabela `pedidos_matricula`
--
ALTER TABLE `pedidos_matricula`
  ADD PRIMARY KEY (`IdPedido`),
  ADD KEY `idx_pedido_estado` (`Estado`),
  ADD KEY `idx_pedido_curso` (`IdCurso`),
  ADD KEY `idx_pedido_aluno` (`IdAluno`);

--
-- Índices para tabela `Perfis`
--
ALTER TABLE `Perfis`
  ADD PRIMARY KEY (`IdPerfis`);

--
-- Índices para tabela `plano_estudos`
--
ALTER TABLE `plano_estudos`
  ADD PRIMARY KEY (`IdDisciplina`,`IdCurso`),
  ADD UNIQUE KEY `ux_plano_estudos` (`IdDisciplina`,`IdCurso`,`Ano`,`Semestre`),
  ADD KEY `fk_plano_estudos_curso` (`IdCurso`);

--
-- Índices para tabela `Users`
--
ALTER TABLE `Users`
  ADD PRIMARY KEY (`IdUser`),
  ADD KEY `fkuser` (`perfil`);

--
-- AUTO_INCREMENT de tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `cursos`
--
ALTER TABLE `cursos`
  MODIFY `IdCurso` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `disciplina`
--
ALTER TABLE `disciplina`
  MODIFY `IdDisciplina` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `matriculas`
--
ALTER TABLE `matriculas`
  MODIFY `IdAluno` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `notas_avaliacao`
--
ALTER TABLE `notas_avaliacao`
  MODIFY `IdNota` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `IdReset` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `pedidos_matricula`
--
ALTER TABLE `pedidos_matricula`
  MODIFY `IdPedido` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `Perfis`
--
ALTER TABLE `Perfis`
  MODIFY `IdPerfis` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `Users`
--
ALTER TABLE `Users`
  MODIFY `IdUser` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restrições para despejos de tabelas
--

--
-- Limitadores para a tabela `matriculas`
--
ALTER TABLE `matriculas`
  ADD CONSTRAINT `fk_curso_aluno` FOREIGN KEY (`IdCurso`) REFERENCES `cursos` (`IdCurso`),
  ADD CONSTRAINT `fk_matriculas_user` FOREIGN KEY (`IdUser`) REFERENCES `Users` (`IdUser`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limitadores para a tabela `notas_avaliacao`
--
ALTER TABLE `notas_avaliacao`
  ADD CONSTRAINT `fk_notas_aluno` FOREIGN KEY (`IdAluno`) REFERENCES `matriculas` (`IdAluno`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notas_disciplina` FOREIGN KEY (`IdDisciplina`) REFERENCES `disciplina` (`IdDisciplina`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limitadores para a tabela `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_password_resets_user` FOREIGN KEY (`IdUser`) REFERENCES `Users` (`IdUser`) ON DELETE CASCADE;

--
-- Limitadores para a tabela `pedidos_matricula`
--
ALTER TABLE `pedidos_matricula`
  ADD CONSTRAINT `fk_pedidos_aluno` FOREIGN KEY (`IdAluno`) REFERENCES `matriculas` (`IdAluno`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pedidos_curso` FOREIGN KEY (`IdCurso`) REFERENCES `cursos` (`IdCurso`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Limitadores para a tabela `plano_estudos`
--
ALTER TABLE `plano_estudos`
  ADD CONSTRAINT `fk_plano_estudos_curso` FOREIGN KEY (`IdCurso`) REFERENCES `cursos` (`IdCurso`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_plano_estudos_disciplina` FOREIGN KEY (`IdDisciplina`) REFERENCES `disciplina` (`IdDisciplina`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Limitadores para a tabela `Users`
--
ALTER TABLE `Users`
  ADD CONSTRAINT `fkuser` FOREIGN KEY (`perfil`) REFERENCES `Perfis` (`IdPerfis`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
