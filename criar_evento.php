<?php
// Arquivo: simple_arena_register_test.php - Página SIMPLES de registro para Arena (TESTE)

// --- CONFIGURAÇÃO DE ERROS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error_simple_register_test.log');
// --- FIM DA CONFIGURAÇÃO DE ERROS ---

// session_start()
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');

// --- CONEXÃO PDO ---
try {
    // AJUSTE O CAMINHO PARA O SEU conexao.php
    require_once("conexao.php");

    if (!isset($conn) || !($conn instanceof PDO)) {
        throw new RuntimeException("A conexão PDO não foi estabelecida corretamente.");
    }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Throwable $e) {
    error_log("Erro CRÍTICO (conexão) em " . basename(__FILE__) . ": " . $e->getMessage());
    echo "<h1>Erro de Conexão</h1><p>Não foi possível conectar ao banco de dados.</p>"; // Feedback direto para simplicidade
    exit;
}

// --- Verifica Autenticação ---
if (!isset($_SESSION['user_id'])) {
    echo "<h1>Não Autenticado</h1><p>Você precisa estar logado para usar esta página de teste.</p><p><a href='../login.php'>Ir para Login</a></p>"; // Feedback direto
    exit();
}
$usuario_id = (int)$_SESSION['user_id'];

// --- ID do Evento para Teste (HARDCODED) ---
// !!! ALtere este ID para um evento REAL que você criou com status 'AGUARDANDO_JOGADORES' !!!
const EVENT_ID_FOR_TEST = 1; // <--- MODIFIQUE ESTE NÚMERO PARA O ID DE UM EVENTO REAL

// --- Feedback na Página ---
$page_feedback = "";
$feedback_class = ""; // 'success' ou 'error'

