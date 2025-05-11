<?php
// --- BLOCO AJAX: Executado PRIMEIRO se a action for 'check_arena_status' ---
// (Permanece igual)
if (isset($_GET['action']) && $_GET['action'] === 'check_arena_status') {
    session_start();
    if (!isset($_SESSION['user_id'])) {
        http_response_code(403); echo json_encode(['success' => false, 'status' => 'ERROR', 'error' => 'Acesso não autorizado.']); exit();
    }
    require_once(__DIR__ . '/conexao.php'); // <<<<< VERIFIQUE PATH

    $response = ['success' => false, 'status' => 'UNKNOWN', 'event_id' => null];
    $eventId = filter_input(INPUT_GET, 'event_id', FILTER_VALIDATE_INT);

    if (!$eventId) {
        http_response_code(400); $response['error'] = 'ID do evento inválido.'; echo json_encode($response); if (isset($conn)) $conn = null; exit();
    }
    $response['event_id'] = $eventId;

    try {
        if (!isset($conn) || !($conn instanceof PDO)) { throw new Exception("DB indisponível para AJAX check."); }
        $stmt = $conn->prepare("SELECT status FROM arena_eventos WHERE id = ? LIMIT 1");
        $stmt->execute([$eventId]);
        $eventStatus = $stmt->fetchColumn();
        if ($eventStatus === false) { $response['status'] = 'NOT_FOUND'; $response['error'] = 'Evento não encontrado.'; }
        else { $response['success'] = true; $response['status'] = $eventStatus; }
    } catch (Exception $e) {
        error_log("Exception AJAX check praca.php ev $eventId: " . $e->getMessage());
        http_response_code(500); $response['error'] = 'Erro interno servidor (check).'; $response['status'] = 'ERROR_INTERNAL';
    }

    if (isset($conn)) $conn = null;
    header('Content-Type: application/json'); echo json_encode($response); exit();
}
// --- FIM DO BLOCO AJAX ---


// --- Execução Normal da Página ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); } // <<<<< VERIFIQUE PATH

// --- Includes Essenciais ---
require_once('conexao.php'); // <<<<< VERIFIQUE PATH
$sala_functions_path = __DIR__ . '/script/sala_functions.php'; // <<<<< VERIFIQUE PATH
if (!file_exists($sala_functions_path)) { $sala_functions_path = __DIR__ . '/../script/sala_functions.php'; }
if (file_exists($sala_functions_path)) { require_once($sala_functions_path); }
else {
    error_log("FATAL: script/sala_functions.php não encontrado (praca.php).");
    if (!function_exists('formatarNomeUsuarioComTag')) { function formatarNomeUsuarioComTag(PDO $conn, $userInfoInput): string { if (is_array($userInfoInput) && isset($userInfoInput['nome'])) { return htmlspecialchars($userInfoInput['nome']); } return 'Desconhecido'; } }
    if (!function_exists('verificarLevelUpHome')) { function verificarLevelUpHome(PDO $conn, int $userId): array { return []; } }
}
if (!function_exists('formatNumber')) { function formatNumber($num) { $n = filter_var($num, FILTER_VALIDATE_INT); return number_format(($n === false ? 0 : $n), 0, ',', '.'); } }

// --- Variáveis e Configurações ---
$id = $_SESSION['user_id'];
$usuario = null;
$outros_na_praca = [];
$chat_history = [];
$pending_challenges = [];
$mensagem_final_feedback = "";
$currentPage = basename($_SERVER['PHP_SELF']);
$nome_formatado_usuario = 'ErroNome';
$rank_nivel = 'N/A';
$rank_sala = 'N/A';
$unread_notifications_for_popup = [];
// Constantes e Configs
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);
$intervalo_atividade_minutos = 5;
$intervalo_limpeza_minutos = $intervalo_atividade_minutos * 2;
$chat_history_limit = 50;
$challenge_duration_seconds = 60;
// Arena (Lógica com registro condicional)
$current_event_status = 'SEM_EVENTO_ABERTO'; // Status inicial padrão
$current_event_id = null;
$is_registered_current_event = false; // Se o user está registrado no evento ABERTO atual
$is_participating_active_arena = false; // Se o user está JOGANDO uma arena EM_ANDAMENTO
$active_event_id = null; // ID da arena EM_ANDAMENTO que o user está jogando
$current_reg_count = 0; // Contagem de registrados no evento ABERTO atual
$required_players = 100; // Limite padrão
$arena_do_usuario_para_exibir = []; // Array para dados da arena EM_ANDAMENTO
$open_event_for_display = null; // Array para dados do evento REGISTRO_ABERTO


