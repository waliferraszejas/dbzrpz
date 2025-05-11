<?php
// Arquivo: arena_fila_simples.php - Código de registro simples para testes

// !!! VERIFIQUE CUIDADOSAMENTE SE NÃO HÁ NADA (ESPAÇOS, LINHAS VAZIAS, CARACTERES ESPECIAIS COMO BOM) ANTES DESTA TAG <?php

// --- CONFIGURAÇÃO DE ERROS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error_arena_fila_simples.log');
// --- FIM DA CONFIGURAÇÃO DE ERROS ---

// session_start() - Esta linha causa o erro se houver output ANTES dela
if (session_status() == PHP_SESSION_NONE) {
 session_start();
}

// O header() TAMBÉM deve vir antes de qualquer output visível
header('Content-Type: text/html; charset=UTF-8');

// --- CONEXÃO PDO ---
// VERIFIQUE O ARQUIVO conexao.php (e quaisquer outros includes ANTES desta linha)
// Garanta que conexao.php NÃO tenha espaços ou linhas vazias ANTES de sua tag <?php nem DEPOIS de sua tag ?>
try {
    // AJUSTE O CAMINHO PARA O SEU conexao.php
    require_once("conexao.php");

    if (!isset($conn) || !($conn instanceof PDO)) {
        throw new RuntimeException("A conexão PDO não foi estabelecida corretamente.");
    }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Throwable $e) {
    error_log("Erro CRÍTICO (conexão) em " . basename(__FILE__) . ": " . $e->getMessage());
    // Feedback direto para simplicidade neste arquivo de teste
    echo "<h1>Erro de Conexão</h1><p>Não foi possível conectar ao banco de dados.</p>";
    exit;
}

// --- Verifica Autenticação ---
if (!isset($_SESSION['user_id'])) {
    echo "<h1>Não Autenticado</h1><p>Você precisa estar logado para usar a Fila da Arena.</p><p><a href='../login.php'>Ir para Login</a></p>";
    exit();
}
$usuario_id = (int)$_SESSION['user_id'];

// --- ID "SENTINELA" para a Fila de Espera ---
// Usaremos um ID de evento que NUNCA existirá na tabela arena_eventos para representar a fila.
const FILA_EVENT_ID = -1; // Valor negativo ou outro valor seguro que não seja usado por IDs reais.

// --- Regra de Início ---
const MIN_JOGADORES_PARA_INICIAR = 3;

// --- Feedback na Página ---
$page_feedback = "";
$feedback_class = ""; // 'success' ou 'error'