// ---> Processar o clique no botão "Registrar-se" (via POST) <---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register_for_arena'])) {
    try {
        $id_evento_teste = EVENT_ID_FOR_TEST;

        $conn->beginTransaction(); // Inicia transação

        // 1. Verificar se o evento de teste existe e está aberto para registro
        $stmt_evento = $conn->prepare("SELECT id, nome_evento, status, base_player_count FROM arena_eventos WHERE id = ? FOR UPDATE");
        $stmt_evento->execute([$id_evento_teste]);
        $evento = $stmt_evento->fetch(PDO::FETCH_ASSOC);

        if (!$evento) {
            throw new Exception("Evento de teste com ID " . EVENT_ID_FOR_TEST . " não encontrado na base de dados.");
        }

        if ($evento['status'] !== 'AGUARDANDO_JOGADORES') {
            throw new Exception("O registro para este evento ({$evento['nome_evento']}) não está aberto. Status atual: {$evento['status']}.");
        }

        $min_players_to_start = 3; // Sua regra: mínimo de 3 jogadores para começar

        // 2. Verificar se o usuário já está registrado para este evento de teste
        $stmt_check_registro = $conn->prepare("SELECT id FROM arena_registros WHERE id_usuario = ? AND id_evento = ?");
        $stmt_check_registro->execute([$usuario_id, $id_evento_teste]);
        $ja_registrado = $stmt_check_registro->fetch(PDO::FETCH_ASSOC);

        if ($ja_registrado) {
            throw new Exception("Você já está registrado para este evento.");
        }

        // 3. Registrar o usuário no evento de teste
        $stmt_register = $conn->prepare("INSERT INTO arena_registros (id_usuario, id_evento, status_participacao) VALUES (?, ?, 'REGISTRADO')");
        if (!$stmt_register->execute([$usuario_id, $id_evento_teste])) {
            error_log("Erro PDO INSERT arena_registros user {$usuario_id}, evento {$id_evento_teste}: " . print_r($conn->errorInfo(), true));
            throw new Exception("Falha ao registrar você no evento no banco de dados.");
        }

        // 4. Contar jogadores registrados para o evento de teste
        $stmt_count_players = $conn->prepare("SELECT COUNT(*) FROM arena_registros WHERE id_evento = ? AND status_participacao = 'REGISTRADO'");
        $stmt_count_players->execute([$id_evento_teste]);
        $total_registrados = $stmt_count_players->fetchColumn();

        // 5. Verificar se o evento pode começar (atingiu o mínimo)
        $should_start_event = false;
        if ($total_registrados >= $min_players_to_start) {
            $should_start_event = true;
        }

        // 6. Se deve começar, atualizar o status do evento
        if ($should_start_event) {
            $stmt_start_event = $conn->prepare("UPDATE arena_eventos SET status = 'EM_ANDAMENTO' WHERE id = ? AND status = 'AGUARDANDO_JOGADORES'");
            if (!$stmt_start_event->execute([$id_evento_teste])) {
                error_log("Erro PDO UPDATE status evento {$id_evento_teste} para EM_ANDAMENTO: " . print_r($conn->errorInfo(), true));
                // Continua, pois o registro já foi feito, apenas o status pode não ter atualizado
            }
            // TODO: Opcional: Logar o início do evento
        }

        $conn->commit(); // Confirma todas as operações

        $page_feedback = "Registro para o evento '{$evento['nome_evento']}' realizado com sucesso!";
        $feedback_class = 'success';

        if ($should_start_event) {
            $page_feedback .= " O evento atingiu {$total_registrados} jogadores e agora está **EM ANDAMENTO**!";
            // Redireciona para a página da Arena do evento quando ele inicia
            $_SESSION['feedback_geral'] = $page_feedback; // Passa o feedback
            header("Location: arenaroyalle.php?id_evento={$id_evento_teste}"); // Redireciona
            exit();
        }


    } catch (PDOException $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        error_log("Erro PDO CAUGHT no registro de evento user {$usuario_id}, evento " . EVENT_ID_FOR_TEST . ": " . $e->getMessage());
        $page_feedback = "Erro no banco de dados ao tentar registrar.";
        $feedback_class = 'error';

    } catch (Exception $ex) {
         if ($conn->inTransaction()) { $conn->rollBack(); }
        error_log("Erro GERAL CAUGHT no registro de evento user {$usuario_id}, evento " . EVENT_ID_FOR_TEST . ": " . $ex->getMessage());
        $page_feedback = "Erro ao registrar: " . $ex->getMessage();
        $feedback_class = 'error';
    }
}
// --- FIM DO PROCESSAMENTO POST ---

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Registro Arena</title>
    <style>
        body { font-family: sans-serif; text-align: center; padding: 50px; background-color: #1a1a1a; color: #eee; }
        .container { background-color: #2a2a2a; padding: 30px; border-radius: 8px; max-width: 500px; margin: 0 auto; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3); }
        h2 { color: #f0a500; margin-bottom: 20px; }
        .feedback { margin-bottom: 20px; padding: 10px; border-radius: 4px; }
        .feedback.success { background-color: #dff0d8; color: #3c763d; border: 1px solid #d6e9c6; }
        .feedback.error { background-color: #f2dede; color: #a94442; border: 1px solid #ebccd1; }
        button { padding: 12px 25px; font-size: 1.1em; background-color: #5cb85c; color: white; border: none; border-radius: 4px; cursor: pointer; transition: background-color 0.3s ease; }
        button:hover { background-color: #4cae4c; }
        a { color: #f0a500; text-decoration: none; margin-top: 20px; display: inline-block; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Teste de Registro Rápido na Arena</h2>

        <?php // Exibir feedback ?>
        <?php if (!empty($page_feedback)): ?>
            <div class="feedback <?php echo $feedback_class; ?>">
                <?php echo htmlspecialchars($page_feedback); ?>
            </div>
        <?php endif; ?>

        <p>Clique no botão abaixo para tentar se registrar no evento de teste (ID: <?php echo EVENT_ID_FOR_TEST; ?>).</p>
        <p>Quando 3 jogadores se registrarem neste evento, ele começará e você será redirecionado.</p>

        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
            <button type="submit" name="register_for_arena">Registrar-se para Arena de Teste</button>
        </form>

        <p><a href="../home.php">Voltar para Página Inicial</a></p> <?php // Link para voltar ?>

    </div>
</body>
</html>