// --- Processar Ações de Notificação via GET ---
// (Permanece igual)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['action'])) {
     if (!isset($conn) || !$conn instanceof PDO) {
       try { require('conexao.php'); if (!isset($conn) || !$conn instanceof PDO) { throw new Exception("DB indisponível para GET actions (Praca)."); } } catch (Exception $e) { die("Erro crítico DB (Praca GET Actions)."); }
     }
     $notification_action_processed = false;
     // Marcar como lida
     if (isset($_GET['mark_read']) && filter_var($_GET['mark_read'], FILTER_VALIDATE_INT)) {
         $notif_id_to_mark = (int)$_GET['mark_read'];
         try { $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_to_mark, ':uid' => $id]); }
         catch (PDOException $e) { $_SESSION['feedback_geral'] = "<span class='error-message'>Erro marcar notificação.</span>"; error_log("Erro PDO marcar notif {$notif_id_to_mark} lida user {$id} (Praca): " . $e->getMessage()); }
         $notification_action_processed = true;
     }
     // Convite de GRUPO
     elseif (isset($_GET['invite_action']) && isset($_GET['notif_id']) && filter_var($_GET['notif_id'], FILTER_VALIDATE_INT)) {
         $invite_action = $_GET['invite_action']; $notif_id_invite = (int)$_GET['notif_id'];
         if ($invite_action === 'accept' || $invite_action === 'reject') {
            try {
                $stmt_get_invite = $conn->prepare("SELECT related_data FROM user_notifications WHERE id = :notif_id AND user_id = :user_id AND type = 'invite'");
                $stmt_get_invite->execute([':notif_id' => $notif_id_invite, ':user_id' => $id]);
                $invite_data_row = $stmt_get_invite->fetch(PDO::FETCH_ASSOC);
                if ($invite_data_row) {
                    $related_data = json_decode($invite_data_row['related_data'] ?? '{}', true);
                    $group_id = $related_data['group_id'] ?? null;
                    if ($invite_action === 'accept' && $group_id) {
                        $sql_check_current_group = "SELECT grupo_id FROM grupo_membros WHERE user_id = :user_id LIMIT 1";
                        $stmt_check_current = $conn->prepare($sql_check_current_group);
                        $stmt_check_current->execute([':user_id' => $id]);
                        if ($stmt_check_current->fetchColumn()) { $_SESSION['feedback_geral'] = "<span class='error-message'>Você já pertence a um grupo.</span>"; }
                        else {
                            $sql_add_member = "INSERT INTO grupo_membros (grupo_id, user_id, cargo) VALUES (:grupo_id, :user_id, 'membro')";
                            if ($conn->prepare($sql_add_member)->execute([':grupo_id' => $group_id, ':user_id' => $id])) {
                                $_SESSION['feedback_geral'] = "<span class='success-message'>Bem-vindo à equipe!</span>";
                                $conn->prepare("UPDATE grupo_convites SET status = 'aceito' WHERE convidado_user_id = :uid AND grupo_id = :gid AND status = 'pendente'")->execute([':uid' => $id, ':gid' => $group_id]);
                                $conn->prepare("UPDATE grupo_convites SET status = 'recusado' WHERE convidado_user_id = :uid AND status = 'pendente' AND grupo_id != :gid")->execute([':uid' => $id, ':gid' => $group_id]);
                            } else { $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao entrar no grupo.</span>"; }
                        }
                    } else { // 'reject'
                        $_SESSION['feedback_geral'] = "<span class='info-message'>Convite de grupo recusado.</span>";
                        if ($group_id) { $conn->prepare("UPDATE grupo_convites SET status = 'recusado' WHERE convidado_user_id = :uid AND grupo_id = :gid AND status = 'pendente'")->execute([':uid' => $id, ':gid' => $group_id]); }
                    }
                    $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_invite, ':uid' => $id]);
                } else { $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de grupo inválido.</span>"; }
            } catch (Exception $e) {
                $_SESSION['feedback_geral'] = "<span class='error-message'>Erro processar convite grupo.</span>"; error_log("Erro invite_action (Praca): " . $e->getMessage());
                try { $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")->execute([':id'=>$notif_id_invite, ':uid'=>$id]); } catch(PDOException $em){}
            } $notification_action_processed = true;
         }
     }
     // Convite de FUSÃO
     elseif (isset($_GET['fusion_action']) && isset($_GET['notif_id']) && filter_var($_GET['notif_id'], FILTER_VALIDATE_INT)) {
        $fusion_action = $_GET['fusion_action']; $notif_id_fusion = (int)$_GET['notif_id'];
        if ($fusion_action === 'accept' || $fusion_action === 'reject') {
            try {
                 $stmt_get_fusion = $conn->prepare("SELECT related_data FROM user_notifications WHERE id = :notif_id AND user_id = :user_id AND type = 'fusion_invite'");
                 $stmt_get_fusion->execute([':notif_id' => $notif_id_fusion, ':user_id' => $id]);
                 $fusion_data_row = $stmt_get_fusion->fetch(PDO::FETCH_ASSOC);
                 if ($fusion_data_row) {
                     $related_data = json_decode($fusion_data_row['related_data'] ?? '{}', true);
                     $inviter_id = $related_data['inviter_id'] ?? null;
                     $desafio_id = $related_data['desafio_id'] ?? null;
                     if ($inviter_id && $desafio_id) {
                         if ($fusion_action === 'accept') {
                             $sql = "UPDATE convites_fusao SET status = 'aceito', data_atualizacao = NOW() WHERE convidante_id = :inviter_id AND convidado_id = :user_id AND desafio_id = :desafio_id AND status = 'pendente'";
                             $stmt = $conn->prepare($sql);
                             if ($stmt->execute([':inviter_id' => $inviter_id, ':user_id' => $id, ':desafio_id' => $desafio_id]) && $stmt->rowCount() > 0) { $_SESSION['feedback_geral'] = "<span class='success-message'>Fusão aceita!</span>"; }
                             else { $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite fusão não encontrado/respondido.</span>"; }
                         } else { // reject
                             $sql = "UPDATE convites_fusao SET status = 'recusado', data_atualizacao = NOW() WHERE convidante_id = :inviter_id AND convidado_id = :user_id AND desafio_id = :desafio_id AND status = 'pendente'";
                             $conn->prepare($sql)->execute([':inviter_id' => $inviter_id, ':user_id' => $id, ':desafio_id' => $desafio_id]);
                             $_SESSION['feedback_geral'] = "<span class='info-message'>Convite de fusão recusado.</span>";
                         }
                         $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_fusion, ':uid' => $id]);
                     } else { $_SESSION['feedback_geral'] = "<span class='error-message'>Dados convite fusão incompletos.</span>"; $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_fusion, ':uid' => $id]); }
                 } else { $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite fusão inválido.</span>"; }
            } catch (Exception $e) {
                 $_SESSION['feedback_geral'] = "<span class='error-message'>Erro processar convite fusão.</span>"; error_log("Erro fusion_action (Praca): " . $e->getMessage());
                 try { $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_fusion, ':uid' => $id]); } catch (PDOException $e_mark) {}
            } $notification_action_processed = true;
        }
     }
     // Entrada na Arena (via notificação de início)
     elseif (isset($_GET['arena_action']) && $_GET['arena_action'] === 'enter' && isset($_GET['notif_id']) && filter_var($_GET['notif_id'], FILTER_VALIDATE_INT) && isset($_GET['event_id']) && filter_var($_GET['event_id'], FILTER_VALIDATE_INT)) {
         $notif_id_arena = (int)$_GET['notif_id']; $event_id_arena = (int)$_GET['event_id'];
         try {
             if ($conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_arena, ':uid' => $id])) {
                 $arena_url = "/script/arena/arenaroyalle.php?event_id=" . $event_id_arena; // <<<<< VERIFIQUE PATH ARENA
                 header("Location: " . $arena_url); exit();
             } else { $_SESSION['feedback_geral'] = "<span class='warning-message'>Não foi possível processar notificação arena.</span>"; }
         } catch (Exception $e) {
             $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao entrar na arena.</span>"; error_log("Erro arena_action (Praca): " . $e->getMessage());
             try { $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")->execute([':id'=>$notif_id_arena, ':uid'=>$id]); } catch(PDOException $em){}
         } $notification_action_processed = true;
     }

     // Redireciona para limpar GET params
     if ($notification_action_processed) { header("Location: praca.php"); exit(); }
}
// --- FIM Processar Ações GET ---

