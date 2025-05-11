<?php
/*
 * Pagina Combinada - Templo com Popup Notificação Kame-style
 * Removeu Notificações da Coluna Esquerda e integrou o Modal Popup
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); } // <<<<< VERIFIQUE PATH

// Includes Essenciais
require_once('conexao.php'); // <<<<< VERIFIQUE PATH
require_once('home/home_functions.php'); // Garanta que funções como formatarNomeUsuarioComTag e verificarLevelUpHome estão aqui ou defina fallbacks <<<<< VERIFIQUE PATH

// Fallbacks (caso home_functions.php não tenha tudo)
if (!function_exists('formatNumber')) { function formatNumber($num) { $n = filter_var($num, FILTER_VALIDATE_INT); return number_format(($n === false ? 0 : $n), 0, ',', '.'); } }
if (!function_exists('formatarNomeUsuarioComTag')) { function formatarNomeUsuarioComTag($c, $d) { if (!is_array($d)||!isset($d['nome'])) { return 'Nome Inválido'; } return htmlspecialchars($d['nome']); } }
if (!function_exists('verificarLevelUpHome')) { function verificarLevelUpHome(PDO $c, int $id_u): array { $m=[]; /* Lógica Level Up Aqui ou no Include */ return $m; } }


// Garante conexão PDO
if (!isset($conn) || !$conn instanceof PDO) {
    if (isset($pdo) && $pdo instanceof PDO) { $conn = $pdo; }
    else {
        try {
            require('conexao.php'); // <<<<< VERIFIQUE PATH
            if (!isset($conn) || !$conn instanceof PDO) { throw new Exception("Falha crítica conexão PDO (Templo Top)."); }
        } catch (Exception $e) {
             error_log("Falha crítica conexão PDO templo.php (topo): " . $e->getMessage());
             die("Erro crítico: DB indisponível. Avise um Admin.");
        }
    }
}

// --- Variáveis e Configurações ---
$id = $_SESSION['user_id'];
$id_usuario_logado = $id;
$usuario = null;
$mensagem_final_feedback = "";
$currentPage = basename(__FILE__); // templo.php
$activeContentId = 'sala_tempo'; // Define que o conteúdo ativo é 'sala_tempo'
$nome_formatado_usuario = 'ErroNome';
$rank_nivel = 'N/A';
$rank_sala = 'N/A';
$lista_jogadores_templo = [];
$jogadores_no_templo_count = 0;
$chat_history = [];
$chat_history_limit = 50;

// ===>>> NOVA Variável para Notificações do Popup <<<===
$unread_notifications_for_popup = [];

// Constantes
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);
if (!defined('PONTOS_GANHO_POR_NIVEL')) define('PONTOS_GANHO_POR_NIVEL', 100);
if (!defined('TEMPO_INTERVALO_XP_SEGUNDOS')) define('TEMPO_INTERVALO_XP_SEGUNDOS', 300); // 5 minutos
if (!defined('XP_POR_INTERVALO')) define('XP_POR_INTERVALO', 50); // XP ganho por intervalo no templo

// --- ===>>> INÍCIO: Processar Ações de Notificação via GET (Copiado do Kame/Urunai e Adaptado) <<<=== ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
     if (!isset($conn) || !$conn instanceof PDO) { die("Erro crítico: Conexão PDO não disponível para GET actions (Templo)."); }
     $notification_action_processed = false;

     // Marcar como lida (genérico)
     if (isset($_GET['mark_read']) && filter_var($_GET['mark_read'], FILTER_VALIDATE_INT)) {
         $notif_id_to_mark = (int)$_GET['mark_read'];
         try {
             $stmt_mark = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
             $stmt_mark->execute([':notif_id' => $notif_id_to_mark, ':user_id' => $id]);
             // $_SESSION['feedback_geral'] = "<span class='info-message'>Notificação marcada como lida.</span>"; // Opcional
         } catch (PDOException $e) {
             error_log("Erro PDO marcar notif {$notif_id_to_mark} lida user {$id} (Templo): " . $e->getMessage());
             $_SESSION['feedback_geral'] = "<span class='error-message'>Erro marcar notificação.</span>";
         }
         $notification_action_processed = true;
     }
     // Lidar com Convites de GRUPO
     elseif (isset($_GET['invite_action']) && isset($_GET['notif_id']) && filter_var($_GET['notif_id'], FILTER_VALIDATE_INT)) {
         $invite_action = $_GET['invite_action'];
         $notif_id_invite = (int)$_GET['notif_id'];
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
                         $current_group = $stmt_check_current->fetchColumn();
                         if ($current_group) {
                             $_SESSION['feedback_geral'] = "<span class='error-message'>Você já pertence a um grupo. Saia do atual primeiro.</span>";
                         } else {
                             $sql_add_member = "INSERT INTO grupo_membros (grupo_id, user_id, cargo) VALUES (:grupo_id, :user_id, 'membro')";
                             $stmt_add_member = $conn->prepare($sql_add_member);
                             if ($stmt_add_member->execute([':grupo_id' => $group_id, ':user_id' => $id])) {
                                 $_SESSION['feedback_geral'] = "<span class='success-message'>Bem-vindo à equipe! Convite aceito.</span>";
                                 // Opcional: Atualizar convite e recusar outros
                                 $conn->prepare("UPDATE grupo_convites SET status = 'aceito' WHERE convidado_user_id = :uid AND grupo_id = :gid AND status = 'pendente'")->execute([':uid' => $id, ':gid' => $group_id]);
                                 $conn->prepare("UPDATE grupo_convites SET status = 'recusado' WHERE convidado_user_id = :uid AND status = 'pendente' AND grupo_id != :gid")->execute([':uid' => $id, ':gid' => $group_id]);
                             } else { $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao tentar entrar no grupo.</span>"; }
                         }
                     } else { // 'reject'
                         $_SESSION['feedback_geral'] = "<span class='info-message'>Convite de grupo recusado.</span>";
                         if ($group_id) { $conn->prepare("UPDATE grupo_convites SET status = 'recusado' WHERE convidado_user_id = :uid AND grupo_id = :gid AND status = 'pendente'")->execute([':uid' => $id, ':gid' => $group_id]); }
                     }
                     $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_invite, ':uid' => $id]);
                 } else { $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de grupo inválido ou já processado.</span>"; }
             } catch (PDOException | Exception $e) {
                 error_log("Erro tratar invite_action {$invite_action} notif {$notif_id_invite} user {$id} (Templo): " . $e->getMessage());
                 $_SESSION['feedback_geral'] = "<span class='error-message'>Erro processar convite de grupo.</span>";
                 try { $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")->execute([':id'=>$notif_id_invite, ':uid'=>$id]); } catch(PDOException $em){}
             }
             $notification_action_processed = true;
         }
     }
     // Lidar com Convites de FUSÃO
     elseif (isset($_GET['fusion_action']) && isset($_GET['notif_id']) && filter_var($_GET['notif_id'], FILTER_VALIDATE_INT)) {
         $fusion_action = $_GET['fusion_action'];
         $notif_id_fusion = (int)$_GET['notif_id'];
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
                             $sql_accept_fusao = "UPDATE convites_fusao SET status = 'aceito', data_atualizacao = NOW() WHERE convidante_id = :inviter_id AND convidado_id = :user_id AND desafio_id = :desafio_id AND status = 'pendente'";
                             $stmt_accept_fusao = $conn->prepare($sql_accept_fusao);
                             if ($stmt_accept_fusao->execute([':inviter_id' => $inviter_id, ':user_id' => $id, ':desafio_id' => $desafio_id]) && $stmt_accept_fusao->rowCount() > 0) {
                                 $_SESSION['feedback_geral'] = "<span class='success-message'>Fusão aceita! Vá para Treinos.</span>";
                             } else { $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de fusão não encontrado ou já respondido.</span>"; }
                         } else { // 'reject'
                             $sql_reject_fusao = "UPDATE convites_fusao SET status = 'recusado', data_atualizacao = NOW() WHERE convidante_id = :inviter_id AND convidado_id = :user_id AND desafio_id = :desafio_id AND status = 'pendente'";
                             $stmt_reject_fusao = $conn->prepare($sql_reject_fusao);
                             $stmt_reject_fusao->execute([':inviter_id' => $inviter_id, ':user_id' => $id, ':desafio_id' => $desafio_id]);
                             $_SESSION['feedback_geral'] = "<span class='info-message'>Convite de fusão recusado.</span>";
                         }
                         $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_fusion, ':uid' => $id]);
                     } else {
                         $_SESSION['feedback_geral'] = "<span class='error-message'>Dados do convite de fusão incompletos.</span>";
                         $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_fusion, ':uid' => $id]);
                     }
                 } else { $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de fusão inválido (não encontrado).</span>"; }
             } catch (PDOException | Exception $e) {
                 error_log("Erro processar fusion_action={$fusion_action} notif={$notif_id_fusion} user={$id} (Templo): " . $e->getMessage());
                 $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao processar o convite de fusão.</span>";
                 try { $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :nid AND user_id = :uid")->execute([':nid' => $notif_id_fusion, ':uid' => $id]); } catch (PDOException $e_mark) {}
             }
             $notification_action_processed = true;
         }
     }
     // Lidar com Ação de Entrada na Arena
     elseif (isset($_GET['arena_action']) && $_GET['arena_action'] === 'enter' &&
             isset($_GET['notif_id']) && filter_var($_GET['notif_id'], FILTER_VALIDATE_INT) &&
             isset($_GET['event_id']) && filter_var($_GET['event_id'], FILTER_VALIDATE_INT))
     {
         $notif_id_arena = (int)$_GET['notif_id'];
         $event_id_arena = (int)$_GET['event_id'];
         try {
             $stmt_mark_arena = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
             if ($stmt_mark_arena->execute([':notif_id' => $notif_id_arena, ':user_id' => $id])) {
                 $arena_url = "script/arena/arenaroyalle.php?event_id=" . $event_id_arena; // <<<<< VERIFIQUE PATH DA ARENA
                 header("Location: " . $arena_url);
                 exit();
             } else {
                  $_SESSION['feedback_geral'] = "<span class='warning-message'>Não foi possível processar a notificação da arena.</span>";
                  error_log("Falha marcar notif arena_start {$notif_id_arena} lida user {$id} (Templo).");
             }
         } catch (PDOException $e) {
             error_log("Erro PDO ação arena_start (notif {$notif_id_arena}, event {$event_id_arena}) user {$id} (Templo): " . $e->getMessage());
             $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao tentar entrar na arena.</span>";
              try { $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid")->execute([':id'=>$notif_id_arena, ':uid'=>$id]); } catch(PDOException $em){}
         }
         $notification_action_processed = true;
     }

     // Redireciona APENAS se uma ação de notificação foi processada
     if ($notification_action_processed) {
         header("Location: templo.php"); // Volta para templo.php
         exit();
     }
}
// --- ===>>> FIM: Processar Ações GET <<<=== ---