// --- Lógica para Processar o clique no botão "Entrar na Fila" (via POST) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['entrar_fila'])) {
    // Limpa feedback anterior
    $page_feedback = ""; $feedback_class = "";

    try {
        $conn->beginTransaction(); // Inicia transação para garantir consistência

        // 1. Verificar se o usuário JÁ ESTÁ na fila ou em algum evento ativo
        $stmt_check_registro = $conn->prepare("SELECT id_evento, status_participacao FROM arena_registros WHERE id_usuario = ?");
        $stmt_check_registro->execute([$usuario_id]);
        $registro_existente = $stmt_check_registro->fetch(PDO::FETCH_ASSOC);

        if ($registro_existente) {
            // Usuário já registrado em algum lugar
            if ($registro_existente['id_evento'] === FILA_EVENT_ID) {
                $page_feedback = "Você já está na fila de espera.";
                $feedback_class = 'info'; // Nova classe CSS para info
            } else {
                // Usuário já está em um evento real
                $stmt_nome_evento = $conn->prepare("SELECT nome_evento FROM arena_eventos WHERE id = ?");
                $stmt_nome_evento->execute([$registro_existente['id_evento']]);
                $nome_evento_real = $stmt_nome_evento->fetchColumn() ?: "Evento Desconhecido";
                $page_feedback = "Você já está registrado no evento '{$nome_evento_real}' com status '{$registro_existente['status_participacao']}'.";
                $feedback_class = 'info';
                // Opcional: Redirecionar para o evento real se ele estiver EM_ANDAMENTO
                // if ($registro_existente['status_participacao'] === 'PARTICIPANDO' || $registro_existente['status_participacao'] === 'REGISTRADO') {
                //    header("Location: arenaroyalle.php?id_evento=" . $registro_existente['id_evento']); exit();
                // }
            }
            $conn->rollBack(); // Nada a commitar se já existe
            // A execução continua para exibir a página com o feedback
        } else {
            // 2. Usuário NÃO está registrado, ADICIONA ele na fila
            // Usa INSERT IGNORE para o caso muito raro de dois cliques simultâneos passarem na primeira checagem
            $stmt_add_fila = $conn->prepare("INSERT INTO arena_registros (id_usuario, id_evento, status_participacao) VALUES (?, ?, 'REGISTRADO')");
            if (!$stmt_add_fila->execute([$usuario_id, FILA_EVENT_ID])) {
                // Loga o erro PDO detalhado
                error_log("Erro PDO INSERT fila arena user {$usuario_id}: " . print_r($conn->errorInfo(), true));
                throw new Exception("Falha ao entrar na fila de espera no banco de dados.");
            }


            // 3. Conta QUEM ESTÁ na fila agora (incluindo o recém-adicionado)
            $stmt_count_fila = $conn->prepare("SELECT id_usuario FROM arena_registros WHERE id_evento = ? AND status_participacao = 'REGISTRADO' ORDER BY id ASC LIMIT " . MIN_JOGADORES_PARA_INICIAR);
            $stmt_count_fila->execute([FILA_EVENT_ID]);
            $jogadores_na_fila = $stmt_count_fila->fetchAll(PDO::FETCH_COLUMN, 0);
            $total_na_fila = count($jogadores_na_fila);

            // 4. Verifica se a fila atingiu o tamanho mínimo para iniciar um evento
            if ($total_na_fila >= MIN_JOGADORES_PARA_INICIAR) {
                // --- INICIAR NOVO EVENTO PARA ESTES JOGADORES ---

                // A. Criar um novo evento na tabela arena_eventos (com dados padrão/teste)
                $nome_evento_novo = "Arena Rápida #" . date('YmdHis'); // Nome com timestamp
                $data_inicio_novo = date('Y-m-d H:i:s'); // Agora
                $xp_reward_novo = 500; // XP padrão
                $player_count_novo = MIN_JOGADORES_PARA_INICIAR; // Máx = Mín para teste
                $descricao_novo = "Evento rápido iniciado pela fila.";

                $sql_insert_evento = "INSERT INTO arena_eventos (nome_evento, data_inicio_evento, base_xp_reward, base_player_count, descricao, status)
                                      VALUES (:nome, :data_inicio, :xp_reward, :player_count, :descricao, 'EM_ANDAMENTO')"; // Inicia já EM_ANDAMENTO

                $stmt_insert_evento = $conn->prepare($sql_insert_evento);
                $insert_params = [
                    ':nome' => $nome_evento_novo,
                    ':data_inicio' => $data_inicio_novo,
                    ':xp_reward' => $xp_reward_novo,
                    ':player_count' => $player_count_novo,
                    ':descricao' => $descricao_novo
                ];
                if (!$stmt_insert_evento->execute($insert_params)) {
                    error_log("Erro PDO INSERT novo evento para fila user {$usuario_id}: " . print_r($conn->errorInfo(), true));
                    throw new Exception("Falha ao criar evento para a fila.");
                }
                $novo_evento_id = $conn->lastInsertId();
                if (!$novo_evento_id) {
                    error_log("Erro PDO lastInsertId novo evento fila user {$usuario_id}: " . print_r($conn->errorInfo(), true));
                    throw new Exception("Não foi possível obter o ID do novo evento.");
                }


                // B. Associar os jogadores da fila ao novo evento e tirá-los da fila
                // Converte o array de IDs de jogadores para uma string para usar no IN clause
                $placeholders = implode(',', array_fill(0, count($jogadores_na_fila), '?'));
                $sql_update_fila = "UPDATE arena_registros
                                    SET id_evento = ?, status_participacao = 'REGISTRADO' -- Mantém REGISTRADO, arenaroyalle.php muda para PARTICIPANDO na entrada
                                    WHERE id_evento = ? AND id_usuario IN ({$placeholders})";

                $stmt_update_fila = $conn->prepare($sql_update_fila);
                // Os parâmetros são: o novo ID do evento, o ID da fila, e os IDs dos jogadores da fila
                $update_params = array_merge([$novo_evento_id, FILA_EVENT_ID], $jogadores_na_fila);

                if (!$stmt_update_fila->execute($update_params)) {
                    error_log("Erro PDO UPDATE fila para evento {$novo_evento_id}: " . print_r($conn->errorInfo(), true));
                    throw new Exception("Falha ao associar jogadores ao novo evento.");
                }

                $conn->commit(); // Confirma a criação do evento e a associação dos jogadores

                // ---> Redirecionar para a página da Arena do evento recém-criado <---
                $_SESSION['feedback_geral'] = "Evento '{$nome_evento_novo}' iniciado com {$total_na_fila} jogadores! Entrando na Arena...";
                header("Location: arenaroyalle.php?id_evento={$novo_evento_id}");
                exit();


            } else {
                // Ainda não tem jogadores suficientes na fila
                $conn->commit(); // Confirma que entrou na fila

                $page_feedback = "Você entrou na fila. Aguardando mais " . (MIN_JOGADORES_PARA_INICIAR - $total_na_fila) . " jogador(es). Total na fila: {$total_na_fila}.";
                $feedback_class = 'info';

                // Re-busca a contagem para exibir corretamente na página (se não houve redirecionamento)
                try {
                    $stmt_count_fila_display = $conn->prepare("SELECT COUNT(*) FROM arena_registros WHERE id_evento = ? AND status_participacao = 'REGISTRADO'");
                    $stmt_count_fila_display->execute([FILA_EVENT_ID]);
                    $total_registrados_atual = $stmt_count_fila_display->fetchColumn();
                    $evento_teste_status = 'AGUARDANDO_JOGADORES'; // Define status para exibição
                    $evento_teste_nome = "Fila Rápida"; // Nome para exibição
                } catch(PDOException $e_display) {
                    error_log("Erro PDO ao re-buscar contagem da fila para exibicao: " . $e_display->getMessage());
                    // Não sobrescreve o feedback principal, apenas loga
                }
            }


    } catch (PDOException $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        error_log("Erro PDO CAUGHT no processamento da fila user {$usuario_id}: " . $e->getMessage());
        $page_feedback = "Erro no banco de dados ao processar sua solicitação.";
        $feedback_class = 'error';

    } catch (Exception $ex) {
         if ($conn->inTransaction()) { $conn->rollBack(); }
        error_log("Erro GERAL CAUGHT no processamento da fila user {$usuario_id}: " . $ex->getMessage());
        $page_feedback = "Ocorreu um erro: " . $ex->getMessage();
        $feedback_class = 'error';
    }
    // A execução continua para exibir a página HTML com o feedback.
}