// --- Coleta de Feedback da Sessão ---
if (isset($_SESSION['feedback_geral'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_geral']; unset($_SESSION['feedback_geral']); }

// --- [REINTRODUZIDO] Processamento Registro Arena (POST - Simples) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    // Validação de conexão
    if (!isset($conn) || !($conn instanceof PDO)) {
        $_SESSION['feedback_geral'] = "<span class='error'>Erro de conexão com o banco de dados ao registrar.</span>";
        header("Location: praca.php"); exit();
    }
    $conn->beginTransaction();
    try {
        // 1. Encontra o evento ABERTO para registro (só deve haver um por vez, gerenciado pelo cron)
        $stmt_evento_aberto = $conn->prepare("SELECT id FROM arena_eventos WHERE status = 'REGISTRO_ABERTO' ORDER BY data_inicio_registro DESC LIMIT 1 FOR UPDATE");
        $stmt_evento_aberto->execute();
        $evento_aberto = $stmt_evento_aberto->fetch(PDO::FETCH_ASSOC);

        // Verifica se realmente existe um evento aberto
        if (!$evento_aberto) {
            throw new Exception("Registro fechado. Nenhuma arena disponível para registro no momento.");
        }
        $id_evento_registro = $evento_aberto['id'];

        // 2. Verifica se já está registrado neste evento
        $stmt_check_reg_aberto = $conn->prepare("SELECT id FROM arena_registros WHERE id_usuario = ? AND id_evento = ?");
        $stmt_check_reg_aberto->execute([$id, $id_evento_registro]);
        if ($stmt_check_reg_aberto->fetch()) {
            $conn->commit(); // Commit pois a verificação foi bem-sucedida, mas não há o que fazer
            $_SESSION['feedback_geral'] = "<span class='info-message'>Você já está registrado neste evento. Aguarde o início.</span>";
            header("Location: praca.php"); exit();
        }

        // 3. Verifica se já está PARTICIPANDO de outra Arena EM ANDAMENTO
        $stmt_check_participando_andamento = $conn->prepare(
            "SELECT r.id_evento FROM arena_registros r JOIN arena_eventos e ON r.id_evento = e.id
             WHERE r.id_usuario = ? AND e.status = 'EM_ANDAMENTO' AND r.status_participacao = 'PARTICIPANDO' LIMIT 1");
        $stmt_check_participando_andamento->execute([$id]);
        if ($active_reg = $stmt_check_participando_andamento->fetch(PDO::FETCH_ASSOC)) {
            $conn->rollBack(); // Rollback pois não deve registrar
            $_SESSION['feedback_geral'] = "<span class='warning-message'>Você já está participando de outra Arena em andamento!</span>";
            header("Location: /script/arena/arenaroyalle.php?event_id=" . $active_reg['id_evento']); // <<<<< VERIFIQUE PATH
            exit();
        }

        // 4. Insere o registro do usuário para o evento aberto
        // (Não verifica mais limite de jogadores aqui, apenas se o evento está aberto)
        $stmt_register = $conn->prepare("INSERT INTO arena_registros (id_usuario, id_evento, status_participacao, data_registro) VALUES (?, ?, 'REGISTRADO', NOW())");
        if (!$stmt_register->execute([$id, $id_evento_registro])) {
            throw new PDOException("Falha ao registrar na Arena.");
        }

        // 5. Commita a transação e dá feedback de sucesso
        $conn->commit();
        $_SESSION['feedback_geral'] = "<span class='success-message'>Você foi registrado na Arena ID: {$id_evento_registro}! Aguarde o início ser anunciado.</span>";
        header("Location: praca.php");
        exit();

    } catch (Throwable $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        error_log("Erro POST Arena Praca user {$id}: " . $e->getMessage());
        // Mostra a mensagem de erro específica se for uma Exception normal, ou uma genérica se for PDOException
        $feedback_error = ($e instanceof Exception && !($e instanceof PDOException)) ? $e->getMessage() : "Erro interno ao processar registro na Arena.";
        $_SESSION['feedback_geral'] = "<span class='error'>" . htmlspecialchars($feedback_error) . "</span>";
        header("Location: praca.php");
        exit();
    }
} // Fim POST

// --- Lógica Geral da Página (GET) ---
try {
    if (!isset($conn) || !$conn instanceof PDO) { throw new Exception("DB indisponível para GET (Praca)."); }

    // Updates de atividade, limpeza, expiração de desafios
    $conn->prepare("INSERT INTO praca_online (user_id, last_active) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_active = NOW()")->execute([$id]);
    $conn->prepare("DELETE FROM praca_online WHERE last_active < NOW() - INTERVAL ? MINUTE")->execute([$intervalo_limpeza_minutos]);
    $conn->prepare("UPDATE pvp_challenges SET status = 'expired' WHERE status = 'pending' AND created_at < NOW() - INTERVAL ? SECOND")->execute([$challenge_duration_seconds]);

    // Busca dados do usuário
    $stmt_usuario = $conn->prepare("SELECT nome, nivel, hp, pontos, xp, ki, forca, id, defesa, velocidade, foto, tempo_sala, raca, zeni, ranking_titulo FROM usuarios WHERE id = :id_usuario");
    $stmt_usuario->execute([':id_usuario' => $id]); $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) { session_destroy(); header("Location: login.php?erro=UserNotFoundPracaFatal"); exit(); } // <<<<< VERIFIQUE PATH

    // Busca Ranks
    try { $sql_rank_nivel = "SELECT rank_nivel FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC) as rank_nivel FROM usuarios) AS ranked_by_level WHERE id = :user_id"; $stmt_rank_nivel = $conn->prepare($sql_rank_nivel); $stmt_rank_nivel->execute([':user_id' => $id]); $result_nivel = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC); if ($result_nivel) { $rank_nivel = $result_nivel['rank_nivel']; } $sql_rank_sala = "SELECT rank_sala FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala FROM usuarios) AS ranked_by_time WHERE id = :user_id"; $stmt_rank_sala = $conn->prepare($sql_rank_sala); $stmt_rank_sala->execute([':user_id' => $id]); $result_sala = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC); if ($result_sala) { $rank_sala = $result_sala['rank_sala']; } } catch (PDOException $e_rank) { error_log("PDOException ranks praca.php user {$id}: " . $e_rank->getMessage()); }

    // Busca NOTIFICAÇÕES NÃO LIDAS para o Popup
    try {
        $sql_unread_notif = "SELECT id, message_text, type, timestamp, related_data FROM user_notifications WHERE user_id = :user_id AND is_read = 0 ORDER BY timestamp ASC";
        $stmt_unread_notif = $conn->prepare($sql_unread_notif);
        if ($stmt_unread_notif && $stmt_unread_notif->execute([':user_id' => $id])) {
            $unread_notifications_for_popup = $stmt_unread_notif->fetchAll(PDO::FETCH_ASSOC);
        } else { error_log("Erro PDO fetch unread notifs user {$id} (Praca): " . print_r($conn->errorInfo() ?: ($stmt_unread_notif ? $stmt_unread_notif->errorInfo() : 'Prepare Failed'), true)); }
    } catch (PDOException $e_notif) { error_log("Erro PDO buscar notifs não lidas user {$id} (Praca): " . $e_notif->getMessage()); }

    // Busca outros na praça, chat history, desafios pendentes (inalterado)
    $sql_outros = "SELECT u.id, u.nome, u.nivel FROM praca_online po JOIN usuarios u ON po.user_id = u.id WHERE po.last_active > NOW() - INTERVAL :intervalo_ativo MINUTE AND po.user_id != :current_user_id ORDER BY u.nome ASC"; $stmt_outros = $conn->prepare($sql_outros); $stmt_outros->bindValue(':intervalo_ativo', $intervalo_atividade_minutos, PDO::PARAM_INT); $stmt_outros->bindValue(':current_user_id', $id, PDO::PARAM_INT); $stmt_outros->execute(); $outros_na_praca = $stmt_outros->fetchAll(PDO::FETCH_ASSOC);
    $sql_hist = "SELECT id, user_id, username, message_text, timestamp FROM ( SELECT id, user_id, username, message_text, timestamp FROM chat_messages ORDER BY id DESC LIMIT :limit_chat ) sub ORDER BY id ASC"; $stmt_hist = $conn->prepare($sql_hist); $stmt_hist->bindValue(':limit_chat', $chat_history_limit, PDO::PARAM_INT); $stmt_hist->execute(); $chat_history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
    $sql_challenges = "SELECT pc.id AS challenge_id, pc.challenger_id, u.nome AS challenger_name, pc.created_at FROM pvp_challenges pc JOIN usuarios u ON pc.challenger_id = u.id WHERE pc.challenged_id = :current_user_id AND pc.status = 'pending'"; $stmt_challenges = $conn->prepare($sql_challenges); $stmt_challenges->execute([':current_user_id' => $id]); $pending_challenges = $stmt_challenges->fetchAll(PDO::FETCH_ASSOC);


    // --- [REVISADO] Lógica Arena (GET - Verifica ativa E aberta) ---
    // 1. Verifica se o usuário está PARTICIPANDO de uma arena EM_ANDAMENTO
    $stmt_check_active_participation = $conn->prepare(
        "SELECT ar.id_evento FROM arena_registros ar JOIN arena_eventos ae ON ar.id_evento = ae.id
         WHERE ar.id_usuario = ? AND ae.status = 'EM_ANDAMENTO' AND ar.status_participacao = 'PARTICIPANDO' LIMIT 1"
    );
    $stmt_check_active_participation->execute([$id]);
    $active_event_data = $stmt_check_active_participation->fetch(PDO::FETCH_ASSOC);

    if ($active_event_data) {
        // Usuário está JOGANDO uma arena ativa
        $is_participating_active_arena = true;
        $active_event_id = $active_event_data['id_evento'];
        //$current_event_status = 'EM_ANDAMENTO'; // Status global não é mais tão relevante aqui

        // Busca dados da arena ativa para exibir link de retorno
        $stmt_get_user_arena = $conn->prepare("SELECT id, data_inicio_evento, base_player_count, titulo FROM arena_eventos WHERE id = ? AND status = 'EM_ANDAMENTO' LIMIT 1");
        $stmt_get_user_arena->execute([$active_event_id]);
        $arena_ativa_usuario = $stmt_get_user_arena->fetch(PDO::FETCH_ASSOC);
        if ($arena_ativa_usuario) {
            $limite_arena_ativa = (int)($arena_ativa_usuario['base_player_count'] ?? 0);
            $required_players_ativa = ($limite_arena_ativa > 0) ? $limite_arena_ativa : 100;
            $arena_ativa_usuario['base_player_count'] = $required_players_ativa; // Garante que o array para display tenha o valor correto

            try {
                $stmt_c = $conn->prepare("SELECT COUNT(*) FROM arena_royalle_stats WHERE id_evento = ? AND hp_arena > 0");
                $stmt_c->execute([$arena_ativa_usuario['id']]);
                $arena_ativa_usuario['active_players_count'] = $stmt_c->fetchColumn();
            } catch (PDOException $e) { $arena_ativa_usuario['active_players_count'] = '?'; }
            $arena_do_usuario_para_exibir[] = $arena_ativa_usuario;
        } else {
            // Erro estranho, registro existe mas dados da arena não? Resetar estado
            error_log("Praca GET: Dados arena ativa {$active_event_id} não encontrados user {$id}, mas registro existe!");
            $is_participating_active_arena = false;
            $active_event_id = null;
        }
    }

    // 2. Se NÃO estiver JOGANDO, verifica se há uma ABERTA PARA REGISTRO
    if (!$is_participating_active_arena) {
        $stmt_find_open = $conn->prepare("SELECT id, status, titulo, base_player_count FROM arena_eventos WHERE status = 'REGISTRO_ABERTO' ORDER BY data_inicio_registro DESC LIMIT 1");
        $stmt_find_open->execute();
        $open_event = $stmt_find_open->fetch(PDO::FETCH_ASSOC);

        if ($open_event) {
            // Encontrou evento aberto! Prepara dados para exibição/registro
            $open_event_for_display = $open_event;
            $current_event_id = $open_event['id'];
            $current_event_status = $open_event['status']; // = REGISTRO_ABERTO
            $required_players = 100; // Define o limite como 100 para exibição
            $open_event_for_display['base_player_count'] = $required_players;

            // Conta registrados
            $current_reg_count = 0;
            try {
                 $stmt_c = $conn->prepare("SELECT COUNT(*) FROM arena_registros WHERE id_evento = ? AND status_participacao = 'REGISTRADO'");
                $stmt_c->execute([$current_event_id]);
                $current_reg_count = $stmt_c->fetchColumn();
            } catch (PDOException $e) { $current_reg_count = '?'; error_log("Erro PDO contar registros arena {$current_event_id}: " . $e->getMessage()); }

            // Verifica se este usuário já está registrado neste evento aberto
            $is_registered_current_event = false;
            try {
                $stmt_cr = $conn->prepare("SELECT id FROM arena_registros WHERE id_usuario = ? AND id_evento = ? AND status_participacao = 'REGISTRADO'");
                $stmt_cr->execute([$id, $current_event_id]);
                if ($stmt_cr->fetch()) {
                    $is_registered_current_event = true;
                }
            } catch (PDOException $e) { error_log("Erro PDO verificar registro user {$id} arena {$current_event_id}: " . $e->getMessage()); }

        } else {
            // Nenhuma arena aberta encontrada
            $current_event_status = 'SEM_EVENTO_ABERTO'; // Define status específico
            $current_event_id = null;
            $open_event_for_display = null;
            $is_registered_current_event = false;
            $current_reg_count = 0;
            $required_players = 100; // Default
        }
    }
    // Se $is_participating_active_arena for true, as variáveis $current_event_* não são preenchidas aqui,
    // o que é correto, pois a seção de registro não será mostrada.

} catch (PDOException $e) {
    error_log("Erro PDO geral praca.php user {$id}: " . $e->getMessage());
    $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") ."<span class='error'>Erro DB ao carregar dados da praça/arena.</span>";
    if ($usuario === null && !headers_sent()) { header("Location: login.php?erro=DBErrorPracaFatal"); exit(); }
} catch (Exception $ex) {
    error_log("Erro GERAL praca.php user {$id}: " . $ex->getMessage());
    $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") ."<span class='error'>Ocorreu um erro inesperado. Tente novamente.</span>";
    if ($usuario === null && !headers_sent()) { header("Location: login.php?erro=GeneralErrorPracaFatal"); exit(); }
}

