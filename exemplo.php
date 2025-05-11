<?php
session_start(); // Inicia a sessão

// Habilita a exibição de erros para depuração
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Dados de conexão com o banco de dados
$servidor = "localhost";
$usuario = "u305246487_fehlimaxd";
$senha = "Pssword9254";
$banco = "u305246487_dbzwali";

// Conectar ao banco de dados da sala (templo_sala)
$db_tempos = new mysqli($servidor, $usuario, $senha, $banco);

// Verifica a conexão com o banco de dados
if ($db_tempos->connect_error) {
    die("Conexão falhou: " . $db_tempos->connect_error);
}

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    echo "Usuário não logado.";
    exit();
}

$user_id = $_SESSION['user_id']; // ID do usuário logado

// Verifica se o jogador já está na sala
$query_sala = "SELECT id FROM templo_sala WHERE usuario_id = ? AND sala_out IS NULL";
$stmt_sala = $db_tempos->prepare($query_sala);
$stmt_sala->bind_param("i", $user_id);
$stmt_sala->execute();
$result_sala = $stmt_sala->get_result();

if ($result_sala->num_rows > 0) {
    echo "Você já está na sala.";
    exit();
}

// Busca os dados do usuário na tabela usuarios
$query_usuario = "SELECT id, nome, hp, ki, forca, defesa FROM usuarios WHERE id = ?";
$stmt_usuario = $db_tempos->prepare($query_usuario);
$stmt_usuario->bind_param("i", $user_id);
$stmt_usuario->execute();
$result_usuario = $stmt_usuario->get_result();

if ($result_usuario->num_rows == 0) {
    echo "Usuário não encontrado.";
    exit();
}

$usuario = $result_usuario->fetch_assoc();

// Multiplica os valores por 10 (HP, KI, Força e Defesa temporários)
$hp_temp = $usuario['hp'] * 10;
$ki_temp = $usuario['ki'] * 10;
$forca_temp = $usuario['forca']; // Pode adicionar lógica de transformação se necessário
$defesa_temp = $usuario['defesa']; // Pode adicionar lógica de transformação se necessário

// Registra o jogador entrando na sala
$query_entrada = "INSERT INTO templo_sala (usuario_id, sala_on, sala_in, sala_hptemp, sala_kitemp, sala_forcatemp, sala_defesatemp, sala_status) 
                  VALUES (?, 1, NOW(), ?, ?, ?, ?, 'ativo')";
$stmt_entrada = $db_tempos->prepare($query_entrada);
$stmt_entrada->bind_param("iiiii", $user_id, $hp_temp, $ki_temp, $forca_temp, $defesa_temp);

if ($stmt_entrada->execute()) {
    echo "Você entrou na sala!";
} else {
    echo "Erro ao entrar na sala.";
}

// Exibir jogadores ativos na sala
$query_ativos = "SELECT u.nome, ts.sala_hptemp, ts.sala_kitemp, ts.sala_forcatemp, ts.sala_defesatemp
                 FROM templo_sala ts
                 JOIN usuarios u ON ts.usuario_id = u.id
                 WHERE ts.sala_on = 1 AND ts.sala_out IS NULL";
$result_ativos = $db_tempos->query($query_ativos);

echo "<h3>Jogadores na Sala:</h3>";
if ($result_ativos->num_rows > 0) {
    while ($player = $result_ativos->fetch_assoc()) {
        echo "Jogador: " . $player['nome'] . " | HP Temp: " . $player['sala_hptemp'] . " | KI Temp: " . $player['sala_kitemp'] . "<br>";
    }
} else {
    echo "Nenhum jogador na sala.";
}

// Código para o ataque
if (isset($_POST['atacar'])) {
    $alvo_id = $_POST['alvo_id']; // ID do alvo
    $ataque_forca = 100; // Exemplo de dano de ataque, você pode adicionar a lógica aqui

    // Registra o ataque
    $query_ataque = "INSERT INTO templo_sala (sala_acao) VALUES (CONCAT(?, ' atacou ', ?, ' com dano ', ?))";
    $stmt_ataque = $db_tempos->prepare($query_ataque);
    $stmt_ataque->bind_param("ssi", $usuario['nome'], $alvo_id, $ataque_forca);
    $stmt_ataque->execute();

    // Atualiza o HP do alvo
    $query_atualiza_hp = "UPDATE templo_sala SET sala_hptemp = sala_hptemp - ? WHERE usuario_id = ?";
    $stmt_hp = $db_tempos->prepare($query_atualiza_hp);
    $stmt_hp->bind_param("ii", $ataque_forca, $alvo_id);
    $stmt_hp->execute();

    echo "Ataque registrado!";
}

// Fecha a conexão
$stmt_usuario->close();
$stmt_sala->close();
$stmt_entrada->close();
$db_tempos->close();
?>

<!-- Formulário para atacar um alvo -->
<form method="POST">
    <label for="alvo_id">Escolha um alvo:</label>
    <select name="alvo_id" id="alvo_id">
        <?php
        // Exibir os jogadores na sala para selecionar o alvo
        $query_alvos = "SELECT ts.usuario_id, u.nome 
                        FROM templo_sala ts 
                        JOIN usuarios u ON ts.usuario_id = u.id 
                        WHERE ts.sala_on = 1 AND ts.sala_out IS NULL";
        $result_alvos = $db_tempos->query($query_alvos);
        
        // Verifica se há jogadores disponíveis
        if ($result_alvos->num_rows > 0) {
            while ($alvo = $result_alvos->fetch_assoc()) {
                echo "<option value='" . $alvo['usuario_id'] . "'>" . $alvo['nome'] . "</option>";
            }
        } else {
            echo "<option value=''>Nenhum jogador disponível</option>";
        }
        ?>
    </select>
    <input type="submit" name="atacar" value="Atacar">
</form>
