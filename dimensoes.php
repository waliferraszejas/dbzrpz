<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

require_once 'conexaohome.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$id_usuario = intval($_SESSION['user_id']);

function formatarTempo($segundos) {
    $horas = floor($segundos / 3600);
    $minutos = floor(($segundos % 3600) / 60);
    $segundos = $segundos % 60;
    return "{$horas}h {$minutos}m {$segundos}s";
}

$bloqueio = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT motivo, expiracao FROM bloqueios_sala_tempo 
    WHERE id_jogador = $id_usuario AND expiracao > NOW()
"));

if ($bloqueio) {
    $segundos_restantes = strtotime($bloqueio['expiracao']) - time();
    $mensagem = "";

    if ($bloqueio['motivo'] === 'inatividade') {
        $mensagem = "Você permaneceu inativo por mais de 15 minutos e foi expulso.<br>";
    } elseif (strpos($bloqueio['motivo'], 'eliminado:') === 0) {
        $nome_atacante = substr($bloqueio['motivo'], 11);
        $mensagem = "Você foi eliminado por <strong>$nome_atacante</strong>.<br>";
    } else {
        $mensagem = "Você está temporariamente impedido de entrar na Sala do Templo.<br>";
    }

    $mensagem .= "Retorne em: <strong>" . formatarTempo($segundos_restantes) . "</strong>";

    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Sala do Templo - Acesso Bloqueado</title>
    </head>
    <body>
        <h2>Sala do Templo</h2>
        <p style='color:red;'>$mensagem</p>
        <a href='home.php'>Voltar</a>
    </body>
    </html>";
    exit();
}

$sql_user = mysqli_query($conn, "SELECT * FROM usuarios WHERE id = $id_usuario");
if (mysqli_num_rows($sql_user) == 0) {
    echo "Usuário não encontrado no banco de dados.";
    exit;
}
$usuario = mysqli_fetch_assoc($sql_user);

if ($usuario['sala_templo_ativo'] == 0) {
    $entrada = date('Y-m-d H:i:s');

    $hp_temp = $usuario['hp'] * 10;
    $ki_temp = $usuario['ki'] * 10;

    mysqli_query($conn, "UPDATE usuarios SET 
        sala_tempo_entrada = '$entrada',
        ultima_acao = '$entrada',
        sala_templo_ativo = 1,
        hp_temp = $hp_temp,
        ki_temp = $ki_temp,
        forca_temp = {$usuario['forca']},
        defesa_temp = {$usuario['defesa']}
    WHERE id = $id_usuario");

    $usuario = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM usuarios WHERE id = $id_usuario"));
}

$tempo_inatividade = 900;
$usuarios_ativos = mysqli_query($conn, "SELECT * FROM usuarios WHERE sala_templo_ativo = 1");
while ($u = mysqli_fetch_assoc($usuarios_ativos)) {
    if (time() - strtotime($u['ultima_acao']) >= $tempo_inatividade) {
        $saida = date('Y-m-d H:i:s');
        $entrada = strtotime($u['sala_tempo_entrada']);
        $tempo_total = time() - $entrada;
        mysqli_query($conn, "UPDATE usuarios SET 
            sala_tempo_saida = '$saida',
            tempo_sala = tempo_sala + $tempo_total,
            sala_templo_ativo = 0
        WHERE id = {$u['id']}");

        $expiracao = date('Y-m-d H:i:s', time() + 600);
        mysqli_query($conn, "INSERT INTO bloqueios_sala_tempo (id_jogador, motivo, expiracao) 
            VALUES ({$u['id']}, 'inatividade', '$expiracao')");
    }
}

$habilidades = mysqli_query($conn, "
    SELECT hb.id, hb.nome, hb.ki 
    FROM habilidades_usuario hu 
    JOIN habilidades_base hb ON hu.habilidade_id = hb.id 
    WHERE hu.usuario_id = $id_usuario
");

$alvos = mysqli_query($conn, "SELECT * FROM usuarios WHERE sala_templo_ativo = 1 AND id != $id_usuario");

$tempo_restante = max(0, 120 - (time() - strtotime($usuario['ultima_acao'])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['sair'])) {
        mysqli_query($conn, "UPDATE usuarios SET 
            sala_templo_ativo = 0,
            sala_tempo_saida = NOW()
        WHERE id = $id_usuario");

        header("Location: templo.php");
        exit();
    }

    $alvo_id = intval($_POST['alvo']);
    $habilidade_id = intval($_POST['habilidade']);
    $habilidade = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM habilidades_base WHERE id = $habilidade_id"));
    $alvo = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM usuarios WHERE id = $alvo_id"));

    if ($usuario['ki_temp'] >= $habilidade['ki']) {
        $dano = $usuario['forca_temp'] + $habilidade['ki'] - $alvo['defesa_temp'];
        $dano = max(0, $dano);

        $ki_restante = $usuario['ki_temp'] - $habilidade['ki'];
        $hp_restante = $alvo['hp_temp'] - $dano;

        mysqli_query($conn, "UPDATE usuarios SET 
            ki_temp = $ki_restante,
            ultima_acao = NOW()
        WHERE id = $id_usuario");

        if ($hp_restante <= 0) {
            $saida = date('Y-m-d H:i:s');
            $entrada = strtotime($alvo['sala_tempo_entrada']);
            $tempo_total = time() - $entrada;

            mysqli_query($conn, "UPDATE usuarios SET 
                hp_temp = 0,
                sala_tempo_saida = '$saida',
                tempo_sala = tempo_sala + $tempo_total,
                sala_templo_ativo = 0
            WHERE id = $alvo_id");

            $expiracao = date('Y-m-d H:i:s', time() + 600);
            mysqli_query($conn, "INSERT INTO bloqueios_sala_tempo (id_jogador, motivo, expiracao) 
                VALUES ($alvo_id, 'eliminado:{$usuario['nome']}', '$expiracao')");
        } else {
            mysqli_query($conn, "UPDATE usuarios SET 
                hp_temp = $hp_restante
            WHERE id = $alvo_id");
        }

        echo json_encode([
            'sucesso' => true,
            'atacante' => $usuario['nome'],
            'habilidade' => $habilidade['nome'],
            'dano' => $dano,
            'hp_restante' => max(0, $hp_restante)
        ]);
    } else {
        echo json_encode(['sucesso' => false, 'erro' => 'KI insuficiente para realizar o ataque!']);
    }
    exit();
}
?>