// --- Validação final e Preparação HTML (sem alterações) ---
if (!$usuario || !is_array($usuario) || !isset($usuario['id'])) { die("Erro Crítico: Dados do usuário inválidos antes do HTML."); }
$xp_necessario_calc = 0; $xp_percent = 0; $xp_necessario_display = 'N/A'; if (defined('XP_BASE_LEVEL_UP') && defined('XP_EXPOENTE_LEVEL_UP') && isset($usuario['nivel'])) { $nvl = filter_var($usuario['nivel'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]); if($nvl === false) $nvl = 1; $xp_nec = (int)ceil(XP_BASE_LEVEL_UP * pow($nvl, XP_EXPOENTE_LEVEL_UP)); $xp_nec = max(XP_BASE_LEVEL_UP, $xp_nec); $xp_necessario_display = formatNumber($xp_nec); if($xp_nec > 0 && isset($usuario['xp'])){ $xp_at = filter_var($usuario['xp'] ?? 0, FILTER_VALIDATE_INT); if($xp_at !== false){ $xp_percent = min(100, max(0, ($xp_at / $xp_nec) * 100)); } } } else { $xp_necessario_display = formatNumber(XP_BASE_LEVEL_UP); $xp_percent = 0; }
$nome_arquivo_foto = 'default.jpg'; if (!empty($usuario['foto'])) { $cfp = __DIR__ . '/uploads/' . $usuario['foto']; if (file_exists($cfp)) { $nome_arquivo_foto = $usuario['foto']; } else { error_log("Foto não existe: " . $cfp); } } $caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto); // <<<<< VERIFIQUE PATH
$hp_atual_base = (int)($usuario['hp'] ?? 0); $ki_atual_base = (int)($usuario['ki'] ?? 0); $pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0); $mensagem_bloqueio_templo_base = 'Recupere HP/Ki base!';
if (function_exists('formatarNomeUsuarioComTag')) { $nome_formatado_usuario = formatarNomeUsuarioComTag($conn, $usuario); } else { $nome_formatado_usuario = htmlspecialchars($usuario['nome'] ?? 'ErroFunc'); }

// --- Array Sidebar (sem alterações) ---
$sidebarItems = [
    ['id' => 'praca', 'label' => 'Praça Central', 'href' => 'praca.php', 'icon' => 'img/praca.jpg', 'fa_icon' => 'fas fa-users'],
    ['id' => 'guia', 'label' => 'Guia do Guerreiro Z', 'href' => 'guia_guerreiro.php', 'icon' => 'img/guiaguerreiro.jpg', 'fa_icon' => 'fas fa-book-open'], // <<<<< VERIFIQUE PATH
    ['id' => 'equipe', 'label' => 'Equipe Z', 'href' => 'script/grupos.php', 'icon' => 'img/grupos.jpg', 'fa_icon' => 'fas fa-users-cog'], // <<<<< VERIFIQUE PATH
    ['id' => 'banco', 'label' => 'Banco Galáctico', 'href' => 'script/banco.php', 'icon' => 'img/banco.jpg', 'fa_icon' => 'fas fa-landmark'], // <<<<< VERIFIQUE PATH
    ['id' => 'urunai', 'label' => 'Palácio da Vovó Uranai', 'href' => 'urunai.php', 'icon' => 'img/urunai.jpg', 'fa_icon' => 'fas fa-hat-wizard'], // <<<<< VERIFIQUE PATH
    ['id' => 'kame', 'label' => 'Casa do Mestre Kame', 'href' => 'kame.php', 'icon' => 'img/kame.jpg', 'fa_icon' => 'fas fa-home'], // <<<<< VERIFIQUE PATH
    ['id' => 'sala_tempo', 'label' => 'Sala do Templo', 'href' => 'templo.php', 'icon' => 'img/templo.jpg', 'fa_icon' => 'fas fa-hourglass-half'], // <<<<< VERIFIQUE PATH
    ['id' => 'treinos', 'label' => 'Zona de Treinamento', 'href' => 'desafios/treinos.php', 'icon' => 'img/treinos.jpg', 'fa_icon' => 'fas fa-dumbbell'], // <<<<< VERIFIQUE PATH
    ['id' => 'desafios', 'label' => 'Desafios Z', 'href' => 'desafios.php', 'icon' => 'img/desafios.jpg', 'fa_icon' => 'fas fa-scroll'], // <<<<< VERIFIQUE PATH
    ['id' => 'esferas', 'label' => 'Buscar Esferas', 'href' => 'esferas.php', 'icon' => 'img/esferar.jpg', 'fa_icon' => 'fas fa-dragon'], // <<<<< VERIFIQUE PATH
    ['id' => 'erros', 'label' => 'Relatar Erros', 'href' => 'erros.php', 'icon' => 'img/erros.jpg', 'fa_icon' => 'fas fa-bug'], // <<<<< VERIFIQUE PATH
    ['id' => 'perfil', 'label' => 'Perfil de Guerreiro', 'href' => 'perfil.php', 'icon' => 'img/perfil.jpg', 'fa_icon' => 'fas fa-user-edit'] // <<<<< VERIFIQUE PATH
];

