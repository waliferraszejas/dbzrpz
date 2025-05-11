<?php
// Arquivo: urunai.php (MODIFICADO com Popup Notificação Kame-style)
// Removeu Notificações da Coluna Esquerda e integrou o Modal Popup

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); } // <<<<< VERIFIQUE PATH

// <<<<< VERIFIQUE PATHS >>>>>
require_once('conexao.php'); // Assume na raiz?
if (file_exists('script/sala_functions.php')) { // Assume na raiz?
    require_once('script/sala_functions.php');
}
// Inclui fallbacks para functions.php se necessário (como no kame.php)
if (file_exists(__DIR__ . "/includes/functions.php")) {
    include_once(__DIR__ . "/includes/functions.php");
}
// Fallback functions essenciais
if (!function_exists('formatNumber')) { function formatNumber($num) { $n = filter_var($num, FILTER_VALIDATE_INT); return number_format(($n === false ? 0 : $n), 0, ',', '.'); } }
if (!function_exists('formatarNomeUsuarioComTag')) { function formatarNomeUsuarioComTag($c, $d) { if (!is_array($d)||!isset($d['nome'])) { return 'Nome Inválido'; } return htmlspecialchars($d['nome']); } }

// Garante conexão PDO (redundância segura)
if (!isset($conn) || !$conn instanceof PDO) {
    if (isset($pdo) && $pdo instanceof PDO) {
        $conn = $pdo;
    } else {
        try {
            require('conexao.php'); // Tenta reconectar
            if (!isset($conn) || !$conn instanceof PDO) {
                throw new Exception("Falha crítica conexão PDO (Urunai Top).");
            }
        } catch (Exception $e) {
             error_log("Falha crítica conexão PDO urunai.php (topo): " . $e->getMessage());
             die("Erro crítico: DB indisponível. Avise um Admin.");
        }
    }
}


// --- Variáveis e Configurações ---
$id = $_SESSION['user_id'];
$id_usuario_logado = $id;
$usuario = null;
$chat_history = [];
$mensagem_final_feedback = "";
$currentPage = basename(__FILE__); // urunai.php
$nome_formatado_usuario = 'ErroNome';
$rank_nivel = 'N/A';
$rank_sala = 'N/A';
$online_users_count = 0;
$online_user_list = [];
$online_interval_minutes = 5;
$chat_history_limit = 50;

// ===>>> NOVA Variável para Notificações do Popup <<<===
$unread_notifications_for_popup = [];

// Constantes
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);
if (!defined('PONTOS_GANHO_POR_NIVEL')) define('PONTOS_GANHO_POR_NIVEL', 100);

// --- Variáveis Específicas Urunai ---
$reset_message = "";
$reset_message_type = "";
$reset_cost = 10000; // Custo em XP


// --- ===>>> INÍCIO: Processar Ações de Notificação via GET (Copiado do Kame e Adaptado) <<<=== ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
     if (!isset($conn) || !$conn instanceof PDO) { die("Erro crítico: Conexão PDO não disponível para GET actions (Urunai)."); }
     $notification_action_processed = false;

     // Marcar como lida (genérico)
     if (isset($_GET['mark_read']) && filter_var($_GET['mark_read'], FILTER_VALIDATE_INT)) {
         $notif_id_to_mark = (int)$_GET['mark_read'];
         try {
             $stmt_mark = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
             $stmt_mark->execute([':notif_id' => $notif_id_to_mark, ':user_id' => $id]);
              // Opcional: $_SESSION['feedback_geral'] = "<span class='info-message'>Notificação marcada como lida.</span>";
         } catch (PDOException $e) {
             error_log("Erro PDO marcar notif {$notif_id_to_mark} lida user {$id} (Urunai): " . $e->getMessage());
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
                   // 1. Busca notificação
                   $stmt_get_invite = $conn->prepare("SELECT related_data FROM user_notifications WHERE id = :notif_id AND user_id = :user_id AND type = 'invite'");
                   $stmt_get_invite->execute([':notif_id' => $notif_id_invite, ':user_id' => $id]);
                   $invite_data_row = $stmt_get_invite->fetch(PDO::FETCH_ASSOC);

                   if ($invite_data_row) {
                        $related_data = json_decode($invite_data_row['related_data'] ?? '{}', true);
                        $group_id = $related_data['group_id'] ?? null;

                        if ($invite_action === 'accept' && $group_id) {
                             // *** INÍCIO LÓGICA ACEITAR GRUPO ***
                             // a) Verifica se usuário já está em algum grupo
                             $sql_check_current_group = "SELECT grupo_id FROM grupo_membros WHERE user_id = :user_id LIMIT 1";
                             $stmt_check_current = $conn->prepare($sql_check_current_group);
                             $stmt_check_current->execute([':user_id' => $id]);
                             $current_group = $stmt_check_current->fetchColumn();

                             if ($current_group) {
                                 $_SESSION['feedback_geral'] = "<span class='error-message'>Você já pertence a um grupo. Saia do atual primeiro.</span>";
                             } else {
                                 // b) Adiciona como membro
                                 $sql_add_member = "INSERT INTO grupo_membros (grupo_id, user_id, cargo) VALUES (:grupo_id, :user_id, 'membro')";
                                 $stmt_add_member = $conn->prepare($sql_add_member);
                                 $added = $stmt_add_member->execute([':grupo_id' => $group_id, ':user_id' => $id]);

                                 if ($added) {
                                     $_SESSION['feedback_geral'] = "<span class='success-message'>Bem-vindo à equipe! Convite aceito.</span>";
                                     // c) Opcional: Atualizar 'grupo_convites' para 'aceito'
                                     $sql_update_g_invite = "UPDATE grupo_convites SET status = 'aceito' WHERE convidado_user_id = :user_id AND grupo_id = :group_id AND status = 'pendente'";
                                     $stmt_update_g_invite = $conn->prepare($sql_update_g_invite);
                                     $stmt_update_g_invite->execute([':user_id' => $id, ':group_id' => $group_id]);
                                     // d) Opcional: Recusar outros convites pendentes
                                     $sql_reject_others = "UPDATE grupo_convites SET status = 'recusado' WHERE convidado_user_id = :user_id AND status = 'pendente' AND grupo_id != :accepted_group_id";
                                     $stmt_reject_others = $conn->prepare($sql_reject_others);
                                     $stmt_reject_others->execute([':user_id' => $id, ':accepted_group_id' => $group_id]);
                                 } else {
                                     $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao tentar entrar no grupo.</span>";
                                 }
                             }
                             // *** FIM LÓGICA ACEITAR GRUPO ***
                        } else { // Ação é 'reject'
                             $_SESSION['feedback_geral'] = "<span class='info-message'>Convite de grupo recusado.</span>";
                             // Opcional: Atualizar 'grupo_convites' para 'recusado'
                             if ($group_id) {
                                 $sql_update_g_invite = "UPDATE grupo_convites SET status = 'recusado' WHERE convidado_user_id = :user_id AND grupo_id = :group_id AND status = 'pendente'";
                                 $stmt_update_g_invite = $conn->prepare($sql_update_g_invite);
                                 $stmt_update_g_invite->execute([':user_id' => $id, ':group_id' => $group_id]);
                             }
                        }

                        // Marcar notificação como lida
                        $stmt_mark_invite = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
                        if (!$stmt_mark_invite->execute([':notif_id' => $notif_id_invite, ':user_id' => $id])) { error_log("Alerta: Falha marcar notif convite {$notif_id_invite} lida user {$id} (Urunai)."); }
                   } else {
                        $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de grupo inválido ou já processado.</span>";
                   }
              } catch (PDOException | Exception $e) {
                   error_log("Erro tratar invite_action {$invite_action} notif {$notif_id_invite} user {$id} (Urunai): " . $e->getMessage());
                   $_SESSION['feedback_geral'] = "<span class='error-message'>Erro processar convite de grupo.</span>";
                   // Tenta marcar como lida mesmo em erro
                   try { $stmt_mark_error = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid"); $stmt_mark_error->execute([':id'=>$notif_id_invite, ':uid'=>$id]); } catch(PDOException $em){}
              }
              $notification_action_processed = true;
         } // Fim if action accept/reject
     } // Fim elseif invite_action

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
                        $desafio_id = $related_data['desafio_id'] ?? null; // Assumindo que desafio_id está nos dados

                        if ($inviter_id && $desafio_id) {
                             if ($fusion_action === 'accept') {
                                 $sql_accept_fusao = "UPDATE convites_fusao SET status = 'aceito', data_atualizacao = NOW() WHERE convidante_id = :inviter_id AND convidado_id = :user_id AND desafio_id = :desafio_id AND status = 'pendente'";
                                 $stmt_accept_fusao = $conn->prepare($sql_accept_fusao);
                                 $fusao_accepted = $stmt_accept_fusao->execute([':inviter_id' => $inviter_id, ':user_id' => $id, ':desafio_id' => $desafio_id]);
                                 if ($fusao_accepted && $stmt_accept_fusao->rowCount() > 0) {
                                     $_SESSION['feedback_geral'] = "<span class='success-message'>Fusão aceita! Vá para Treinos.</span>";
                                 } elseif ($fusao_accepted) {
                                     $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de fusão não encontrado ou já respondido.</span>";
                                 } else {
                                     throw new PDOException("Falha ao executar a atualização para aceitar fusão.");
                                 }
                             } else { // 'reject'
                                 $sql_reject_fusao = "UPDATE convites_fusao SET status = 'recusado', data_atualizacao = NOW() WHERE convidante_id = :inviter_id AND convidado_id = :user_id AND desafio_id = :desafio_id AND status = 'pendente'";
                                 $stmt_reject_fusao = $conn->prepare($sql_reject_fusao);
                                 $fusao_rejected = $stmt_reject_fusao->execute([':inviter_id' => $inviter_id, ':user_id' => $id, ':desafio_id' => $desafio_id]);
                                  if (!$fusao_rejected) {
                                      throw new PDOException("Falha ao executar a atualização para recusar fusão.");
                                 }
                                 $_SESSION['feedback_geral'] = "<span class='info-message'>Convite de fusão recusado.</span>";
                             }
                             // Marcar notificação como lida
                             $stmt_mark_fus = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
                             if (!$stmt_mark_fus->execute([':notif_id' => $notif_id_fusion, ':user_id' => $id])) { error_log("Alerta: Falha marcar notif fusão {$notif_id_fusion} lida user {$id} (Urunai)."); }
                        } else { // Dados incompletos na notificação
                             $_SESSION['feedback_geral'] = "<span class='error-message'>Dados do convite de fusão estão incompletos na notificação.</span>";
                             // Marcar como lida para não aparecer mais
                             $stmt_mark_incomplete = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
                             $stmt_mark_incomplete->execute([':notif_id' => $notif_id_fusion, ':user_id' => $id]);
                        }
                   } else { // Notificação não encontrada para o usuário
                        $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de fusão inválido ou já processado (notificação não encontrada).</span>";
                   }
              } catch (PDOException | Exception $e) {
                   error_log("Erro processar fusion_action={$fusion_action} notif={$notif_id_fusion} user={$id} (Urunai): " . $e->getMessage());
                   $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao processar o convite de fusão.</span>";
                   // Tenta marcar como lida mesmo em erro
                   try { $stmt_mark_error = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id"); $stmt_mark_error->execute([':notif_id' => $notif_id_fusion, ':user_id' => $id]); } catch (PDOException $e_mark) {}
              }
              $notification_action_processed = true;
         } // Fim if action accept/reject
     } // Fim elseif fusion_action

     // Lidar com Ação de Entrada na Arena
     elseif (isset($_GET['arena_action']) && $_GET['arena_action'] === 'enter' &&
             isset($_GET['notif_id']) && filter_var($_GET['notif_id'], FILTER_VALIDATE_INT) &&
             isset($_GET['event_id']) && filter_var($_GET['event_id'], FILTER_VALIDATE_INT))
     {
         $notif_id_arena = (int)$_GET['notif_id'];
         $event_id_arena = (int)$_GET['event_id'];

         try {
             // 1. Marcar a notificação como lida
             $stmt_mark_arena = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
             $marked = $stmt_mark_arena->execute([':notif_id' => $notif_id_arena, ':user_id' => $id]);

             if ($marked) {
                 // 2. Redirecionar para a página da arena (AJUSTE O CAMINHO SE NECESSÁRIO)
                 $arena_url = "script/arena/arenaroyalle.php?event_id=" . $event_id_arena; // <<<<< VERIFIQUE PATH DA ARENA
                 // Se urunai.php está em outra pasta, ajuste o caminho relativo ou use absoluto /script/arena/...
                 header("Location: " . $arena_url);
                 exit(); // Importante sair após o header
             } else {
                  $_SESSION['feedback_geral'] = "<span class='warning-message'>Não foi possível processar a notificação da arena. Talvez já tenha sido lida.</span>";
                  error_log("Falha ao marcar notificação arena_start {$notif_id_arena} como lida para user {$id} (Urunai).");
             }

         } catch (PDOException $e) {
             error_log("Erro PDO ao processar ação arena_start (notif {$notif_id_arena}, event {$event_id_arena}) user {$id} (Urunai): " . $e->getMessage());
             $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao tentar entrar na arena pela notificação.</span>";
              // Tenta marcar como lida mesmo em erro
              try { $stmt_mark_error = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid"); $stmt_mark_error->execute([':id'=>$notif_id_arena, ':uid'=>$id]); } catch(PDOException $em){}
         }
         $notification_action_processed = true;
     }
     // FIM: Lidar com Ação de Entrada na Arena

     // Redireciona APENAS se uma ação de notificação foi processada
     if ($notification_action_processed) {
         header("Location: urunai.php"); // Volta para urunai.php para limpar GET params e/ou mostrar próximo popup
         exit();
     }
}
// --- ===>>> FIM: Processar Ações GET <<<=== ---


