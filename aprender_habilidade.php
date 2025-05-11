<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include("conexaohome.php");

$id = $_SESSION['user_id'];
$habilidade_id = isset($_POST['habilidade_id']) ? (int)$_POST['habilidade_id'] : 0;

if ($habilidade_id <= 0) {
    header("Location: habilidades.php?erro=1");
    exit();
}

// Pega os dados da habilidade correta da tabela habilidades_base
$sqlHabilidade = "SELECT * FROM habilidades_base WHERE id = ?";
$stmtHabilidade = $conn->prepare($sqlHabilidade);
$stmtHabilidade->bind_param("i", $habilidade_id);
$stmtHabilidade->execute();
$resultHabilidade = $stmtHabilidade->get_result();

if ($resultHabilidade->num_rows === 0) {
    header("Location: habilidades.php?erro=1");
    exit();
}

$habilidade = $resultHabilidade->fetch_assoc();

// Pega os dados do usuário (nível e tempo_sala)
$sqlUsuario = "SELECT nivel, tempo_sala FROM usuarios WHERE id = ?";
$stmtUsuario = $conn->prepare($sqlUsuario);
$stmtUsuario->bind_param("i", $id);
$stmtUsuario->execute();
$resultUsuario = $stmtUsuario->get_result();

if ($resultUsuario->num_rows === 0) {
    header("Location: habilidades.php?erro=4"); // Usuário não encontrado
    exit();
}

$usuario = $resultUsuario->fetch_assoc();

// Verifica se o usuário já aprendeu a habilidade
$sqlVerificaAprendida = "SELECT 1 FROM usuario_habilidades WHERE usuario_id = ? AND habilidade_id = ?";
$stmtVerificaAprendida = $conn->prepare($sqlVerificaAprendida);
$stmtVerificaAprendida->bind_param("ii", $id, $habilidade_id);
$stmtVerificaAprendida->execute();
$resultVerifica = $stmtVerificaAprendida->get_result();

if ($resultVerifica->num_rows > 0) {
    header("Location: habilidades.php?erro=2"); // Já aprendeu
    exit();
}

// Verifica requisitos
if ($usuario['nivel'] >= $habilidade['nivel_requerido'] && $usuario['tempo_sala'] >= $habilidade['tempo_requirido']) {
    $sqlInserir = "INSERT INTO usuario_habilidades (usuario_id, habilidade_id) VALUES (?, ?)";
    $stmtInserir = $conn->prepare($sqlInserir);
    $stmtInserir->bind_param("ii", $id, $habilidade_id);
    $stmtInserir->execute();

    header("Location: habilidades.php?sucesso=1");
} else {
    header("Location: habilidades.php?erro=3"); // Requisitos não atendidos
}
exit();
?>