// --- Array Bottom Nav (sem alterações) ---
$bottomNavItems = [
    ['label' => 'Home', 'href' => 'home.php', 'icon' => '🏠', 'id'=>'home'], // <<<<< VERIFIQUE PATH
    ['label' => 'Praça', 'href' => 'praca.php', 'icon' => '👥', 'id'=>'praca'],
    ['label' => 'Templo', 'href' => 'templo.php', 'icon' => '🏛️', 'id' => 'sala_tempo'], // <<<<< VERIFIQUE PATH
    ['label' => 'Kame', 'href' => 'kame.php', 'icon' => '🐢', 'id'=>'kame'], // <<<<< VERIFIQUE PATH
    ['label' => 'Perfil', 'href' => 'perfil.php', 'icon' => '👤', 'id'=>'perfil'] // <<<<< VERIFIQUE PATH
];
$arenaPath = 'script/arena/arenaroyalle.php'; $treinosPath = 'desafios/treinos.php'; // <<<<< VERIFIQUE PATHS
$foundArena = false; $foundTreinos = false;
foreach ($bottomNavItems as $item) { if (($item['id'] ?? null) === 'arena') $foundArena = true; if (($item['id'] ?? null) === 'treinos') $foundTreinos = true; }
if (!$foundArena) { $bottomNavItems[] = ['label' => 'Arena', 'href' => $arenaPath, 'icon' => '⚔️', 'id' => 'arena']; }
if (!$foundTreinos) { $bottomNavItems[] = ['label' => 'Treinos', 'href' => $treinosPath, 'icon' => '💪', 'id' => 'treinos']; }

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Praça Central - <?= htmlspecialchars($usuario['nome'] ?? 'Jogador') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="praca.css">
</head>
<body>
    <?php if (!empty($mensagem_final_feedback)): ?>
        <div class="feedback-container"><?= nl2br($mensagem_final_feedback) ?></div>
    <?php endif; ?>

    <div class="container">
        <?php // --- COLUNA ESQUERDA (sem alterações) --- ?>
        <div class="coluna coluna-esquerda">
             <div class="player-card"> <img class="foto" src="<?= $caminho_web_foto ?>" alt="Foto de <?= htmlspecialchars($usuario['nome'] ?? '') ?>"> <div class="player-name"><?= $nome_formatado_usuario ?></div> <div class="player-level">Nível <?= htmlspecialchars($usuario['nivel'] ?? '?') ?></div> <div class="player-race">Raça: <?= htmlspecialchars($usuario['raca'] ?? 'N/D') ?></div> <div class="player-rank">Título: <?= htmlspecialchars($usuario['ranking_titulo'] ?? 'Iniciante') ?></div> </div> <div class="zeni-display">💰 Zeni: <?= formatNumber($usuario['zeni'] ?? 0) ?></div> <div class="xp-bar-container"> <span class="xp-label">Experiência</span> <div class="xp-bar"> <div class="xp-bar-fill" style="width: <?= $xp_percent ?>%;"></div> <div class="xp-text"><?= formatNumber($usuario['xp'] ?? 0) ?> / <?= $xp_necessario_display ?></div> </div> </div> <?php $pontos_num = filter_var($usuario['pontos'] ?? 0, FILTER_VALIDATE_INT); ?> <?php if ($pontos_num > 0 && basename($_SERVER['PHP_SELF']) !== 'perfil.php'): ?> <a href="perfil.php" class="btn-pontos"> <?= formatNumber($pontos_num) ?> Pts <span style="font-size: 0.8em;">(Distribuir)</span> </a> <?php elseif($pontos_num > 0): ?> <div class="btn-pontos" style="cursor: default;"><?= formatNumber($pontos_num) ?> Pts Disponíveis</div> <?php endif; ?> <div class="stats-grid"> <strong>HP Base</strong><span><?= formatNumber($usuario['hp'] ?? 0) ?></span> <strong>Ki Base</strong><span><?= formatNumber($usuario['ki'] ?? 0) ?></span> <strong>Força</strong><span><?= formatNumber($usuario['forca'] ?? 0) ?></span> <strong>Defesa</strong><span><?= formatNumber($usuario['defesa'] ?? 0) ?></span> <strong>Velocidade</strong><span><?= formatNumber($usuario['velocidade'] ?? 0) ?></span> <strong>Pontos</strong><span style="color: var(--accent-color-2); font-weight:700;"><?= formatNumber($usuario['pontos'] ?? 0) ?></span> <strong>Rank (Nível)</strong><span style="color: var(--accent-color-1); font-weight:700;"><?= htmlspecialchars($rank_nivel) ?><?= ($rank_nivel !== 'N/A' ? 'º' : '') ?></span> <strong>Rank (Sala)</strong><span style="color: var(--accent-color-1); font-weight:700;"><?= htmlspecialchars($rank_sala) ?><?= ($rank_sala !== 'N/A' ? 'º' : '') ?></span> </div> <div class="divider"></div> <div class="stats-grid" style="font-size: 0.85rem;"> <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span> <strong>Tempo Sala</strong><span><?= formatNumber($usuario['tempo_sala'] ?? 0) ?> min</span> </div>
             <h3 style="font-size: 1rem; color: var(--accent-color-3); margin-top: 15px; margin-bottom: 8px; text-align: left; border-bottom: 1px solid var(--panel-border); padding-bottom: 5px;">Chat da Praça</h3>
             <div class="chat-container-ajax">
                 <div id="chat-messages-ajax" class="chat-messages-display">
                      <?php if (!empty($chat_history)):
                          $chatHistoryCount = count($chat_history); $messageCounter = 0;
                          foreach ($chat_history as $msg):
                              $messageCounter++; $isLastMessage = ($messageCounter === $chatHistoryCount); ?>
                              <div class="chat-message-ajax <?= $msg['user_id'] == $id ? 'mine' : 'other' ?>"
                                   data-message-id="<?= $msg['id'] ?>"
                                   <?= $isLastMessage ? 'id="last-initial-message"' : '' ?> >
                                   <span class="sender"><?= htmlspecialchars($msg['username']) ?> <small>(<?= date('H:i', strtotime($msg['timestamp'])) ?>)</small></span>
                                   <span class="msg-text"><?= htmlspecialchars($msg['message_text']) ?></span>
                              </div>
                          <?php endforeach; else: ?>
                          <p class="chat-empty">Nenhuma mensagem recente.</p>
                      <?php endif; ?>
                 </div>
                 <form id="chat-form-ajax" class="chat-input-area">
                      <input type="text" id="chat-message-input" placeholder="Digite sua mensagem..." autocomplete="off" required maxlength="500">
                      <button type="submit">Enviar</button>
                 </form>
             </div>
             <div style="margin-top: auto; padding-top: 15px; display: flex; flex-direction: column; gap: 10px;">
                 <a class="action-button btn-perfil" href="perfil.php">Editar Perfil</a> <?php // <<<<< VERIFIQUE PATH ?>
                 <a class="action-button btn-logout" href="sair.php">Sair (Logout)</a> <?php // <<<<< VERIFIQUE PATH ?>
             </div>
        </div>

        <?php // --- COLUNA CENTRAL (HTML da Arena Revisado) --- ?>
        <div class="coluna coluna-central">
             <h2>Praça Central</h2> <p> O ponto de encontro dos guerreiros! Explore, converse e desafie outros lutadores. </p>
             <div class="praca-acoes">
               <a href="home.php" class="praca-link">Home</a> <?php // <<<<< VERIFIQUE PATH ?>
               <a href="templo.php" class="praca-link <?= $pode_entrar_templo_base ? '' : 'disabled' ?>" <?= !$pode_entrar_templo_base ? 'onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base).'\'); return false;" title="'.htmlspecialchars($mensagem_bloqueio_templo_base).'"' : 'title="Ir para Sala do Templo"' ?>>Templo</a> <?php // <<<<< VERIFIQUE PATH ?>
               <a href="urunai.php" class="praca-link">Urunai</a> <?php // <<<<< VERIFIQUE PATH ?>
               <a href="kame.php" class="praca-link">Kame</a> <?php // <<<<< VERIFIQUE PATH ?>
               <a href="script/grupos.php" class="praca-link">Grupo</a> <?php // <<<<< VERIFIQUE PATH ?>
               <a href="desafios.php" class="praca-link">Desafios</a> <?php // <<<<< VERIFIQUE PATH ?>
               <a href="script/banco.php" class="praca-link">Banco</a> <?php // <<<<< VERIFIQUE PATH ?>
               <a href="#" class="praca-link disabled" title="Em Desenvolvimento">Missões</a>
             </div>

             <div id="challenge-notifications"> <?php foreach ($pending_challenges as $challenge): ?> <div class="challenge-notification" data-challenge-id="<?= $challenge['challenge_id'] ?>"> <span class="challenge-text"> <strong><?= formatarNomeUsuarioComTag($conn, ['id' => $challenge['challenger_id'], 'nome' => $challenge['challenger_name']]) ?></strong> te desafiou! <?php if (!empty($challenge['created_at'])) { echo '<small>(às ' . date('H:i', strtotime($challenge['created_at'])) . ')</small>'; } ?> </span> <div class="challenge-actions"> <button class="btn-aceitar" onclick="respondChallenge(<?= $challenge['challenge_id'] ?>, 'accept', this)">Aceitar</button> <button class="btn-recusar" onclick="respondChallenge(<?= $challenge['challenge_id'] ?>, 'decline', this)">Recusar</button> </div> </div> <?php endforeach; ?> </div>

             <hr style="border-color: var(--panel-border); margin: 15px 0; width: 90%;">

             <div class="arena-register-section">
                 <h3 class="arena-section-title">Arena Royalle</h3>
                 <div class="refresh-button-container" style="margin-bottom: 10px;"> <button onclick="window.location.href='praca.php';" class="refresh-button">Atualizar Status Arena</button> </div>

                 <?php // PRIMEIRO: Verifica se o usuário está JOGANDO uma arena ativa ?>
                 <?php if ($is_participating_active_arena): ?>
                     <h3 class="arena-section-title" style="color: #e9c46a;">Sua Arena Atual</h3>
                     <?php if (!empty($arena_do_usuario_para_exibir)): ?>
                         <div class="arena-list" style="text-align: left; padding-top: 0;">
                             <?php foreach ($arena_do_usuario_para_exibir as $arena_ativa): ?>
                                 <div class="arena-item">
                                     <p><strong>Título:</strong> <?php echo htmlspecialchars($arena_ativa['titulo'] ?? 'Arena Royalle'); ?></p>
                                     <p><strong>Início:</strong> <?php echo htmlspecialchars(date('d/m H:i', strtotime($arena_ativa['data_inicio_evento'] ?? 'now'))); ?></p>
                                     <p><strong>Jogadores Ativos:</strong> <?php echo htmlspecialchars($arena_ativa['active_players_count'] ?? '?'); ?> / <?php echo htmlspecialchars($arena_ativa['base_player_count'] ?? '100'); ?></p>
                                     <p style="margin-top: 5px;"><a href="/script/arena/arenaroyalle.php?event_id=<?= htmlspecialchars($arena_ativa['id']) ?>">Retornar à sua Arena</a></p> <?php // <<<<< VERIFIQUE PATH ?>
                                 </div>
                             <?php endforeach; ?>
                         </div>
                     <?php else: ?>
                         <p class="error">Erro ao carregar os dados da sua arena ativa. Tente atualizar.</p>
                     <?php endif; ?>

                 <?php // SEGUNDO: Se não está JOGANDO, verifica se há evento ABERTO para registro ?>
                 <?php elseif ($current_event_status === 'REGISTRO_ABERTO' && $current_event_id !== null): ?>
                     <p>Arena aberta para registro! (ID: <?php echo htmlspecialchars($current_event_id); ?>)</p>
                     <p>Limite: <strong><?php echo htmlspecialchars($required_players); // 100 ?></strong> | Registrados: (<strong><?php echo htmlspecialchars($current_reg_count); ?> / <?php echo htmlspecialchars($required_players); // 100 ?></strong>)</p>
                     <?php if ($is_registered_current_event): ?>
                         <?php // Usuário já está registrado neste evento ?>
                         <p style="color: var(--accent-color-1);"><strong>Você já está registrado!</strong></p>
                         <p style="font-size: 0.8em; color: var(--text-secondary);">(Aguarde o início ser anunciado para poder entrar)</p>
                         <form method="post" action="praca.php"><button type="submit" disabled>Registrado</button></form>
                     <?php else: ?>
                         <?php // Usuário NÃO está registrado, botão HABILITADO ?>
                         <form method="post" action="praca.php">
                             <input type="hidden" name="register" value="1">
                             <button type="submit">Registrar na Arena</button>
                         </form>
                     <?php endif; ?>

                 <?php // TERCEIRO: Se não está JOGANDO e NÃO HÁ evento ABERTO ?>
                 <?php else: ?>
                     <p>Nenhuma arena disponível para registro no momento.</p>
                     <p style="font-size: 0.8em; color: var(--text-secondary);">(Aguarde o anúncio da próxima Arena Royalle!)</p>
                     <?php // Botão DESABILITADO ?>
                     <form method="post" action="praca.php">
                         <input type="hidden" name="register" value="1">
                         <button type="submit" disabled title="Nenhuma arena aberta para registro">Registrar na Arena</button>
                     </form>
                 <?php endif; ?>
                 <hr style="border-color: var(--panel-border); margin: 20px 0;">
                 <h3 class="arena-section-title" style="color: #e9c46a;">Sua Arena Atual</h3>
                 <p>
                    <?php if ($is_participating_active_arena): ?>
                        Ver detalhes acima. <a href="/script/arena/arenaroyalle.php?event_id=<?= htmlspecialchars($active_event_id) ?>">Retornar</a>. <?php // <<<<< VERIFIQUE PATH ?>
                    <?php else: ?>
                        Você não está participando de nenhuma Arena no momento.
                    <?php endif; ?>
                 </p>

             </div>

             <hr style="border-color: var(--panel-border); margin: 15px 0; width: 90%;">
             <h3>Guerreiros Presentes (Últimos <?= $intervalo_atividade_minutos ?> min)</h3>
             <?php if (!empty($outros_na_praca)): ?>
                  <ul class="lista-jogadores-praca">
                      <?php foreach ($outros_na_praca as $outro): ?> <li class="jogador-item"> <div class="jogador-info"> <a href="perfil.php?id=<?= htmlspecialchars($outro['id']) ?>" class="player-profile-link" title="Ver perfil de <?= htmlspecialchars($outro['nome']) ?>"> <span class="nome"><?= formatarNomeUsuarioComTag($conn, $outro) ?></span> </a> <span class="nivel">Nível <?= htmlspecialchars($outro['nivel']) ?></span> </div> <button class="btn-desafiar" data-target-id="<?= $outro['id'] ?>" onclick="sendChallenge(this)">Desafiar</button> </li> <?php endforeach; ?> <?php // <<<<< VERIFIQUE PATH ?>
                  </ul>
             <?php else: ?> <p class="praca-vazia">A praça está tranquila.</p> <?php endif; ?>
        </div>

        <?php // --- COLUNA DIREITA (sem alterações) --- ?>
        <div class="coluna coluna-direita">
            <?php
                 $currentPageBaseName = basename($_SERVER['PHP_SELF']);
                 foreach ($sidebarItems as $item) {
                     $href = $item['href']; $label = htmlspecialchars($item['label']);
                     $iconPath = $item['icon'] ?? ''; $faIconClass = $item['fa_icon'] ?? 'fas fa-question-circle';
                     $idItem = $item['id']; $classe_extra = ''; $atributos_extra = ''; $title = $label;
                     $iconHtml = "<i class='{$faIconClass} nav-button-icon'></i>";
                     if (!empty($iconPath)) { $ifp = __DIR__ . '/' . $iconPath; if (file_exists($ifp)) { $iconHtml = '<img src="' . htmlspecialchars($iconPath) . '" class="nav-button-icon" alt="">'; } else { error_log("Icone nav D (img) nao encontrado praca.php: " . $ifp); } }
                     if ($idItem === 'sala_tempo') { if (!$pode_entrar_templo_base) { $classe_extra = ' disabled'; $atributos_extra = ' onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES).'\'); return false;"'; $title = htmlspecialchars($mensagem_bloqueio_templo_base); $href = '#'; } else { $title = 'Entrar na Sala do Templo'; } }
                     $isCurrent = ($idItem === 'praca');
                     if ($isCurrent && strpos($classe_extra, 'disabled') === false) { $classe_extra .= ' active'; }
                     $finalHref = htmlspecialchars($href);
                     echo "<a class=\"nav-button{$classe_extra}\" href=\"{$finalHref}\" title=\"{$title}\" {$atributos_extra}>";
                     echo "<div class='nav-button-icon-area'>{$iconHtml}</div>"; echo "<span>{$label}</span>"; echo "</a>";
                 }
                 // Botão Sair
                 echo '<div style="margin-top: auto; padding-top: 10px; border-top: 1px solid var(--panel-border);">';
                 $sairIconPath = 'img/sair.jpg'; $sairIconHtml = '<i class="fas fa-sign-out-alt nav-button-icon"></i>'; // <<<<< VERIFIQUE PATH
                 if(file_exists(__DIR__.'/'.$sairIconPath)) { $sairIconHtml = '<img src="'.htmlspecialchars($sairIconPath).'" class="nav-button-icon" alt="Sair">'; }
                 echo '<a class="nav-button logout" href="sair.php" title="Sair do Jogo">'; // <<<<< VERIFIQUE PATH
                 echo "<div class='nav-button-icon-area'>{$sairIconHtml}</div>"; echo '<span>Sair</span></a>'; echo '</div>';
            ?>
        </div>
    </div> <?php // Fechamento .container ?>

    <?php // --- Bottom Nav (sem alterações) --- ?>
    <nav id="bottom-nav">
         <?php
              $currentPageBaseNameNav = basename($_SERVER['PHP_SELF']);
              foreach ($bottomNavItems as $item):
                  $url_destino_item = $item['href']; $itemIdNav = $item['id'] ?? null; $labelNav = htmlspecialchars($item['label']); $iconNav = $item['icon'];
                  $final_href = '#'; $final_onclick = ''; $final_title = ''; $extra_class = ''; $active_class = '';
                  $is_current_page_nav = ($itemIdNav === 'praca');
                  if ($itemIdNav === 'sala_tempo') { if (!$pode_entrar_templo_base) { $final_onclick = ' onclick="alert(\'' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '\'); return false;"'; $final_title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES); $extra_class = ' disabled'; } else { $final_href = htmlspecialchars($url_destino_item); $final_title = 'Entrar na Sala'; } }
                  else { $final_href = htmlspecialchars($url_destino_item); $final_title = 'Ir para ' . $labelNav; }
                  if ($is_current_page_nav && strpos($extra_class, 'disabled') === false) { $active_class = ' active'; }
                  echo "<a href='{$final_href}' class='bottom-nav-item{$active_class}{$extra_class}' {$final_onclick} title='{$final_title}'>";
                  echo "<span class='bottom-nav-icon'>{$iconNav}</span>"; echo "<span class='bottom-nav-label'>{$labelNav}</span>"; echo "</a>";
              endforeach;
         ?>
    </nav>

     <?php // --- HTML do Modal Popup (sem alterações) --- ?>
     <div id="notification-popup-overlay" class="modal-overlay">
         <div class="modal-popup">
             <button class="modal-close-btn" onclick="markCurrentNotificationAsRead()" title="Marcar como lida">&times;</button>
             <h3>Nova Notificação</h3>
             <div id="popup-message">Carregando mensagem...</div>
             <div id="popup-actions"></div>
         </div>
     </div>

    <?php // --- JavaScript Essencial (Polling da Arena Removido) --- ?>
    <script>
        // <<< Variáveis Globais >>>
        const chatMessagesDiv = document.getElementById('chat-messages-ajax');
        const chatForm = document.getElementById('chat-form-ajax');
        const messageInput = document.getElementById('chat-message-input');
        const challengeNotificationsDiv = document.getElementById('challenge-notifications');
        const currentUserId = <?php echo json_encode($id); ?>;
        const currentUsername_Chat = <?php echo json_encode($usuario['nome'] ?? 'Eu'); ?>;
        const chatHandlerUrl = 'chat_handler_ajax.php'; // <<<<< VERIFIQUE PATH
        let lastMessageId = 0; <?php $lmId = 0; if(!empty($chat_history)){ $lmsg = end($chat_history); if(isset($lmsg['id'])) $lmId = (int)$lmsg['id']; } echo 'lastMessageId = '.$lmId.';'; ?>
        let isFetchingChat = false; const chatPollInterval = 3500; let chatPollingIntervalId = null;

        // --- Variáveis de Polling da Arena REMOVIDAS ---
        // const isUserRegisteredForCurrentEvent = ...;
        // const userRegisteredEventId = ...;
        // const arenaCheckInterval = ...;
        // let arenaStatusPollIntervalId = null;

        // Popup Notificação (variáveis permanecem)
        const currentUserId_Popup = <?php echo json_encode((int)$id); ?>;
        const unreadNotifications = <?php echo json_encode($unread_notifications_for_popup); ?>;
        let currentNotification = null;
        const modalOverlay = document.getElementById('notification-popup-overlay');
        const popupMessage = document.getElementById('popup-message');
        const popupActions = document.getElementById('popup-actions');

        // --- Funções Utilitárias (sem alterações) ---
        function escapeHtml(unsafe){ if(typeof unsafe!=='string') return ''; return unsafe.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");}
        function formatTimestamp(timestamp){ if(!timestamp) return '??:??'; try { let d=new Date(timestamp.replace(/-/g,'/')); if(!isNaN(d.getTime())) return d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}); } catch(e){} const parts=timestamp.split(' '); if(parts.length>1&&parts[1].includes(':')){ const tp=parts[1].substring(0,5); if(/^\d{2}:\d{2}$/.test(tp)) return tp;} return '??:??'; }
        function scrollToBottomChat(){ if(chatMessagesDiv){ setTimeout(() => { chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight; }, 50); }}
        function checkEmptyChat() { if(!chatMessagesDiv) return; const e=chatMessagesDiv.querySelector('.chat-empty'); const h=chatMessagesDiv.querySelector('.chat-message-ajax'); if (!h&&!e){const p=document.createElement('p');p.className='chat-empty';p.textContent='Nenhuma msg.';chatMessagesDiv.innerHTML='';chatMessagesDiv.appendChild(p);} else if(h&&e){e.remove();} }

        // --- Lógica do Modal Popup (sem alterações) ---
        function showModal() { if (modalOverlay) modalOverlay.classList.add('visible'); }
        function hideModal() { if (modalOverlay) { modalOverlay.classList.remove('visible'); if(popupMessage) popupMessage.innerHTML = ''; if(popupActions) popupActions.innerHTML = ''; currentNotification = null; } }
        function markCurrentNotificationAsRead() { if (currentNotification?.id) { window.location.href = `praca.php?mark_read=${currentNotification.id}`; hideModal(); } else { hideModal(); } }
        function displayNextNotification() {
             if (!modalOverlay || !popupMessage || !popupActions) { console.error("Elementos modal não encontrados (Praca)."); return; }
             if (unreadNotifications?.length > 0) {
                  currentNotification = unreadNotifications.shift();
                  if (!currentNotification) { hideModal(); return; }
                  popupMessage.textContent = currentNotification.message_text || 'Msg indisponível.'; popupActions.innerHTML = '';
                  const markReadLink = `praca.php?mark_read=${currentNotification.id}`;
                  let hasSpecificAction = false; let relatedData = {};
                  if (currentNotification.related_data) { try { relatedData = (typeof currentNotification.related_data === 'string') ? JSON.parse(currentNotification.related_data) : currentNotification.related_data; if(typeof relatedData !== 'object') relatedData={}; } catch(e) { console.error("Erro JSON (Praca):", e); relatedData={}; } }

                  if (currentNotification.type === 'invite') {
                      const groupId = relatedData.group_id;
                      if(groupId){ const aL=`praca.php?invite_action=accept&notif_id=${currentNotification.id}`; const rL=`praca.php?invite_action=reject&notif_id=${currentNotification.id}`; popupActions.innerHTML = `<a href="${aL}" class="popup-action-btn btn-accept">Aceitar Grupo</a><a href="${rL}" class="popup-action-btn btn-reject">Recusar</a>`; hasSpecificAction=true; }
                  } else if (currentNotification.type === 'fusion_invite') {
                      const invId=relatedData.inviter_id; const desId=relatedData.desafio_id;
                      if(invId&&desId){ const aL=`praca.php?fusion_action=accept&notif_id=${currentNotification.id}`; const rL=`praca.php?fusion_action=reject&notif_id=${currentNotification.id}`; popupActions.innerHTML = `<a href="${aL}" class="popup-action-btn btn-accept">Aceitar Fusão</a><a href="${rL}" class="popup-action-btn btn-reject">Recusar</a>`; hasSpecificAction=true; }
                  } else if (currentNotification.type === 'arena_start') { // ESSA NOTIFICAÇÃO É IMPORTANTE AGORA
                      const evId=relatedData.event_id;
                      if(evId){ const eL=`praca.php?arena_action=enter&notif_id=${currentNotification.id}&event_id=${evId}`; popupActions.innerHTML = `<a href="${eL}" class="popup-action-btn btn-accept">Entrar Arena</a><a href="${markReadLink}" class="popup-action-btn btn-ok">OK</a>`; hasSpecificAction=true; }
                  } else if (currentNotification.type === 'zeni_transfer_received') {
                      popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK</a>`; hasSpecificAction=true;
                  }
                  // Adicione mais tipos aqui...

                  if (!hasSpecificAction) { popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`; }
                  showModal();
             } else { hideModal(); }
        }

        // --- Lógica do Chat (sem alterações) ---
        function addChatMessageToDisplay(msg, isHistory=false){ if(!chatMessagesDiv||!msg||!msg.id||document.querySelector(`.chat-message-ajax[data-message-id="${msg.id}"]`)) return; checkEmptyChat(); const me=document.createElement('div'); me.className=`chat-message-ajax ${msg.user_id==currentUserId?'mine':'other'}`; me.dataset.messageId=msg.id; const ts=formatTimestamp(msg.timestamp); const sn=escapeHtml(msg.username||'???'); const mt=escapeHtml(msg.message_text||''); me.innerHTML=`<span class="sender">${sn} <small>(${ts})</small></span><span class="msg-text">${mt}</span>`; chatMessagesDiv.appendChild(me); if(!isHistory){scrollToBottomChat();} if(msg.id&&parseInt(msg.id)>lastMessageId){lastMessageId=parseInt(msg.id);} }
        if(chatForm&&messageInput){chatForm.addEventListener('submit',async(e)=>{ e.preventDefault();const m=messageInput.value.trim();if(m&&m.length<=500){messageInput.disabled=true;const b=chatForm.querySelector('button');if(b)b.disabled=true;const fd=new FormData();fd.append('message',m);try{const r=await fetch(`${chatHandlerUrl}?action=send`,{method:'POST',body:fd});const d=await r.json();if(d.success){messageInput.value='';setTimeout(fetchNewChatMessages,150);}else{alert('Erro: '+(d.error||'?'));}}catch(err){alert('Erro conexão envio.');}finally{messageInput.disabled=false;if(b)b.disabled=false;messageInput.focus();}}});}
        async function fetchNewChatMessages(){ if(isFetchingChat||document.hidden)return;isFetchingChat=true;try{const r=await fetch(`${chatHandlerUrl}?action=fetch&last_id=${lastMessageId}&t=${Date.now()}`);if(!r.ok){isFetchingChat=false;return;}const d=await r.json();if(d.success&&d.messages?.length>0){d.messages.forEach(m=>addChatMessageToDisplay(m,false));}if(d.last_processed_id&&parseInt(d.last_processed_id)>lastMessageId){lastMessageId=parseInt(d.last_processed_id);}}catch(err){}finally{isFetchingChat=false;}}
        function pollForChatUpdates(){fetchNewChatMessages();}

        // --- Lógica Desafios (sem alterações) ---
        async function sendChallenge(btn){const tId=btn.dataset.targetId;if(!tId)return;btn.disabled=true;btn.textContent='...';const fd=new FormData();fd.append('target_id',tId);try{const r=await fetch('challenge_handler.php?action=send',{method:'POST',body:fd});if(!r.ok)throw new Error(`Erro ${r.status}`);const d=await r.json();if(d.success)alert('Desafio enviado!');else alert('Erro: '+(d.error||'?'));}catch(err){alert('Erro conexão desafio.');}finally{btn.disabled=false;btn.textContent='Desafiar';}} // <<<<< VERIFIQUE PATH
        function displayChallengeNotification(ch){if(!challengeNotificationsDiv||challengeNotificationsDiv.querySelector(`.challenge-notification[data-challenge-id="${ch.challenge_id}"]`))return;const ne=document.createElement('div');ne.className='challenge-notification';ne.dataset.challengeId=ch.challenge_id;let tsH='';try{const dt=new Date(ch.created_at.replace(/-/g,'/'));if(!isNaN(dt))tsH=`<small>(às ${dt.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'})})</small>`;}catch(e){}const cNameF=ch.challenger_name_formatted||escapeHtml(ch.challenger_name||'??');ne.innerHTML=`<span class="challenge-text"><strong>${cNameF}</strong> te desafiou! ${tsH}</span><div class="challenge-actions"><button class="btn-aceitar" onclick="respondChallenge(${ch.challenge_id},'accept',this)">Aceitar</button><button class="btn-recusar" onclick="respondChallenge(${ch.challenge_id},'decline',this)">Recusar</button></div>`;challengeNotificationsDiv.appendChild(ne);}
        async function respondChallenge(chId,respAct,btn){const ne=btn?.closest('.challenge-notification');if(ne)ne.querySelectorAll('button').forEach(b=>b.disabled=true);const fd=new FormData();fd.append('challenge_id',chId);fd.append('response_action',respAct);try{const r=await fetch('challenge_handler.php?action=respond',{method:'POST',body:fd});const d=await r.json();if(d.success){if(ne)ne.remove();if(respAct==='accept'&&d.redirect_url)window.location.href=d.redirect_url;}else{alert('Erro: '+(d.error||'?'));if(ne)ne.querySelectorAll('button').forEach(b=>b.disabled=false);}}catch(err){alert('Erro conexão resposta.');if(ne)ne.querySelectorAll('button').forEach(b=>b.disabled=false);}} // <<<<< VERIFIQUE PATH

        // --- [REMOVIDO] Lógica Arena Poll ---
        // function checkArenaStatusForRegisteredUser() { ... }

        // --- Inicialização Geral e Lógica UI ---
        document.addEventListener('DOMContentLoaded', () => {
            // Botões Templo e Desabilitados
            document.querySelectorAll('.entrar-templo-btn').forEach(b => { b.addEventListener('click', e => { e.preventDefault(); if (b.classList.contains('disabled')) return; const url = b.dataset.url; if (url) window.location.href = url; }); });
            document.querySelectorAll('.nav-button.disabled[onclick*="alert"], .bottom-nav-item.disabled[onclick*="alert"], .praca-link.disabled[onclick*="alert"]').forEach(b=>{b.addEventListener('click',e=>{b.style.transition='box-shadow 0.1s ease';b.style.boxShadow='0 0 5px 2px var(--danger-color,#dc3545)';setTimeout(()=>{b.style.boxShadow='';},300);});});

            // Chat
            checkEmptyChat(); scrollToBottomChat();
            if (chatPollingIntervalId) clearInterval(chatPollingIntervalId);
            chatPollingIntervalId = setInterval(pollForChatUpdates, chatPollInterval);

            // --- [REMOVIDO] Início do Polling da Arena ---
            // if (isUserRegisteredForCurrentEvent && userRegisteredEventId && !isUserParticipatingActiveArena) { ... }

            // Popup Notificação
            displayNextNotification();
            if (modalOverlay) { modalOverlay.addEventListener('click', function(event) { if (event.target === modalOverlay) { markCurrentNotificationAsRead(); } }); }

            // Lógica Bottom Nav
            const bottomNav = document.getElementById('bottom-nav'); const rightColumn = document.querySelector('.coluna-direita'); const bodyEl = document.body;
            function checkBottomNavVisibility() { if(!bottomNav || !rightColumn || !bodyEl) return; const originalPadding = getComputedStyle(bodyEl).getPropertyValue('--body-original-padding-bottom').trim() || '20px'; if (window.getComputedStyle(rightColumn).display === 'none') { bottomNav.style.display = 'flex'; bodyEl.style.paddingBottom = (bottomNav.offsetHeight + 15) + 'px'; } else { bottomNav.style.display = 'none'; bodyEl.style.paddingBottom = originalPadding; } }
            if(bodyEl) bodyEl.style.setProperty('--body-original-padding-bottom', getComputedStyle(bodyEl).paddingBottom); checkBottomNavVisibility(); window.addEventListener('resize', checkBottomNavVisibility); setTimeout(checkBottomNavVisibility, 300);

             // Fechar feedback
             const feedbackBox = document.querySelector('.feedback-container');
             if (feedbackBox && window.getComputedStyle(feedbackBox).display !== 'none') {
                  setTimeout(() => { feedbackBox.style.transition = 'opacity 0.5s ease-out'; feedbackBox.style.opacity = '0'; setTimeout(() => { feedbackBox.style.display = 'none'; }, 500); }, 7000);
             }

             // Visibilidade da aba (não precisa mais reiniciar o poll da arena)
             document.addEventListener("visibilitychange", () => {
                 if (document.hidden) {
                     if (chatPollingIntervalId) { clearInterval(chatPollingIntervalId); chatPollingIntervalId = null; }
                 } else {
                     if (!chatPollingIntervalId) { pollForChatUpdates(); chatPollingIntervalId = setInterval(pollForChatUpdates, chatPollInterval); }
                 }
             });
        });
    </script>
</body>
</html>
<?php
if (isset($conn)) { $conn = null; } // Fecha conexão principal
?>