// ---> Bloco para processar o pedido de Reset (via POST) <---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reset_attributes'])) {
    // Garante conexão antes de operar (recheck, might be redundant but safe)
    if (!isset($conn) || !$conn instanceof PDO) {
        if (isset($pdo) && $pdo instanceof PDO) { $conn = $pdo; }
        else {
            try {
                require('conexao.php'); // <<<<< VERIFIQUE PATH >>>>>
                if (!isset($conn) || !$conn instanceof PDO) {
                    throw new Exception("Falha ao restabelecer conexão PDO no POST (Urunai).");
                }
            } catch (Exception $e) {
                error_log("Erro crítico conexão PDO (Urunai POST): " . $e->getMessage());
                $_SESSION['feedback_geral'] = "Erro crítico de banco de dados ao tentar resetar. Avise um Admin.";
                header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
                exit();
            }
        }
    }

    try {
        if (!$conn->beginTransaction()) { throw new PDOException("Não foi possível iniciar a transação."); }

        $sql_check_user = "SELECT hp, ki, forca, defesa, velocidade, pontos, xp, raca FROM usuarios WHERE id = :id FOR UPDATE";
        $stmt_check = $conn->prepare($sql_check_user);
        $stmt_check->execute([':id' => $id]);
        $user_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$user_data) { throw new PDOException("Usuário não encontrado durante o processo de reset."); }

        $current_xp = (int)($user_data['xp'] ?? 0);

        if ($current_xp < $reset_cost) { throw new PDOException("XP insuficiente. Você precisa de " . number_format($reset_cost, 0, ',', '.') . " XP."); }

        // Calcular Pontos a Reembolsar (!!! AJUSTAR BASE STATS !!!)
        $base_hp = 0; $base_ki = 0; $base_forca = 0; $base_defesa = 0; $base_velocidade = 0;
        // ADICIONE AQUI A LÓGICA PARA BUSCAR OS STATS BASE DA RAÇA $user_data['raca'] SE ELES NÃO FOREM ZERO

        $points_to_refund = ((int)$user_data['hp'] - $base_hp)
                           + ((int)$user_data['ki'] - $base_ki)
                           + ((int)$user_data['forca'] - $base_forca)
                           + ((int)$user_data['defesa'] - $base_defesa)
                           + ((int)$user_data['velocidade'] - $base_velocidade);
        $points_to_refund = max(0, $points_to_refund);

        if ($points_to_refund <= 0) { throw new PDOException("Você não possui pontos distribuídos acima do valor base para resetar."); }

        $sql_update = "UPDATE usuarios
                           SET hp = :base_hp, ki = :base_ki, forca = :base_forca,
                               defesa = :base_defesa, velocidade = :base_velocidade,
                               pontos = pontos + :points_refund, xp = xp - :cost
                         WHERE id = :id";
        $stmt_update = $conn->prepare($sql_update);
        $update_params = [
            ':base_hp' => $base_hp, ':base_ki' => $base_ki, ':base_forca' => $base_forca,
            ':base_defesa' => $base_defesa, ':base_velocidade' => $base_velocidade,
            ':points_refund' => $points_to_refund, ':cost' => $reset_cost, ':id' => $id
        ];

        if (!$stmt_update->execute($update_params)) { throw new PDOException("Falha ao executar o reset de atributos."); }
        if ($stmt_update->rowCount() == 0) { throw new PDOException("Nenhuma linha afetada pelo reset (ID: $id). Verifique."); }

        if (!$conn->commit()) { throw new PDOException("Falha ao confirmar as alterações (commit)."); }

        $reset_message = "Atributos resetados com sucesso! Você recuperou " . number_format($points_to_refund, 0, ',', '.') . " pontos e gastou " . number_format($reset_cost, 0, ',', '.') . " XP.";
        $reset_message_type = 'success';

    } catch (PDOException | Exception $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        error_log("Erro ao resetar atributos user {$id} (Urunai): " . $e->getMessage());
        $reset_message = "Erro ao resetar: " . $e->getMessage();
        $reset_message_type = 'error';
    }
    // Armazena a mensagem na sessão para exibição após o redirect
    if (!empty($reset_message)) {
        $_SESSION['feedback_reset'] = $reset_message;
        $_SESSION['feedback_reset_type'] = $reset_message_type;
    }
    header("Location: " . htmlspecialchars($_SERVER['PHP_SELF'])); // Redireciona para urunai.php
    exit();
}
// --- FIM DO BLOCO DE PROCESSAMENTO POST RESET ---