// --- Coleta de Feedback da Sessão ---
$mensagem_final_feedback = '';
if (isset($_SESSION['feedback_wali'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_wali']; unset($_SESSION['feedback_wali']); }
if (isset($_SESSION['feedback_geral'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_geral']; unset($_SESSION['feedback_geral']); }
if (isset($_SESSION['feedback_cooldown'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_cooldown']; unset($_SESSION['feedback_cooldown']); }


// --- Array de Navegação Sidebar ---
$sidebarItems = [
    ['id' => 'praca',        'label' => 'Praça Central',        'href' => 'praca.php',                'icon' => 'img/praca.jpg', 'fa_icon' => 'fas fa-users'],
    ['id' => 'guia',         'label' => 'Guia do Guerreiro Z',  'href' => 'guia_guerreiro.php',      'icon' => 'img/guiaguerreiro.jpg', 'fa_icon' => 'fas fa-book-open'],
    ['id' => 'equipe',       'label' => 'Equipe Z',             'href' => 'script/grupos.php',       'icon' => 'img/grupos.jpg', 'fa_icon' => 'fas fa-users-cog'],
    ['id' => 'banco',        'label' => 'Banco Galáctico',      'href' => 'script/banco.php',        'icon' => 'img/banco.jpg', 'fa_icon' => 'fas fa-landmark'],
    ['id' => 'urunai',       'label' => 'Palácio da Vovó Uranai','href' => 'urunai.php',              'icon' => 'img/urunai.jpg', 'fa_icon' => 'fas fa-hat-wizard'],
    ['id' => 'kame',         'label' => 'Casa do Mestre Kame',  'href' => 'kame.php',                'icon' => 'img/kame.jpg', 'fa_icon' => 'fas fa-home'],
    ['id' => 'sala_tempo',   'label' => 'Sala do Templo',       'href' => 'templo.php',              'icon' => 'img/templo.jpg', 'fa_icon' => 'fas fa-hourglass-half'], // Página Atual
    ['id' => 'treinos',      'label' => 'Zona de Treinamento',  'href' => 'desafios/treinos.php',    'icon' => 'img/treinos.jpg', 'fa_icon' => 'fas fa-dumbbell'],
    ['id' => 'desafios',     'label' => 'Desafios Z',           'href' => 'desafios.php',            'icon' => 'img/desafios.jpg', 'fa_icon' => 'fas fa-scroll'],
    ['id' => 'esferas',      'label' => 'Buscar Esferas',       'href' => 'esferas.php',             'icon' => 'img/esferar.jpg', 'fa_icon' => 'fas fa-dragon'],
    ['id' => 'erros',        'label' => 'Relatar Erros',        'href' => 'erros.php',               'icon' => 'img/erro.png', 'fa_icon' => 'fas fa-bug'],
    ['id' => 'perfil',       'label' => 'Perfil de Guerreiro',  'href' => 'perfil.php',              'icon' => 'img/perfil.jpg', 'fa_icon' => 'fas fa-user-edit']
];
// <<<<< VERIFIQUE TODOS OS PATHS E HREFS ACIMA >>>>>

// --- Lógica de Busca de Dados ---
try {
    // Garante conexão (recheck)
    if (!isset($conn) || !$conn instanceof PDO) {
        if (isset($pdo) && $pdo instanceof PDO) { $conn = $pdo;}
        else { require_once('conexao.php'); if (!isset($conn) || !$conn instanceof PDO) { throw new Exception("Conexão PDO não disponível (Templo Main Fetch)."); } }
    }

    $sql_usuario = "SELECT nome, nivel, hp, pontos, xp, ki, forca, id, defesa, velocidade, foto, tempo_sala, raca, zeni, ranking_titulo FROM usuarios WHERE id = :id_usuario";
    $stmt_usuario = $conn->prepare($sql_usuario);
    $stmt_usuario->execute([':id_usuario' => $id]);
    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) {
        session_destroy(); header("Location: login.php?erro=UsuarioNaoEncontradoFatal"); exit(); // <<<<< VERIFIQUE PATH
    }

    // Atualiza last_active (se aplicável)
     try {
         $stmt_update_active = $conn->prepare("UPDATE usuarios SET last_active = NOW() WHERE id = :id");
         $stmt_update_active->execute([':id' => $id]);
     } catch (PDOException $e_active) {
         error_log("Erro ao atualizar last_active para user {$id} (Templo): " . $e_active->getMessage());
     }

    // Verificar Level Up
    $mensagens_level_up_detectado = verificarLevelUpHome($conn, $id);
    if (!empty($mensagens_level_up_detectado)) {
        $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . implode("<br>", $mensagens_level_up_detectado);
        $stmt_usuario->execute([':id_usuario' => $id]); // Rebusca dados
        $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
    }

    // Busca Ranks
    try {
        $sql_rank_nivel = "SELECT rank_nivel FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC, id ASC) as rank_nivel FROM usuarios) AS ranked_by_level WHERE id = :user_id";
        $stmt_rank_nivel = $conn->prepare($sql_rank_nivel);
        $stmt_rank_nivel->execute([':user_id' => $id]);
        $result_nivel = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC);
        if ($result_nivel) $rank_nivel = $result_nivel['rank_nivel'];

        $sql_rank_sala = "SELECT rank_sala FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala FROM usuarios) AS ranked_by_time WHERE id = :user_id";
        $stmt_rank_sala = $conn->prepare($sql_rank_sala);
        $stmt_rank_sala->execute([':user_id' => $id]);
        $result_sala = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC);
        if ($result_sala) $rank_sala = $result_sala['rank_sala'];
    } catch (PDOException $e_rank) { error_log("PDOException ao buscar ranks (Templo): " . $e_rank->getMessage()); }

     // ===>>> Busca NOTIFICAÇÕES NÃO LIDAS para o Popup <<<===
     try {
         $sql_unread_notif = "SELECT id, message_text, type, timestamp, related_data FROM user_notifications WHERE user_id = :user_id AND is_read = 0 ORDER BY timestamp ASC";
         $stmt_unread_notif = $conn->prepare($sql_unread_notif);
         if ($stmt_unread_notif && $stmt_unread_notif->execute([':user_id' => $id])) {
             $unread_notifications_for_popup = $stmt_unread_notif->fetchAll(PDO::FETCH_ASSOC);
         } else {
             error_log("Erro PDO fetch unread notifs user {$id} (Templo): " . print_r($conn->errorInfo() ?: ($stmt_unread_notif ? $stmt_unread_notif->errorInfo() : 'Prepare Failed'), true));
         }
     } catch (PDOException $e_notif) {
         error_log("Erro PDO buscar notifs não lidas user {$id} (Templo): " . $e_notif->getMessage());
     }
     // ===>>> FIM Busca Notificações Popup <<<===

    // Busca Jogadores no Templo
    try {
        $sql_jogadores_templo = "SELECT u.id, u.nome FROM sala_templo st JOIN usuarios u ON st.id_usuario = u.id WHERE st.ativo = 1 ORDER BY u.nome ASC";
        $stmt_jogadores_templo = $conn->prepare($sql_jogadores_templo);
        $stmt_jogadores_templo->execute();
        $lista_jogadores_templo = $stmt_jogadores_templo->fetchAll(PDO::FETCH_ASSOC);
        $jogadores_no_templo_count = count($lista_jogadores_templo);
    } catch (PDOException $e_count) {
        error_log("PDOException ao buscar jogadores no templo: " . $e_count->getMessage());
        $jogadores_no_templo_count = '?';
    }

    // Busca Histórico do Chat
    try {
        $sql_hist = "SELECT id, user_id, username, message_text, timestamp FROM ( SELECT id, user_id, username, message_text, timestamp FROM chat_messages ORDER BY id DESC LIMIT :limit_chat ) sub ORDER BY id ASC";
        $stmt_hist = $conn->prepare($sql_hist);
        $stmt_hist->bindValue(':limit_chat', $chat_history_limit, PDO::PARAM_INT);
        $stmt_hist->execute();
        $chat_history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e_chat) {
        error_log("PDOException ao buscar histórico do Chat (Templo): ".$e_chat->getMessage());
        $chat_history = [];
    }

} catch (PDOException | Exception $e) {
    error_log("Erro GERAL/PDO (Setup) em ".basename(__FILE__)." para user {$id}: " . $e->getMessage());
    $mensagem_final_feedback .= "<br><span class='error-message'>Erro crítico ao carregar dados. Avise um Administrador.</span>";
    if ($usuario === null) { die("Erro crítico: Falha ao carregar dados essenciais do usuário."); }
    // Define defaults para evitar erros na interface
    $rank_nivel = $rank_nivel ?? 'N/A'; $rank_sala = $rank_sala ?? 'N/A';
    $lista_jogadores_templo = $lista_jogadores_templo ?? []; $jogadores_no_templo_count = $jogadores_no_templo_count ?? '?';
    $chat_history = $chat_history ?? []; $unread_notifications_for_popup = [];
}

// --- Cálculos e Formatações Finais ---
$nome_formatado_usuario = formatarNomeUsuarioComTag($conn, $usuario);

// Cálculo XP Bar
$xp_necessario_calc=0; $xp_percent=0; $xp_necessario_display='N/A';
if(isset($usuario['nivel'])){
    $nvl=filter_var($usuario['nivel'] ?? 1, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if($nvl === false) $nvl = 1;
    $xp_nec = (int)ceil(XP_BASE_LEVEL_UP * pow($nvl, XP_EXPOENTE_LEVEL_UP));
    $xp_nec = max(XP_BASE_LEVEL_UP, $xp_nec);
    $xp_necessario_display = formatNumber($xp_nec);
    if($xp_nec > 0 && isset($usuario['xp'])){
        $xp_at = filter_var($usuario['xp'] ?? 0, FILTER_VALIDATE_INT);
        if($xp_at !== false){ $xp_percent = min(100, max(0, ($xp_at / $xp_nec) * 100)); }
    }
} else { $xp_necessario_display = formatNumber(XP_BASE_LEVEL_UP); $xp_percent = 0; }

// Foto do Perfil
$nome_arquivo_foto = 'default.jpg';
if (!empty($usuario['foto'])) {
    $caminho_fisico_foto = __DIR__ . '/uploads/' . $usuario['foto']; // <<<<< VERIFIQUE PATH BASE UPLOADS
    if (file_exists($caminho_fisico_foto)) { $nome_arquivo_foto = $usuario['foto']; }
    else { error_log("Foto não encontrada: " . $caminho_fisico_foto . " (User: {$id}, Page: Templo)"); }
}
$caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto); // <<<<< VERIFIQUE PATH WEB UPLOADS

// Lógica Templo para botão
$hp_atual_base = (int)($usuario['hp'] ?? 0);
$ki_atual_base = (int)($usuario['ki'] ?? 0);
$pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0);
$mensagem_bloqueio_templo_base = ($pode_entrar_templo_base) ? '' : 'Recupere seu HP e Ki base para entrar no Templo!';

// --- Array Bottom Nav ---
$bottomNavItems = [
    ['label' => 'Home', 'href' => 'home.php', 'icon' => '🏠', 'id'=>'home'],
    ['label' => 'Praça', 'href' => 'praca.php', 'icon' => '👥', 'id'=>'praca'],
    ['label' => 'Templo', 'href' => 'templo.php', 'icon' => '🏛️', 'id' => 'sala_tempo'], // Página Atual
    ['label' => 'Kame', 'href' => 'kame.php', 'icon' => '🐢', 'id'=>'kame'],
    ['label' => 'Perfil', 'href' => 'perfil.php', 'icon' => '👤', 'id'=>'perfil']
];
// <<<<< VERIFIQUE PATHS Arena/Treinos >>>>>
$arenaPath = 'script/arena/arenaroyalle.php';
$treinosPath = 'desafios/treinos.php';
$foundArena = false; $foundTreinos = false;
foreach ($bottomNavItems as $item) {
    if ($item['id'] === 'arena') $foundArena = true;
    if ($item['id'] === 'treinos') $foundTreinos = true;
}
if (!$foundArena) { $bottomNavItems[] = ['label' => 'Arena', 'href' => $arenaPath, 'icon' => '⚔️', 'id' => 'arena']; }
if (!$foundTreinos) { $bottomNavItems[] = ['label' => 'Treinos', 'href' => $treinosPath, 'icon' => '💪', 'id' => 'treinos']; }

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sala do Templo - <?= htmlspecialchars($usuario['nome'] ?? 'Jogador') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="home/home.css"> <?php // <<<<< VERIFIQUE PATH home.css ?>

    <style>
        /* Estilos Base (Colunas, Container, etc - Presume que estão em home.css) */
        /* Se home.css não for carregado, copie os estilos base para cá */

        /* Estilos Específicos desta Página Combinada (Templo + Chat) */
        :root {
            /* Cores Templo */
            --templo-bg-image: url('img/templo.jpg'); /* <<<<< VERIFIQUE PATH IMAGEM FUNDO */
            --templo-panel-bg: rgba(245, 245, 245, 0.9); /* Branco suave quase opaco */
            --templo-panel-border: #bdbdbd; /* Cinza claro */
            --templo-text-primary: #424242; /* Cinza escuro */
            --templo-text-secondary: #757575; /* Cinza médio */
            --templo-accent-gold: #ffca28; /* Dourado para títulos/destaques */
            --templo-accent-blue: #1e88e5; /* Azul para links/contadores */
            --templo-chat-bg: #37474f; /* Azul acinzentado escuro para chat */
            --templo-chat-text: #eceff1; /* Branco azulado para texto chat */
            --templo-chat-input-bg: #546e7a; /* Azul acinzentado médio para input */
            --templo-chat-border: #455a64; /* Azul acinzentado para borda chat */
            --templo-font-title: 'Montserrat', 'Roboto', sans-serif;
            --templo-font-body: 'Roboto', sans-serif;

            /* Adicionando variáveis do Kame/Urunai para compatibilidade do modal */
             --dbz-blue: #1a2a4d; --dbz-orange: #f5a623; --dbz-orange-dark: #e47d1e;
             --dbz-white: #ffffff; --dbz-light-grey: #f0f0f0; --dbz-dark-grey: #333333;
             --accent-color-1: #00e676; /* Verde */
             --accent-color-2: #ffab00; /* Laranja/Amarelo */
             --accent-color-3: #2979ff; /* Azul */
             --danger-color: #ff5252; --disabled-color: #5f6368; --success-color: #00e676;
             --info-color: #29b6f6; --warning-color: #ffa726;
             --border-radius: 10px; --shadow: 0 8px 25px rgba(0, 0, 0, 0.5);
             --body-original-padding-bottom: 20px; /* Para lógica do bottom nav */
        }

        /* Sobrescreve fundo do body */
        body {
             background-image: var(--templo-bg-image);
             background-size: cover;
             background-position: center center;
             background-attachment: fixed;
             font-family: var(--templo-font-body);
             color: var(--templo-text-primary); /* Cor padrão texto */
             background-color: #e0e0e0; /* Fallback bg */
        }

        /* Adapta painel base para tema templo (se necessário sobrescrever home.css) */
        .coluna {
            /* Exemplo: Se precisar de fundo/borda diferente do home.css */
             /* background-color: var(--templo-panel-bg); */
             /* border: 1px solid var(--templo-panel-border); */
             /* color: var(--templo-text-primary); */
             /* box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15); */
             /* backdrop-filter: blur(3px); */
        }
        /* Ajustes específicos para colunas no Templo */
        .coluna-esquerda, .coluna-direita {
             background-color: rgba(40, 44, 52, 0.92); /* Fundo escuro padrão para colunas laterais */
             color: #e8eaed; /* Texto claro */
             border: 1px solid rgba(255, 255, 255, 0.15);
             backdrop-filter: blur(5px);
        }
        /* Coluna Esquerda: Sobrescreve cores de texto/links se necessário */
        .coluna-esquerda .player-name { color: #fff; }
        .coluna-esquerda .player-level { color: #00e676; } /* Verde */
        .coluna-esquerda .player-race, .coluna-esquerda .stats-grid strong { color: #bdc1c6; } /* Cinza claro */
        .coluna-esquerda .player-rank { color: #2979ff; } /* Azul */
        .coluna-esquerda .zeni-display { color: #ffab00; } /* Laranja */
        .coluna-esquerda .stats-grid span { color: #e8eaed; } /* Branco */
        .coluna-esquerda .stats-grid span[style*="--accent-color-2"] { color: #ffab00; }
        .coluna-esquerda .stats-grid span[style*="--accent-color-1"] { color: #00e676; }
        .coluna-esquerda .action-button.btn-perfil { background-color: #00e676; color: #111; }
        .coluna-esquerda .action-button.btn-logout { background-color: #ff5252; color: #fff; }

        /* Coluna Central (Conteúdo do Templo) */
        .coluna-central {
            background-color: var(--templo-panel-bg); /* Usa cor tema templo */
            color: var(--templo-text-primary);
            border: 1px solid var(--templo-panel-border);
            text-align: center;
            display: flex;
            flex-direction: column;
            padding: 25px 20px; /* Mais padding */
            font-family: var(--templo-font-body);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            backdrop-filter: none; /* Remove blur se tiver no .coluna geral */
            /* Opcional: Se quiser limitar a altura total da coluna e adicionar scroll nela: */
            /* max-height: 85vh; */
            /* overflow-y: auto; */
        }

        .titulo-central {
            font-family: var(--templo-font-title);
            color: var(--templo-accent-gold); /* Dourado */
            font-size: 2.1em;
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--templo-accent-gold);
            flex-shrink: 0;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
        }

        .texto-descricao {
            font-size: 1.05rem; /* Um pouco maior */
            line-height: 1.8;
            margin-bottom: 20px;
            color: var(--templo-text-primary);
            max-width: 90%;
            margin-left: auto;
            margin-right: auto;
            flex-shrink: 0;
        }
        .texto-descricao img {
            display: block;
            max-width: 250px; /* Imagem maior */
            height: auto;
            margin: 15px auto 20px auto;
            border-radius: 6px;
            border: 1px solid var(--templo-panel-border);
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .texto-descricao strong {
            color: var(--templo-accent-blue); /* Azul para destaque */
            font-weight: 600;
        }

        .player-count-templo {
            font-size: 1rem;
            margin-bottom: 20px;
            color: var(--templo-text-secondary);
            flex-shrink: 0;
        }
        .player-count-templo strong {
            color: var(--templo-accent-blue); /* Azul */
            font-weight: 700;
            font-size: 1.1em;
        }

        /* Lista de Jogadores no Templo */
        .templo-player-list-container {
            background-color: rgba(0,0,0,0.03); /* Fundo quase imperceptível */
            border: 1px solid #e0e0e0; /* Borda bem clara */
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
            flex-shrink: 0;
            max-width: 550px;
            margin-left: auto; margin-right: auto; width: 100%;
        }
        .templo-player-list-container h3 {
            font-family: var(--templo-font-title);
            color: var(--templo-text-secondary); /* Cinza médio */
            font-size: 1.2em;
            margin-bottom: 15px; margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--templo-panel-border);
            text-align: center; font-weight: 500;
            text-transform: uppercase; letter-spacing: 1px;
        }
        .templo-player-list {
            list-style: none; padding: 0; margin: 0;
            max-height: 180px; overflow-y: auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 8px;
            scrollbar-width: thin;
            scrollbar-color: #cccccc #f0f0f0;
        }
        .templo-player-list::-webkit-scrollbar { width: 6px; }
        .templo-player-list::-webkit-scrollbar-track { background: #f0f0f0; border-radius: 3px; }
        .templo-player-list::-webkit-scrollbar-thumb { background-color: #cccccc; border-radius: 3px; }
        .templo-player-list::-webkit-scrollbar-thumb:hover { background-color: #bdbdbd; }
        .templo-player-list li {
            background-color: #ffffff;
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #eee;
            color: var(--templo-text-primary);
            font-size: 0.9em;
            text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
         .templo-player-list li::before { content: none !important; }
        .templo-player-list-container .empty-list-msg {
             text-align: center; color: var(--templo-text-secondary); padding: 15px 0; font-style: italic; font-size:0.95em; grid-column: 1 / -1;
        }

        /* Botão de Entrar/Sair Templo */
        .action-button.btn-templo-action {
            background: linear-gradient(45deg, var(--templo-accent-blue), #42a5f5); /* Gradiente azul */
            border: none;
            color: #ffffff;
            margin: 10px auto 25px auto; /* Centraliza e adiciona espaço */
            padding: 14px 30px;
            font-size: 1.05rem; display: inline-block; width: auto;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.2); border-radius: 6px; cursor: pointer;
            transition: all 0.2s ease; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            flex-shrink: 0; /* Impede que encolha */
        }
        .action-button.btn-templo-action:hover {
             background: linear-gradient(45deg, #1e88e5, #64b5f6);
             transform: translateY(-2px);
             box-shadow: 0 6px 12px rgba(0, 0, 0, 0.25);
        }
        /* Estilo específico para botão SAIR */
         .action-button.btn-templo-action.sair {
             background: linear-gradient(45deg, #ef5350, #e57373); /* Gradiente vermelho */
        }
         .action-button.btn-templo-action.sair:hover {
             background: linear-gradient(45deg, #e53935, #ef5350);
         }
         /* Estilo desabilitado */
         .action-button.btn-templo-action:disabled {
             background: #bdbdbd; /* Cinza */
             cursor: not-allowed;
             opacity: 0.7;
             box-shadow: none;
             transform: none;
         }
         .templo-disabled-reason { /* Mensagem abaixo do botão desabilitado */
              font-size: 0.85em; color: var(--danger-color, #d32f2f); margin-top: -20px; margin-bottom: 25px;
              flex-shrink: 0;
         }

        /* Divisor */
        .coluna-central hr.divider {
            border: 0; height: 1px; background: var(--templo-panel-border); margin: 25px auto; width: 85%; flex-shrink: 0;
        }

        /* Título do Chat */
        .coluna-central h3.chat-titulo {
             font-family: var(--templo-font-title); font-size: 1.3em; font-weight: 500; color: var(--templo-text-secondary); margin-bottom: 15px; margin-top: 0; text-align: center; border-bottom: 1px solid var(--templo-panel-border); padding-bottom: 10px; flex-shrink: 0;
        }

        /* Container do Chat */
        .chat-container-ajax {
            width: 100%; max-width: 650px; margin-left: auto; margin-right: auto; margin-top: 0;
            border: 1px solid var(--templo-chat-border); border-radius: 8px;
            background-color: var(--templo-chat-bg); /* Fundo escuro chat */
            display: flex; flex-direction: column;
            flex-grow: 1; /* Ocupa espaço vertical disponível */
            min-height: 200px; /* <<<< REDUZIDO DE 250px */
            max-height: 35vh; /* <<<< REDUZIDO DE 50vh - AJUSTE ESTE VALOR */
            padding: 10px; margin-bottom: 0;
            box-shadow: inset 0 2px 5px rgba(0,0,0,0.4);
        }

        /* Área de Exibição das Mensagens */
        .chat-messages-display {
            flex-grow: 1; overflow-y: auto; padding: 8px 5px; display: flex; flex-direction: column; gap: 10px;
            background-color: transparent; border-radius: 0; margin-bottom: 15px; border: none;
            scrollbar-width: thin; scrollbar-color: var(--templo-chat-border) rgba(0,0,0,0.1);
        }
        .chat-messages-display::-webkit-scrollbar { width: 6px; }
        .chat-messages-display::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 3px; }
        .chat-messages-display::-webkit-scrollbar-thumb { background-color: var(--templo-chat-border); border-radius: 3px; }
        .chat-messages-display::-webkit-scrollbar-thumb:hover { background-color: #78909c; }

        /* Mensagem Individual */
        .chat-message-ajax {
             max-width: 85%; padding: 9px 14px; border-radius: 12px; line-height: 1.5; font-size: 0.9rem; word-wrap: break-word;
             background-color: var(--templo-chat-input-bg); /* Fundo input */
             color: var(--templo-chat-text); /* Texto claro */
             align-self: flex-start; border-bottom-left-radius: 4px;
             border: none; box-shadow: 1px 1px 3px rgba(0,0,0,0.25); position: relative;
        }
        .chat-message-ajax.mine {
             background-color: var(--templo-accent-blue); /* Azul para minhas mensagens */
             color: #ffffff;
             align-self: flex-end; border-bottom-right-radius: 4px; border-bottom-left-radius: 12px;
        }
        .chat-message-ajax .sender {
             font-weight: 600; font-size: 0.75rem; color: rgba(255,255,255,0.75); display: block; margin-bottom: 4px; text-transform: capitalize;
        }
        .chat-message-ajax.mine .sender { color: rgba(255,255,255,0.85); text-align: right; }
        .chat-message-ajax .sender small { font-size: 0.9em; color: inherit; font-weight: 400; margin-left: 6px; opacity: 0.8; }
        .chat-message-ajax .msg-text { font-size: 0.9rem; color: inherit; }

        /* Mensagem de Chat Vazio */
        .chat-empty { text-align: center; color: #90a4ae; padding: 20px; font-style: italic; font-size: 0.85rem; margin: auto; }

        /* Área de Input do Chat */
        .chat-input-area { display: flex; padding: 0; margin-top: auto; flex-shrink: 0; }
        .chat-input-area input[type="text"] {
            flex-grow: 1; padding: 11px 15px; border: 1px solid var(--templo-chat-border); border-right: none; border-radius: 6px 0 0 6px;
            background-color: var(--templo-chat-input-bg); color: var(--templo-chat-text); font-size: 0.9rem; outline: none; transition: border-color 0.3s, background-color 0.3s;
        }
        .chat-input-area input[type="text"]:focus { border-color: var(--templo-accent-blue); background-color: #607d8b; }
        .chat-input-area button {
             padding: 11px 18px; border: none; background-color: var(--templo-accent-blue); color: #ffffff; border-radius: 0 6px 6px 0; cursor: pointer; font-weight: 600; font-size: 0.9rem; transition: background-color 0.2s ease; font-family: var(--templo-font-body); text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid #1565c0; border-left: none;
        }
        .chat-input-area button:hover { background-color: #1976d2; }
        .chat-input-area button:active { background-color: #1565c0; }

        /* Feedback Global (Sobrescrito para tema Templo) */
        .feedback-container {
            padding: 12px 20px; margin: 15px auto; border-radius: 6px;
            background-color: #fff3e0; /* Laranja bem claro */
            color: #e65100; /* Laranja escuro */
            border: 1px solid #ffe0b2; /* Borda laranja clara */
            border-left-width: 5px; border-left-color: #ff9800; /* Destaque laranja */
            max-width: 95%; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .feedback-container .error-message { color: #c62828; } /* Vermelho escuro para erros */
        .feedback-container .success-message { color: #2e7d32; } /* Verde escuro para sucesso */
        .feedback-container .info-message { color: #0277bd; } /* Azul escuro para info */
        .feedback-container .warning-message { color: #ef6c00; } /* Laranja escuro para avisos */
        /* Fundo específico para erro */
        .feedback-container:has(.error-message) {
             background-color: #ffebee; border-color: #ef9a9a; border-left-color: #e57373;
        }
        /* Fundo específico para sucesso */
        .feedback-container:has(.success-message) {
             background-color: #e8f5e9; border-color: #a5d6a7; border-left-color: #81c784;
        }

        /* ===>>> CSS do Modal Popup (Copiado do Kame/Urunai) <<<=== */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.75); display: flex; justify-content: center; align-items: center; z-index: 1100; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0s linear 0.3s; }
        .modal-overlay.visible { opacity: 1; visibility: visible; transition: opacity 0.3s ease; }
        .modal-popup { background-color: var(--panel-bg, #263238); /* Fundo escuro padrão */ padding: 25px 30px; border-radius: var(--border-radius, 10px); border: 1px solid var(--panel-border, rgba(255, 255, 255, 0.15)); box-shadow: var(--shadow, 0 8px 25px rgba(0, 0, 0, 0.5)); width: 90%; max-width: 450px; text-align: center; position: relative; transform: scale(0.9); transition: transform 0.3s ease; color: var(--text-primary, #e8eaed); /* Texto claro padrão */ }
        .modal-overlay.visible .modal-popup { transform: scale(1); }
        .modal-popup h3 { color: var(--dbz-orange, #f5a623); font-family: var(--font-main); margin-top: 0; margin-bottom: 15px; font-size: 1.4em; border-bottom: 1px solid var(--dbz-orange-dark, #e47d1e); padding-bottom: 10px; }
        .modal-popup #popup-message { color: inherit; /* Herda cor do popup */ font-size: 1rem; line-height: 1.6; margin-bottom: 25px; min-height: 40px; word-wrap: break-word; }
        .modal-popup #popup-actions { display: flex; justify-content: center; gap: 15px; flex-wrap: wrap; }
        .modal-popup .popup-action-btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; text-align: center; cursor: pointer; transition: all 0.2s ease; min-width: 100px; }
        .popup-action-btn.btn-ok { background-color: var(--accent-color-3, #2979ff); color: #fff; }
        .popup-action-btn.btn-accept { background-color: var(--accent-color-1, #00e676); color: #111; }
        .popup-action-btn.btn-reject { background-color: var(--danger-color, #ff5252); color: #fff; }
        .popup-action-btn:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .modal-close-btn { position: absolute; top: 10px; right: 15px; background: none; border: none; font-size: 1.8em; color: var(--text-secondary, #bdc1c6); cursor: pointer; line-height: 1; padding: 0; opacity: 0.7; }
        .modal-close-btn:hover { color: var(--text-primary, #e8eaed); opacity: 1; }
        /* ===>>> FIM CSS Modal Popup <<<=== */


    </style>
</head>
<body>

    <?php if (!empty($mensagem_final_feedback)): ?>
       <div class="feedback-container">
           <?php
             // Limpa spans antigos e aplica a classe correta ao container ou a um novo span
             $cleaned_message = preg_replace('/<span class=[\'"][^\'"]*[\'"]>(.*?)<\/span>/i', '$1', $mensagem_final_feedback);
             $msg_class = 'info-message'; // Default
             if (strpos($mensagem_final_feedback, 'error-message') !== false) $msg_class = 'error-message';
             elseif (strpos($mensagem_final_feedback, 'success-message') !== false) $msg_class = 'success-message';
             elseif (strpos($mensagem_final_feedback, 'warning-message') !== false) $msg_class = 'warning-message';
             elseif (strpos($mensagem_final_feedback, 'levelup-message') !== false) $msg_class = 'success-message';

             // Exibe a mensagem limpa dentro de um novo span com a classe correta
             echo '<span class="' . $msg_class . '">' . nl2br(htmlspecialchars(strip_tags($cleaned_message))) . '</span>';
           ?>
       </div>
    <?php endif; ?>

    <div class="container">

        <?php // --- COLUNA ESQUERDA (Layout Padrão - SEM Notificações) --- ?>
        <div class="coluna coluna-esquerda">
             <div class="player-card">
                  <img class="foto" src="<?= $caminho_web_foto ?>" alt="Foto do Perfil">
                  <div class="player-name"><?= $nome_formatado_usuario ?></div>
                  <div class="player-level">Nível <?= htmlspecialchars($usuario['nivel'] ?? '?') ?></div>
                  <div class="player-race">Raça: <?= htmlspecialchars($usuario['raca'] ?? 'N/D') ?></div>
                  <div class="player-rank">Título: <?= htmlspecialchars($usuario['ranking_titulo'] ?? 'Iniciante') ?></div>
             </div>
             <div class="zeni-display">💰 Zeni: <?= formatNumber($usuario['zeni'] ?? 0) ?></div>
             <div class="xp-bar-container">
                  <span class="xp-label">Experiência</span>
                  <div class="xp-bar">
                       <div class="xp-bar-fill" style="width: <?= $xp_percent ?>%;"></div>
                       <div class="xp-text"><?= formatNumber($usuario['xp'] ?? 0) ?> / <?= $xp_necessario_display ?></div>
                  </div>
             </div>
             <?php $pontos_num = filter_var($usuario['pontos'] ?? 0, FILTER_VALIDATE_INT); ?>
             <?php if ($pontos_num !== false && $pontos_num > 0): ?>
                  <?php if (basename($_SERVER['PHP_SELF']) !== 'perfil.php'): ?>
                       <a href="perfil.php" class="btn-pontos"> <?= formatNumber($pontos_num) ?> Pts <span style="font-size: 0.8em;">(Distribuir)</span> </a>
                  <?php else: ?>
                       <div class="btn-pontos" style="cursor: default; animation: none; filter: none; background: #555;"> <?= formatNumber($pontos_num) ?> Pts <span style="font-size: 0.8em;">(Disponíveis)</span> </div>
                  <?php endif; ?>
             <?php endif; ?>
             <div class="stats-grid">
                  <strong>HP Base</strong><span><?= formatNumber($usuario['hp'] ?? 0) ?></span>
                  <strong>Ki Base</strong><span><?= formatNumber($usuario['ki'] ?? 0) ?></span>
                  <strong>Força</strong><span><?= formatNumber($usuario['forca'] ?? 0) ?></span>
                  <strong>Defesa</strong><span><?= formatNumber($usuario['defesa'] ?? 0) ?></span>
                  <strong>Velocidade</strong><span><?= formatNumber($usuario['velocidade'] ?? 0) ?></span>
                  <strong>Pontos</strong><span style="color: var(--accent-color-2);"><?= formatNumber($usuario['pontos'] ?? 0) ?></span>
                  <strong>Rank (Nível)</strong><span style="color: var(--accent-color-1);"><?= htmlspecialchars($rank_nivel) ?><?= ($rank_nivel !== 'N/A' && is_numeric($rank_nivel) ? 'º' : '') ?></span>
                  <strong>Rank (Sala)</strong><span style="color: var(--accent-color-1);"><?= htmlspecialchars($rank_sala) ?><?= ($rank_sala !== 'N/A' && is_numeric($rank_sala) ? 'º' : '') ?></span>
             </div>
             <div class="divider"></div>

             <div class="stats-grid" style="font-size: 0.85rem; margin-top: auto; padding-top: 10px; border-top: 1px solid var(--panel-border);">
                  <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span>
                  <strong>Tempo Sala</strong><span><?= formatNumber($usuario['tempo_sala'] ?? 0) ?> min</span>
             </div>

             <div style="margin-top: 15px; display: flex; flex-direction: column; gap: 10px;">
                  <a class="action-button btn-perfil" href="perfil.php">Meu Perfil</a> <?php // <<<<< VERIFIQUE PATH ?>
                  <a class="action-button btn-logout" href="sair.php">Sair (Logout)</a> <?php // <<<<< VERIFIQUE PATH ?>
             </div>
        </div>

        <?php // --- COLUNA CENTRAL (Conteúdo do Templo) --- ?>
        <div class="coluna coluna-central">
             <h1 class="titulo-central">Sala do Templo</h1>
             <p class="texto-descricao">
                 <img src="img/templo.jpg" alt="Imagem da Sala do Templo"> <?php // <<<<< VERIFIQUE PATH ?>
                 Bem-vindo guerreiro Z. Neste lugar sagrado, o tempo flui diferente. Aqui você pode meditar, treinar sua mente e espírito, acumulando <strong>experiência vital (XP)</strong> para sua jornada.
             </p>
             <p class="texto-descricao">
                 Permaneça ativo na sala para ganhar experiência continuamente. Quanto mais tempo dedicado, mais forte você se tornará. Use o chat para interagir com outros guerreiros que também buscam poder.
             </p>
             <p class="player-count-templo">
                 Guerreiros presentes meditando: <strong><?= htmlspecialchars($jogadores_no_templo_count) ?></strong>
             </p>
             <div class="templo-player-list-container">
                 <h3>Presentes na Sala do Templo</h3>
                 <?php if (!empty($lista_jogadores_templo)): ?>
                      <ul class="templo-player-list">
                          <?php foreach ($lista_jogadores_templo as $jogador_templo): ?>
                               <li><?= htmlspecialchars($jogador_templo['nome']); ?></li>
                          <?php endforeach; ?>
                      </ul>
                 <?php else: ?>
                      <p class="empty-list-msg">A Sala do Templo está momentaneamente vazia.</p>
                 <?php endif; ?>
             </div>

             <?php if ($pode_entrar_templo_base): ?>
                  <a class="action-button btn-templo-action" href="script/saladotemplo.php"> Entrar na Sala do Templo </a> <?php // <<<<< VERIFIQUE PATH ?>
             <?php else: ?>
                  <button class="action-button btn-templo-action" disabled title="<?= htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) ?>">Entrar na Sala do Templo</button>
                  <p class="templo-disabled-reason"><?= htmlspecialchars($mensagem_bloqueio_templo_base) ?></p>
             <?php endif; ?>

             <hr class="divider">
             <h3 class="chat-titulo">Chat da Sala do Templo</h3>
             <div class="chat-container-ajax">
                 <div id="chat-messages-ajax" class="chat-messages-display">
                      <?php if (!empty($chat_history)):
                          $chatHistoryCount = count($chat_history); $messageCounter = 0;
                          foreach ($chat_history as $msg):
                               $messageCounter++; $isLastMessage = ($messageCounter === $chatHistoryCount); ?>
                               <div class="chat-message-ajax <?= $msg['user_id'] == $id ? 'mine' : 'other' ?>"
                                    data-message-id="<?= $msg['id'] ?>"
                                    <?= $isLastMessage ? 'id="last-initial-message"' : '' ?> >
                                   <span class="sender"><?= htmlspecialchars($msg['username']) ?> <small></small></span>
                                   <span class="msg-text"><?= htmlspecialchars($msg['message_text']) ?></span>
                                   <span class="data-timestamp" style="display:none;"><?= htmlspecialchars($msg['timestamp']) ?></span>
                               </div>
                          <?php endforeach;
                          else: ?>
                          <p class="chat-empty">Nenhuma mensagem recente. Comece a conversa!</p>
                      <?php endif; ?>
                 </div>
                 <form id="chat-form-ajax" class="chat-input-area">
                      <input type="text" id="chat-message-input" placeholder="Digite sua mensagem aqui..." autocomplete="off" required maxlength="500">
                      <button type="submit">Enviar</button>
                 </form>
             </div>
        </div>

        <?php // --- COLUNA DIREITA (Navegação) --- ?>
        <div class="coluna coluna-direita">
            <?php
                  $currentPageBaseName = basename($_SERVER['PHP_SELF']); // templo.php
                  foreach ($sidebarItems as $item) {
                      $href = $item['href'];
                      $label = htmlspecialchars($item['label']);
                      $iconPath = $item['icon'] ?? ''; // Caminho img
                      $faIconClass = $item['fa_icon'] ?? 'fas fa-question-circle'; // Classe FA
                      $idItem = $item['id'];
                      $classe_extra = '';
                      $atributos_extra = '';
                      $title = $label;

                      // Determina ícone
                      $iconHtml = "<i class='{$faIconClass} nav-button-icon'></i>"; // Default FA
                       if (!empty($iconPath)) {
                            $iconFullPathFisico = __DIR__ . '/' . $iconPath; // Assume templo.php na raiz
                            if (file_exists($iconFullPathFisico)) {
                                $iconHtml = '<img src="' . htmlspecialchars($iconPath) . '" class="nav-button-icon" alt="">';
                            } else { error_log("Icone nav D (img) nao encontrado em templo.php: " . $iconFullPathFisico . " - Usando FA: " . $faIconClass); }
                       }

                      // Lógica para desabilitar botão Templo
                      if ($idItem === 'sala_tempo') {
                           if (!$pode_entrar_templo_base) {
                               $classe_extra = ' disabled';
                               $atributos_extra = ' onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES).'\'); return false;"';
                               $title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                               $href = '#';
                           } else { $title = 'Entrar na Sala do Templo'; }
                      }

                      // Verifica se é a página atual
                      $isCurrent = ($idItem === 'sala_tempo'); // Sempre ativo para esta página

                      if ($isCurrent && strpos($classe_extra, 'disabled') === false) { $classe_extra .= ' active'; }

                      $finalHref = htmlspecialchars($href);
                      echo "<a class=\"nav-button{$classe_extra}\" href=\"{$finalHref}\" title=\"{$title}\" {$atributos_extra}>";
                      echo "<div class='nav-button-icon-area'>{$iconHtml}</div>";
                      echo "<span>{$label}</span>";
                      echo "</a>";
                  } // Fim do foreach

                  // Botão Sair
                  echo '<div style="margin-top: auto; padding-top: 10px; border-top: 1px solid var(--panel-border);">';
                  $sairIconPath = 'img/sair.jpg'; // <<<<< VERIFIQUE PATH
                  $sairIconHtml = '<i class="fas fa-sign-out-alt nav-button-icon"></i>';
                   if(file_exists(__DIR__.'/'.$sairIconPath)) { $sairIconHtml = '<img src="'.htmlspecialchars($sairIconPath).'" class="nav-button-icon" alt="Sair">'; }
                   echo '<a class="nav-button logout" href="sair.php" title="Sair do Jogo">'; // <<<<< VERIFIQUE PATH
                   echo "<div class='nav-button-icon-area'>{$sairIconHtml}</div>";
                   echo '<span>Sair</span></a>';
                   echo '</div>';
            ?>
        </div>
    </div> <?php // Fechamento do .container ?>

    <?php // --- Bottom Nav (Exibido em telas menores) --- ?>
    <nav id="bottom-nav">
         <?php
              $currentPageBaseNameNav = basename($_SERVER['PHP_SELF']); // templo.php
              foreach ($bottomNavItems as $item):
                  $url_destino_item = $item['href'];
                  $itemIdNav = $item['id'] ?? null;
                  $labelNav = htmlspecialchars($item['label']);
                  $iconNav = $item['icon'];
                  $final_href = '#'; $final_onclick = ''; $final_title = '';
                  $extra_class = ''; $active_class = '';

                  $is_current_page_nav = ($itemIdNav === 'sala_tempo'); // Verifica se é Templo

                  if ($itemIdNav === 'sala_tempo') { // Lógica específica botão Templo
                       if (!$pode_entrar_templo_base) {
                           $final_onclick = ' onclick="alert(\'' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '\'); return false;"';
                           $final_title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                           $extra_class = ' disabled'; // href fica #
                       } else {
                           $final_href = htmlspecialchars($url_destino_item); $final_title = 'Entrar na Sala';
                       }
                  } else { $final_href = htmlspecialchars($url_destino_item); $final_title = 'Ir para ' . $labelNav; }

                  if ($is_current_page_nav && strpos($extra_class, 'disabled') === false) { $active_class = ' active'; }

                  echo "<a href='{$final_href}' class='bottom-nav-item{$active_class}{$extra_class}' {$final_onclick} title='{$final_title}'>";
                  echo "<span class='bottom-nav-icon'>{$iconNav}</span>";
                  echo "<span class='bottom-nav-label'>{$labelNav}</span>";
                  echo "</a>";
              endforeach;
         ?>
    </nav>

     <?php // --- ===>>> HTML do Modal Popup (Copiado do Kame/Urunai) <<<=== --- ?>
     <div id="notification-popup-overlay" class="modal-overlay">
         <div class="modal-popup">
             <button class="modal-close-btn" onclick="markCurrentNotificationAsRead()" title="Marcar como lida">&times;</button>
             <h3>Nova Notificação</h3>
             <div id="popup-message">Carregando mensagem...</div>
             <div id="popup-actions"></div>
         </div>
     </div>


    <?php // --- JavaScript Essencial (Chat + BottomNav + NOVO Popup Notificação) --- ?>
    <script>
        // --- Variáveis e Constantes Globais ---
        const currentUserId_Chat = <?php echo json_encode((int)$id); ?>;
        const currentUsername_Chat = <?php echo json_encode($usuario['nome'] ?? 'Eu'); ?>;
        const chatHandlerUrl = 'chat_handler_ajax.php'; // <<<<< VERIFIQUE PATH
        const chatMessagesDiv = document.getElementById('chat-messages-ajax');
        const chatForm = document.getElementById('chat-form-ajax');
        const messageInput = document.getElementById('chat-message-input');
        let lastMessageId_Chat = 0; <?php $lastMId = 0; if(!empty($chat_history)){ if(count($chat_history) > 0){ $lastMsg = end($chat_history); if(isset($lastMsg['id'])) $lastMId = (int)$lastMsg['id']; } } echo 'lastMessageId_Chat = '.$lastMId.';'; ?>
        const chatPollInterval = 3500; // 3.5 segundos
        let chatPollingIntervalId = null;
        let isFetchingChat = false;

        // ===>>> NOVAS Variáveis e Constantes para Popup Notificação <<<===
        const currentUserId_Popup = <?php echo json_encode((int)$id); ?>;
        const unreadNotifications = <?php echo json_encode($unread_notifications_for_popup); ?>; // Vindo do PHP
        let currentNotification = null;
        const modalOverlay = document.getElementById('notification-popup-overlay');
        const popupMessage = document.getElementById('popup-message');
        const popupActions = document.getElementById('popup-actions');
        // ===>>> FIM NOVAS Variáveis Popup <<<===

        // --- Funções Utilitárias ---
        function escapeHtml(unsafe){ if(typeof unsafe!=='string'){if(typeof unsafe==='number'){unsafe=String(unsafe);}else{return'';}} return unsafe.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;"); }
        function formatTimestamp(timestamp){ if(!timestamp)return'??:??'; try { let ds = timestamp.replace(/-/g, '/'); if (ds.indexOf(' ') > 0 && ds.indexOf('T') === -1 && !ds.endsWith('Z') && !ds.match(/[+-]\d{2}:?\d{2}$/)) {} const d = new Date(ds); if (!isNaN(d.getTime())) { return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); } } catch(e) {} const parts = timestamp.split(' '); if (parts.length > 1 && parts[1].includes(':')) { const timePart = parts[1].substring(0, 5); if (/^\d{2}:\d{2}$/.test(timePart)) return timePart; } return'??:??'; }

        // --- ===>>> Lógica do Modal Popup (Copiada do Kame/Urunai e Adaptada para Templo) <<<=== ---
        function showModal() { if (modalOverlay) modalOverlay.classList.add('visible'); }
        function hideModal() { if (modalOverlay) { modalOverlay.classList.remove('visible'); if(popupMessage) popupMessage.innerHTML = ''; if(popupActions) popupActions.innerHTML = ''; currentNotification = null; } }
        function markCurrentNotificationAsRead() {
            if (currentNotification && currentNotification.id) {
                window.location.href = `templo.php?mark_read=${currentNotification.id}`; // <<<< Aponta para Templo
                hideModal();
            } else { hideModal(); }
        }
        function displayNextNotification() {
             if (!modalOverlay || !popupMessage || !popupActions) { console.error("Elementos do modal não encontrados (Templo)."); return; }
             if (unreadNotifications && unreadNotifications.length > 0) {
                  currentNotification = unreadNotifications[0];
                  if (!currentNotification) { hideModal(); return; }
                  popupMessage.textContent = currentNotification.message_text || 'Mensagem indisponível.';
                  popupActions.innerHTML = '';
                  const markReadLink = `templo.php?mark_read=${currentNotification.id}`; // <<<< Aponta para Templo
                  let hasSpecificAction = false;
                  let relatedData = {};
                  if (currentNotification.related_data) {
                       if (typeof currentNotification.related_data === 'string') { try { relatedData = JSON.parse(currentNotification.related_data); } catch(e) { console.error("Erro JSON related_data (Templo):", e); relatedData = {}; } }
                       else if (typeof currentNotification.related_data === 'object') { relatedData = currentNotification.related_data; }
                  }

                  if (currentNotification.type === 'invite') {
                       const groupId = relatedData.group_id;
                       if (groupId) { const acceptLink = `templo.php?invite_action=accept&notif_id=${currentNotification.id}`; const rejectLink = `templo.php?invite_action=reject&notif_id=${currentNotification.id}`; popupActions.innerHTML = `<a href="${acceptLink}" class="popup-action-btn btn-accept">Aceitar Grupo</a><a href="${rejectLink}" class="popup-action-btn btn-reject">Recusar</a>`; hasSpecificAction = true; }
                       else { console.warn("Dados grupo incompletos (Templo):", currentNotification); }
                  }
                  else if (currentNotification.type === 'fusion_invite') {
                       const inviterId = relatedData.inviter_id; const desafioId = relatedData.desafio_id;
                       if (inviterId && desafioId) { const acceptLink = `templo.php?fusion_action=accept&notif_id=${currentNotification.id}`; const rejectLink = `templo.php?fusion_action=reject&notif_id=${currentNotification.id}`; popupActions.innerHTML = `<a href="${acceptLink}" class="popup-action-btn btn-accept">Aceitar Fusão</a><a href="${rejectLink}" class="popup-action-btn btn-reject">Recusar</a>`; hasSpecificAction = true; }
                       else { console.warn("Dados fusão incompletos (Templo):", currentNotification); }
                  }
                  else if (currentNotification.type === 'arena_start') {
                       const eventId = relatedData.event_id;
                       if (eventId) { const enterArenaLink = `templo.php?arena_action=enter&notif_id=${currentNotification.id}&event_id=${eventId}`; popupActions.innerHTML = `<a href="${enterArenaLink}" class="popup-action-btn btn-accept">Entrar na Arena</a><a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`; hasSpecificAction = true; }
                       else { console.warn("Dados arena incompletos (Templo):", currentNotification); }
                  }
                  else if (currentNotification.type === 'zeni_transfer_received') {
                       popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`; hasSpecificAction = true;
                  }

                  if (!hasSpecificAction) { popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`; }
                  showModal();
             } else { hideModal(); }
        }
        // --- ===>>> FIM Lógica Modal Popup <<<=== ---

        // --- Sistema de Chat (Inalterado da versão anterior corrigida) ---
        function scrollToBottomChat(force = false){ if(!chatMessagesDiv) return; const isNearBottom = chatMessagesDiv.scrollHeight - chatMessagesDiv.clientHeight <= chatMessagesDiv.scrollTop + 30; if(force || isNearBottom){ setTimeout(() => { if(chatMessagesDiv) chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight; }, 50); } }
        function checkEmptyChat() { if(!chatMessagesDiv) return; const emptyMsgElement = chatMessagesDiv.querySelector('.chat-empty'); const hasMessages = chatMessagesDiv.querySelector('.chat-message-ajax'); if (!hasMessages && !emptyMsgElement) { const p = document.createElement('p'); p.classList.add('chat-empty'); p.textContent = 'Nenhuma mensagem recente.'; chatMessagesDiv.innerHTML = ''; chatMessagesDiv.appendChild(p); } else if (hasMessages && emptyMsgElement) { emptyMsgElement.remove(); } }
        function addChatMessageToDisplay(msg, isHistoryLoad = false){ if (!chatMessagesDiv||!msg||!msg.id||document.querySelector(`.chat-message-ajax[data-message-id="${msg.id}"]`)) return; checkEmptyChat(); const me=document.createElement('div'); me.classList.add('chat-message-ajax'); me.classList.toggle('mine',msg.user_id==currentUserId_Chat); me.classList.toggle('other',msg.user_id!=currentUserId_Chat); me.dataset.messageId=msg.id; const ts=formatTimestamp(msg.timestamp); const sn=escapeHtml(msg.username||'???'); const mt=escapeHtml(msg.message_text||''); me.innerHTML=`<span class="sender">${sn} <small>(${ts})</small></span><span class="msg-text">${mt}</span>`; chatMessagesDiv.appendChild(me); if(!isHistoryLoad){ me.scrollIntoView({ behavior: 'auto', block: 'end' }); } if(msg.id && parseInt(msg.id) > lastMessageId_Chat){ lastMessageId_Chat=parseInt(msg.id); } }
        function initializeChatSystem(){ if (!chatMessagesDiv || !chatForm || !messageInput) return; chatMessagesDiv.querySelectorAll('.chat-message-ajax').forEach(msgElement => { const timestampDataSpan = msgElement.querySelector('.data-timestamp'); const senderSmallSpan = msgElement.querySelector('.sender small'); if(timestampDataSpan && senderSmallSpan){ senderSmallSpan.textContent = `(${formatTimestamp(timestampDataSpan.textContent)})`; timestampDataSpan.remove(); } }); const lastInitialMessage = document.getElementById('last-initial-message'); if (lastInitialMessage) { setTimeout(() => { lastInitialMessage.scrollIntoView({ behavior: 'auto', block: 'end' }); console.log('Chat inicial rolado via ID.'); }, 100); } else { scrollToBottomChat(true); console.log('Chat inicial: ID não encontrado, rolagem genérica.'); } chatForm.addEventListener('submit', handleChatSubmit); if(chatPollingIntervalId) clearInterval(chatPollingIntervalId); setTimeout(fetchNewChatMessages, 1500); chatPollingIntervalId = setInterval(fetchNewChatMessages, chatPollInterval); document.addEventListener("visibilitychange", handleVisibilityChangeChat); checkEmptyChat(); }
        function handleVisibilityChangeChat(){ if(document.hidden){ if(chatPollingIntervalId){clearInterval(chatPollingIntervalId); chatPollingIntervalId = null;} } else { if(!chatPollingIntervalId){ setTimeout(fetchNewChatMessages, 200); chatPollingIntervalId = setInterval(fetchNewChatMessages, chatPollInterval);} } }
        async function handleChatSubmit(event){ event.preventDefault(); if(!messageInput || !chatForm) return; const messageText = messageInput.value.trim(); if(messageText.length === 0 || messageText.length > 500) return; messageInput.disabled = true; const submitButton = chatForm.querySelector('button'); if(submitButton) submitButton.disabled = true; const formData = new FormData(); formData.append('message', messageText); try { const response = await fetch(`${chatHandlerUrl}?action=send`, { method: 'POST', body: formData }); const data = await response.json(); if(data.success && data.message){ messageInput.value = ''; addChatMessageToDisplay(data.message, false); } else { console.error('Erro ao enviar:', data.error); alert('Erro: ' + (data.error || 'Tente.')); } } catch (error) { console.error('Falha envio chat:', error); alert('Erro conexão envio.'); } finally { messageInput.disabled = false; if(submitButton) submitButton.disabled = false; messageInput.focus(); } }
        async function fetchNewChatMessages(){ if(isFetchingChat || document.hidden || !chatMessagesDiv) return; isFetchingChat = true; try { const url = `${chatHandlerUrl}?action=fetch&last_id=${lastMessageId_Chat}&t=${Date.now()}`; const response = await fetch(url); if(!response.ok){ console.warn(`Chat fetch: ${response.status}`); isFetchingChat = false; return; } const data = await response.json(); if(data.success && Array.isArray(data.messages) && data.messages.length > 0){ checkEmptyChat(); data.messages.forEach(m => { addChatMessageToDisplay(m, false); }); } else if (!data.success){ console.error('Erro buscar chat:', data.error); } if (data.last_processed_id && parseInt(data.last_processed_id) > lastMessageId_Chat) { lastMessageId_Chat = parseInt(data.last_processed_id); } } catch(error) { console.error('Falha req busca chat:', error); } finally { isFetchingChat = false; } }

        // --- Inicialização Geral e Lógica da UI ---
        document.addEventListener('DOMContentLoaded', () => {
            // Feedback visual para botões desabilitados
            document.querySelectorAll('.nav-button.disabled[onclick*="alert"], .bottom-nav-item.disabled[onclick*="alert"]').forEach(button => { button.addEventListener('click', event => { button.style.transition = 'box-shadow 0.1s ease'; button.style.boxShadow = '0 0 5px 2px var(--danger-color, #dc3545)'; setTimeout(() => { button.style.boxShadow = ''; }, 300); }); });
            // Links com data-url
            document.querySelectorAll('[data-url]').forEach(button => { if (!button.classList.contains('disabled') && button.getAttribute('href') === '#') { button.addEventListener('click', ev => { ev.preventDefault(); const url = button.dataset.url; if(url) window.location.href = url; }); } });

            // Lógica Bottom Nav
            const bottomNav = document.getElementById('bottom-nav'); const rightColumn = document.querySelector('.coluna-direita'); const bodyEl = document.body;
            function checkBottomNavVisibility() { if(!bottomNav || !rightColumn || !bodyEl) return; const originalPadding = getComputedStyle(bodyEl).getPropertyValue('--body-original-padding-bottom').trim() || '20px'; if (window.getComputedStyle(rightColumn).display === 'none') { bottomNav.style.display = 'flex'; bodyEl.style.paddingBottom = (bottomNav.offsetHeight + 15) + 'px'; } else { bottomNav.style.display = 'none'; bodyEl.style.paddingBottom = originalPadding; } }
            bodyEl.style.setProperty('--body-original-padding-bottom', getComputedStyle(bodyEl).paddingBottom); checkBottomNavVisibility(); window.addEventListener('resize', checkBottomNavVisibility); setTimeout(checkBottomNavVisibility, 300);

            // Inicializa Chat
            initializeChatSystem();

            // ===>>> INICIALIZA Popup Notificação <<<===
            displayNextNotification(); // Mostra a primeira notificação não lida (se houver)
            if (modalOverlay) { modalOverlay.addEventListener('click', function(event) { if (event.target === modalOverlay) { markCurrentNotificationAsRead(); } }); }
            // ===>>> FIM Inicialização Popup <<<===

             // Opcional: Fechar feedback após X segundos
             const feedbackBox = document.querySelector('.feedback-container');
             if (feedbackBox && window.getComputedStyle(feedbackBox).display !== 'none') { // Verifica se está visível
                  setTimeout(() => {
                      feedbackBox.style.transition = 'opacity 0.5s ease-out';
                      feedbackBox.style.opacity = '0';
                      setTimeout(() => { feedbackBox.style.display = 'none'; }, 500);
                  }, 7000); // Fecha após 7 segundos
             }
        });
    </script>

</body>
</html>
<?php
// Fecha conexão PDO no final do script
if (isset($conn) && $conn instanceof PDO) { $conn = null; }
if (isset($pdo) && $pdo instanceof PDO) { $pdo = null; }
?>