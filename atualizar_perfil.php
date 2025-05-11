<?php
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: perfil.php");
    exit();
}

include("../conexaohome.php");

// Dados básicos do usuário
$id   = $_SESSION['user_id'];
$nome = trim($_POST['nome']);

// Consulta dados atuais do usuário
$stmt = $conn->prepare("
    SELECT pontos, hp, ki, forca, defesa, velocidade, foto 
    FROM usuarios 
    WHERE id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$result  = $stmt->get_result();
$usuario = $result->fetch_assoc();

$pontos_disponiveis = $usuario['pontos'];

// Recebe os pontos distribuídos (só valores positivos)
$add_hp        = max(0, (int)$_POST['hp']);
$add_ki        = max(0, (int)$_POST['ki']);
$add_forca     = max(0, (int)$_POST['forca']);
$add_defesa    = max(0, (int)$_POST['defesa']);
$add_velocidade= max(0, (int)$_POST['velocidade']);

$total_gasto = $add_hp + $add_ki + $add_forca + $add_defesa + $add_velocidade;

// Valida os pontos distribuídos
if ($total_gasto > $pontos_disponiveis) {
    die("⚠️ Você tentou usar mais pontos do que possui.");
}

// Processa o upload da foto (se enviada)
$foto_atual = $usuario['foto'];
$nova_foto  = $foto_atual;

if (!empty($_FILES['foto']['name'])) {
    $pasta         = "uploads/";
    $nome_arquivo  = uniqid() . "_" . basename($_FILES['foto']['name']);
    $caminho_final = $pasta . $nome_arquivo;

    if (move_uploaded_file($_FILES['foto']['tmp_name'], $caminho_final)) {
        $nova_foto = $nome_arquivo;
    }
}

// Atualiza o banco de dados com os novos valores
$stmt = $conn->prepare("
    UPDATE usuarios SET 
        nome        = ?, 
        foto        = ?, 
        hp          = hp + ?, 
        ki          = ki + ?, 
        forca       = forca + ?, 
        defesa      = defesa + ?, 
        velocidade  = velocidade + ?, 
        pontos      = pontos - ?
    WHERE id = ?
");

$stmt->bind_param(
    "ssiiiiiii", 
    $nome,
    $nova_foto,
    $add_hp,
    $add_ki,
    $add_forca,
    $add_defesa,
    $add_velocidade,
    $total_gasto,
    $id
);

// Executa e redireciona
if ($stmt->execute()) {
    header("Location: index.php");
    exit();
} else {
    echo "❌ Erro ao atualizar o perfil. Tente novamente.";
}
