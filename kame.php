<?php
// Arquivo: kame.php (Popup Notificação + Handler Grupo, Fusão, Arena + CSS COMPLETO)
// MODIFICADO PARA EXIBIR NOTIFICAÇÃO DE TRANSFERÊNCIA (zeni_transfer_received)

// --- PHP (Início - Sessão, Conexão, Fallbacks) ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
require_once("conexao.php"); // Assume $conn ou $pdo
if (!isset($conn) || !$conn instanceof PDO) { if (isset($pdo) && $pdo instanceof PDO) { $conn = $pdo; } else { error_log("Falha crítica conexão PDO kame.php"); die("Erro crítico: DB indisponível."); } }
// Inclui fallbacks para functions.php se necessário (mantido como no original)
if (file_exists(__DIR__ . "/includes/functions.php")) {
    include_once(__DIR__ . "/includes/functions.php");
}
if (!function_exists('formatNumber')) { function formatNumber($num) { $n = filter_var($num, FILTER_VALIDATE_INT); return number_format(($n === false ? 0 : $n), 0, ',', '.'); } }
if (!function_exists('formatarNomeUsuarioComTag')) { function formatarNomeUsuarioComTag($c, $d) { if (!is_array($d)||!isset($d['nome'])) { return 'Nome Inválido'; } return htmlspecialchars($d['nome']); } }

$id = $_SESSION['user_id'];
$nome_formatado_usuario = 'Carregando...';
$unread_notifications_for_popup = [];

// --- Lógica de Aprender Habilidade (POST) ---
// (Código POST inalterado - omitido para brevidade, assumindo que está correto como no original)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aprender'])) {
    if (!isset($conn) || !$conn instanceof PDO) { die("Erro crítico: Conexão PDO não disponível para POST (Kame)."); }
    // --- INÍCIO LÓGICA POST APRENDER HABILIDADE ---
    $habilidade_id_post = filter_input(INPUT_POST, 'habilidade_id', FILTER_VALIDATE_INT);
    if ($habilidade_id_post) {
        try {
            $conn->beginTransaction();
            // 1. Buscar dados do usuário
            $stmt_user = $conn->prepare("SELECT nivel, tempo_sala, raca FROM usuarios WHERE id = :id FOR UPDATE");
            $stmt_user->execute([':id' => $id]);
            $user_data_post = $stmt_user->fetch(PDO::FETCH_ASSOC);

            // 2. Buscar dados da habilidade
            $stmt_hab = $conn->prepare("SELECT nome, nivel_requerido, tempo_requerido, raca_requerida, ki FROM habilidades_base WHERE id = :hab_id");
            $stmt_hab->execute([':hab_id' => $habilidade_id_post]);
            $hab_data = $stmt_hab->fetch(PDO::FETCH_ASSOC);

            // 3. Verificar se já possui
            $stmt_check = $conn->prepare("SELECT COUNT(*) FROM habilidades_usuario WHERE usuario_id = :user_id AND habilidade_id = :hab_id");
            $stmt_check->execute([':user_id' => $id, ':hab_id' => $habilidade_id_post]);
            $already_has = $stmt_check->fetchColumn() > 0;

            if ($user_data_post && $hab_data && !$already_has) {
                // 4. Validar requisitos
                $req_nivel_ok_post = ($user_data_post['nivel'] >= $hab_data['nivel_requerido']);
                $req_tempo_ok_post = ($user_data_post['tempo_sala'] >= $hab_data['tempo_requerido']);
                $req_raca_ok_post = (empty($hab_data['raca_requerida']) || $user_data_post['raca'] === $hab_data['raca_requerida']);

                if ($req_nivel_ok_post && $req_tempo_ok_post && $req_raca_ok_post) {
                    // 5. Adicionar habilidade
                    $stmt_add = $conn->prepare("INSERT INTO habilidades_usuario (usuario_id, habilidade_id) VALUES (:user_id, :hab_id)");
                    if ($stmt_add->execute([':user_id' => $id, ':hab_id' => $habilidade_id_post])) {
                        $_SESSION['feedback_kame'] = "<span class='success-message'>Habilidade '" . htmlspecialchars($hab_data['nome']) . "' aprendida!</span>";
                        $conn->commit();
                    } else {
                        throw new PDOException("Falha ao inserir habilidade para usuário.");
                    }
                } else {
                    // Monta mensagem de erro de requisitos
                    $errors_req = [];
                    if (!$req_nivel_ok_post) $errors_req[] = "nível " . $hab_data['nivel_requerido'];
                    if (!$req_tempo_ok_post) $errors_req[] = "tempo de sala " . $hab_data['tempo_requerido'] . "m";
                    if (!$req_raca_ok_post) $errors_req[] = "raça " . htmlspecialchars($hab_data['raca_requerida']);
                    $_SESSION['feedback_kame'] = "<span class='error-message'>Requisitos não atendidos: " . implode(', ', $errors_req) . ".</span>";
                    $conn->rollBack(); // Requisitos não atendidos, desfaz a transação
                }
            } elseif ($already_has) {
                $_SESSION['feedback_kame'] = "<span class='warning-message'>Você já conhece essa habilidade.</span>";
                $conn->rollBack(); // Já possui, desfaz
            } else {
                 $_SESSION['feedback_kame'] = "<span class='error-message'>Habilidade ou usuário inválido.</span>";
                 $conn->rollBack(); // Dados inválidos, desfaz
            }
        } catch (PDOException | Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Erro POST aprender habilidade kame.php user {$id}: " . $e->getMessage());
            $_SESSION['feedback_kame'] = "<span class='error-message'>Erro ao tentar aprender a habilidade.</span>";
        }
    } else {
        $_SESSION['feedback_kame'] = "<span class='warning-message'>Nenhuma habilidade selecionada.</span>";
    }
    // --- FIM LÓGICA POST APRENDER HABILIDADE ---
    header("Location: kame.php"); exit();
} // Fim do Bloco POST


// --- Processar Ações de Notificação via GET (Grupo, Fusão, Arena) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($conn) || !$conn instanceof PDO) { die("Erro crítico: Conexão PDO não disponível para GET actions (Kame)."); }
    $notification_action_processed = false;

    // Marcar como lida (genérico)
    if (isset($_GET['mark_read']) && filter_var($_GET['mark_read'], FILTER_VALIDATE_INT)) {
        $notif_id_to_mark = (int)$_GET['mark_read'];
        try {
            $stmt_mark = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id");
            $stmt_mark->execute([':notif_id' => $notif_id_to_mark, ':user_id' => $id]);
             // Opcional: $_SESSION['feedback_geral'] = "<span class='info-message'>Notificação marcada como lida.</span>";
        } catch (PDOException $e) {
            error_log("Erro PDO marcar notif {$notif_id_to_mark} lida user {$id}: " . $e->getMessage());
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
                     if (!$stmt_mark_invite->execute([':notif_id' => $notif_id_invite, ':user_id' => $id])) { error_log("Alerta: Falha marcar notif convite {$notif_id_invite} lida user {$id}."); }
                 } else {
                      $_SESSION['feedback_geral'] = "<span class='warning-message'>Convite de grupo inválido ou já processado.</span>";
                 }
             } catch (PDOException | Exception $e) {
                 error_log("Erro tratar invite_action {$invite_action} notif {$notif_id_invite} user {$id}: " . $e->getMessage());
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
                         if (!$stmt_mark_fus->execute([':notif_id' => $notif_id_fusion, ':user_id' => $id])) { error_log("Alerta: Falha marcar notif fusão {$notif_id_fusion} lida user {$id}."); }
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
                 error_log("Erro processar fusion_action={$fusion_action} notif={$notif_id_fusion} user={$id}: " . $e->getMessage());
                 $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao processar o convite de fusão.</span>";
                 // Tenta marcar como lida mesmo em erro
                 try { $stmt_mark_error = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :notif_id AND user_id = :user_id"); $stmt_mark_error->execute([':notif_id' => $notif_id_fusion, ':user_id' => $id]); } catch (PDOException $e_mark) {}
             }
             $notification_action_processed = true;
        } // Fim if action accept/reject
    } // Fim elseif fusion_action

    // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // +++ Lidar com Ação de Entrada na Arena                 +++
    // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
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
                $arena_url = "script/arena/arenaroyalle.php?event_id=" . $event_id_arena; // Assumindo que está na raiz e arena está em script/arena/
                // Se kame.php está em outra pasta, ajuste o caminho relativo ou use absoluto /script/arena/...
                header("Location: " . $arena_url);
                exit(); // Importante sair após o header
            } else {
                 $_SESSION['feedback_geral'] = "<span class='warning-message'>Não foi possível processar a notificação da arena. Talvez já tenha sido lida.</span>";
                 error_log("Falha ao marcar notificação arena_start {$notif_id_arena} como lida para user {$id}.");
            }

        } catch (PDOException $e) {
            error_log("Erro PDO ao processar ação arena_start (notif {$notif_id_arena}, event {$event_id_arena}) user {$id}: " . $e->getMessage());
            $_SESSION['feedback_geral'] = "<span class='error-message'>Erro ao tentar entrar na arena pela notificação.</span>";
             // Tenta marcar como lida mesmo em erro
             try { $stmt_mark_error = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE id = :id AND user_id = :uid"); $stmt_mark_error->execute([':id'=>$notif_id_arena, ':uid'=>$id]); } catch(PDOException $em){}
        }
        $notification_action_processed = true;
    }
    // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
    // +++ FIM: Lidar com Ação de Entrada na Arena             +++
    // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++


    // Redireciona APENAS se uma ação foi processada
    if ($notification_action_processed) {
        header("Location: kame.php"); // Volta para kame.php para limpar GET params e/ou mostrar próximo popup
        exit();
    }
}
// --- FIM: Processar Ações GET ---