// --- Coleta de Feedback (Sessão + Reset) ---
if (isset($_SESSION['feedback_reset'])) {
    $feedback_class = ($_SESSION['feedback_reset_type'] === 'success') ? 'success-message' : 'error-message';
    $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . "<span class='{$feedback_class}'>" . htmlspecialchars($_SESSION['feedback_reset']) . "</span>";
    unset($_SESSION['feedback_reset']);
    unset($_SESSION['feedback_reset_type']);
}
if (isset($_SESSION['feedback_wali'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_wali']; unset($_SESSION['feedback_wali']); }
if (isset($_SESSION['feedback_geral'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_geral']; unset($_SESSION['feedback_geral']); }
if (isset($_SESSION['feedback_cooldown'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_cooldown']; unset($_SESSION['feedback_cooldown']); }


// Função Level Up (Garantir que está definida)
if (!function_exists('verificarLevelUpHome')) {
    function verificarLevelUpHome(PDO $pdo_conn, int $id_usuario): array {
         // Copiado de kame.php - Mantenha a lógica original ou a sua adaptada
         $m = []; $t = 0; $max_t = 50;
         while ($t < $max_t) { $t++; try { $s = $pdo_conn->prepare("SELECT nivel, xp, pontos FROM usuarios WHERE id = :id"); $s->execute([':id' => $id_usuario]); $d = $s->fetch(PDO::FETCH_ASSOC); if (!$d) { break; } $n = (int)$d['nivel']; $x = (int)$d['xp']; $p = (int)($d['pontos']??0); $xn = max(XP_BASE_LEVEL_UP, (int)ceil(XP_BASE_LEVEL_UP * pow($n, XP_EXPOENTE_LEVEL_UP))); if ($x >= $xn) { $nn = $n + 1; $nx = $x - $xn; $np = $p + PONTOS_GANHO_POR_NIVEL; $su = $pdo_conn->prepare("UPDATE usuarios SET nivel = :n, xp = :x, pontos = :p WHERE id = :id"); $prm = [':n'=>$nn, ':x'=>$nx, ':p'=>$np, ':id'=>$id_usuario]; if ($su->execute($prm)) { $m[] = "<span class='levelup-message'>⭐ LEVEL UP! ⭐ Nível <strong>{$nn}</strong>! +<strong>".PONTOS_GANHO_POR_NIVEL."</strong> Pontos!</span>"; continue; } else { error_log("Falha level up update user $id_usuario (Urunai). Err: ".print_r($su->errorInfo(), true)); break; } } else { break; } } catch (PDOException $e) { error_log("Falha level up check user $id_usuario (Urunai): ".$e->getMessage()); break; } }
         return $m;
    }
}


// --- Arrays de Navegação (Definidos ANTES para poder usar depois) ---
$sidebarItems = [
    ['id' => 'praca',       'label' => 'Praça Central',         'href' => 'home.php',               'icon' => 'img/praca.jpg', 'fa_icon' => 'fas fa-users'],
    ['id' => 'guia',        'label' => 'Guia do Guerreiro Z',   'href' => 'guia_guerreiro.php',     'icon' => 'img/guiaguerreiro.jpg', 'fa_icon' => 'fas fa-book-open'],
    ['id' => 'equipe',      'label' => 'Equipe Z',              'href' => 'script/grupos.php',      'icon' => 'img/grupos.jpg', 'fa_icon' => 'fas fa-users-cog'],
    ['id' => 'banco',       'label' => 'Banco Galáctico',       'href' => 'script/banco.php',       'icon' => 'img/banco.jpg', 'fa_icon' => 'fas fa-landmark'],
    ['id' => 'urunai',      'label' => 'Palácio da Vovó Uranai','href' => 'urunai.php',             'icon' => 'img/urunai.jpg', 'fa_icon' => 'fas fa-hat-wizard'], // Página Atual
    ['id' => 'kame',        'label' => 'Casa do Mestre Kame',   'href' => 'kame.php',               'icon' => 'img/kame.jpg', 'fa_icon' => 'fas fa-home'],
    ['id' => 'sala_tempo',  'label' => 'Sala do Tempo',         'href' => 'templo.php',             'icon' => 'img/templo.jpg', 'fa_icon' => 'fas fa-hourglass-half'],
    ['id' => 'treinos',     'label' => 'Zona de Treinamento',   'href' => 'desafios/treinos.php',   'icon' => 'img/treinos.jpg', 'fa_icon' => 'fas fa-dumbbell'],
    ['id' => 'desafios',    'label' => 'Desafios Z',            'href' => 'desafios.php',           'icon' => 'img/desafios.jpg', 'fa_icon' => 'fas fa-scroll'],
    ['id' => 'esferas',     'label' => 'Buscar Esferas',        'href' => 'esferas.php',            'icon' => 'img/esferar.jpg', 'fa_icon' => 'fas fa-dragon'],
    ['id' => 'erros',       'label' => 'Relatar Erros',         'href' => 'erros.php',              'icon' => 'img/erro.png', 'fa_icon' => 'fas fa-bug'],
    ['id' => 'perfil',      'label' => 'Perfil de Guerreiro',   'href' => 'perfil.php',             'icon' => 'img/perfil.jpg', 'fa_icon' => 'fas fa-user-edit']
];
// <<<<< VERIFIQUE TODOS OS PATHS DOS ÍCONES E HREF ACIMA >>>>>

// --- Início da lógica de busca de dados para exibição ---
try {
    // Garante conexão (recheck)
    if (!isset($conn) || !$conn instanceof PDO) {
        if (isset($pdo) && $pdo instanceof PDO) { $conn = $pdo;}
        else { require_once('conexao.php'); if (!isset($conn) || !$conn instanceof PDO) { throw new Exception("Conexão PDO não disponível vinda de conexao.php (Urunai Main Fetch)."); } }
    }

    $sql_usuario = "SELECT nome, nivel, hp, pontos, xp, ki, forca, id, defesa, velocidade, foto, tempo_sala, raca, zeni, ranking_titulo FROM usuarios WHERE id = :id_usuario";
    $stmt_usuario = $conn->prepare($sql_usuario);
    $stmt_usuario->execute([':id_usuario' => $id]);
    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        session_destroy();
        header("Location: login.php?erro=UsuarioNaoEncontradoFatal"); // <<<<< VERIFIQUE PATH >>>>>
        exit();
    }

    // Atualiza last_active
    try {
        $stmt_update_active = $conn->prepare("UPDATE usuarios SET last_active = NOW() WHERE id = :id");
        $stmt_update_active->execute([':id' => $id]);
    } catch (PDOException $e_active) {
        error_log("Erro ao atualizar last_active para user {$id} (Urunai): " . $e_active->getMessage());
    }

    // Verifica Level Up
    $mensagens_level_up_detectado = verificarLevelUpHome($conn, $id);
    if (!empty($mensagens_level_up_detectado)) {
        $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . implode("<br>", $mensagens_level_up_detectado);
        // Recarrega os dados do usuário
        $stmt_usuario->execute([':id_usuario' => $id]);
        $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) { throw new PDOException("Falha recarregar dados pós-LUP user {$id} (Urunai)."); }
        error_log("User {$id} dados recarregados pós LUP (Urunai).");
    }

    // Busca Ranks
    try {
        $sql_rank_nivel = "SELECT rank_nivel FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC) as rank_nivel FROM usuarios) AS ranked_by_level WHERE id = :user_id";
        $stmt_rank_nivel = $conn->prepare($sql_rank_nivel);
        $stmt_rank_nivel->execute([':user_id' => $id]);
        $result_nivel = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC);
        if ($result_nivel) { $rank_nivel = $result_nivel['rank_nivel']; }

        $sql_rank_sala = "SELECT rank_sala FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala FROM usuarios) AS ranked_by_time WHERE id = :user_id";
        $stmt_rank_sala = $conn->prepare($sql_rank_sala);
        $stmt_rank_sala->execute([':user_id' => $id]);
        $result_sala = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC);
        if ($result_sala) { $rank_sala = $result_sala['rank_sala']; }
    } catch (PDOException $e_rank) {
        error_log("PDOException ranks user {$id} (Urunai): " . $e_rank->getMessage());
    }

    // Busca Jogadores Online
    try {
        $sql_online_count = "SELECT COUNT(id) as online_count FROM usuarios WHERE last_active >= NOW() - INTERVAL :interval MINUTE";
        $stmt_online_count = $conn->prepare($sql_online_count);
        $stmt_online_count->bindValue(':interval', $online_interval_minutes, PDO::PARAM_INT);
        $stmt_online_count->execute();
        $result_count = $stmt_online_count->fetch(PDO::FETCH_ASSOC);
        if ($result_count) { $online_users_count = (int)$result_count['online_count']; }

        if ($online_users_count > 0) {
            $sql_online_list = "SELECT id FROM usuarios
                                WHERE last_active >= NOW() - INTERVAL :interval MINUTE
                                AND id != :current_user_id
                                ORDER BY nome ASC LIMIT 20";
            $stmt_online_list = $conn->prepare($sql_online_list);
            $stmt_online_list->bindValue(':interval', $online_interval_minutes, PDO::PARAM_INT);
            $stmt_online_list->bindValue(':current_user_id', $id_usuario_logado, PDO::PARAM_INT);
            $stmt_online_list->execute();
            $online_user_ids_raw = $stmt_online_list->fetchAll(PDO::FETCH_COLUMN, 0);
            $online_user_list = array_map('intval', $online_user_ids_raw);
        }
    } catch (PDOException $e_online) {
        error_log("PDOException buscando jogadores online user {$id} (Urunai): " . $e_online->getMessage());
        $online_users_count = 0; $online_user_list = [];
    }

    // ===>>> Busca NOTIFICAÇÕES NÃO LIDAS para o Popup <<<===
     try {
         $sql_unread_notif = "SELECT id, message_text, type, timestamp, related_data FROM user_notifications WHERE user_id = :user_id AND is_read = 0 ORDER BY timestamp ASC";
         $stmt_unread_notif = $conn->prepare($sql_unread_notif);
         if ($stmt_unread_notif && $stmt_unread_notif->execute([':user_id' => $id])) {
             $unread_notifications_for_popup = $stmt_unread_notif->fetchAll(PDO::FETCH_ASSOC);
         } else {
             error_log("Erro PDO fetch unread notifs user {$id} (Urunai): " . print_r($conn->errorInfo() ?: ($stmt_unread_notif ? $stmt_unread_notif->errorInfo() : 'Prepare Failed'), true));
         }
     } catch (PDOException $e_notif) {
         error_log("Erro PDO buscar notifs não lidas user {$id} (Urunai): " . $e_notif->getMessage());
     }


    // Busca Histórico do Chat
    try {
        $stmt_hist = $conn->prepare("SELECT id, user_id, username, message_text, timestamp FROM ( SELECT id, user_id, username, message_text, timestamp FROM chat_messages ORDER BY id DESC LIMIT :limit_chat ) sub ORDER BY id ASC");
        $stmt_hist->bindValue(':limit_chat', $chat_history_limit, PDO::PARAM_INT);
        $stmt_hist->execute();
        $chat_history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e_chat) {
        error_log("PDOException Chat Hist user {$id} (Urunai): ".$e_chat->getMessage());
        $chat_history=[];
    }

} catch (PDOException $e) {
    error_log("Erro PDO GERAL (Setup) " . basename(__FILE__) . " user {$id}: " . $e->getMessage());
    $mensagem_final_feedback .= "<br><span class='error-message'>Erro crítico DB (Setup). Avise um Admin.</span>";
    if ($usuario === null) { echo "Erro crítico de banco de dados ao carregar a página."; exit(); }
} catch (Exception $ex) {
    error_log("Erro GERAL (Setup) " . basename(__FILE__) . " user {$id}: " . $ex->getMessage());
    $mensagem_final_feedback .= "<br><span class='error-message'>Erro crítico APP (Setup). Avise um Admin.</span>";
    if ($usuario === null) { echo "Erro crítico inesperado ao carregar a página."; exit(); }
}

// --- Cálculos e Formatações Finais ---
$nome_formatado_usuario = formatarNomeUsuarioComTag($conn, $usuario);

// Cálculo XP Bar
$xp_necessario_calc = 0; $xp_percent = 0; $xp_necessario_display = 'N/A';
if (isset($usuario['nivel'])) {
    $nivel_atual_num = filter_var($usuario['nivel'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($nivel_atual_num === false) $nivel_atual_num = 1;
    $xp_necessario_calc = (int)ceil(XP_BASE_LEVEL_UP * pow($nivel_atual_num, XP_EXPOENTE_LEVEL_UP));
    $xp_necessario_calc = max(XP_BASE_LEVEL_UP, $xp_necessario_calc);
    $xp_necessario_display = number_format($xp_necessario_calc);
    if ($xp_necessario_calc > 0 && isset($usuario['xp'])) {
        $xp_atual_num = filter_var($usuario['xp'] ?? 0, FILTER_VALIDATE_INT);
        $xp_percent = ($xp_atual_num !== false) ? min(100, max(0, ($xp_atual_num / $xp_necessario_calc) * 100)) : 0;
    }
}

// Foto do PERFIL
$nome_arquivo_foto = 'default.jpg';
if (!empty($usuario['foto'])) {
    $caminho_fisico_foto = __DIR__ . '/uploads/' . $usuario['foto']; // <<<<< VERIFIQUE PATH BASE UPLOADS
    if (file_exists($caminho_fisico_foto)) {
        $nome_arquivo_foto = $usuario['foto'];
    } else {
         error_log("Foto não encontrada: $caminho_fisico_foto (User: {$id}, Page: urunai.php)");
    }
}
$caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto); // <<<<< VERIFIQUE PATH WEB UPLOADS

// Lógica Templo
$hp_atual_base = (int)($usuario['hp'] ?? 0);
$ki_atual_base = (int)($usuario['ki'] ?? 0);
$pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0);
$mensagem_bloqueio_templo_base = 'Recupere HP/Ki base!';


// --- Array de Navegação Inferior (Bottom Nav) ---
$bottomNavItems = [
    ['label' => 'Home', 'href' => 'home.php', 'icon' => '🏠', 'id' => 'home'],
    ['label' => 'Praça', 'href' => 'praca.php', 'icon' => '👥', 'id' => 'praca'],
    ['label' => 'Templo', 'href' => 'templo.php', 'icon' => '🏛️', 'id' => 'sala_tempo'],
    ['label' => 'Kame', 'href' => 'kame.php', 'icon' => '🐢', 'id' => 'kame'],
    ['label' => 'Perfil', 'href' => 'perfil.php', 'icon' => '👤', 'id' => 'perfil'],
    ['label' => 'Arena', 'href' => 'script/arena/arenaroyalle.php', 'icon' => '⚔️', 'id' => 'arena'], // <<<<< VERIFIQUE PATH
    ['label' => 'Treinos','href' => 'desafios/treinos.php', 'icon' => '💪', 'id' => 'treinos'], // <<<<< VERIFIQUE PATH
    ['label' => 'Urunai','href' => 'urunai.php', 'icon' => '🔮', 'id' => 'urunai'] // Adicionado Urunai
];

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Urunai - <?= htmlspecialchars($usuario['nome'] ?? 'Jogador') ?></title> <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* === CSS Completo (Layout Urunai/Goku House Original + Modal Popup Kame) === */
        :root {
            --bg-image-url: url('img/urunai.jpg'); /* <<<<< VERIFIQUE PATH */
            --panel-bg: rgba(25, 28, 36, 0.9); --panel-border: rgba(255, 255, 255, 0.15);
            --text-primary: #e8eaed; --text-secondary: #bdc1c6;
            --accent-color-1: #00e676; /* Verde */
            --accent-color-2: #ffab00; /* Laranja/Amarelo */
            --accent-color-3: #2979ff; /* Azul */
            --danger-color: #ff5252; --disabled-color: #5f6368; --success-color: #00e676;
            --info-color: #29b6f6; --warning-color: #ffa726; /* Adicionados para consistência modal */
            --border-radius: 10px; --shadow: 0 8px 25px rgba(0, 0, 0, 0.5);
            --font-main: 'Roboto', sans-serif;
             --dbz-blue: #1a2a4d; --dbz-orange: #f5a623; --dbz-orange-dark: #e47d1e; /* Adicionados para compatibilidade modal kame */
             --dbz-white: #ffffff; --dbz-light-grey: #f0f0f0; --dbz-dark-grey: #333333; /* Adicionados para compatibilidade modal kame */
             --body-original-padding-bottom: 20px; /* Para lógica do bottom nav */
        }
        /* Keyframes */
        @keyframes pulse-glow-button { 0%{box-shadow: 0 0 8px var(--accent-color-2), inset 0 1px 1px rgba(255,255,255,0.2);transform:scale(1)} 70%{box-shadow: 0 0 16px 4px var(--accent-color-2), inset 0 1px 1px rgba(255,255,255,0.3);transform:scale(1.03)} 100%{box-shadow: 0 0 8px var(--accent-color-2), inset 0 1px 1px rgba(255,255,255,0.2);transform:scale(1)} }
        @keyframes shine { 0%{left:-100%} 50%,100%{left:150%} }
        @keyframes ki-charge { 0%{background-size:100% 100%;opacity:.6} 50%{background-size:150% 150%;opacity:1} 100%{background-size:100% 100%;opacity:.6} }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { background-image: var(--bg-image-url); background-size: cover; background-position: center; background-attachment: fixed; color: var(--text-primary); font-family: var(--font-main); margin: 0; padding: 20px; min-height: 100vh; background-color: #0b0c10; overflow-x: hidden; }
        .container { width: 100%; max-width: 1500px; display: flex; justify-content: center; gap: 20px; align-items: flex-start; margin-left: auto; margin-right: auto; }
        .coluna { background-color: var(--panel-bg); border-radius: var(--border-radius); padding: 20px; box-shadow: var(--shadow); border: 1px solid var(--panel-border); backdrop-filter: blur(6px); }

        /* Feedback */
        .feedback-container { width: 100%; max-width: 1200px; /* Aumentado */ margin: 0 auto 20px auto; padding: 15px 25px; background-color: rgba(30, 34, 44, 0.95); border-radius: var(--border-radius); box-shadow: var(--shadow); text-align: center; font-weight: 500; font-size: 1rem; line-height: 1.6; border: 1px solid var(--panel-border); border-left-width: 5px; display: <?php echo !empty($mensagem_final_feedback) ? 'block' : 'none'; ?>; border-left-color: var(--accent-color-3); color: var(--text-primary); }
        .feedback-container:has(.levelup-message) { border-left-color: var(--accent-color-2); }
        .feedback-container .levelup-message, .feedback-container:has(.levelup-message) strong { color: var(--accent-color-2); font-weight: bold; }
        .feedback-container:has(.error-message), .feedback-container:has(.error) { border-left-color: var(--danger-color); background-color: rgba(50, 30, 30, 0.95); }
        .feedback-container .error-message, .feedback-container .error { color: var(--danger-color) !important; font-weight: bold; }
        .feedback-container:has(.success-message) { border-left-color: var(--success-color); background-color: rgba(30, 50, 30, 0.95); }
        .feedback-container .success-message { color: var(--success-color) !important; font-weight: bold; }
        .feedback-container:has(.info-message) { border-left-color: var(--info-color); background-color: rgba(20, 40, 60, 0.95); }
        .feedback-container .info-message { color: var(--info-color) !important; font-weight: bold; }
        .feedback-container:has(.warning-message) { border-left-color: var(--warning-color); background-color: rgba(60, 50, 20, 0.95); }
        .feedback-container .warning-message { color: var(--warning-color) !important; font-weight: bold; }


        /* Coluna Esquerda (SEM Notificações) */
        .coluna-esquerda { width: 25%; min-width: 270px; text-align: center; display: flex; flex-direction: column; gap: 12px; }
        .player-card { margin-bottom: 5px; } .foto { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid var(--accent-color-1); margin: 0 auto 10px auto; box-shadow: 0 0 15px rgba(0, 230, 118, 0.5); transition: transform 0.3s ease; display: block; } .foto:hover { transform: scale(1.05); } .player-name { font-size: 1.3rem; font-weight: 700; color: #fff; margin-bottom: 2px; word-wrap: break-word; } .player-level { font-size: 1.0rem; font-weight: 500; color: var(--accent-color-1); margin-bottom: 3px; } .player-race { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); margin-bottom: 3px; } .player-rank { font-size: 0.8rem; font-weight: 500; color: var(--accent-color-3); margin-bottom: 10px; } .zeni-display { font-size: 1rem; color: var(--accent-color-2); font-weight: 700; margin-bottom: 6px; }
        .xp-bar-container { width: 100%; margin-bottom: 10px; } .xp-label { font-size: 0.7rem; color: var(--text-secondary); text-align: left; margin-bottom: 3px; display: block; } .xp-bar { height: 11px; background-color: rgba(0, 0, 0, 0.3); border-radius: 6px; overflow: hidden; position: relative; border: 1px solid rgba(255,255,255,0.1); } .xp-bar-fill { height: 100%; background: linear-gradient(90deg, var(--accent-color-3), var(--accent-color-1)); border-radius: 6px; transition: width 0.5s ease-in-out; animation: ki-charge 1.5s linear infinite; } .xp-text { position: absolute; top: -1px; left: 0; right: 0; font-size: 0.65rem; font-weight: 500; color: #fff; text-shadow: 1px 1px 2px rgba(0,0,0,0.7); line-height: 13px; text-align: center; }
        .btn-pontos { display: inline-block; padding: 8px 15px; background: linear-gradient(45deg, var(--accent-color-2), #ffc107); color: #111; text-decoration: none; font-weight: 700; font-size: 0.9rem; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3); margin-bottom: 8px; border: none; text-align: center; transition: all 0.2s ease; cursor: pointer; animation: pulse-glow-button 2.5s infinite alternate ease-in-out; } .btn-pontos:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0, 0, 0, 0.4); filter: brightness(1.1); } .btn-pontos[style*="cursor: default"] { background: var(--disabled-color); color: var(--text-secondary); filter: none; box-shadow: none; animation: none; opacity: 0.7; }
        .stats-grid { display: grid; grid-template-columns: auto 1fr; gap: 5px 10px; text-align: left; font-size: 0.8rem; width: 100%; } .stats-grid strong { color: var(--text-secondary); font-weight: 500; font-size: 0.75rem;} .stats-grid span { color: var(--text-primary); font-weight: 500; text-align: right; font-size: 0.8rem;} .stats-grid span[style*="color: var(--accent-color-2)"] { color: var(--accent-color-2); } .stats-grid span[style*="color: var(--accent-color-1)"] { color: var(--accent-color-1); }
        .divider { height: 1px; background-color: var(--panel-border); border: none; margin: 10px 0; }
        /* Online Users CSS */
        .online-users-details { margin-top: 10px; font-size: 0.8rem; text-align: left; }
        .online-users-details summary { cursor: pointer; color: var(--text-secondary); font-weight: 500; padding: 4px 0; outline: none; list-style: none; display: block; }
        .online-users-details summary::-webkit-details-marker { display: none; }
        .online-users-details summary::before { content: '▶ '; font-size: 0.8em; margin-right: 4px; display: inline-block; transition: transform 0.2s ease-in-out; }
        .online-users-details[open] summary::before { transform: rotate(90deg); }
        .online-users-details summary strong { color: var(--accent-color-1); font-weight: bold; }
        .online-users-details[open] summary { margin-bottom: 5px; }
        .online-users-list { font-size: 0.75rem; color: var(--text-primary); padding: 8px 0 8px 15px; max-height: 120px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--panel-border) rgba(0,0,0,0.2); border-top: 1px dashed rgba(255, 255, 255, 0.1); margin-top: 5px; line-height: 1.5; word-wrap: break-word; }
        .online-users-list::-webkit-scrollbar { width: 4px; }
        .online-users-list::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); border-radius: 2px; }
        .online-users-list::-webkit-scrollbar-thumb { background-color: var(--panel-border); border-radius: 2px; }
        /* Botões Ação Inferiores Esquerda */
        .coluna-esquerda > div:last-child { margin-top: auto; padding-top: 15px; display: flex; flex-direction: column; gap: 10px; border-top: 1px solid var(--panel-border); flex-shrink: 0; }
        .action-button { display: block; width: 100%; padding: 10px 15px; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 500; text-decoration: none; text-align: center; cursor: pointer; transition: all 0.2s ease; }
        .action-button:hover { transform: translateY(-1px) scale(1.01); filter: brightness(1.1);}
        .action-button.btn-perfil { background-color: var(--accent-color-1); color: #111; }
        .action-button.btn-logout { background-color: var(--danger-color); color: #fff; }


        /* --- COLUNA CENTRAL --- */
        .coluna-central { width: 48%; display: flex; flex-direction: column; text-align: left; gap: 15px; max-height: calc(100vh - 60px); overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--panel-border) transparent; }
        .coluna-central::-webkit-scrollbar { width: 6px; } .coluna-central::-webkit-scrollbar-track { background: transparent; } .coluna-central::-webkit-scrollbar-thumb { background-color: var(--panel-border); border-radius: 3px; } .coluna-central::-webkit-scrollbar-thumb:hover { background-color: var(--text-secondary); }
        /* Títulos e Texto */
        .coluna-central h1 { font-family: var(--font-main); font-weight: 700; color: var(--accent-color-2); text-align: center; font-size: 2.1rem; margin: 0 0 20px 0; padding-bottom: 10px; border-bottom: 3px solid var(--accent-color-2); text-shadow: 1px 1px 3px rgba(0,0,0,0.5); letter-spacing: 1px; text-transform: uppercase; flex-shrink: 0; }
        .coluna-central h2 { color: #fff; font-weight: 700; font-size: 1.5rem; margin-bottom: 15px; border-bottom: 2px solid var(--accent-color-1); padding-bottom: 8px; display: block; text-align: left; flex-shrink: 0; }
        .coluna-central h3 { color: var(--accent-color-3); font-weight: 500; font-size: 1.1rem; margin-top: 10px; margin-bottom: 10px; width: 100%; text-align: left; padding-bottom: 6px; border-bottom: 1px solid var(--panel-border); flex-shrink: 0;}
        .coluna-central p { color: var(--text-primary); line-height: 1.6; font-size: 0.9rem; margin-bottom: 12px;} .coluna-central strong { color: var(--accent-color-1); } .coluna-central ul { list-style: disc; padding-left: 25px; margin-bottom: 15px; } .coluna-central li { margin-bottom: 8px; font-size: 0.9rem; }
        /* --- ESTILOS URUNAI --- */
         .coluna-central .urunai-description { font-size: 0.95rem; line-height: 1.7; margin-bottom: 18px; color: var(--text-primary); max-width: 700px; margin-left: auto; margin-right: auto; text-align: center; }
         .coluna-central .urunai-description img { display: block; max-width: 120px; margin: 5px auto 15px auto; border-radius: 50%; border: 3px solid var(--panel-border); box-shadow: 0 3px 8px rgba(0,0,0,0.4); }
         .coluna-central .urunai-cost { font-size: 1.0em; margin-bottom: 10px; font-family: var(--font-main); font-weight: 500; text-align: center; color: var(--text-secondary); }
         .coluna-central .urunai-cost strong { color: var(--accent-color-2); font-weight: 700; }
         .coluna-central form { text-align: center; margin-top: 20px; margin-bottom: 20px; }
         .action-button.btn-reset { background-color: var(--danger-color); color: #fff; max-width: 250px; margin-left: auto; margin-right: auto;} /* Estilo reset */
         .action-button.btn-reset:hover { background-color: #e53935; filter: brightness(1.1); }
         .action-button.btn-reset:disabled { background: var(--disabled-color) !important; color: var(--text-secondary) !important; opacity: 0.6; cursor: not-allowed; filter: grayscale(60%); box-shadow: none; transform: none; animation: none; }
         .xp-insufficient-warning { color: var(--danger-color); font-weight: bold; margin-top: 10px; background-color: rgba(255, 82, 82, 0.1); padding: 8px 12px; border-radius: 4px; border: 1px solid var(--danger-color); display: inline-block; font-size: 0.85em; }
         .coluna-central a[href="praca.php"], .coluna-central a[href="home.php"] { display: inline-block; margin-top: 25px; color: var(--accent-color-3); text-decoration: none; font-weight: 500; font-size: 0.85em; text-align: center; padding: 5px 10px; border: 1px solid var(--accent-color-3); border-radius: 5px; transition: all 0.2s ease; }
         .coluna-central a[href="praca.php"]:hover, .coluna-central a[href="home.php"]:hover { background-color: rgba(41, 121, 255, 0.1); text-decoration: none; }
        /* --- FIM ESTILOS URUNAI --- */

        /* Chat Area */
        .chat-area { margin-top: auto; border-top: 1px solid var(--panel-border); padding-top: 15px; flex-shrink: 0; } .chat-area h3 { margin-bottom: 10px; text-align: center; border-bottom: none; } .chat-container-ajax { width: 100%; display: flex; flex-direction: column; } #chat-messages-ajax { flex-grow: 1; overflow-y: auto; padding: 10px; display: flex; flex-direction: column; gap: 8px; max-height: 250px; min-height: 120px; background-color: rgba(0,0,0,0.25); border-radius: 8px; margin-bottom: 10px; border: 1px solid var(--panel-border); scrollbar-width: thin; scrollbar-color: var(--panel-border) rgba(0,0,0,0.2); } #chat-messages-ajax::-webkit-scrollbar { width: 5px; } #chat-messages-ajax::-webkit-scrollbar-track { background: rgba(0,0,0,0.2); border-radius: 3px;} #chat-messages-ajax::-webkit-scrollbar-thumb { background-color: var(--panel-border); border-radius: 3px; } #chat-messages-ajax::-webkit-scrollbar-thumb:hover { background-color: var(--accent-color-3); } .chat-message-ajax { max-width: 80%; padding: 8px 14px; border-radius: 15px; line-height: 1.4; font-size: 0.85rem; word-wrap: break-word; } .chat-message-ajax.other { background-color: #4a5568; border-bottom-left-radius: 4px; align-self: flex-start; } .chat-message-ajax.mine { background-color: var(--accent-color-3); color: #fff; align-self: flex-end; border-bottom-right-radius: 4px; } .chat-message-ajax .sender { display: block; font-size: 0.75em; font-weight: 600; color: var(--accent-color-2); margin-bottom: 3px; opacity: 0.9; } .chat-message-ajax.mine .sender { color: rgba(255,255,255,0.8); } .chat-message-ajax .sender small { font-size: 0.9em; margin-left: 4px; color: var(--text-secondary); }
        #chat-form-ajax { display: flex; gap: 8px; } #chat-message-input { flex-grow: 1; padding: 10px 15px; border: 1px solid var(--panel-border); border-radius: 20px; background-color: rgba(0,0,0,0.3); color: var(--text-primary); font-size: 0.9rem; outline: none; transition: border-color 0.2s; } #chat-message-input:focus { border-color: var(--accent-color-3); } #chat-form-ajax button { padding: 10px 18px; border: none; background: var(--accent-color-3); color: #fff; border-radius: 20px; cursor: pointer; font-weight: 500; font-size: 0.9rem; transition: all 0.2s; } #chat-form-ajax button:hover { background-color: #1e88e5; filter: brightness(1.1); transform: scale(1.02); }
        .chat-empty { text-align: center; color: var(--text-secondary); padding: 20px; font-style: italic; font-size: 0.85rem; }


        /* --- COLUNA DIREITA --- */
        .coluna-direita { width: 25%; min-width: 250px; padding: 15px; display: flex; flex-direction: column; gap: 8px; }
        .nav-button { display: grid; grid-template-columns: 45px 1fr; align-items: center; gap: 12px; padding: 10px 12px 10px 8px; background: linear-gradient(to bottom, rgba(55, 62, 76, 0.85), rgba(40, 44, 52, 0.85)); color: var(--text-secondary); text-decoration: none; border-radius: 8px; border: 1px solid var(--panel-border); font-size: 0.85rem; font-weight: 500; transition: all 0.2s ease; position: relative; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.2); cursor: pointer; }
        .nav-button-icon-area { grid-column: 1 / 2; display: flex; justify-content: center; align-items: center; width: 100%; height: 28px; }
        .nav-button-icon { width: 28px; height: 28px; object-fit: contain; opacity: 0.7; transition: opacity 0.2s ease, transform 0.2s ease; display: block; margin: 0; flex-shrink: 0;}
        .nav-button i.fas { font-size: 22px; opacity: 0.7; transition: opacity 0.2s ease, transform 0.2s ease; color: inherit; } /* Para ícones FA */
        .nav-button span { grid-column: 2 / 3; text-align: left; }
        .nav-button:hover { background: linear-gradient(to bottom, rgba(65, 72, 86, 0.95), rgba(50, 54, 62, 0.95)); border-color: rgba(255, 255, 255, 0.3); color: #fff; transform: translateY(-2px) scale(1.01); box-shadow: 0 5px 10px rgba(0,0,0,0.3), 0 0 8px rgba(41, 121, 255, 0.4); }
        .nav-button:hover .nav-button-icon, .nav-button:hover i.fas { opacity: 1; transform: scale(1.05); }
        .nav-button:active { transform: translateY(0px) scale(1.0); box-shadow: 0 2px 4px rgba(0,0,0,0.2); filter: brightness(0.95); }
        .nav-button.active { background: linear-gradient(to bottom, var(--accent-color-1), #00c853); color: #000; border-color: rgba(0, 230, 118, 0.5); font-weight: 700; box-shadow: 0 3px 6px rgba(0,0,0,0.3), inset 0 1px 1px rgba(255,255,255,0.2); } .nav-button.active .nav-button-icon, .nav-button.active i.fas { opacity: 1; filter: brightness(0.8); } .nav-button.active:hover { filter: brightness(1.1); box-shadow: 0 5px 10px rgba(0,0,0,0.3), 0 0 8px var(--accent-color-1); }
        .nav-button.disabled { background: var(--disabled-color) !important; color: #8894a8 !important; cursor: not-allowed !important; border-color: rgba(0, 0, 0, 0.2) !important; opacity: 0.6 !important; box-shadow: none !important; } .nav-button.disabled:hover { background: var(--disabled-color) !important; transform: none !important; border-color: rgba(0, 0, 0, 0.2) !important; color: #8894a8 !important; box-shadow: none !important; } .nav-button.disabled .nav-button-icon, .nav-button.disabled i.fas { filter: grayscale(100%); opacity: 0.5; }
        .nav-button.logout:hover { background: linear-gradient(to bottom, var(--danger-color), #c0392b) !important; border-color: rgba(255, 255, 255, 0.2) !important; box-shadow: 0 5px 10px rgba(0,0,0,0.3), 0 0 8px var(--danger-color) !important; }
        .nav-button.logout span { grid-column: 2 / 3; } .nav-button.logout .nav-button-icon-area { grid-column: 1 / 2; }


        /* --- Bottom Nav --- */
        #bottom-nav { display: none; }
        @media (max-width: 768px) {
            #bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; right: 0; background-color: rgba(20, 23, 31, 0.97); border-top: 1px solid var(--panel-border); padding: 2px 0; box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.35); justify-content: space-around; align-items: stretch; z-index: 1000; backdrop-filter: blur(6px); height: 60px; }
            .bottom-nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; text-decoration: none; color: var(--text-secondary); padding: 4px 2px; font-size: 0.65rem; text-align: center; transition: background-color 0.2s ease, color 0.2s ease; cursor: pointer; border-radius: 5px; margin: 2px; line-height: 1.2; font-family: var(--font-main); }
            .bottom-nav-item:not(.active):not(.disabled):hover { background-color: rgba(255, 255, 255, 0.05); color: var(--text-primary); }
            .bottom-nav-item.active { color: var(--accent-color-1); font-weight: bold; background-color: rgba(0, 230, 118, 0.1); }
            .bottom-nav-icon { font-size: 1.5rem; line-height: 1; margin-bottom: 4px; } /* Ajustado para usar .bottom-nav-icon */
            .bottom-nav-label { font-size: inherit; } /* Ajustado para usar .bottom-nav-label */
            .bottom-nav-item.disabled { cursor: not-allowed; opacity: 0.5; color: #6a7383 !important; background-color: transparent !important; }
            .bottom-nav-item.disabled:hover { color: #6a7383 !important; }
        }
        @media (max-width: 480px) {
             #bottom-nav { height: 55px; }
             .bottom-nav-item .bottom-nav-icon { font-size: 1.3rem; }
             .bottom-nav-item .bottom-nav-label { font-size: 0.6rem; }
        }

        /* --- Media Queries --- */
        @media (max-width: 1200px) { body { padding: 15px; } .container { flex-direction: column; align-items: center; width: 100%; max-width: 95%; /* Aumentado */ padding: 0; gap: 15px; } .coluna { width: 100% !important; max-width: 800px; /* Aumentado */ margin-bottom: 15px; } .coluna-direita, .coluna-esquerda { min-width: unset; } .coluna-central { max-height: none; overflow-y: visible; } }
        @media (max-width: 768px) { body { padding: 10px; padding-bottom: 75px; /* Ajustado para bottom-nav */ } .feedback-container { font-size: 0.9rem; padding: 10px 15px; margin: 0 auto 10px auto;} .coluna-direita { display: none; } .coluna { padding: 15px; } .foto { width: 100px; height: 100px; } .player-name { font-size: 1.2rem; } .player-level { font-size: 0.95rem; } .stats-grid { font-size: 0.8rem; } .btn-pontos { font-size: 0.85rem; } .coluna-central h1 { font-size: 1.8rem; margin-bottom: 15px;} .coluna-central h2 { font-size: 1.3rem; } .coluna-central h3 { font-size: 1.0rem; } .coluna-central p { font-size: 0.85rem; } .action-button { font-size: 0.85rem; padding: 10px 12px;} #chat-messages-ajax { max-height: 200px;} .online-users-details { font-size: 0.75rem; } .online-users-list { font-size: 0.7rem; max-height: 80px;} }
        @media (max-width: 480px) { body { padding: 8px; padding-bottom: 70px; /* Ajustado para bottom-nav */ } .container { gap: 8px; } .coluna { padding: 12px; margin-bottom: 8px; } .foto { width: 80px; height: 80px; border-width: 3px;} .player-name { font-size: 1.0rem; } .player-level { font-size: 0.85rem; } .player-race, .player-rank { font-size: 0.75rem; } .zeni-display { font-size: 0.9rem; } .xp-bar-container { margin-bottom: 8px;} .xp-label { font-size: 0.7rem;} .xp-bar { height: 9px;} .xp-text { font-size: 0.6rem; line-height: 11px;} .stats-grid { font-size: 0.75rem; gap: 4px 8px; } .stats-grid strong { font-weight: 400; font-size: 0.7rem;} .stats-grid span { font-size: 0.75rem;} .btn-pontos { font-size: 0.8rem; padding: 7px 12px;} .action-button { font-size: 0.8rem; padding: 9px 10px;} .coluna-central h1 { font-size: 1.6rem;} .coluna-central h2 { font-size: 1.1rem; } .coluna-central h3 { font-size: 0.95rem; } .coluna-central p { font-size: 0.8rem; } .feedback-container { font-size: 0.85rem; padding: 8px 12px; margin: 0 auto 8px auto;} .online-users-details { font-size: 0.7rem; } .online-users-list { font-size: 0.65rem; max-height: 60px;} }

        /* --- ===>>> CSS do Modal Popup (Copiado do Kame) <<<=== --- */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); display: flex; justify-content: center; align-items: center; z-index: 1100; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0s linear 0.3s; }
        .modal-overlay.visible { opacity: 1; visibility: visible; transition: opacity 0.3s ease; }
        .modal-popup { background-color: var(--panel-bg, #1e2129); padding: 25px 30px; border-radius: var(--border-radius, 10px); border: 1px solid var(--panel-border, rgba(255, 255, 255, 0.15)); box-shadow: var(--shadow, 0 8px 25px rgba(0, 0, 0, 0.5)); width: 90%; max-width: 450px; text-align: center; position: relative; transform: scale(0.9); transition: transform 0.3s ease; }
        .modal-overlay.visible .modal-popup { transform: scale(1); }
        .modal-popup h3 { color: var(--dbz-orange, #f5a623); font-family: var(--font-main); margin-top: 0; margin-bottom: 15px; font-size: 1.4em; border-bottom: 1px solid var(--dbz-orange-dark, #e47d1e); padding-bottom: 10px; }
        .modal-popup #popup-message { color: var(--text-primary, #e8eaed); font-size: 1rem; line-height: 1.6; margin-bottom: 25px; min-height: 40px; word-wrap: break-word; }
        .modal-popup #popup-actions { display: flex; justify-content: center; gap: 15px; flex-wrap: wrap; }
        .modal-popup .popup-action-btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; text-align: center; cursor: pointer; transition: all 0.2s ease; min-width: 100px; }
        .popup-action-btn.btn-ok { background-color: var(--accent-color-3, #2979ff); color: #fff; }
        .popup-action-btn.btn-accept { background-color: var(--accent-color-1, #00e676); color: #111; }
        .popup-action-btn.btn-reject { background-color: var(--danger-color, #ff5252); color: #fff; }
        .popup-action-btn:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .modal-close-btn { position: absolute; top: 10px; right: 15px; background: none; border: none; font-size: 1.8em; color: var(--text-secondary, #bdc1c6); cursor: pointer; line-height: 1; padding: 0; opacity: 0.7; }
        .modal-close-btn:hover { color: var(--text-primary, #e8eaed); opacity: 1; }
        /* --- ===>>> FIM CSS Modal Popup <<<=== --- */

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

        <?php // --- COLUNA ESQUERDA (Layout Urunai/Goku House Original - SEM Notificações) --- ?>
        <div class="coluna coluna-esquerda">
              <div class="player-card">
                   <img class="foto" src="<?= $caminho_web_foto ?>" alt="Foto de <?= htmlspecialchars($usuario['nome'] ?? '') ?>">
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
                   <?php if ($currentPage !== 'perfil.php'): // <<<<< VERIFIQUE PATH 'perfil.php' ?>
                        <a href="perfil.php" class="btn-pontos"> <?= number_format($pontos_num) ?> Pontos! <span style="font-size: 0.8em;">(Distribuir)</span> </a>
                   <?php else: ?>
                        <div class="btn-pontos" style="cursor: default; animation: none; filter: none;">
                            <?= number_format($pontos_num) ?> Pontos <span style="font-size: 0.8em;">(Disponíveis)</span>
                        </div>
                   <?php endif; ?>
              <?php endif; ?>
              <div class="stats-grid">
                   <strong>HP Base</strong><span><?= formatNumber($usuario['hp'] ?? 0) ?></span>
                   <strong>Ki Base</strong><span><?= formatNumber($usuario['ki'] ?? 0) ?></span>
                   <strong>Força</strong><span><?= formatNumber($usuario['forca'] ?? 0) ?></span>
                   <strong>Defesa</strong><span><?= formatNumber($usuario['defesa'] ?? 0) ?></span>
                   <strong>Velocidade</strong><span><?= formatNumber($usuario['velocidade'] ?? 0) ?></span>
                   <strong>Pontos</strong><span style="color: var(--accent-color-2); font-weight:700;"><?= formatNumber($usuario['pontos'] ?? 0) ?></span>
                   <strong>Rank (Nível)</strong><span style="color: var(--accent-color-1); font-weight:700;"><?= htmlspecialchars($rank_nivel) ?><?= ($rank_nivel !== 'N/A' && is_numeric($rank_nivel) ? 'º' : '') ?></span>
                   <strong>Rank (Sala)</strong><span style="color: var(--accent-color-1); font-weight:700;"><?= htmlspecialchars($rank_sala) ?><?= ($rank_sala !== 'N/A' && is_numeric($rank_sala) ? 'º' : '') ?></span>
              </div>

              <div class="divider"></div>

               <details class="online-users-details">
                    <summary>Jogadores Online: <strong><?= $online_users_count ?></strong></summary>
                    <div class="online-users-list">
                        <?php if (!empty($online_user_list)): ?>
                            <?= implode(', ', $online_user_list); // Displaying IDs for now ?>
                            <?php if ($online_users_count > count($online_user_list)) echo ', ...'; ?>
                        <?php else: ?>
                            Nenhum outro jogador online no momento.
                        <?php endif; ?>
                    </div>
                </details>
               <div class="stats-grid" style="font-size: 0.85rem; margin-top: 10px;">
                    <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span>
                    <strong>Tempo Sala</strong><span><?= formatNumber($usuario['tempo_sala'] ?? 0) ?> min</span>
               </div>

              <div style="margin-top: auto; padding-top: 15px; display: flex; flex-direction: column; gap: 10px; border-top: 1px solid var(--panel-border);">
                   <a class="action-button btn-perfil" href="perfil.php">Meu Perfil</a> <?php // <<<<< VERIFIQUE PATH ?>
                   <a class="action-button btn-logout" href="sair.php">Sair (Logout)</a> <?php // <<<<< VERIFIQUE PATH ?>
              </div>
        </div>

        <?php // --- COLUNA CENTRAL (Conteúdo da Vovó Uranai + Chat) --- ?>
        <div class="coluna coluna-central">

             <h2>Palácio da Vovó Uranai</h2>
                   <?php
                       // Busca Ícone Uranai para a imagem central
                       $urunai_icon_path_central = 'img/urunai.jpg'; // Fallback <<<<< VERIFIQUE PATH
                       foreach ($sidebarItems as $item) {
                           if ($item['id'] === 'urunai' && !empty($item['icon'])) {
                                $icon_test_path = __DIR__ . '/' . $item['icon']; // Assume urunai.php na raiz
                                if (file_exists($icon_test_path)) {
                                    $urunai_icon_path_central = $item['icon']; // Usa o caminho relativo web
                                    break;
                                } else {
                                    error_log("Icone Central Uranai nao encontrado: ".$icon_test_path);
                                }
                           }
                       }
                   ?>
              <p class="urunai-description">
                   <img src="<?= htmlspecialchars($urunai_icon_path_central) ?>" alt="Vovó Uranai">
              </p>
              <p class="urunai-description">
                   "HOHOHO! Vejo que busca conhecimento... ou talvez... arrependimento? Se distribuiu mal sua energia vital, posso ajudá-lo a... recomeçar. Mas tudo tem um preço!"
              </p>
              <p class="urunai-description">
                   Posso retirar os pontos que você investiu em HP, Ki, Força, Defesa e Velocidade, devolvendo-os para que possa redistribuí-los. Sua experiência, no entanto, será consumida neste processo místico.
              </p>
              <p class="urunai-cost"> Custo para resetar: <strong><?php echo formatNumber($reset_cost); ?> XP</strong> </p>
              <p class="urunai-cost" style="font-size: 1rem; margin-bottom: 20px; color: var(--text-secondary);"> Seu XP atual: <strong><?php echo formatNumber($usuario['xp'] ?? 0); ?></strong> </p>

              <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" onsubmit="return confirm('Tem certeza que deseja resetar todos os seus atributos distribuídos?\n\nCusto: <?php echo formatNumber($reset_cost); ?> XP\nEsta ação NÃO pode ser desfeita!');">
                   <?php
                        // Recalcula condições aqui para o botão
                        $current_xp_display = (int)($usuario['xp'] ?? 0);
                        $can_afford_reset_display = $current_xp_display >= $reset_cost;
                        // Assume base 0, AJUSTAR SE NECESSÁRIO
                        $base_hp_display = 0; $base_ki_display = 0; $base_forca_display = 0; $base_defesa_display = 0; $base_velocidade_display = 0;
                        // ADICIONE AQUI A LÓGICA PARA PEGAR BASE STATS DA RAÇA
                        $hp_dist_display = max(0, (int)($usuario['hp']??0) - $base_hp_display);
                        $ki_dist_display = max(0, (int)($usuario['ki']??0) - $base_ki_display);
                        $for_dist_display = max(0, (int)($usuario['forca']??0) - $base_forca_display);
                        $def_dist_display = max(0, (int)($usuario['defesa']??0) - $base_defesa_display);
                        $vel_dist_display = max(0, (int)($usuario['velocidade']??0) - $base_velocidade_display);
                        $has_distributed_points_display = ($hp_dist_display + $ki_dist_display + $for_dist_display + $def_dist_display + $vel_dist_display) > 0;
                        $can_really_reset_display = $can_afford_reset_display && $has_distributed_points_display;
                   ?>
                   <button type="submit" name="reset_attributes" class="action-button btn-reset" <?php echo !$can_really_reset_display ? 'disabled' : ''; ?>>
                       Resetar Atributos
                   </button>

                   <?php if (!$can_afford_reset_display): ?>
                        <span class="xp-insufficient-warning">XP insuficiente para resetar.</span>
                   <?php elseif (!$has_distributed_points_display && $can_afford_reset_display): ?>
                        <span class="xp-insufficient-warning" style="color: var(--text-secondary); border-color: var(--disabled-color); background-color: rgba(95, 99, 104, 0.1);">Sem pontos distribuídos para resetar.</span>
                   <?php endif; ?>
              </form>

              <div style="text-align: center;"> <a href="home.php">Voltar para Praça Central</a> <?php // <<<<< VERIFIQUE PATH ?>
              </div>

              <div style="height: 30px; border-bottom: 1px solid var(--panel-border); margin: 25px 0;"></div>

              <div class="chat-area">
                   <h3>Chat da Praça</h3>
                   <div class="chat-container-ajax">
                        <div id="chat-messages-ajax">
                            <?php if (!empty($chat_history)): ?>
                                <?php foreach ($chat_history as $msg): ?>
                                    <div class="chat-message-ajax <?= $msg['user_id'] == $id ? 'mine' : 'other' ?>" data-message-id="<?= $msg['id'] ?>">
                                        <span class="sender"><?= htmlspecialchars($msg['username']) ?> <small>(<?= date('H:i', strtotime($msg['timestamp'])) ?>)</small></span>
                                        <span class="msg-text"><?= htmlspecialchars($msg['message_text']) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="chat-empty">Nenhuma mensagem recente.</p>
                            <?php endif; ?>
                        </div>
                        <form id="chat-form-ajax">
                            <input type="text" id="chat-message-input" placeholder="Digite sua mensagem..." autocomplete="off" required maxlength="500">
                            <button type="submit">Enviar</button>
                        </form>
                   </div>
              </div>
              </div>

        <?php // --- COLUNA DIREITA (Navegação) --- ?>
        <div class="coluna coluna-direita">
            <?php
                 $currentPageBaseName = basename($_SERVER['PHP_SELF']); // urunai.php

                 foreach ($sidebarItems as $item) {
                     $href = $item['href'];
                     $label = htmlspecialchars($item['label']);
                     $iconPath = $item['icon'] ?? ''; // Caminho img
                     $faIconClass = $item['fa_icon'] ?? 'fas fa-question-circle'; // Classe FA
                     $idItem = $item['id'];
                     $classe_extra = '';
                     $atributos_extra = '';
                     $title = $label;

                      // Determina ícone (Imagem se existir, senão FA)
                      $iconHtml = "<i class='{$faIconClass} nav-button-icon'></i>"; // Default FA
                      $iconFullPathFisico = '';
                      if (!empty($iconPath)) {
                          $iconFullPathFisico = __DIR__ . '/' . $iconPath; // Assume urunai.php na raiz
                          if (file_exists($iconFullPathFisico)) {
                              $iconHtml = '<img src="' . htmlspecialchars($iconPath) . '" class="nav-button-icon" alt="">'; // Caminho web
                          } else {
                               error_log("Icone nav D (img) não encontrado em urunai.php: " . $iconFullPathFisico . " - Usando FA: " . $faIconClass);
                           }
                      }

                     // Lógica para botão da Sala do Tempo
                     if ($idItem === 'sala_tempo') {
                         if (!$pode_entrar_templo_base) {
                             $classe_extra = ' disabled';
                             $atributos_extra = ' onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES).'\'); return false;"';
                             $title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                             $href = '#'; // Desativa o link
                         } else {
                             $title = 'Entrar na Sala do Templo';
                         }
                     }

                     // Verifica se é a página atual
                     $isCurrent = ($currentPageBaseName == basename($href) && $href !== '#');

                     if ($isCurrent && strpos($classe_extra, 'disabled') === false) {
                         $classe_extra .= ' active';
                     }

                     $finalHref = htmlspecialchars($href);

                     echo "<a class=\"nav-button{$classe_extra}\" href=\"{$finalHref}\" title=\"{$title}\" {$atributos_extra}>";
                     echo "<div class='nav-button-icon-area'>{$iconHtml}</div>";
                     echo "<span>{$label}</span>";
                     echo "</a>";
                 }

                 // Botão Sair
                 $sairIconPath = 'img/sair.jpg'; // <<<<< VERIFIQUE PATH
                 $sairIconHtml = '<i class="fas fa-sign-out-alt nav-button-icon"></i>'; // Fallback FA
                  if(file_exists(__DIR__.'/'.$sairIconPath)) {
                       $sairIconHtml = '<img src="'.htmlspecialchars($sairIconPath).'" class="nav-button-icon" alt="Sair">';
                  }
                  echo '<a class="nav-button logout" href="sair.php" title="Sair do Jogo">'; // <<<<< VERIFIQUE PATH
                  echo "<div class='nav-button-icon-area'>{$sairIconHtml}</div>";
                  echo '<span>Sair</span></a>';
            ?>
        </div>
    </div> <?php // Fechamento do .container ?>

    <?php // --- Bottom Nav --- ?>
    <nav id="bottom-nav">
        <?php
            $currentPageBaseNameNav = basename($_SERVER['PHP_SELF']); // urunai.php
            foreach ($bottomNavItems as $item):
                $url_destino_item = $item['href'];
                $itemIdNav = $item['id'] ?? null;
                $labelNav = htmlspecialchars($item['label']);
                $iconNav = $item['icon']; // Pode ser Emoji ou classe FA
                $final_href = '#';
                $final_onclick = '';
                $final_title = '';
                $extra_class = '';
                $active_class = '';

                $is_current_page_nav = ($itemIdNav === 'urunai'); // Especificamente para Urunai no Bottom Nav

                // Lógica Templo
                if ($itemIdNav === 'sala_tempo') {
                    if (!$pode_entrar_templo_base) {
                        $final_onclick = ' onclick="alert(\'' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '\'); return false;"';
                        $final_title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                        $extra_class = ' disabled';
                        // href fica #
                    } else {
                        $final_href = htmlspecialchars($url_destino_item);
                        $final_title = 'Entrar na Sala do Templo';
                    }
                } else {
                    $final_href = htmlspecialchars($url_destino_item);
                    $final_title = 'Ir para ' . $labelNav;
                }

                if ($is_current_page_nav && strpos($extra_class, 'disabled') === false) {
                    $active_class = ' active';
                }

                echo "<a href='{$final_href}' class='bottom-nav-item{$active_class}{$extra_class}' {$final_onclick} title='{$final_title}'>";
                // Adiciona spans para ícone e label
                echo "<span class='bottom-nav-icon'>{$iconNav}</span>";
                echo "<span class='bottom-nav-label'>{$labelNav}</span>";
                echo "</a>";
            endforeach;
        ?>
    </nav>

    <?php // --- ===>>> HTML do Modal Popup (Copiado do Kame) <<<=== --- ?>
    <div id="notification-popup-overlay" class="modal-overlay">
        <div class="modal-popup">
            <button class="modal-close-btn" onclick="markCurrentNotificationAsRead()" title="Marcar como lida">&times;</button>
            <h3>Nova Notificação</h3>
            <div id="popup-message">Carregando mensagem...</div>
            <div id="popup-actions"></div>
        </div>
    </div>


    <?php // --- JavaScript Essencial (Chat + Lógica Bottom Nav + NOVO Popup Notificação) --- ?>
    <script>
        // Variáveis JS Globais (IDs, Usuário, URLs AJAX)
        const currentUserId_Chat=<?php echo json_encode((int)$id);?>;
        const currentUsername_Chat=<?php echo json_encode($usuario['nome']??'Eu');?>;
        const chatHandlerUrl='chat_handler_ajax.php'; // <<<<< VERIFIQUE PATH

        // ===>>> NOVAS Variáveis e Constantes para Popup Notificação <<<===
        const currentUserId_Popup = <?php echo json_encode((int)$id); ?>;
        const unreadNotifications = <?php echo json_encode($unread_notifications_for_popup); ?>; // Vindo do PHP
        let currentNotification = null;
        const modalOverlay = document.getElementById('notification-popup-overlay');
        const popupMessage = document.getElementById('popup-message');
        const popupActions = document.getElementById('popup-actions');
        // ===>>> FIM NOVAS Variáveis Popup <<<===


        // Variáveis de Estado e Controle (Chat)
        let lastMessageId_Chat=0;
        <?php if(!empty($chat_history)){$lid=0;if(count($chat_history)>0){$lm=end($chat_history);if(isset($lm['id'])){$lid=(int)$lm['id'];}}echo 'lastMessageId_Chat = '.$lid.';';} ?>
        const chatPollInterval=3500;
        let chatPollingIntervalId=null;
        let isFetchingChat=false;
        const chatMessagesDiv=document.getElementById('chat-messages-ajax');
        const chatForm=document.getElementById('chat-form-ajax');
        const messageInput=document.getElementById('chat-message-input');

        // Função Utilitária para escapar HTML
        function escapeHtml(unsafe){if(typeof unsafe!=='string'){if(typeof unsafe==='number'){unsafe=String(unsafe);}else{return'';}} return unsafe.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");}

        // Função Utilitária para formatar Timestamp
        function formatTimestamp(timestamp){if(!timestamp)return'??:??'; try{ let ds=timestamp.replace(/-/g,'/'); if(ds.indexOf(' ')>0&&ds.indexOf('T')===-1&&!ds.endsWith('Z')&&!ds.match(/[+-]\d{2}:?\d{2}$/)){ds+='Z';} const d=new Date(ds); if(!isNaN(d)){ return d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',timeZone:'America/Sao_Paulo'}); } }catch(e){console.error("Erro formatar timestamp:", e);} const parts=timestamp.split(' '); if(parts.length>1&&parts[1].includes(':')){return parts[1].substring(0,5);} return'??:??'; }


        // --- ===>>> Lógica do Modal Popup (Copiada do Kame e Adaptada) <<<=== ---
        function showModal() { if (modalOverlay) modalOverlay.classList.add('visible'); }
        function hideModal() { if (modalOverlay) { modalOverlay.classList.remove('visible'); if(popupMessage) popupMessage.innerHTML = ''; if(popupActions) popupActions.innerHTML = ''; currentNotification = null; } }

        // Chamada quando clica no 'X' ou no botão 'OK (Lida)'
        function markCurrentNotificationAsRead() {
            if (currentNotification && currentNotification.id) {
                // Redireciona para a PRÓPRIA PÁGINA (urunai.php) com o parâmetro
                window.location.href = `urunai.php?mark_read=${currentNotification.id}`;
                hideModal(); // Esconde imediatamente
            } else {
                hideModal(); // Apenas esconde se não houver notificação atual
            }
        }

        // Função principal para exibir a próxima notificação não lida no popup
        function displayNextNotification() {
             if (!modalOverlay || !popupMessage || !popupActions) { console.error("Elementos do modal não encontrados."); return; }

             // Usa a variável JS 'unreadNotifications' preenchida pelo PHP
             if (unreadNotifications && unreadNotifications.length > 0) {
                  currentNotification = unreadNotifications[0]; // Pega a primeira não lida
                  if (!currentNotification) { hideModal(); return; }

                  popupMessage.textContent = currentNotification.message_text || 'Mensagem indisponível.';
                  popupActions.innerHTML = ''; // Limpa ações anteriores

                  // URL base para marcar como lida (aponta para urunai.php)
                  const markReadLink = `urunai.php?mark_read=${currentNotification.id}`;
                  let hasSpecificAction = false;
                  let relatedData = {};

                  // Tenta parsear related_data
                  if (currentNotification.related_data) {
                       if (typeof currentNotification.related_data === 'string') {
                            try { relatedData = JSON.parse(currentNotification.related_data); }
                            catch(e) { console.error("Erro JSON related_data (Urunai):", e, "Data:", currentNotification.related_data); relatedData = {}; }
                       } else if (typeof currentNotification.related_data === 'object') {
                            relatedData = currentNotification.related_data;
                       }
                  }

                  // --- Define Ações com base no TIPO (aponta para urunai.php) ---
                  if (currentNotification.type === 'invite') { // Convite de GRUPO
                       const groupId = relatedData.group_id;
                       if (groupId) {
                            const acceptLink = `urunai.php?invite_action=accept&notif_id=${currentNotification.id}`;
                            const rejectLink = `urunai.php?invite_action=reject&notif_id=${currentNotification.id}`;
                            popupActions.innerHTML = `<a href="${acceptLink}" class="popup-action-btn btn-accept">Aceitar Grupo</a><a href="${rejectLink}" class="popup-action-btn btn-reject">Recusar</a>`;
                            hasSpecificAction = true;
                       } else { console.warn("Dados incompletos (group_id) na notificação 'invite' (Urunai):", currentNotification); }
                  }
                  else if (currentNotification.type === 'fusion_invite') { // Convite de FUSÃO
                       const inviterId = relatedData.inviter_id;
                       const desafioId = relatedData.desafio_id;
                       if (inviterId && desafioId) {
                            const acceptLink = `urunai.php?fusion_action=accept&notif_id=${currentNotification.id}`;
                            const rejectLink = `urunai.php?fusion_action=reject&notif_id=${currentNotification.id}`;
                            popupActions.innerHTML = `<a href="${acceptLink}" class="popup-action-btn btn-accept">Aceitar Fusão</a><a href="${rejectLink}" class="popup-action-btn btn-reject">Recusar</a>`;
                            hasSpecificAction = true;
                       } else { console.warn("Dados incompletos (inviter_id/desafio_id) na notificação 'fusion_invite' (Urunai):", currentNotification); }
                  }
                  else if (currentNotification.type === 'arena_start') { // Notificação ARENA START
                       const eventId = relatedData.event_id;
                       if (eventId) {
                            // Link que vai para o handler PHP em urunai.php, que então redireciona para a arena
                            const enterArenaLink = `urunai.php?arena_action=enter&notif_id=${currentNotification.id}&event_id=${eventId}`;
                            popupActions.innerHTML = `
                                <a href="${enterArenaLink}" class="popup-action-btn btn-accept">Entrar na Arena</a>
                                <a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`;
                            hasSpecificAction = true;
                       } else { console.warn("Dados incompletos (event_id) na notificação 'arena_start' (Urunai):", currentNotification); }
                  }
                  else if (currentNotification.type === 'zeni_transfer_received') { // Notificação de Zeni recebido
                       // Apenas informativa, só botão OK
                       popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`;
                       hasSpecificAction = true;
                  }
                  // --- Fim Tipos Específicos ---

                  // Botão "OK" genérico se nenhuma ação específica foi gerada
                  if (!hasSpecificAction) {
                       popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`;
                  }

                  showModal(); // Mostra o modal com a notificação e ações
             } else {
                  hideModal(); // Nenhuma notificação não lida
             }
        } // Fim displayNextNotification
        // --- ===>>> FIM Lógica Modal Popup <<<=== ---


        // --- Sistema de Chat (Inalterado) ---
        function initializeChatSystem() {
            if (!chatMessagesDiv || !chatForm || !messageInput) {console.warn("Elementos do chat não encontrados."); return;}
            scrollToBottomChat(true);
            chatForm.addEventListener('submit', handleChatSubmit);
            if (chatPollingIntervalId) clearInterval(chatPollingIntervalId);
            setTimeout(fetchNewChatMessages, 1500);
            chatPollingIntervalId = setInterval(fetchNewChatMessages, chatPollInterval);
            document.addEventListener("visibilitychange", handleVisibilityChangeChat);
            checkEmptyChat();
        }

        function handleVisibilityChangeChat() {
            if (document.hidden) {
                if (chatPollingIntervalId) clearInterval(chatPollingIntervalId);
                chatPollingIntervalId = null;
            } else {
                if (!chatPollingIntervalId) {
                    setTimeout(fetchNewChatMessages, 200);
                    chatPollingIntervalId = setInterval(fetchNewChatMessages, chatPollInterval);
                }
            }
        }

        function scrollToBottomChat(force = false) {
            if (!chatMessagesDiv) return;
            const threshold = 80;
            const isScrolledToBottom = chatMessagesDiv.scrollHeight - chatMessagesDiv.scrollTop <= chatMessagesDiv.clientHeight + threshold;
            if (force || isScrolledToBottom) {
                chatMessagesDiv.scrollTo({ top: chatMessagesDiv.scrollHeight, behavior: 'auto' });
            }
        }

         function checkEmptyChat() {
             if (chatMessagesDiv && chatMessagesDiv.children.length === 0 && !chatMessagesDiv.querySelector('.chat-empty')) {
                  const emptyMsg = document.createElement('p');
                  emptyMsg.classList.add('chat-empty');
                  emptyMsg.textContent = 'Nenhuma mensagem recente.';
                  chatMessagesDiv.appendChild(emptyMsg);
             }
         }

        function addChatMessageToDisplay(msg, isHistoryLoad = false) {
            if (!chatMessagesDiv || !msg || !msg.id) return;
            if (document.querySelector(`.chat-message-ajax[data-message-id="${msg.id}"]`)) return;

            const loadingOrEmptyMsg = chatMessagesDiv.querySelector('.chat-empty');
            if (loadingOrEmptyMsg) loadingOrEmptyMsg.remove();

            const messageElement = document.createElement('div');
            messageElement.dataset.messageId = msg.id;
            messageElement.classList.add('chat-message-ajax');
            messageElement.classList.add(msg.user_id == currentUserId_Chat ? 'mine' : 'other');
            let timestampFormatted = formatTimestamp(msg.timestamp);

            messageElement.innerHTML = `
                <span class="sender">${escapeHtml(msg.username) || '??'} <small>(${timestampFormatted})</small></span>
                <span class="msg-text">${escapeHtml(msg.message_text) || ''}</span>
            `;

            const shouldScroll = chatMessagesDiv.scrollHeight - chatMessagesDiv.scrollTop <= chatMessagesDiv.clientHeight + 80;
            chatMessagesDiv.appendChild(messageElement);

            if (!isHistoryLoad && shouldScroll) {
                scrollToBottomChat();
            }

            const maxChatMessages = 100;
            while (chatMessagesDiv.children.length > maxChatMessages) {
                 if (!chatMessagesDiv.firstChild.classList.contains('chat-empty')) {
                      chatMessagesDiv.removeChild(chatMessagesDiv.firstChild);
                 } else { break; }
            }

            if (msg.id && parseInt(msg.id) > lastMessageId_Chat) {
                lastMessageId_Chat = parseInt(msg.id);
                // console.log("Last Chat ID updated to:", lastMessageId_Chat);
            }
        }

        async function handleChatSubmit(event) {
            event.preventDefault();
            const message = messageInput.value.trim();
            if (message && message.length > 0 && message.length <= 500) {
                messageInput.disabled = true;
                const submitButton = chatForm.querySelector('button');
                if (submitButton) submitButton.disabled = true;

                const tempId = `temp_${Date.now()}`;
                const optimisticMessage = {
                    id: tempId, user_id: currentUserId_Chat, username: currentUsername_Chat,
                    message_text: message, timestamp: new Date().toISOString().slice(0, 19).replace('T', ' ')
                };
                addChatMessageToDisplay(optimisticMessage);
                messageInput.value = '';

                const formData = new FormData();
                formData.append('message', message);
                try {
                    const response = await fetch(`${chatHandlerUrl}?action=send`, { method: 'POST', body: formData }); // <<<<< VERIFIQUE PATH E ACTION
                    const data = await response.json();
                    if (data.success && data.newMessageId) {
                        const tempMsgElement = chatMessagesDiv.querySelector(`.chat-message-ajax[data-message-id="${tempId}"]`);
                        if (tempMsgElement) {
                            tempMsgElement.dataset.messageId = data.newMessageId;
                            if (data.newMessageId > lastMessageId_Chat) {
                                lastMessageId_Chat = data.newMessageId;
                                // console.log("Last Chat ID updated (after send) to:", lastMessageId_Chat);
                            }
                        }
                    } else {
                        const failedMsgElement = chatMessagesDiv.querySelector(`.chat-message-ajax[data-message-id="${tempId}"]`);
                        if (failedMsgElement) {
                             failedMsgElement.style.opacity = '0.5'; failedMsgElement.title = `Falha: ${data.error || '?'}`; failedMsgElement.style.border = '1px dashed var(--danger-color)';
                        }
                        console.error('Erro ao enviar msg:', data.error || 'Tente.');
                        alert("Falha ao enviar: " + (data.error || 'Erro desconhecido'));
                    }
                } catch (error) {
                    const failedMsgElement = chatMessagesDiv.querySelector(`.chat-message-ajax[data-message-id="${tempId}"]`);
                    if (failedMsgElement) {
                        failedMsgElement.style.opacity = '0.5'; failedMsgElement.title = `Falha de conexão.`; failedMsgElement.style.border = '1px dashed var(--danger-color)';
                    }
                    console.error("Erro de rede ao enviar msg:", error);
                    alert("Falha de conexão. Verifique sua internet.");
                } finally {
                    messageInput.disabled = false;
                    if (submitButton) submitButton.disabled = false;
                    messageInput.focus();
                }
            } else if (message.length > 500) { alert("Mensagem muito longa (max 500)."); }
        }

        async function fetchNewChatMessages() {
            if (isFetchingChat || document.hidden || !chatMessagesDiv) return;
            isFetchingChat = true;
            try {
                const fetchUrl = `${chatHandlerUrl}?action=fetch&last_id=${lastMessageId_Chat}&t=${Date.now()}`; // <<<<< VERIFIQUE PATH E ACTION
                const response = await fetch(fetchUrl);
                if (!response.ok) { console.error("Erro fetch chat:", response.status); isFetchingChat = false; return; }
                const data = await response.json();
                if (data.success && data.messages && data.messages.length > 0) {
                    const shouldScroll = chatMessagesDiv.scrollHeight - chatMessagesDiv.scrollTop <= chatMessagesDiv.clientHeight + 80;
                    data.messages.forEach(msg => { addChatMessageToDisplay(msg, false); });
                    if (shouldScroll) { scrollToBottomChat(); }
                 } else if (data.error) { console.warn("Erro backend fetch chat:", data.error); }
            } catch (error) { console.error("Erro rede fetch chat:", error); }
            finally { isFetchingChat = false; }
        }
        // --- FIM Sistema de Chat ---


        // --- Inicialização Geral ---
        document.addEventListener('DOMContentLoaded', () => {
            // Lógica Botões Específicos (Templo)
            document.querySelectorAll('.nav-button[onclick*="alert"], .bottom-nav-item[onclick*="alert"]').forEach(button => {
                button.addEventListener('click', e => {
                    if (button.classList.contains('disabled')) {
                        button.style.transition = 'box-shadow 0.1s ease-in-out';
                        button.style.boxShadow = '0 0 5px 2px var(--danger-color)';
                        setTimeout(() => { button.style.boxShadow = ''; }, 300);
                    }
                });
            });
            // Lógica Botões com data-url (se houver)
            document.querySelectorAll('[data-url]').forEach(button => {
                if (!button.classList.contains('disabled') && button.getAttribute('href') === '#') {
                    button.addEventListener('click', event => {
                        event.preventDefault(); const url = button.dataset.url; if (url) window.location.href = url;
                    });
                }
            });

            // Controle Visibilidade Bottom Nav
            const bottomNav = document.getElementById('bottom-nav');
            const rightColumn = document.querySelector('.coluna-direita');
            const bodyEl = document.body; // Cache body element
            function checkBottomNavVisibility() {
                if (!bottomNav || !rightColumn || !bodyEl) return;
                // Pega o padding original do body armazenado na variável CSS
                const originalPadding = getComputedStyle(bodyEl).getPropertyValue('--body-original-padding-bottom').trim() || '20px';
                if (window.getComputedStyle(rightColumn).display === 'none') {
                    bottomNav.style.display = 'flex';
                    // Define o padding baseado na altura REAL do bottomNav + uma margem
                    bodyEl.style.paddingBottom = (bottomNav.offsetHeight + 15) + 'px';
                } else {
                    bottomNav.style.display = 'none';
                    // Restaura o padding original
                    bodyEl.style.paddingBottom = originalPadding;
                }
            }
            // Armazena o padding inicial do body na variável CSS
            bodyEl.style.setProperty('--body-original-padding-bottom', getComputedStyle(bodyEl).paddingBottom);
            checkBottomNavVisibility();
            window.addEventListener('resize', checkBottomNavVisibility);
            setTimeout(checkBottomNavVisibility, 300); // Recheck após render inicial

            // Inicializa Chat
            initializeChatSystem();

            // ===>>> INICIALIZA Popup Notificação <<<===
            displayNextNotification(); // Mostra a primeira notificação não lida (se houver)

            // Listener para fechar modal clicando fora
            if (modalOverlay) {
                modalOverlay.addEventListener('click', function(event) {
                    // Fecha SOMENTE se clicar no overlay e não no popup interno
                    if (event.target === modalOverlay) {
                        markCurrentNotificationAsRead(); // Marca como lida e redireciona
                    }
                });
            }
            // ===>>> FIM Inicialização Popup <<<===

             // Opcional: Fechar feedback após X segundos
             const feedbackBox = document.querySelector('.feedback-container');
             if (feedbackBox && feedbackBox.style.display !== 'none') {
                  setTimeout(() => {
                       feedbackBox.style.opacity = '0';
                       feedbackBox.style.transition = 'opacity 0.5s ease-out';
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