// --- Lógica para exibir o Status da Fila/Evento atual do usuário ao carregar a página ---
// Se o método NÃO for POST (primeira carga ou recarga após erro não fatal de POST)
if ($_SERVER["REQUEST_METHOD"] !== "POST" || (!empty($page_feedback) && $feedback_class === 'error')) {
    try {
        // Primeiro, verifica se o usuário já está em algum registro (fila ou evento)
        $stmt_check_my_status = $conn->prepare("SELECT id_evento, status_participacao FROM arena_registros WHERE id_usuario = ? LIMIT 1");
        $stmt_check_my_status->execute([$usuario_id]);
        $my_registro = $stmt_check_my_status->fetch(PDO::FETCH_ASSOC);

        if ($my_registro) {
            if ($my_registro['id_evento'] === FILA_EVENT_ID) {
                // Usuário está na fila, busca contagem atual da fila
                $stmt_count_fila_display = $conn->prepare("SELECT COUNT(*) FROM arena_registros WHERE id_evento = ? AND status_participacao = 'REGISTRADO'");
                $stmt_count_fila_display->execute([FILA_EVENT_ID]);
                $total_na_fila = $stmt_count_fila_display->fetchColumn();

                $page_feedback = "Você está na fila de espera. Aguardando mais " . max(0, MIN_JOGADORES_PARA_INICIAR - $total_na_fila) . " jogador(es). Total na fila: {$total_na_fila}.";
                $feedback_class = 'info';
                $evento_teste_status = 'AGUARDANDO_JOGADORES';
                $evento_teste_nome = "Fila Rápida";

            } else {
                // Usuário está em um evento real, busca status e nome do evento
                $stmt_event_real = $conn->prepare("SELECT nome_evento, status FROM arena_eventos WHERE id = ?");
                $stmt_event_real->execute([$my_registro['id_evento']]);
                $event_real_info = $stmt_event_real->fetch(PDO::FETCH_ASSOC);

                if ($event_real_info) {
                    $evento_teste_status = $event_real_info['status'];
                    $evento_teste_nome = htmlspecialchars($event_real_info['nome_evento']);
                    $total_registrados_atual = "N/D"; // Não exibimos contagem na página principal para eventos já criados aqui
                    $page_feedback = "Você está registrado no evento '{$evento_teste_nome}' (Status: {$evento_teste_status}).";
                    $feedback_class = 'info'; // Ou 'success' se EM_ANDAMENTO
                    if ($evento_teste_status === 'EM_ANDAMENTO') {
                        $page_feedback .= " Pronto para entrar na Arena!";
                        $feedback_class = 'success';
                    }

                } else {
                    // Registro aponta para evento inexistente
                    $page_feedback = "Seu registro aponta para um evento que não existe mais.";
                    $feedback_class = 'error';
                    $evento_teste_status = "Registro Invalido";
                    $evento_teste_nome = "Erro";
                }
            }
        } else {
            // Usuário não está registrado em nenhum lugar, busca a contagem da fila para exibir
            $stmt_count_fila_display = $conn->prepare("SELECT COUNT(*) FROM arena_registros WHERE id_evento = ? AND status_participacao = 'REGISTRADO'");
            $stmt_count_fila_display->execute([FILA_EVENT_ID]);
            $total_na_fila = $stmt_count_fila_display->fetchColumn();

            $page_feedback = "Clique abaixo para entrar na fila da Arena.";
            $feedback_class = 'info'; // Ou outra classe neutra
            $evento_teste_status = 'AGUARDANDO_JOGADORES'; // Define status para exibição
            $evento_teste_nome = "Fila Rápida";
            $total_registrados_atual = $total_na_fila;
        }

    } catch (PDOException $e) {
        error_log("Erro PDO na lógica de status inicial user {$usuario_id}: " . $e->getMessage());
        $page_feedback = "Erro ao verificar seu status na Arena.";
        $feedback_class = 'error';
    } catch (Exception $ex) {
        error_log("Erro GERAL na lógica de status inicial user {$usuario_id}: " . $ex->getMessage());
        $page_feedback = "Ocorreu um erro ao verificar seu status.";
        $feedback_class = 'error';
    }
}

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
        .feedback.info { background-color: #cce5ff; color: #004085; border: 1px solid #b8daff; } /* Novo estilo para info */

        button { padding: 12px 25px; font-size: 1.1em; background-color: #5cb85c; color: white; border: none; border-radius: 4px; cursor: pointer; transition: background-color 0.3s ease; }
        button:hover { background-color: #4cae4c; }
        button:disabled { background-color: #888; cursor: not-allowed; } /* Estilo para botão desabilitado */

        a { color: #f0a500; text-decoration: none; margin-top: 20px; display: inline-block; }
        a:hover { text-decoration: underline; }

        .event-status-info { margin-top: 20px; font-size: 1em; line-height: 1.6; }
        .status-aguardando { color: #f0a500; font-weight: bold; }
        .status-em-andamento { color: #5cb85c; font-weight: bold; }
        .status-outro { color: #bbb; } /* Para outros status */

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

        <?php // Exibir status atual da Fila/Evento de teste ?>
        <?php if ($evento_teste_status !== null): ?>
            <div class="event-status-info">
                <?php if ($evento_teste_status === 'AGUARDANDO_JOGADORES'): ?>
                    Status da Fila: <span class="status-aguardando">Aguardando Jogadores</span><br>
                    Jogadores na Fila: <strong><?php echo $total_registrados_atual; ?> / <?php echo MIN_JOGADORES_PARA_INICIAR; ?></strong>
                <?php elseif ($evento_teste_status === 'EM_ANDAMENTO'): ?>
                    Evento: <strong><?php echo $evento_teste_nome; ?></strong><br>
                    Status: <span class="status-em-andamento">EM ANDAMENTO</span>
                <?php else: ?>
                    Evento: <strong><?php echo $evento_teste_nome; ?></strong><br>
                    Status: <span class="status-outro"><?php echo htmlspecialchars($evento_teste_status); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>


        <?php // Formulário/Botão de Registro ou Link para Arena ?>
        <?php
 // Verifica se o usuário já está na fila ou em um evento para exibir o botão correto
 $stmt_check_my_status_display = $conn->prepare("SELECT id_evento, status_participacao FROM arena_registros WHERE id_usuario = ? LIMIT 1");
 $stmt_check_my_status_display->execute([$usuario_id]);
 $my_current_arena_status = $stmt_check_my_status_display->fetch(PDO::FETCH_ASSOC);

 if (!$my_current_arena_status): // Se o usuário não está registrado em nenhum lugar ?>
                <p style="margin-top: 20px;">Clique no botão abaixo para entrar na fila de espera da Arena.</p>
                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <button type="submit" name="entrar_fila">Entrar na Fila da Arena</button>
                </form>
            <?php elseif ($my_current_arena_status['id_evento'] === FILA_EVENT_ID): // Se está na fila ?>
                <p style="margin-top: 20px;">Você está na fila de espera.</p>
                <button disabled>Aguardando outros jogadores...</button> <?php // Botão desabilitado na fila ?>
            <?php else: // Se está registrado em um evento real ?>
                <?php
 // Busca o status real do evento em que o usuário está registrado
 $stmt_real_event_status = $conn->prepare("SELECT status FROM arena_eventos WHERE id = ?");
 $stmt_real_event_status->execute([$my_current_arena_status['id_evento']]);
 $real_event_status = $stmt_real_event_status->fetchColumn();

 if ($real_event_status === 'EM_ANDAMENTO'): // Se o evento real está em andamento ?>
                        <p style="margin-top: 20px;">Seu evento já começou!</p>
                        <p><a href="arenaroyalle.php?id_evento=<?php echo $my_current_arena_status['id_evento']; ?>">Entrar na Arena</a></p>
                    <?php else: // Se o evento real não está mais em andamento (Concluido, etc.) ?>
                        <p style="margin-top: 20px;">Seu registro anterior está em um evento que não está mais ativo.</p>
                        <?php // Opcional: Adicionar um botão para "Sair do evento concluído" que deletaria o registro ?>
                    <?php endif; ?>
            <?php endif; ?>


        <p><a href="../home.php">Voltar para Página Inicial</a></p> <?php // Link para voltar ?>

    </div>
</body>
</html>