// --- Definições, Constantes, Feedback Session ---
$usuario = null; $habilidades_aprendidas_nomes = []; $habilidades_disponiveis = []; $mensagem_final_feedback = ""; $currentPage = basename($_SERVER['PHP_SELF']); $rank_nivel = 'N/A'; $rank_sala = 'N/A';
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);
if (!defined('PONTOS_GANHO_POR_NIVEL')) define('PONTOS_GANHO_POR_NIVEL', 100);
if (isset($_SESSION['feedback_geral'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_geral']; unset($_SESSION['feedback_geral']); }
if (isset($_SESSION['feedback_cooldown'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_cooldown']; unset($_SESSION['feedback_cooldown']); }
if (isset($_SESSION['feedback_kame'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_kame']; unset($_SESSION['feedback_kame']); }


// --- Função Level Up ---
// (Código da função verificarLevelUpHome inalterado - omitido para brevidade)
if (!function_exists('verificarLevelUpHome')) { function verificarLevelUpHome(PDO $c, int $id_u): array { $m = []; $t = 0; $max_t = 50; while ($t < $max_t) { $t++; try { $s = $c->prepare("SELECT nivel, xp, pontos FROM usuarios WHERE id = :id"); $s->execute([':id' => $id_u]); $d = $s->fetch(PDO::FETCH_ASSOC); if (!$d) { break; } $n = (int)$d['nivel']; $x = (int)$d['xp']; $p = (int)($d['pontos']??0); $xn = max(XP_BASE_LEVEL_UP, (int)ceil(XP_BASE_LEVEL_UP * pow($n, XP_EXPOENTE_LEVEL_UP))); if ($x >= $xn) { $nn = $n + 1; $nx = $x - $xn; $np = $p + PONTOS_GANHO_POR_NIVEL; $su = $c->prepare("UPDATE usuarios SET nivel = :n, xp = :x, pontos = :p WHERE id = :id"); $prm = [':n'=>$nn, ':x'=>$nx, ':p'=>$np, ':id'=>$id_u]; if ($su->execute($prm)) { $m[] = "<span class='levelup-message'>⭐ LEVEL UP! ⭐ Nível <strong>{$nn}</strong>! +<strong>".PONTOS_GANHO_POR_NIVEL."</strong> Pontos!</span>"; continue; } else { error_log("Falha level up update user $id_u. Err: ".print_r($su->errorInfo(), true)); break; } } else { break; } } catch (PDOException $e) { error_log("Falha level up check user $id_u: ".$e->getMessage()); break; } } return $m; } }


// --- Lógica PHP Essencial (Busca de dados PRINCIPAIS) ---
try {
    if (!isset($conn) || !$conn instanceof PDO) { throw new Exception("Conexão PDO perdida busca principal."); }
    $sql_fetch_user = "SELECT nome, nivel, hp, pontos, xp, ki, forca, id, defesa, velocidade, foto, tempo_sala, raca, zeni, ranking_titulo FROM usuarios WHERE id = :id_usuario";
    $stmt_fetch = $conn->prepare($sql_fetch_user);
    $stmt_fetch->execute([':id_usuario' => $id]);
    $usuario = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) { session_destroy(); header("Location: login.php?erro=UsuarioNaoEncontradoFatal"); exit(); }

    // Verifica Level Up
    $mensagens_lup = verificarLevelUpHome($conn, $id);
    if (!empty($mensagens_lup)) {
        $mensagem_final_feedback .= ($mensagem_final_feedback ? "<br>" : "") . implode("<br>", $mensagens_lup);
        // Rebusca dados do usuário após level up
        $stmt_fetch->execute([':id_usuario' => $id]);
        $usuario = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) { session_destroy(); header("Location: login.php?erro=UsuarioNaoEncontradoAposLUP"); exit(); }
    }

    $hp_atual_base = (int)($usuario['hp'] ?? 0);
    $ki_atual_base = (int)($usuario['ki'] ?? 0);
    $pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0);
    $mensagem_bloqueio_templo_base = $pode_entrar_templo_base ? '' : 'Recupere HP/Ki base!';

    // Busca Ranks
    try {
        $sql_rank_nivel = "SELECT rank_nivel FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC) as rank_nivel FROM usuarios) AS r WHERE id = :id";
        $stmt_rank_nivel = $conn->prepare($sql_rank_nivel);
        $stmt_rank_nivel->execute([':id' => $id]);
        $res_n = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC);
        if ($res_n) $rank_nivel = $res_n['rank_nivel'];

        $sql_rank_sala = "SELECT rank_sala FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala FROM usuarios) AS r WHERE id = :id";
        $stmt_rank_sala = $conn->prepare($sql_rank_sala);
        $stmt_rank_sala->execute([':id' => $id]);
        $res_s = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC);
        if ($res_s) $rank_sala = $res_s['rank_sala'];
    } catch (PDOException $e_rank) {
        error_log("PDOException ranks user {$id}: " . $e_rank->getMessage());
    }

    // --- Busca NOTIFICAÇÕES NÃO LIDAS para o Popup ---
    try {
        $sql_unread_notif = "SELECT id, message_text, type, timestamp, related_data FROM user_notifications WHERE user_id = :user_id AND is_read = 0 ORDER BY timestamp ASC";
        $stmt_unread_notif = $conn->prepare($sql_unread_notif);
        if ($stmt_unread_notif && $stmt_unread_notif->execute([':user_id' => $id])) {
            $unread_notifications_for_popup = $stmt_unread_notif->fetchAll(PDO::FETCH_ASSOC);
        } else {
            error_log("Erro PDO fetch unread notifs user {$id}: " . print_r($conn->errorInfo() ?: ($stmt_unread_notif ? $stmt_unread_notif->errorInfo() : 'Prepare Failed'), true));
        }
    } catch (PDOException $e_notif) {
        error_log("Erro PDO buscar notifs não lidas user {$id}: " . $e_notif->getMessage());
    }

    // --- Busca de Habilidades ---
    $tempo_sala_kame = $usuario['tempo_sala'] ?? 0;
    $nivel_kame = $usuario['nivel'] ?? 0;
    $raca_usuario_kame = $usuario['raca'] ?? null;
    $query_aprendidas = "SELECT hb.nome FROM habilidades_usuario hu JOIN habilidades_base hb ON hu.habilidade_id = hb.id WHERE hu.usuario_id = :id ORDER BY hb.nome ASC";
    $stmt_aprendidas = $conn->prepare($query_aprendidas);
    $stmt_aprendidas->execute([':id' => $id]);
    $habilidades_aprendidas_nomes = $stmt_aprendidas->fetchAll(PDO::FETCH_COLUMN, 0);

    $subQueryAprendidas = "SELECT habilidade_id FROM habilidades_usuario WHERE usuario_id = :id";
    $query_disponiveis = "SELECT id, nome, ki, nivel_requerido, tempo_requerido, raca_requerida FROM habilidades_base WHERE id NOT IN ({$subQueryAprendidas}) AND (raca_requerida IS NULL OR raca_requerida = :raca) ORDER BY nivel_requerido ASC, nome ASC";
    $stmt_disponiveis = $conn->prepare($query_disponiveis);
    $stmt_disponiveis->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt_disponiveis->bindValue(':raca', $raca_usuario_kame, PDO::PARAM_STR);
    $stmt_disponiveis->execute();
    $habilidades_disponiveis = $stmt_disponiveis->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException | Exception $e) {
    error_log("Erro GERAL/PDO buscar dados kame.php user {$id}: " . $e->getMessage());
    $usuario = $usuario ?? []; // Evita erro se falhar antes de buscar usuário
    $mensagem_final_feedback .= ($mensagem_final_feedback ? "<br>" : "") . "<span class='error-message'>Erro carregar dados. Recarregue.</span>";
    if (empty($usuario['id'])) { // Verifica se dados essenciais foram perdidos
        die("Erro crítico: Dados essenciais do usuário ausentes após falha.");
    }
}


// --- Cálculos e Formatações Finais ---
$xp_necessario_calc = 0; $xp_percent = 0; $xp_necessario_display = 'N/A';
if (isset($usuario['nivel'])) {
    $nivel_atual_num = filter_var($usuario['nivel'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $xp_necessario_calc = (int)ceil(XP_BASE_LEVEL_UP * pow($nivel_atual_num, XP_EXPOENTE_LEVEL_UP));
    $xp_necessario_calc = max(XP_BASE_LEVEL_UP, $xp_necessario_calc);
    $xp_necessario_display = formatNumber($xp_necessario_calc);
    if ($xp_necessario_calc > 0 && isset($usuario['xp'])) {
        $xp_atual_num = filter_var($usuario['xp'] ?? 0, FILTER_VALIDATE_INT);
        $xp_percent = ($xp_atual_num !== false) ? min(100, max(0, ($xp_atual_num / $xp_necessario_calc) * 100)) : 0;
    }
} else { error_log("AVISO: \$usuario['nivel'] não encontrado para cálculo de XP user {$id}."); }

// Formatação do caminho da foto (Caminho Web)
$nome_arquivo_foto = 'default.jpg';
if (!empty($usuario['foto'])) {
    $caminho_fisico = __DIR__ . '/uploads/' . $usuario['foto']; // Assumindo que uploads está na raiz
    if (file_exists($caminho_fisico)) {
        $nome_arquivo_foto = $usuario['foto'];
    } else {
        error_log("Foto não encontrada no caminho físico: $caminho_fisico (User: {$id}) - Usando default.");
    }
}
// Caminho relativo à raiz para o HTML
$caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto);


// Formata nome do usuário
if (isset($usuario) && is_array($usuario)) {
    $nome_formatado_usuario = formatarNomeUsuarioComTag($conn, $usuario);
} else {
    error_log("ERRO CRÍTICO: \$usuario inválido antes formatação final user {$id}");
    $nome_formatado_usuario = 'Erro Dados Usuário';
}


// --- Arrays de Navegação ---
// (Mantidos como no original - Ajuste os caminhos e ícones conforme sua estrutura final)
$sidebarItems = [
    ['id' => 'praca', 'label' => 'Praça Central', 'href' => 'home.php', 'icon' => 'img/praca.jpg', 'fa_icon' => 'fas fa-users'],
    ['id' => 'guia', 'label' => 'Guia do Guerreiro Z', 'href' => 'guia_guerreiro.php', 'icon' => 'img/guiaguerreiro.jpg', 'fa_icon' => 'fas fa-book-open'],
    ['id' => 'equipe', 'label' => 'Equipe Z', 'href' => 'script/grupos.php', 'icon' => 'img/grupos.jpg', 'fa_icon' => 'fas fa-users-cog'],
    ['id' => 'banco', 'label' => 'Banco Galáctico', 'href' => 'script/banco.php', 'icon' => 'img/banco.jpg', 'fa_icon' => 'fas fa-landmark'],
    ['id' => 'urunai', 'label' => 'Palácio da Vovó Uranai', 'href' => 'urunai.php', 'icon' => 'img/urunai.jpg', 'fa_icon' => 'fas fa-hat-wizard'],
    ['id' => 'kame', 'label' => 'Casa do Mestre Kame', 'href' => 'kame.php', 'icon' => 'img/kame.jpg', 'fa_icon' => 'fas fa-home'], // Página atual
    ['id' => 'sala_tempo', 'label' => 'Sala do Tempo', 'href' => 'templo.php', 'icon' => 'img/templo.jpg', 'fa_icon' => 'fas fa-hourglass-half'],
    ['id' => 'treinos', 'label' => 'Zona de Treinamento', 'href' => 'desafios/treinos.php', 'icon' => 'img/treinos.jpg', 'fa_icon' => 'fas fa-dumbbell'],
    ['id' => 'desafios', 'label' => 'Desafios Z', 'href' => 'desafios.php', 'icon' => 'img/desafios.jpg', 'fa_icon' => 'fas fa-scroll'],
    ['id' => 'esferas', 'label' => 'Buscar Esferas', 'href' => 'esferas.php', 'icon' => 'img/esferar.jpg', 'fa_icon' => 'fas fa-dragon'],
    ['id' => 'erros', 'label' => 'Relatar Erros', 'href' => 'erros.php', 'icon' => 'img/erro.png', 'fa_icon' => 'fas fa-bug'],
    ['id' => 'perfil', 'label' => 'Perfil de Guerreiro', 'href' => 'perfil.php', 'icon' => 'img/perfil.jpg', 'fa_icon' => 'fas fa-user-edit']
];
$bottomNavItems = [
    ['label' => 'Home', 'href' => 'home.php', 'icon' => '🏠', 'id' => 'home'], // Corrigido ID para 'home' se for o caso
    ['label' => 'Praça', 'href' => 'praca.php', 'icon' => '👥', 'id' => 'praca'],
    ['label' => 'Templo', 'href' => 'templo.php', 'icon' => '🏛️', 'id' => 'sala_tempo'], // ID ajustado
    ['label' => 'Kame', 'href' => 'kame.php', 'icon' => '🐢', 'id' => 'kame'], // Página atual
    ['label' => 'Perfil', 'href' => 'perfil.php', 'icon' => '👤', 'id' => 'perfil'],
    ['label' => 'Arena', 'href' => 'script/arena/arenaroyalle.php', 'icon' => '⚔️', 'id' => 'arena'], // Caminho ajustado
    ['label' => 'Treinos','href' => 'desafios/treinos.php', 'icon' => '💪', 'id' => 'treinos']
];

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Casa do Mestre Kame - <?= $nome_formatado_usuario ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
         /* <<< TODO O CSS (Base + Modal) COLADO AQUI >>> */
         /* (O CSS extenso foi omitido novamente para brevidade, mas usar o CSS do kame.php original) */
          :root { --panel-bg: rgba(25, 28, 36, 0.9); --panel-border: rgba(255, 255, 255, 0.15); --text-primary: #e8eaed; --text-secondary: #bdc1c6; --accent-color-1: #00e676; --accent-color-2: #ffab00; --accent-color-3: #2979ff; --danger-color: #ff5252; --disabled-color: #5f6368; --success-color: #00e676; --info-color: #29b6f6; --warning-color: #ffa726; --border-radius: 10px; --shadow: 0 8px 25px rgba(0, 0, 0, 0.5); --font-main: 'Roboto', sans-serif; --body-original-padding-bottom: 20px; --dbz-blue: #1a2a4d; --dbz-orange: #f5a623; --dbz-orange-dark: #e47d1e; --dbz-red: #cc5500; --dbz-white: #ffffff; --dbz-grey: #aaaaaa; --dbz-light-grey: #f0f0f0; --dbz-dark-grey: #333333; --dbz-font: 'Roboto', sans-serif; --bg-image-url: url('img/kame.jpg'); }
          @keyframes pulse-glow-button{0%{box-shadow:0 0 8px var(--accent-color-2),inset 0 1px 1px rgba(255,255,255,.2);transform:scale(1)}70%{box-shadow:0 0 16px 4px var(--accent-color-2),inset 0 1px 1px rgba(255,255,255,.3);transform:scale(1.03)}100%{box-shadow:0 0 8px var(--accent-color-2),inset 0 1px 1px rgba(255,255,255,.2);transform:scale(1)}}@keyframes ki-charge{0%{background-size:100% 100%;opacity:.6}50%{background-size:150% 150%;opacity:1}100%{background-size:100% 100%;opacity:.6}}
          *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
          body { background-image: var(--bg-image-url); background-size: cover; background-position: center; background-attachment: fixed; color: var(--text-primary); font-family: var(--font-main); margin: 0; padding: 20px; min-height: 100vh; background-color: #0b0c10; overflow-x: hidden; }
          .container.page-container { width: 100%; max-width: 1400px; display: flex; justify-content: center; gap: 20px; align-items: stretch; margin-left: auto; margin-right: auto; }
          .coluna { background-color: var(--panel-bg); border-radius: var(--border-radius); padding: 20px; box-shadow: var(--shadow); border: 1px solid var(--panel-border); backdrop-filter: blur(4px); }
          .feedback-container { width: 100%; max-width: 1200px; margin: 0 auto 20px auto; padding: 15px 25px; background-color: rgba(30, 34, 44, 0.92); border-radius: var(--border-radius); text-align: center; font-weight: 500; font-size: 1rem; line-height: 1.6; border: 1px solid; display: <?php echo !empty($mensagem_final_feedback) ? 'block' : 'none'; ?>; } .feedback-container.hidden { display: none; } .feedback-container strong { color: var(--success-color); } .feedback-container span.error-message { color: var(--danger-color) !important; font-weight: bold; } .feedback-container span.success-message { color: var(--success-color) !important; font-weight: bold; } .feedback-container span.info-message { color: var(--info-color) !important; font-weight: bold; } .feedback-container span.warning-message { color: var(--warning-color) !important; font-weight: bold; } .feedback-container:has(span.success-message) { background-color: rgba(0, 230, 118, 0.15); border-color: var(--success-color); } .feedback-container:has(span.error-message) { background-color: rgba(255, 82, 82, 0.15); border-color: var(--danger-color); } .feedback-container:has(span.info-message) { background-color: rgba(41, 182, 246, 0.15); border-color: var(--info-color); } .feedback-container:has(span.warning-message) { background-color: rgba(255, 167, 38, 0.15); border-color: var(--warning-color); }
          .coluna-esquerda { width: 25%; min-width: 270px; display: flex; flex-direction: column; gap: 12px; } .player-card { margin-bottom: 5px; text-align: center;} .foto { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 4px solid var(--accent-color-1); margin: 0 auto 10px auto; box-shadow: 0 0 15px rgba(0, 230, 118, 0.5); transition: transform 0.3s ease; display: block; } .foto:hover { transform: scale(1.05); } .player-name { font-size: 1.3rem; font-weight: 700; color: #fff; margin-bottom: 2px; word-wrap: break-word; } .player-level { font-size: 1.0rem; font-weight: 500; color: var(--accent-color-1); margin-bottom: 3px; } .player-race { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); margin-bottom: 3px; } .player-rank { font-size: 0.8rem; font-weight: 500; color: var(--accent-color-3); margin-bottom: 10px; } .zeni-display { font-size: 1rem; color: var(--accent-color-2); font-weight: 700; margin-bottom: 6px; background-color: rgba(0,0,0,0.15); padding: 5px; border-radius: 4px; text-align: center; } .xp-bar-container { width: 100%; margin-bottom: 10px; } .xp-label { font-size: 0.7rem; color: var(--text-secondary); text-align: left; margin-bottom: 3px; display: block; } .xp-bar { height: 11px; background-color: rgba(0, 0, 0, 0.3); border-radius: 6px; overflow: hidden; position: relative; border: 1px solid rgba(255,255,255,0.1); } .xp-bar-fill { height: 100%; background: linear-gradient(90deg, var(--accent-color-3), var(--accent-color-1)); border-radius: 6px; transition: width 0.5s ease-in-out; animation: ki-charge 1.5s linear infinite; } .xp-text { position: absolute; top: -1px; left: 0; right: 0; font-size: 0.65rem; font-weight: 500; color: #fff; text-shadow: 1px 1px 2px rgba(0,0,0,0.7); line-height: 13px; text-align: center; } .btn-pontos { display: inline-block; padding: 8px 15px; background: linear-gradient(45deg, var(--accent-color-2), #ffc107); color: #111; text-decoration: none; font-weight: 700; font-size: 0.9rem; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3); margin-bottom: 8px; border: none; text-align: center; transition: all 0.2s ease; cursor: pointer; animation: pulse-glow-button 2.5s infinite alternate ease-in-out; } .btn-pontos:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0, 0, 0, 0.4); filter: brightness(1.1); } .btn-pontos[style*="cursor: default"] { background: var(--disabled-color); color: var(--text-secondary); filter: none; box-shadow: none; animation: none; } .stats-grid { display: grid; grid-template-columns: auto 1fr; gap: 5px 10px; text-align: left; font-size: 0.8rem; width: 100%; background-color: rgba(0,0,0,0.1); padding: 8px; border-radius: 4px; margin-top: 5px; } .stats-grid strong { color: var(--text-secondary); font-weight: 500; font-size: 0.75rem;} .stats-grid span { color: var(--text-primary); font-weight: 500; text-align: right; font-size: 0.8rem;} .divider { height: 1px; background-color: var(--panel-border); border: none; margin: 10px 0; }
          .stats-grid-secondary { font-size: 0.8rem; background-color: transparent; border: none; padding: 0; display: grid; grid-template-columns: auto 1fr; gap: 3px 10px; margin-top: 10px; } .stats-grid-secondary strong { color: var(--dbz-grey); text-align: left; font-weight: 500;} .stats-grid-secondary span { color: var(--dbz-light-grey); text-align: right; font-weight: normal;} .left-column-bottom-buttons { margin-top: auto; padding-top: 15px; display: flex; flex-direction: column; gap: 10px; border-top: 1px solid var(--panel-border); flex-shrink: 0; } .action-button { display: block; width: 100%; padding: 10px 15px; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 500; text-decoration: none; text-align: center; cursor: pointer; transition: all 0.2s ease; } .action-button:hover { transform: translateY(-1px) scale(1.01); filter: brightness(1.1);} .btn-perfil { background-color: var(--accent-color-1); color: #111; } .btn-logout { background-color: var(--danger-color); color: #fff; }
          .coluna-central { flex: 1; min-width: 0; display: flex; flex-direction: column; text-align: left; height: calc(100vh - 40px); max-height: 850px; gap: 15px; } .coluna-central > h2 { font-family: var(--dbz-font); color: var(--dbz-orange); font-size: 1.8em; text-align: center; margin-bottom: 15px; text-transform: uppercase; letter-spacing: 1px; text-shadow: 1px 1px 3px rgba(0,0,0,0.5); padding-bottom: 8px; border-bottom: 1px solid var(--dbz-orange-dark); flex-shrink: 0; } .main-content-scrollable { flex-grow: 1; overflow-y: auto; min-height: 0; padding: 0 10px 10px 5px ; scrollbar-width: thin; scrollbar-color: var(--dbz-orange-dark) transparent; } .main-content-scrollable::-webkit-scrollbar { width: 6px; } .main-content-scrollable::-webkit-scrollbar-track { background: transparent; } .main-content-scrollable::-webkit-scrollbar-thumb { background-color: var(--dbz-orange-dark); border-radius: 3px; } .main-content-scrollable::-webkit-scrollbar-thumb:hover { background-color: var(--dbz-orange); }
          .main-content-scrollable h3 { font-family: var(--dbz-font); color: var(--dbz-orange); font-size: 1.4em; text-align: left; margin-top: 20px; margin-bottom: 15px; border-bottom: 1px solid rgba(245, 166, 35, 0.5); padding-bottom: 8px; } .main-content-scrollable p { color: var(--dbz-light-grey); line-height: 1.6; margin-bottom: 15px; text-align: left; font-size: 0.95rem; } .kame-image-container { text-align: center; margin-bottom: 10px; } .kame-image-container img { max-width: 180px; border-radius: 50%; border: 4px solid var(--dbz-orange-dark); box-shadow: var(--shadow); } .kame-form { display: flex; flex-direction: column; align-items: center; gap: 15px; margin-top: 10px; } .kame-form select { width: 100%; max-width: 450px; padding: 10px 14px; border-radius: 4px; border: 1px solid var(--dbz-blue); background-color: rgba(0,0,0,0.3); color: var(--dbz-white); font-size: 0.95rem; cursor: pointer; } .kame-form select option { background-color: var(--dbz-dark-grey); color: var(--dbz-light-grey); } .kame-form select option:disabled { color: var(--dbz-grey); font-style: italic; } .kame-form select:focus { outline: none; border-color: var(--dbz-orange); box-shadow: 0 0 8px rgba(245, 166, 35, 0.5); }
          .lista-habilidades { list-style: none; padding: 0; width: 100%; max-width: 500px; margin: 10px auto 0 auto; max-height: 250px; overflow-y: auto; border: 1px solid var(--dbz-blue); border-radius: 6px; background-color: rgba(0,0,0,0.1); padding: 10px; scrollbar-width: thin; scrollbar-color: var(--success-color) transparent; } .lista-habilidades::-webkit-scrollbar { width: 6px; } .lista-habilidades::-webkit-scrollbar-track { background: rgba(0,0,0,0.1); } .lista-habilidades::-webkit-scrollbar-thumb { background-color: var(--success-color); border-radius: 3px; } .lista-habilidades::-webkit-scrollbar-thumb:hover { background-color: #00c853; } .lista-habilidades li { background-color: rgba(0, 230, 118, 0.1); border: 1px solid rgba(0, 230, 118, 0.3); padding: 10px 15px; border-radius: 4px; margin-bottom: 8px; font-size: 0.95rem; color: var(--success-color); font-weight: bold; text-align: center; font-family: var(--dbz-font); letter-spacing: 0.5px; } .lista-habilidades li:last-child { margin-bottom: 0; } .kame-texto-info { text-align: center; color: var(--dbz-grey); margin-top: 15px; font-style: italic; font-size: 0.9rem;}
          .coluna-direita { width: 25%; min-width: 250px; padding: 15px; display: flex; flex-direction: column; gap: 8px; } .nav-button { display: grid; grid-template-columns: 45px 1fr; align-items: center; gap: 12px; padding: 10px 12px 10px 8px; background: linear-gradient(to bottom, rgba(55, 62, 76, 0.85), rgba(40, 44, 52, 0.85)); color: var(--text-secondary); text-decoration: none; border-radius: 8px; border: 1px solid var(--panel-border); font-size: 0.85rem; font-weight: 500; transition: all 0.2s ease; position: relative; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.2); cursor: pointer; } .nav-button-icon-area { grid-column: 1 / 2; display: flex; justify-content: center; align-items: center; width: 100%; height: 28px; } .nav-button-icon { width: 28px; height: 28px; object-fit: contain; opacity: 0.7; transition: opacity 0.2s ease, transform 0.2s ease; display: block; margin: 0; flex-shrink: 0;} .nav-button i.fas { font-size: 22px; opacity: 0.7; transition: opacity 0.2s ease, transform 0.2s ease; color: inherit; } .nav-button span { grid-column: 2 / 3; text-align: left; } .nav-button:hover { background: linear-gradient(to bottom, rgba(65, 72, 86, 0.95), rgba(50, 54, 62, 0.95)); border-color: rgba(255, 255, 255, 0.3); color: #fff; transform: translateY(-2px) scale(1.01); box-shadow: 0 5px 10px rgba(0,0,0,0.3), 0 0 8px rgba(41, 121, 255, 0.4); } .nav-button:hover .nav-button-icon, .nav-button:hover i.fas { opacity: 1; transform: scale(1.05); } .nav-button:active { transform: translateY(0px) scale(1.0); box-shadow: 0 2px 4px rgba(0,0,0,0.2); filter: brightness(0.95); } .nav-button.active { background: linear-gradient(to bottom, var(--dbz-orange), var(--dbz-orange-dark)); color: var(--dbz-blue); border-color: var(--dbz-orange); font-weight: 700; box-shadow: 0 3px 6px rgba(0,0,0,0.3), inset 0 1px 1px rgba(255,255,255,0.2); } .nav-button.active .nav-button-icon, .nav-button.active i.fas { opacity: 1; filter: none; } .nav-button.active:hover { filter: brightness(1.1); box-shadow: 0 5px 10px rgba(0,0,0,0.3), 0 0 8px var(--dbz-orange); } .nav-button.disabled { background: var(--disabled-color) !important; color: #8894a8 !important; cursor: not-allowed !important; border-color: rgba(0, 0, 0, 0.2) !important; opacity: 0.6 !important; box-shadow: none !important; } .nav-button.disabled:hover { background: var(--disabled-color) !important; transform: none !important; border-color: rgba(0, 0, 0, 0.2) !important; color: #8894a8 !important; box-shadow: none !important; } .nav-button.disabled .nav-button-icon, .nav-button.disabled i.fas { filter: grayscale(100%); opacity: 0.5; } .nav-button.logout:hover { background: linear-gradient(to bottom, var(--danger-color), #c0392b) !important; border-color: rgba(255, 255, 255, 0.2) !important; box-shadow: 0 5px 10px rgba(0,0,0,0.3), 0 0 8px var(--danger-color) !important; } .nav-button.logout span { grid-column: 2 / 3; } .nav-button.logout .nav-button-icon-area { grid-column: 1 / 2; }
          #bottom-nav { display: none; } @media (max-width: 768px) { #bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; right: 0; background-color: rgba(20, 23, 31, 0.97); border-top: 1px solid var(--dbz-orange); padding: 2px 0; box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.35); justify-content: space-around; align-items: stretch; z-index: 1000; backdrop-filter: blur(6px); height: 60px; } .bottom-nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; text-decoration: none; color: var(--dbz-grey); padding: 4px 2px; font-size: 0.65rem; text-align: center; transition: background-color 0.2s ease, color 0.2s ease; cursor: pointer; border-radius: 5px; margin: 2px; line-height: 1.2; font-family: var(--dbz-font); } .bottom-nav-item:not(.active):not(.disabled):hover { background-color: rgba(245, 166, 35, 0.1); color: var(--dbz-orange); } .bottom-nav-item.active { color: var(--dbz-orange); font-weight: bold; background-color: rgba(245, 166, 35, 0.2); } .bottom-nav-icon { font-size: 1.5rem; line-height: 1; margin-bottom: 4px; } .bottom-nav-label { font-size: inherit; } .bottom-nav-item.disabled { cursor: not-allowed; opacity: 0.5; color: #6a7383 !important; background-color: transparent !important; } .bottom-nav-item.disabled:hover { color: #6a7383 !important; } } @media (max-width: 480px) { #bottom-nav { height: 55px; } .bottom-nav-item .bottom-nav-icon { font-size: 1.3rem; } .bottom-nav-item .bottom-nav-label { font-size: 0.6rem; } }
          @media (max-width: 1200px) { body { padding: 15px; } .container.page-container { flex-direction: column; align-items: center; width: 100%; max-width: 95%; padding: 0; gap: 15px; } .coluna-esquerda, .coluna-central, .coluna-direita { flex-basis: auto !important; width: 100% !important; max-width: 800px; margin: 0 auto; min-width: unset; margin-bottom: 15px; height: auto !important; max-height: none !important; } .coluna-direita { margin-bottom: 0; padding: 15px !important; background-color: var(--panel-bg) !important; } .coluna-central { order: 1; } .coluna-esquerda { order: 2; } .coluna-direita { order: 3; } }
          @media (max-width: 768px) { body { padding: 10px; padding-bottom: 75px; } .coluna-esquerda { display: none !important; } .coluna-direita { display: none; } .coluna-central { max-width: 100%; width: 100%; } .coluna { padding: 15px; } .feedback-container { font-size: 0.9rem; padding: 10px 15px; margin: 0 auto 10px auto; } }
          @media (max-width: 480px) { body { padding: 8px; padding-bottom: 70px; } .container.page-container { gap: 10px; } .coluna { padding: 12px; margin-bottom: 10px; } .coluna-central > h2 { font-size: 1.5em; } .main-content-scrollable h3 { font-size: 1.2em; } .feedback-container { font-size: 0.85rem; padding: 8px 12px; max-width: calc(100% - 16px); } }
          /* --- CSS do Modal Popup (Inalterado) --- */
          .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); display: flex; justify-content: center; align-items: center; z-index: 1100; opacity: 0; visibility: hidden; transition: opacity 0.3s ease, visibility 0s linear 0.3s; } .modal-overlay.visible { opacity: 1; visibility: visible; transition: opacity 0.3s ease; } .modal-popup { background-color: var(--panel-bg, #1e2129); padding: 25px 30px; border-radius: var(--border-radius, 10px); border: 1px solid var(--panel-border, rgba(255, 255, 255, 0.15)); box-shadow: var(--shadow, 0 8px 25px rgba(0, 0, 0, 0.5)); width: 90%; max-width: 450px; text-align: center; position: relative; transform: scale(0.9); transition: transform 0.3s ease; } .modal-overlay.visible .modal-popup { transform: scale(1); } .modal-popup h3 { color: var(--dbz-orange, #f5a623); font-family: var(--dbz-font, 'Roboto', sans-serif); margin-top: 0; margin-bottom: 15px; font-size: 1.4em; border-bottom: 1px solid var(--dbz-orange-dark, #e47d1e); padding-bottom: 10px; } .modal-popup #popup-message { color: var(--text-primary, #e8eaed); font-size: 1rem; line-height: 1.6; margin-bottom: 25px; min-height: 40px; word-wrap: break-word; } .modal-popup #popup-actions { display: flex; justify-content: center; gap: 15px; flex-wrap: wrap; } .modal-popup .popup-action-btn { display: inline-block; padding: 10px 20px; border: none; border-radius: 6px; font-size: 0.9rem; font-weight: 500; text-decoration: none; text-align: center; cursor: pointer; transition: all 0.2s ease; min-width: 100px; } .popup-action-btn.btn-ok { background-color: var(--accent-color-3, #2979ff); color: #fff; } .popup-action-btn.btn-accept { background-color: var(--accent-color-1, #00e676); color: #111; } .popup-action-btn.btn-reject { background-color: var(--danger-color, #ff5252); color: #fff; } .popup-action-btn:hover { transform: translateY(-2px); filter: brightness(1.1); } .modal-close-btn { position: absolute; top: 10px; right: 15px; background: none; border: none; font-size: 1.8em; color: var(--text-secondary, #bdc1c6); cursor: pointer; line-height: 1; padding: 0; opacity: 0.7; } .modal-close-btn:hover { color: var(--text-primary, #e8eaed); opacity: 1; }
         /* <<< FIM CSS >>> */
    </style>
</head>
<body>

    <?php if (!empty($mensagem_final_feedback)): ?>
        <div class="feedback-container">
             <?php
                // Limpa tags span antes de exibir para evitar duplicação ou conflito
                $cleaned_message = preg_replace('/<span class=[\'"][^\'"]*[\'"]>(.*?)<\/span>/i', '$1', $mensagem_final_feedback);
                // Determina a classe baseada no conteúdo original da mensagem
                $msg_class = 'info-message'; // Default
                if (strpos($mensagem_final_feedback, 'error-message') !== false) $msg_class = 'error-message';
                elseif (strpos($mensagem_final_feedback, 'success-message') !== false) $msg_class = 'success-message';
                elseif (strpos($mensagem_final_feedback, 'warning-message') !== false) $msg_class = 'warning-message';
                elseif (strpos($mensagem_final_feedback, 'levelup-message') !== false) $msg_class = 'success-message'; // Level up como sucesso

                // Exibe a mensagem limpa dentro de um novo span com a classe correta
                echo '<span class="' . $msg_class . '">' . nl2br(htmlspecialchars(strip_tags($cleaned_message))) . '</span>';
             ?>
        </div>
    <?php endif; ?>

    <div class="container page-container">

        <?php // --- COLUNA ESQUERDA (SEM NOTIFICAÇÕES) --- ?>
        <div class="coluna coluna-esquerda">
            <div class="player-card">
                <img class="foto" src="<?= htmlspecialchars($caminho_web_foto) ?>" alt="Foto de perfil">
                <div class="player-name"><?= $nome_formatado_usuario ?></div>
                <div class="player-level">Nível <?= htmlspecialchars($usuario['nivel'] ?? '?') ?></div>
                <div class="player-race">Raça: <?= htmlspecialchars($usuario['raca'] ?? 'N/D') ?></div>
                <div class="player-rank">Título: <?= htmlspecialchars($usuario['ranking_titulo'] ?? 'Iniciante') ?></div>
            </div>
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
             <div class="zeni-display">💰 Zeni: <?= is_numeric($usuario['zeni'] ?? null) ? formatNumber($usuario['zeni']) : '0' ?></div>
             <div class="xp-bar-container">
                <span class="xp-label">Experiência</span>
                <div class="xp-bar">
                    <div class="xp-bar-fill" style="width: <?= $xp_percent ?>%;"></div>
                    <div class="xp-text"><?= formatNumber($usuario['xp'] ?? 0) ?> / <?= $xp_necessario_display ?></div>
                </div>
            </div>
             <?php $pontos_num_check = filter_var($usuario['pontos'] ?? 0, FILTER_VALIDATE_INT); if ($pontos_num_check !== false && $pontos_num_check > 0): ?>
                <a href="perfil.php" class="btn-pontos action-button">
                    <?= formatNumber($pontos_num_check) ?> Pontos! <span style="font-size: 0.8em;">(Distribuir)</span></a>
             <?php endif; ?>
             <div class="stats-grid-secondary">
                <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span>
                <strong>Tempo Sala</strong><span><?= formatNumber($usuario['tempo_sala'] ?? 0) ?> min</span>
            </div>
             <div class="divider"></div>
             <div class="left-column-bottom-buttons">
                <a class="action-button btn-perfil" href="perfil.php">Editar Perfil</a>
                <a class="action-button btn-logout" href="sair.php">Sair (Logout)</a>
            </div>
        </div>

        <?php // --- COLUNA CENTRAL --- ?>
        <div class="coluna coluna-central">
            <h2>Casa do Mestre Kame</h2>
             <div class="main-content-scrollable">
                 <div class="kame-image-container">
                    <img src="img/kame.jpg" alt="Mestre Kame" class="kame-image">
                 </div>
                 <p>Yo! Parece que você tem potencial, hein? Mas só músculos não bastam! Para aprender minhas técnicas lendárias, você precisa mostrar dedicação e treinamento árduo na Sala do Templo.</p>

                 <h3>Habilidades Disponíveis para Aprender</h3>
                 <?php if (!empty($habilidades_disponiveis)): ?>
                    <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="kame-form">
                        <select name="habilidade_id" required>
                            <option value="">-- Selecione --</option>
                            <?php foreach ($habilidades_disponiveis as $hab): ?>
                                <?php
                                    $req_nivel_ok = ($nivel_kame >= $hab['nivel_requerido']);
                                    $req_tempo_ok = ($tempo_sala_kame >= $hab['tempo_requerido']);
                                    $pode_aprender = $req_nivel_ok && $req_tempo_ok;
                                    $req_parts = [];
                                    if ($hab['nivel_requerido'] > 0) $req_parts[] = "Nv ". $hab['nivel_requerido'];
                                    if ($hab['tempo_requerido'] > 0) $req_parts[] = "Tempo ".$hab['tempo_requerido']."m";
                                    if ($hab['raca_requerida']) $req_parts[] = "Raça: ".htmlspecialchars($hab['raca_requerida']);
                                    if (isset($hab['ki']) && $hab['ki'] > 0) $req_parts[] = "Ki: ".$hab['ki'];
                                    $req_text = !empty($req_parts) ? "(Req: " . implode(', ', $req_parts) . ")" : "";
                                    $disabled_attr = !$pode_aprender ? 'disabled' : '';
                                    $option_text = htmlspecialchars($hab['nome']) . " " . htmlspecialchars($req_text);
                                    if (!$pode_aprender) {
                                        $motivo_bloqueio = [];
                                        if (!$req_nivel_ok) $motivo_bloqueio[] = 'Nível';
                                        if (!$req_tempo_ok) $motivo_bloqueio[] = 'Tempo';
                                        $option_text .= ' (' . implode('/', $motivo_bloqueio) . ' insuficiente)';
                                    }
                                ?>
                                <option value="<?= $hab['id'] ?>" <?= $disabled_attr ?>><?= $option_text ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" name="aprender" class="action-button btn-accept" style="max-width: 250px; background-color: var(--accent-color-1); color: #111;">Aprender Habilidade</button>
                    </form>
                <?php else: ?>
                    <p class="kame-texto-info">Hohoho! Parece que você já aprendeu tudo que eu tinha para ensinar por enquanto, ou não cumpre os requisitos para as demais técnicas.</p>
                <?php endif; ?>

                 <h3>Habilidades Aprendidas</h3>
                 <?php if (!empty($habilidades_aprendidas_nomes)): ?>
                    <ul class="lista-habilidades">
                        <?php foreach ($habilidades_aprendidas_nomes as $h_nome): ?>
                            <li><?= htmlspecialchars($h_nome) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="kame-texto-info">Nenhuma habilidade aprendida ainda. Continue treinando!</p>
                <?php endif; ?>
            </div>
        </div>

        <?php // --- COLUNA DIREITA --- ?>
        <div class="coluna coluna-direita">
            <?php
                // Reutiliza $sidebarItems definido anteriormente no PHP
                $currentPageBase = basename($_SERVER['PHP_SELF']); // Pega 'kame.php'

                foreach ($sidebarItems as $item) {
                    $href = $item['href'];
                    $label = htmlspecialchars($item['label']);
                    $iconPath = $item['icon'] ?? ''; // Caminho da imagem (relativo à raiz)
                    $faIconClass = $item['fa_icon'] ?? 'fas fa-question-circle'; // Classe Font Awesome
                    $idItem = $item['id'] ?? null;
                    $isDisabled = $item['disabled'] ?? false;
                    $title = htmlspecialchars($item['title'] ?? $item['label']);
                    $classe_extra = $item['extra_class'] ?? '';
                    $atributos_extra = '';

                    // Determina ícone (Imagem se existir no caminho físico, senão FA)
                    $iconHtml = "<i class='{$faIconClass} nav-button-icon'></i>"; // Default FA
                    $iconFullPathFisico = '';
                    if (!empty($iconPath)) {
                        // Constrói caminho físico a partir de kame.php (assumindo que está na raiz)
                        $iconFullPathFisico = __DIR__ . '/' . $iconPath; // Ex: /path/to/kame/./img/praca.jpg
                        if (file_exists($iconFullPathFisico)) {
                            // Caminho web (relativo à raiz)
                            $iconHtml = '<img src="' . htmlspecialchars($iconPath) . '" class="nav-button-icon" alt="">';
                        } else {
                             error_log("Icone nav D (img) não encontrado em kame.php: " . $iconFullPathFisico . " - Usando FA: " . $faIconClass);
                         }
                    }

                    // Lógica específica para Sala do Templo (mantida do original kame.php)
                    if ($idItem === 'sala_tempo') {
                        if (!$pode_entrar_templo_base) {
                            $classe_extra .= ' disabled';
                            $atributos_extra = ' onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES).'\'); return false;"';
                            $title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                            $href = '#'; // Desabilita o link
                        } else {
                            $title = 'Entrar na Sala do Templo';
                        }
                    }

                    // Verifica se é a página atual
                    $isCurrent = ($currentPageBase == basename($href));

                    if ($isCurrent && !$isDisabled && strpos($classe_extra, 'disabled') === false) { // Garante que não está desabilitado por outra razão
                         $classe_extra .= ' active';
                    } elseif ($isDisabled) { // Aplica disabled se $item['disabled'] for true
                        $classe_extra .= ' disabled';
                        $href = '#';
                        $atributos_extra = ' onclick="return false;"';
                    }


                    $finalHref = htmlspecialchars($href);
                    echo "<a class=\"nav-button {$classe_extra}\" href=\"{$finalHref}\" title=\"{$title}\" {$atributos_extra}>";
                    echo "<div class='nav-button-icon-area'>{$iconHtml}</div>";
                    echo "<span>{$label}</span>";
                    echo "</a>";
                }

                // Botão Sair separado (Caminho relativo à raiz)
                $sairIconPathFisico = __DIR__ . '/img/sair.jpg';
                $sairIconHtml = '<i class="fas fa-sign-out-alt nav-button-icon"></i>'; // Fallback FA
                if(file_exists($sairIconPathFisico)) {
                     $sairIconHtml = '<img src="img/sair.jpg" class="nav-button-icon" alt="Sair">';
                }
                echo '<a class="nav-button logout" href="sair.php" title="Sair do Jogo">';
                echo "<div class='nav-button-icon-area'>{$sairIconHtml}</div>";
                echo '<span>Sair</span></a>';
            ?>
        </div> </div> <?php // Fim Container ?>

    <?php // --- Bottom Nav --- ?>
    <nav id="bottom-nav">
        <?php
            // Reutiliza $bottomNavItems definido antes
            foreach ($bottomNavItems as $item):
                $url_destino_item = $item['href'];
                $itemIdNav = $item['id'] ?? null;
                $labelNav = htmlspecialchars($item['label']);
                $iconNav = $item['icon'];
                $final_href = '#';
                $final_onclick = '';
                $final_title = '';
                $extra_class = '';
                $active_class = '';

                $is_current_page = ($itemIdNav === 'kame'); // Verifica se é a página KAME

                // Lógica específica para Templo (mantida do original)
                if ($itemIdNav === 'sala_tempo') {
                    if (!$pode_entrar_templo_base) {
                        $final_onclick = ' onclick="alert(\'' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '\'); return false;"';
                        $final_title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                        $extra_class = ' disabled';
                        $final_href = '#';
                    } else {
                        $final_href = htmlspecialchars($url_destino_item);
                        $final_title = 'Entrar na Sala do Templo';
                    }
                } else {
                    $final_href = htmlspecialchars($url_destino_item);
                    $final_title = 'Ir para ' . $labelNav;
                }

                // Aplica classe ativa se for a página atual E não estiver desabilitado
                if ($is_current_page && strpos($extra_class, 'disabled') === false) {
                    $active_class = ' active';
                }

                echo "<a href='{$final_href}' class='bottom-nav-item{$active_class}{$extra_class}' {$final_onclick} title='{$final_title}'";
                // Adiciona data-url se for o templo e puder entrar (mantido)
                if($itemIdNav === 'sala_tempo' && $pode_entrar_templo_base) {
                    echo " data-url='" . htmlspecialchars($url_destino_item) . "'";
                }
                echo ">";
                // Verifica se ícone é FA ou Emoji
                if (strpos($iconNav, 'fa-') !== false) {
                    echo "<span class='bottom-nav-icon'><i class='fas {$iconNav}'></i></span>";
                } else {
                    echo "<span class='bottom-nav-icon'>{$iconNav}</span>";
                }
                echo "<span class='bottom-nav-label'>" . $labelNav . "</span>";
                echo "</a>";
            endforeach;
        ?>
    </nav>

    <?php // --- HTML do Modal Popup (Inalterado) --- ?>
    <div id="notification-popup-overlay" class="modal-overlay">
        <div class="modal-popup">
            <button class="modal-close-btn" onclick="markCurrentNotificationAsRead()" title="Marcar como lida">&times;</button>
            <h3>Nova Notificação</h3>
            <div id="popup-message">Carregando mensagem...</div>
            <div id="popup-actions"></div>
        </div>
    </div>


    <?php // --- JavaScript Essencial (Popup com Handler Grupo, Fusão, ARENA e TRANSFERÊNCIA) --- ?>
    <script>
        // === Variáveis Globais ===
        const currentUserId = <?php echo json_encode((int)$id); ?>;
        const unreadNotifications = <?php echo json_encode($unread_notifications_for_popup); ?>;
        let currentNotification = null;

        // === Elementos do DOM ===
        const modalOverlay = document.getElementById('notification-popup-overlay');
        const popupMessage = document.getElementById('popup-message');
        const popupActions = document.getElementById('popup-actions');

        // === Funções do Modal ===
        function showModal() { if (modalOverlay) modalOverlay.classList.add('visible'); }
        function hideModal() { if (modalOverlay) { modalOverlay.classList.remove('visible'); if(popupMessage) popupMessage.innerHTML = ''; if(popupActions) popupActions.innerHTML = ''; currentNotification = null; } }
        function markCurrentNotificationAsRead() { if (currentNotification && currentNotification.id) { window.location.href = `kame.php?mark_read=${currentNotification.id}`; hideModal(); } else { hideModal(); } }

        // === Lógica Principal Popup (ATUALIZADA PARA GRUPO, FUSÃO, ARENA e TRANSFERÊNCIA) ===
        function displayNextNotification() {
             if (!modalOverlay || !popupMessage || !popupActions) { console.error("Elementos do modal não encontrados."); return; }

             if (unreadNotifications && unreadNotifications.length > 0) {
                 currentNotification = unreadNotifications[0];
                 if (!currentNotification) { hideModal(); return; }

                 popupMessage.textContent = currentNotification.message_text || 'Mensagem indisponível.';
                 popupActions.innerHTML = ''; // Limpa ações anteriores

                 const markReadLink = `kame.php?mark_read=${currentNotification.id}`;
                 let hasSpecificAction = false;
                 let relatedData = {};

                 // Tenta parsear related_data
                 if (currentNotification.related_data) {
                     if (typeof currentNotification.related_data === 'string') {
                         try { relatedData = JSON.parse(currentNotification.related_data); }
                         catch(e) { console.error("Erro JSON related_data:", e, "Data:", currentNotification.related_data); relatedData = {}; }
                     } else if (typeof currentNotification.related_data === 'object') {
                         relatedData = currentNotification.related_data;
                     }
                 }

                 // --- Define Ações com base no TIPO ---
                 if (currentNotification.type === 'invite') { // Convite de GRUPO
                     const groupId = relatedData.group_id;
                     if (groupId) {
                         const acceptLink = `kame.php?invite_action=accept&notif_id=${currentNotification.id}`;
                         const rejectLink = `kame.php?invite_action=reject&notif_id=${currentNotification.id}`;
                         popupActions.innerHTML = `<a href="${acceptLink}" class="popup-action-btn btn-accept">Aceitar Grupo</a><a href="${rejectLink}" class="popup-action-btn btn-reject">Recusar</a>`;
                         hasSpecificAction = true;
                     } else { console.warn("Dados incompletos (group_id) na notificação 'invite':", currentNotification); }
                 }
                 else if (currentNotification.type === 'fusion_invite') { // Convite de FUSÃO
                     const inviterId = relatedData.inviter_id;
                     const desafioId = relatedData.desafio_id; // Assume que está nos dados
                     if (inviterId && desafioId) {
                         const acceptLink = `kame.php?fusion_action=accept&notif_id=${currentNotification.id}`; // Ação vai para kame.php
                         const rejectLink = `kame.php?fusion_action=reject&notif_id=${currentNotification.id}`; // Ação vai para kame.php
                         popupActions.innerHTML = `<a href="${acceptLink}" class="popup-action-btn btn-accept">Aceitar Fusão</a><a href="${rejectLink}" class="popup-action-btn btn-reject">Recusar</a>`;
                         hasSpecificAction = true;
                     } else { console.warn("Dados incompletos (inviter_id/desafio_id) na notificação 'fusion_invite':", currentNotification); }
                 }
                 // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
                 // +++ Lida com Notificação de Início de Arena              +++
                 // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
                 else if (currentNotification.type === 'arena_start') { // Notificação ARENA START
                     const eventId = relatedData.event_id;
                     if (eventId) {
                         // Link que vai para o handler PHP em kame.php
                         const enterArenaLink = `kame.php?arena_action=enter&notif_id=${currentNotification.id}&event_id=${eventId}`;
                         // Adiciona botão para entrar E botão para só marcar como lida
                         popupActions.innerHTML = `
                             <a href="${enterArenaLink}" class="popup-action-btn btn-accept">Entrar na Arena</a>
                             <a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`;
                         hasSpecificAction = true;
                     } else { console.warn("Dados incompletos (event_id) na notificação 'arena_start':", currentNotification); }
                 }
                 // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
                 // +++ NOVO: Lida com Notificação de Transferência de Zeni   +++
                 // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
                 else if (currentNotification.type === 'zeni_transfer_received') { // Notificação de Zeni recebido
                     // É apenas informativa, então só precisa do botão OK
                     popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`;
                     hasSpecificAction = true; // Indica que tratamos esse tipo
                 }
                 // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++
                 // +++ FIM: Lida com Notificação de Transferência de Zeni    +++
                 // +++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++

                 // --- Fim Tipos Específicos ---

                 // Botão "OK" genérico se nenhuma ação específica foi gerada OU se faltaram dados
                 if (!hasSpecificAction) {
                      popupActions.innerHTML = `<a href="${markReadLink}" class="popup-action-btn btn-ok">OK (Lida)</a>`;
                 }

                 showModal();
             } else {
                 hideModal(); // Nenhuma notificação não lida
             }
        } // Fim displayNextNotification

        // === Inicialização Geral ===
        document.addEventListener('DOMContentLoaded', () => {
            // Lógica Bottom Nav (igual anterior)
             const bottomNav = document.getElementById('bottom-nav'); const rightColumn = document.querySelector('.coluna-direita'); function checkBottomNavVisibility() { if (!bottomNav || !rightColumn) return; const bS = window.getComputedStyle(document.body); const pVal = bS.getPropertyValue('--body-original-padding-bottom').trim() || '20px'; if (window.getComputedStyle(rightColumn).display === 'none') { bottomNav.style.display = 'flex'; document.body.style.paddingBottom = (bottomNav.offsetHeight + 15) + 'px'; } else { bottomNav.style.display = 'none'; document.body.style.paddingBottom = pVal; } } const initPad = window.getComputedStyle(document.body).paddingBottom; document.body.style.setProperty('--body-original-padding-bottom', initPad); checkBottomNavVisibility(); window.addEventListener('resize', checkBottomNavVisibility);

            // Exibe a primeira notificação não lida (se houver) - AGORA INCLUI ARENA e TRANSFERÊNCIA
            displayNextNotification();

            // Listener para fechar modal clicando fora (igual anterior)
             if (modalOverlay) { modalOverlay.addEventListener('click', function(event) { if (event.target === modalOverlay) { markCurrentNotificationAsRead(); } }); }

             // Opcional: Adicionar um listener para fechar o feedback após alguns segundos
             const feedbackBox = document.querySelector('.feedback-container');
             if (feedbackBox && feedbackBox.style.display !== 'none') {
                 setTimeout(() => {
                     feedbackBox.style.opacity = '0';
                     feedbackBox.style.transition = 'opacity 0.5s ease-out';
                     setTimeout(() => { feedbackBox.style.display = 'none'; }, 500);
                 }, 6000); // Fecha após 6 segundos
             }
        });
    </script>

</body>
</html>
<?php
// Garante que a conexão seja fechada no final do script
if (isset($conn) && $conn instanceof PDO) {
    $conn = null;
}
?>