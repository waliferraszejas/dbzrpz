<?php
// Arquivo: desafios.php (PADRÃO DBZ - CSS Embutido - CORRIGIDO)

// --- CONFIGURAÇÃO DE ERROS ---
ini_set('display_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error_desafios.log');

if (session_status() == PHP_SESSION_NONE) { session_start(); }

// Define o tipo de conteúdo ANTES de qualquer output HTML
header('Content-Type: text/html; charset=UTF-8');

// --- CONEXÃO E FUNÇÕES ---
try {
    require_once("script/conexao.php"); // Assume na raiz
    require_once('script/sala_functions.php'); // Assume na raiz
    if (!isset($conn) || !($conn instanceof PDO)) { throw new RuntimeException("A conexão PDO não foi estabelecida corretamente."); }
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    error_log("Erro CRÍTICO (inicial) em " . basename(__FILE__) . ": " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    // Página de erro genérica
    ?> <!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"><title>Erro</title><style>body{font-family: sans-serif; text-align: center; padding: 40px; color: #c00; background-color:#333;} div{border: 1px solid #c00; padding: 20px; display: inline-block; background-color:#fff;}</style></head><body><div><h1>Erro ao Carregar</h1><p>Não foi possível iniciar a aplicação.</p><p>Tente novamente mais tarde.</p><?php if(ini_get('display_errors') == 1):?><p><small>Detalhes: <?= htmlspecialchars($e->getMessage()) ?></small></p><?php endif; ?></div></body></html> <?php
    exit;
}

// --- Verifica Autenticação ---
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); } // Assume login na raiz
$usuario_id = (int)$_SESSION['user_id'];
$id = $usuario_id;

// --- Variáveis e Busca de Dados Essenciais ---
$usuario = null;
$initial_notifications = [];
$active_battles = [];
$mensagem_final_feedback = $_SESSION['feedback_geral'] ?? ''; unset($_SESSION['feedback_geral']);
$nome_formatado = 'Usuário'; $foto_path = 'uploads/default.jpg'; $rank_nivel = 'N/A'; $rank_sala = 'N/A'; $notification_limit = 20;

// Constantes
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);

// Função Level Up
if (!function_exists('verificarLevelUpHome')) { function verificarLevelUpHome(PDO $pdo_conn, int $id_usuario): array { /* ... Função completa ... */ return []; } }

try {
    // Busca dados do usuário
    $sql_usuario = "SELECT id, nome, nivel, hp, pontos, xp, ki, forca, defesa, velocidade, foto, tempo_sala, raca, zeni, ranking_titulo FROM usuarios WHERE id = :id_usuario";
    $stmt_usuario = $conn->prepare($sql_usuario); $stmt_usuario->execute([':id_usuario' => $usuario_id]); $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC); $stmt_usuario->closeCursor();
    if (!$usuario) { session_destroy(); header("Location: login.php?erro=dados_nao_encontrados"); exit(); }

    // Level Up Check
    $mensagens_lup = verificarLevelUpHome($conn, $id); if (!empty($mensagens_lup)) { $mensagem_final_feedback .= ($mensagem_final_feedback ? "<br>" : "") . implode("<br>", $mensagens_lup); /* Recarregar $usuario opcional */ }

    // Templo Button Logic
    $hp_atual_base = (int)($usuario['hp'] ?? 0); $ki_atual_base = (int)($usuario['ki'] ?? 0); $pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0); $mensagem_bloqueio_templo_base = 'Recupere HP/Ki base!';

    // Ranks
    try { $sql_rank_nivel = "SELECT rank_nivel FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC) as rank_nivel FROM usuarios) AS ranked_by_level WHERE id = :user_id"; $stmt_rank_nivel = $conn->prepare($sql_rank_nivel); $stmt_rank_nivel->execute([':user_id' => $id]); $result_nivel = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC); if ($result_nivel) $rank_nivel = $result_nivel['rank_nivel']; $sql_rank_sala = "SELECT rank_sala FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala FROM usuarios) AS ranked_by_time WHERE id = :user_id"; $stmt_rank_sala = $conn->prepare($sql_rank_sala); $stmt_rank_sala->execute([':user_id' => $id]); $result_sala = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC); if ($result_sala) $rank_sala = $result_sala['rank_sala']; } catch (PDOException $e_rank) { error_log("PDOException ranks user {$id} (".basename(__FILE__)."): " . $e_rank->getMessage()); }

    // Notificações
     $sql_notif_init = "SELECT id, message_text, type, is_read, timestamp, related_data FROM user_notifications WHERE user_id = :user_id ORDER BY timestamp DESC LIMIT :limit_notif"; $stmt_notif_init = $conn->prepare($sql_notif_init); $notif_params = [':user_id' => $id, ':limit_notif' => $notification_limit]; if ($stmt_notif_init && $stmt_notif_init->execute($notif_params)) { $initial_notifications = $stmt_notif_init->fetchAll(PDO::FETCH_ASSOC); } else { error_log("Erro PDO user {$id} (Notif Init Fetch - ".basename(__FILE__)."): " . print_r($conn->errorInfo() ?: ($stmt_notif_init ? $stmt_notif_init->errorInfo() : 'Prepare Failed'), true)); $initial_notifications = []; }

    // Busca Batalhas Ativas
    $sql_active_battles = "SELECT b.id, b.desafiante_id, b.desafiado_id, u1.nome as desafiante_nome, u2.nome as desafiado_nome FROM batalhas_arena b LEFT JOIN usuarios u1 ON b.desafiante_id = u1.id LEFT JOIN usuarios u2 ON b.desafiado_id = u2.id WHERE b.status = 'ativa' AND (b.desafiante_id = :userId1 OR b.desafiado_id = :userId2) ORDER BY b.data_inicio DESC";
    $stmt_active_battles = $conn->prepare($sql_active_battles); $stmt_active_battles->execute([':userId1' => $usuario_id, ':userId2' => $usuario_id]); $active_battles = $stmt_active_battles->fetchAll(PDO::FETCH_ASSOC); $stmt_active_battles->closeCursor();

} catch (PDOException | Exception $e) {
    error_log("Erro GERAL/PDO ao buscar dados essenciais/batalhas em " . basename(__FILE__) . " para User ID {$usuario_id}: " . $e->getMessage());
    $mensagem_final_feedback .= "<span class='error-message'>Erro ao carregar dados da página. Tente atualizar.</span>";
    if ($usuario === null) { die("Erro crítico ao carregar dados do usuário."); }
}

// --- Cálculos e Formatações Finais ---
$xp_necessario_calc = 0; $xp_percent = 0; $xp_necessario_display = 'N/A'; if (isset($usuario['nivel'])) { $nivel_atual_num = filter_var($usuario['nivel'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($nivel_atual_num === false) $nivel_atual_num = 1; $xp_necessario_calc = (int)ceil(XP_BASE_LEVEL_UP * pow($nivel_atual_num, XP_EXPOENTE_LEVEL_UP)); $xp_necessario_calc = max(XP_BASE_LEVEL_UP, $xp_necessario_calc); $xp_necessario_display = number_format($xp_necessario_calc); if ($xp_necessario_calc > 0 && isset($usuario['xp'])) { $xp_atual_num = filter_var($usuario['xp'] ?? 0, FILTER_VALIDATE_INT); if ($xp_atual_num !== false) { $xp_percent = min(100, max(0, ($xp_atual_num / $xp_necessario_calc) * 100)); } } }
$nome_arquivo_foto = !empty($usuario['foto']) ? $usuario['foto'] : 'default.jpg'; $caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto);
if (!function_exists('formatarNomeUsuarioComTag')) { function formatarNomeUsuarioComTag($conn_ignored, $user_data_param) { return htmlspecialchars($user_data_param['nome'] ?? 'ErroNome'); } $nome_formatado = formatarNomeUsuarioComTag(null, $usuario); } else { $nome_formatado = isset($conn) ? formatarNomeUsuarioComTag($conn, $usuario) : formatarNomeUsuarioComTag(null, $usuario); }
if (!function_exists('formatNumber')) { function formatNumber($num) { return number_format($num ?? 0, 0, ',', '.'); } }
$currentPage = basename($_SERVER['PHP_SELF']);

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Desafios PvP - <?= strip_tags($nome_formatado) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* --- Configurações Gerais e Variáveis --- */
        :root { --dbz-blue: #1a2a4d; --dbz-orange: #f5a623; --dbz-orange-dark: #e47d1e; --dbz-red: #cc5500; --dbz-white: #ffffff; --dbz-grey: #aaaaaa; --dbz-light-grey: #f0f0f0; --dbz-dark-grey: #333333; --danger-color: #ff5252; --success-color: #00e676; --info-color: #29b6f6; --warning-color: #ffa726; --disabled-color: #555e6d; --border-radius: 8px; --shadow: 0 5px 15px rgba(0, 0, 0, 0.3); --dbz-font: 'Saiyan Sans', 'Roboto', sans-serif; /* FONTE DBZ */ --base-font: 'Roboto', sans-serif; --bg-image-url: url('https://wallpapercave.com/wp/wp6732526.jpg'); /* Fundo ARENA/DESAFIOS */ }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background-image: var(--bg-image-url); background-size: cover; background-position: center; background-attachment: fixed; color: var(--dbz-white); font-family: var(--base-font); margin: 0; padding: 20px; min-height: 100vh; }
        /* --- Container Principal e Layout --- */
        .container.page-container { width: 100%; max-width: 1400px; display: flex; justify-content: center; gap: 20px; align-items: flex-start; margin-left: auto; margin-right: auto; }
        .coluna { background-color: rgba(26, 42, 77, 0.88); border-radius: var(--border-radius); padding: 20px; box-shadow: var(--shadow); border: 1px solid var(--dbz-blue); backdrop-filter: blur(4px); }
        .coluna-esquerda { flex: 0 0 280px; display: flex; flex-direction: column; gap: 12px; }
        .coluna-centro-wrapper { flex: 1; display: flex; flex-direction: column; gap: 20px; min-width: 0; } /* Usado se central tiver mais de um bloco */
        .coluna-central { flex: 1; } /* Coluna central padrão */
        /* --- COLUNA DIREITA CORRIGIDA PARA SIDEBAR --- */
        .coluna-direita { flex: 0 0 260px; padding: 0 !important; background-color: var(--dbz-blue); border-left: 4px solid var(--dbz-orange); border-radius: var(--border-radius); box-shadow: var(--shadow); backdrop-filter: blur(4px); display: flex; flex-direction: column; }
        /* --- Feedback Container --- */
        .feedback-container { width: 100%; max-width: 1200px; margin: 0 auto 20px auto; padding: 15px 25px; background-color: rgba(30, 34, 44, 0.92); border-radius: var(--border-radius); text-align: center; font-weight: 500; font-size: 1rem; line-height: 1.6; border: 1px solid; display: <?php echo !empty($mensagem_final_feedback) ? 'block' : 'none'; ?>; } .feedback-container.hidden { display: none; } .feedback-container strong { color: var(--success-color); } .feedback-container .error-message, .feedback-container .error { color: var(--danger-color) !important; font-weight: bold; } .feedback-container .success-message { color: var(--success-color) !important; font-weight: bold; } .feedback-container:has(.success-message) { background-color: rgba(0, 230, 118, 0.15); border-color: var(--success-color); } .feedback-container:has(.error-message) { background-color: rgba(255, 82, 82, 0.15); border-color: var(--danger-color); }
        /* --- COLUNA ESQUERDA (DBZ - Padrão) --- */
        /* ... (Estilos Coluna Esquerda - idênticos ao template) ... */
        .player-card { background-color: rgba(0, 0, 0, 0.2); border: 1px solid var(--dbz-blue); border-radius: var(--border-radius); padding: 15px; text-align: center; } .player-card .foto { display: block; margin: 0 auto 10px auto; max-width: 110px; border-radius: 50%; border: 3px solid var(--dbz-orange); } .player-card .player-name { font-family: var(--dbz-font); color: var(--dbz-orange); font-size: 1.4em; font-weight: bold; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); margin-bottom: 5px; word-wrap: break-word; } .player-card .player-level, .player-card .player-race, .player-card .player-rank { font-size: 0.95em; color: var(--dbz-light-grey); margin-bottom: 3px; } .player-card .player-rank span { color: var(--dbz-orange); font-weight: bold; }
        .stats-grid-main { background-color: rgba(0, 0, 0, 0.15); border: 1px solid var(--dbz-blue); padding: 10px 12px; border-radius: 5px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px 10px; font-size: 0.9em; } .stats-grid-main strong { color: var(--dbz-light-grey); font-weight: 500; text-align: left; } .stats-grid-main span { color: var(--dbz-white); font-weight: bold; text-align: right; } .stats-grid-main strong:contains("Pontos") + span, .stats-grid-main strong:contains("Rank") + span { color: var(--dbz-orange); }
        .zeni-display { font-size: 1.1em; font-weight: bold; color: var(--dbz-orange); text-align: center; background-color: rgba(0,0,0,0.3); padding: 6px; border-radius: 5px; border: 1px solid var(--dbz-orange-dark); }
        .xp-bar-container .xp-label { font-weight: bold; color: var(--dbz-white); display: block; margin-bottom: 3px; text-align: center; font-size: 0.8em; } .xp-bar { background-color: var(--dbz-dark-grey); border: 1px solid var(--dbz-blue); border-radius: 15px; overflow: hidden; height: 18px; position: relative; } .xp-bar-fill { background-color: var(--dbz-orange); height: 100%; border-radius: 15px; transition: width 0.5s ease-in-out; } .xp-text { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; color: var(--dbz-white); font-size: 0.75em; font-weight: bold; text-shadow: 1px 1px 1px var(--dbz-dark-grey); }
        .coluna-comunicacao { padding: 0; background: none; border: none; box-shadow: none; backdrop-filter: none; margin-top: 10px; } .notificacoes-titulo { font-family: var(--dbz-font); color: var(--dbz-orange); text-align: center; margin-bottom: 8px; font-size: 1.3em; text-transform: uppercase; } .notifications-container { background-color: rgba(0, 0, 0, 0.35); border: 1px solid var(--dbz-blue); border-radius: 5px; max-height: 200px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: rgba(245, 166, 35, 0.5) transparent; } .notifications-container::-webkit-scrollbar { width: 6px; } .notifications-container::-webkit-scrollbar-track { background: transparent; } .notifications-container::-webkit-scrollbar-thumb { background-color: rgba(245, 166, 35, 0.5); border-radius: 3px; } .notifications-container::-webkit-scrollbar-thumb:hover { background-color: var(--dbz-orange); } .notification-list { padding: 8px; } .notification-item { background-color: rgba(26, 42, 77, 0.5); border: 1px solid rgba(26, 42, 77, 0.8); border-left-width: 5px; border-left-style: solid; border-radius: 4px; padding: 10px 15px; margin-bottom: 8px; position: relative; transition: opacity 0.3s ease-out, transform 0.3s ease-out; color: var(--dbz-white); line-height: 1.5; } .notification-item:last-child { margin-bottom: 0; border-bottom: none; } .notification-item.type-invite { border-left-color: var(--dbz-orange); } .notification-item.type-info { border-left-color: var(--info-color); } .notification-item .mark-read-btn { position: absolute; top: 4px; right: 4px; background: none; border: none; color: var(--dbz-grey); font-size: 1.6em; line-height: 1; cursor: pointer; padding: 0 4px; opacity: 0.6; transition: color 0.2s, opacity 0.2s; } .notification-item .mark-read-btn:hover { color: var(--dbz-red); opacity: 1; } .notification-item .timestamp { font-size: 0.75em; color: var(--dbz-grey); position: absolute; top: 6px; right: 30px; } .notification-item .msg-text { display: block; padding-right: 55px; font-size: 0.9em; } .notification-actions { margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(245, 166, 35, 0.4); text-align: right; } .notification-actions .btn-action { display: inline-block; text-decoration: none; font-family: var(--dbz-font); font-weight: bold; text-transform: uppercase; padding: 5px 10px; border-radius: 4px; text-align: center; cursor: pointer; transition: background-color 0.3s, color 0.3s, transform 0.1s; margin-left: 8px; font-size: 0.8em; border-width: 1px; border-style: solid; } .notification-actions .btn-action:active { transform: translateY(1px); } .notification-actions .btn-aceitar { background-color: #28a745; border-color: #1e7e34; color: white; } .notification-actions .btn-aceitar:hover { background-color: #218838; } .notification-actions .btn-recusar { background-color: #dc3545; border-color: #bd2130; color: white; } .notification-actions .btn-recusar:hover { background-color: #c82333; } .notification-empty { text-align: center; color: var(--dbz-grey); padding: 10px; font-style: italic; font-size: 0.85rem; }
        .btn-pontos { display: block; background-color: #28a745; border-color: #1e7e34; color: white; padding: 10px 15px; border-radius: 5px; font-family: var(--dbz-font); font-weight: bold; text-transform: uppercase; text-align: center; text-decoration: none; cursor: pointer; transition: background-color 0.3s, transform 0.1s; } .btn-pontos:hover { background-color: #218838; } .btn-pontos[style*="cursor: default"] { background-color: #6c757d !important; border-color:#5a6268 !important; cursor: default; opacity: 0.7;} .btn-pontos[style*="cursor: default"]:hover { transform: none; }
        .stats-grid-secondary { font-size: 0.8rem; background-color: transparent; border: none; padding: 0; display: grid; grid-template-columns: auto 1fr; gap: 3px 10px; } .stats-grid-secondary strong { color: var(--dbz-grey); text-align: left; font-weight: 500;} .stats-grid-secondary span { color: var(--dbz-light-grey); text-align: right; font-weight: normal;}
        .divider { height: 1px; background-color: rgba(245, 166, 35, 0.4); border: none; margin: 10px 0; }
        .left-column-bottom-buttons { margin-top: auto; padding-top: 10px; display: flex; flex-direction: column; gap: 10px; } .action-button { display: block; width: 100%; padding: 10px 15px; border-radius: 5px; font-family: var(--dbz-font); font-weight: bold; text-transform: uppercase; text-align: center; text-decoration: none; cursor: pointer; transition: background-color 0.3s, color 0.3s, transform 0.1s, box-shadow 0.2s; border-width: 1px; border-style: solid; box-shadow: 0 2px 4px rgba(0,0,0,0.2); } .action-button:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.3); } .action-button:active { transform: translateY(1px); box-shadow: inset 0 1px 3px rgba(0,0,0,0.2); }
        .btn-perfil { background-color: var(--dbz-orange); color: var(--dbz-blue); border-color: var(--dbz-orange-dark); } .btn-perfil:hover { background-color: var(--dbz-orange-dark); color: var(--dbz-white); }
        .btn-logout { background-color: var(--dbz-red); border-color: #a04400; color: var(--dbz-white); } .btn-logout:hover { background-color: #a04400; }
        /* --- FIM COLUNA ESQUERDA --- */

        /* --- COLUNA CENTRAL (Estilos Gerais + Desafios) --- */
        .coluna-central { /* Estilo base */ }
        .coluna-central h2 { font-family: var(--dbz-font); color: var(--dbz-orange); font-size: 2em; text-align: center; margin-bottom: 25px; text-transform: uppercase; letter-spacing: 1px; text-shadow: 1px 1px 3px rgba(0,0,0,0.5); }
        .coluna-central h3 { font-family: var(--dbz-font); color: var(--dbz-orange); font-size: 1.4em; text-align: left; margin-top: 20px; margin-bottom: 15px; border-bottom: 1px solid rgba(245, 166, 35, 0.5); padding-bottom: 8px; }
        .coluna-central p { color: var(--dbz-light-grey); line-height: 1.6; }

        /* Seção Batalhas Ativas */
        .secao-batalhas-ativas { margin-bottom: 25px; padding: 15px; background-color: rgba(0, 0, 0, 0.2); border-radius: var(--border-radius); border: 1px solid var(--dbz-blue); }
        .secao-batalhas-ativas h3 { color: var(--info-color); margin-top: 0; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 1px solid var(--info-color); font-size: 1.3rem; text-align: center; }
        .secao-batalhas-ativas ul { list-style: none; padding: 0; margin: 0; }
        .secao-batalhas-ativas li { background-color: rgba(41, 121, 255, 0.15); padding: 12px 15px; border-radius: 6px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; font-size: 0.95rem; border: 1px solid var(--info-color); }
        .secao-batalhas-ativas li span { color: var(--dbz-light-grey); } .secao-batalhas-ativas li span strong { color: var(--dbz-white); font-weight: bold; }
        .secao-batalhas-ativas a.action-button { /* Aplica estilo de botão */ padding: 6px 14px; font-size: 0.85rem; background-color: var(--success-color); color: var(--dbz-blue); border-color: #00c853; display: inline-block; width: auto; }
        .secao-batalhas-ativas a.action-button:hover { background-color: #00cf68; color: #111; }
        .secao-batalhas-ativas .no-battles { color: var(--dbz-grey); text-align: center; padding: 10px; font-style: italic; font-size: 0.9rem;}

        /* Seção Desafios PvP */
        .secao-desafios-pvp { margin-bottom: 25px; }
        #lista-desafios-recebidos { min-height: 50px; padding: 10px; background-color: rgba(0,0,0,0.15); border-radius: 6px; border: 1px solid var(--dbz-blue); margin-bottom: 15px; }
        .desafio-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 15px; margin-bottom: 8px; background-color: rgba(26, 42, 77, 0.7); border-radius: 6px; border: 1px solid var(--dbz-blue); border-left: 5px solid var(--warning-color); }
        .desafio-info { flex-grow: 1; margin-right: 15px; font-size: 0.9rem; color: var(--dbz-light-grey); } .desafio-info strong { color: var(--dbz-orange); } .desafio-aposta { display: block; font-size: 0.8rem; color: var(--dbz-grey); margin-top: 3px; }
        .desafio-actions button { padding: 6px 12px; font-size: 0.8rem; border: none; border-radius: 5px; cursor: pointer; transition: background-color 0.2s ease, transform 0.1s; margin-left: 8px; font-family: var(--dbz-font); font-weight: bold; text-transform: uppercase; } .desafio-actions button:active { transform: translateY(1px); }
        .btn-aceitar { background-color: var(--success-color); color: #111; } .btn-aceitar:hover { background-color: #00cf68; }
        .btn-recusar { background-color: var(--danger-color); color: white; } .btn-recusar:hover { background-color: #ff3d3d; }
        #btnAtualizarLista { display: block; margin: 0 auto; /* Centraliza */ background-color: var(--info-color); color: white; border:none; border-radius: 6px; cursor:pointer; transition: background-color 0.2s; padding: 8px 20px; font-size: 0.9rem; }
        #btnAtualizarLista:hover { background-color: #1e88e5; }

        /* Formulário Novo Desafio */
        #form-novo-desafio { margin-top: 15px; padding: 20px; background-color: rgba(0,0,0,0.25); border-radius: 8px; border: 1px solid var(--dbz-blue); }
        #form-novo-desafio div { display: flex; align-items: center; margin-bottom: 15px; gap: 10px; flex-wrap: wrap; }
        #form-novo-desafio label { width: 100px; text-align: right; color: var(--dbz-grey); font-size: 0.9rem; font-weight: bold; flex-shrink: 0; }
        #form-novo-desafio input[type=number] { flex-grow: 1; padding: 8px 12px; background-color: rgba(0,0,0,0.3); border: 1px solid var(--dbz-blue); border-radius: 4px; color: var(--dbz-white); min-width: 100px; }
        #form-novo-desafio input[type=number]:focus { outline: none; border-color: var(--dbz-orange); box-shadow: 0 0 6px rgba(245, 166, 35, 0.4); }
        #form-novo-desafio button[type="submit"] { /* Aplica estilo .action-button */ display: block; width: fit-content; margin: 15px auto 0 auto; padding: 10px 25px; font-size: 1rem; background-color: var(--dbz-orange); color: var(--dbz-blue); border-color: var(--dbz-orange-dark); }
        #form-novo-desafio button[type="submit"]:hover { background-color: var(--dbz-orange-dark); color: var(--dbz-white); }

        /* Feedback AJAX divs */
        #resposta-desafio-feedback:not(:empty), #novo-desafio-feedback:not(:empty) { padding: 10px 15px; margin: 15px 0 0 0; border-radius: 6px; font-weight: 500; text-align: center; font-size: 0.9rem; }
        /* Classes .feedback-success e .feedback-error já definidas no geral */
        /* --- FIM COLUNA CENTRAL (Desafios) --- */

        /* --- COLUNA DIREITA (SIDEBAR DBZ - Padrão) --- */
        /* Estilos da Sidebar Direita */
        .sidebar-dbz { width: 100%; font-family: var(--dbz-font); flex-grow: 1; overflow-y: auto; padding: 15px 0; } .sidebar-dbz ul { list-style: none; padding: 0; margin: 0; } .sidebar-dbz li { margin-bottom: 5px; } .sidebar-dbz li a { display: flex; align-items: center; padding: 14px 20px; color: var(--dbz-white); text-decoration: none; font-size: 1.1em; transition: background-color 0.3s, color 0.3s, padding-left 0.3s, border-left-color 0.3s; border-left: 5px solid transparent; } .sidebar-dbz li a .icon-placeholder { display: inline-block; width: 25px; text-align: center; margin-right: 10px; font-size: 1.1em; color: var(--dbz-orange); transition: color 0.3s; } .sidebar-dbz li a:hover { background-color: rgba(245, 166, 35, 0.2); color: var(--dbz-orange); border-left-color: var(--dbz-orange); padding-left: 25px; } .sidebar-dbz li a:hover .icon-placeholder { color: var(--dbz-orange); } .sidebar-dbz li a.disabled { color: var(--dbz-grey); cursor: not-allowed; background-color: transparent !important; border-left-color: transparent !important; padding-left: 20px; } .sidebar-dbz li a.disabled .icon-placeholder { color: #666; } .sidebar-dbz li a.disabled:hover { color: var(--dbz-grey); padding-left: 20px; } .sidebar-dbz li.active a, .sidebar-dbz li a:active { background-color: var(--dbz-orange); color: var(--dbz-blue); font-weight: bold; border-left-color: var(--dbz-white); } .sidebar-dbz li.active a .icon-placeholder, .sidebar-dbz li a:active .icon-placeholder { color: var(--dbz-blue); } .sidebar-dbz li a[href="sair.php"], .sidebar-dbz li a[href="../sair.php"] { margin-top: 20px; border-top: 1px solid rgba(255, 255, 255, 0.2); padding-top: 15px; } .sidebar-dbz li a[href="sair.php"] .icon-placeholder, .sidebar-dbz li a[href="../sair.php"] .icon-placeholder { color: var(--danger-color); } .sidebar-dbz li a[href="sair.php"]:hover, .sidebar-dbz li a[href="../sair.php"]:hover { color: var(--danger-color); background-color: rgba(220, 53, 69, 0.1); border-left-color: var(--danger-color); }
        /* --- FIM COLUNA DIREITA --- */

        /* --- NAVEGAÇÃO INFERIOR (MOBILE - Padrão) --- */
        #bottom-nav { display: none; }
        @media (max-width: 768px) { #bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; right: 0; background-color: rgba(20, 23, 31, 0.97); border-top: 1px solid var(--dbz-orange); padding: 2px 0; box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.35); justify-content: space-around; align-items: stretch; z-index: 1000; backdrop-filter: blur(6px); height: 60px; } .bottom-nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; text-decoration: none; color: var(--dbz-grey); padding: 4px 2px; font-size: 0.65rem; text-align: center; transition: background-color 0.2s ease, color 0.2s ease; cursor: pointer; border-radius: 5px; margin: 2px; line-height: 1.2; font-family: var(--dbz-font); } .bottom-nav-item:not(.active):not(.disabled):hover { background-color: rgba(245, 166, 35, 0.1); color: var(--dbz-orange); } .bottom-nav-item.active { color: var(--dbz-orange); font-weight: bold; background-color: rgba(245, 166, 35, 0.2); } .bottom-nav-item span:first-child { font-size: 1.5rem; line-height: 1; margin-bottom: 4px; } .bottom-nav-item.disabled { cursor: not-allowed; opacity: 0.5; color: #6a7383 !important; background-color: transparent !important; } .bottom-nav-item.disabled:hover { color: #6a7383 !important; } }
        @media (max-width: 480px) { #bottom-nav { height: 55px; } .bottom-nav-item span:first-child { font-size: 1.3rem; } .bottom-nav-item span:last-child { font-size: 0.6rem; } }
        /* --- FIM NAVEGAÇÃO INFERIOR --- */

        /* --- RESPONSIVO GERAL (Padrão) --- */
        @media (max-width: 1200px) { body { padding: 15px; } .container.page-container { flex-direction: column; align-items: center; width: 100%; max-width: 95%; padding: 0; gap: 15px; } .coluna-esquerda, .coluna-centro-wrapper, .coluna-central, .coluna-direita { flex-basis: auto !important; width: 100% !important; max-width: 800px; margin: 0 auto; min-width: unset; margin-bottom: 15px; } .coluna-direita { margin-bottom: 0; } }
        @media (max-width: 768px) { body { padding: 10px; padding-bottom: 75px; } .coluna-esquerda, .coluna-direita { display: none; } .coluna-centro-wrapper, .coluna-central { max-width: 100%; width: 100%; } .coluna { padding: 15px; } .feedback-container { font-size: 0.9rem; padding: 10px 15px; margin: 0 auto 10px auto; } }
        @media (max-width: 480px) { body { padding: 8px; padding-bottom: 70px; } .container.page-container { gap: 10px; } .coluna-centro-wrapper { gap: 10px; } .coluna { padding: 12px; } .coluna-central h2 { font-size: 1.5em; } .coluna-central h3 { font-size: 1.2em; } .feedback-container { font-size: 0.85rem; padding: 8px 12px; max-width: calc(100% - 16px); } /* Ajustes desafios mobile */ .secao-batalhas-ativas li {flex-direction: column; align-items: stretch; gap: 8px; } .secao-batalhas-ativas a.action-button { align-self: center; margin-top: 5px;} .desafio-item { padding: 10px; flex-direction: column; align-items: stretch; gap: 8px;} .desafio-info { margin-right: 0; } .desafio-aposta { margin-top: 2px; } .desafio-actions { margin-top: 8px; display: flex; justify-content: space-around; gap: 10px;} .desafio-actions button { margin-left: 0; flex-grow: 1; font-size: 0.8rem;} #form-novo-desafio div { flex-direction: column; align-items: stretch; gap: 5px; margin-bottom: 10px;} #form-novo-desafio label { width: auto; text-align: left;} #form-novo-desafio input[type=number] { width: 100%; padding: 8px 10px;} #form-novo-desafio button[type="submit"] { width: 100%; margin-top: 10px; padding: 10px 15px;} }
        /* --- FIM RESPONSIVO --- */

    </style>
</head>
<body>

    <?php if (!empty($mensagem_final_feedback)): ?>
        <?php $feedback_class = (strpos($mensagem_final_feedback, 'error-message') !== false || strpos($mensagem_final_feedback, 'Erro') !== false) ? 'error' : 'success'; ?>
        <div class="feedback-container <?php echo $feedback_class; ?>" style="display: block;">
            <?php echo $mensagem_final_feedback; ?>
        </div>
    <?php endif; ?>

    <div class="container page-container">

        <div class="coluna coluna-esquerda">
            <div class="player-card"> <img class="foto" src="<?= htmlspecialchars($foto_path) ?>" alt="Foto de perfil"> <div class="player-name"><?= $nome_formatado ?></div> <div class="player-level">Nível <?= htmlspecialchars($usuario['nivel'] ?? '?') ?></div> <div class="player-race">Raça: <?= htmlspecialchars($usuario['raca'] ?? 'N/D') ?></div> <div class="player-rank">Título: <?= htmlspecialchars($usuario['ranking_titulo'] ?? 'Iniciante') ?></div> </div>
            <div class="stats-grid-main"> <strong>HP Base</strong><span><?= number_format($usuario['hp'] ?? 0) ?></span> <strong>Ki Base</strong><span><?= number_format($usuario['ki'] ?? 0) ?></span> <strong>Força</strong><span><?= number_format($usuario['forca'] ?? 0) ?></span> <strong>Defesa</strong><span><?= number_format($usuario['defesa'] ?? 0) ?></span> <strong>Velocidade</strong><span><?= number_format($usuario['velocidade'] ?? 0) ?></span> <strong>Pontos</strong><span><?= number_format($usuario['pontos'] ?? 0) ?></span> <strong>Rank (Nível)</strong><span><?= htmlspecialchars($rank_nivel) ?><?= ($rank_nivel !== 'N/A' ? 'º' : '') ?></span> <strong>Rank (Sala)</strong><span><?= htmlspecialchars($rank_sala) ?><?= ($rank_sala !== 'N/A' ? 'º' : '') ?></span> </div>
            <div class="zeni-display">💰 Zeni: <?= is_numeric($usuario['zeni'] ?? null) ? number_format($usuario['zeni']) : '0' ?></div>
            <div class="xp-bar-container"> <span class="xp-label">Experiência</span> <div class="xp-bar"> <div class="xp-bar-fill" style="width: <?= $xp_percent ?>%;"></div> <div class="xp-text"><?= number_format($usuario['xp'] ?? 0) ?> / <?= $xp_necessario_display ?></div> </div> </div>
            <div class="coluna-comunicacao"> <h3 class="notificacoes-titulo">Notificações</h3> <div id="notifications-container" class="notifications-container"> <div id="notification-list" class="notification-list"> <?php if (!empty($initial_notifications)): ?> <?php foreach (array_reverse($initial_notifications) as $notif): ?> <?php $notif_class = 'notification-item type-' . htmlspecialchars($notif['type'] ?? 'info'); $timestamp_fmt = isset($notif['timestamp']) ? date('H:i', strtotime($notif['timestamp'])) : '??:??'; $related_data_json = json_encode($notif['related_data'] ?? new stdClass()); ?> <div class="<?= $notif_class ?>" data-notification-id="<?= (int)$notif['id'] ?>" data-related='<?= htmlspecialchars($related_data_json, ENT_QUOTES, 'UTF-8') ?>'> <button class="mark-read-btn" title="Remover Notificação" onclick="markNotificationAsRead(<?= (int)$notif['id'] ?>, this)">×</button> <span class="timestamp"><?= $timestamp_fmt ?></span> <span class="msg-text"><?= htmlspecialchars($notif['message_text']) ?></span> <?php /* Ações JS */ ?> </div> <?php endforeach; ?> <?php else: ?> <p class="notification-empty">Nenhuma notificação.</p> <?php endif; ?> </div> </div> </div>
            <?php $pontos_num_check = filter_var($usuario['pontos'] ?? 0, FILTER_VALIDATE_INT); ?> <?php if ($pontos_num_check !== false && $pontos_num_check > 0): ?> <a href="perfil.php" class="btn-pontos action-button"> <?= number_format($pontos_num_check) ?> Pontos! <span style="font-size: 0.8em;">(Distribuir)</span></a> <?php endif; ?>
            <div class="stats-grid-secondary"> <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span> <strong>Tempo Sala</strong><span><?= number_format($usuario['tempo_sala'] ?? 0) ?> min</span> </div>
            <div class="divider"></div>
            <div class="left-column-bottom-buttons"> <a class="action-button btn-perfil" href="perfil.php">Editar Perfil</a> <a class="action-button btn-logout" href="sair.php">Sair (Logout)</a> </div>
        </div>


        <div class="coluna coluna-central">
            <h2>Central de Desafios PvP</h2>

            <div class="secao-batalhas-ativas">
                <h3>Minhas Batalhas Ativas</h3>
                 <?php if (empty($active_battles)): ?>
                     <p class="no-battles">Nenhuma batalha ativa no momento.</p>
                 <?php else: ?>
                     <ul>
                         <?php foreach ($active_battles as $battle): ?>
                             <?php
                                 $opponent_name = 'Oponente Desconhecido';
                                 if ($battle['desafiante_id'] == $usuario_id) { $opponent_name = $battle['desafiado_nome'] ?? "ID: {$battle['desafiado_id']}"; }
                                 else { $opponent_name = $battle['desafiante_nome'] ?? "ID: {$battle['desafiante_id']}"; }
                             ?>
                             <li>
                                 <span>Batalha contra <strong><?= htmlspecialchars($opponent_name) ?></strong></span>
                                 <a href="arena.php?batalha_id=<?= $battle['id'] ?>" class="action-button">Entrar</a>
                             </li>
                         <?php endforeach; ?>
                     </ul>
                 <?php endif; ?>
             </div>

            <div id="resposta-desafio-feedback" class="feedback-container" style="display: none; margin-bottom: 15px;"></div>

            <div class="secao-desafios-pvp">
                <h3>Desafios Recebidos</h3>
                <div id="lista-desafios-recebidos">
                    <p style="color: var(--dbz-grey); text-align: center;">Carregando desafios...</p>
                </div>
                <button id="btnAtualizarLista" type="button" class="action-button" style="margin-top: 15px; background-color: var(--info-color); color: white; width: auto; align-self: center;">Atualizar Lista</button>
            </div>

            <div class="secao-desafios-pvp">
                <h3>Enviar Novo Desafio</h3>
                <form id="form-novo-desafio">
                    <div> <label for="desafio-target-id">ID do Alvo:</label> <input type="number" id="desafio-target-id" name="target_id" required min="1" placeholder="ID do jogador"> </div>
                    <div> <label for="desafio-bet-xp">Aposta XP:</label> <input type="number" id="desafio-bet-xp" name="bet_xp" min="0" value="0" required> </div>
                    <div> <label for="desafio-bet-zeni">Aposta Zeni:</label> <input type="number" id="desafio-bet-zeni" name="bet_zeni" min="0" value="0" required> </div>
                    <button type="submit" class="action-button">Enviar Desafio</button>
                    <div id="novo-desafio-feedback" class="feedback-container" style="display: none; margin-top: 15px;"></div>
                </form>
            </div>
        </div> <div class="coluna coluna-direita">
             <nav class="sidebar-dbz">
                 <ul>
                     <?php $sidebarItems = [ ['id' => 'praca', 'label' => 'Praça Central', 'href' => 'praca.php', 'icon' => 'fa-users'], ['id' => 'guia', 'label' => 'Guia do Guerreiro Z', 'href' => 'guia_guerreiro.php', 'icon' => 'fa-book-open'], ['id' => 'equipe', 'label' => 'Equipe Z', 'href' => 'equipe.php', 'icon' => 'fa-shield-alt'], ['id' => 'banco', 'label' => 'Banco Galáctico', 'href' => 'banco.php', 'icon' => 'fa-piggy-bank'], ['id' => 'urunai', 'label' => 'Palácio da Vovó Uranai', 'href' => 'urunai.php', 'icon' => 'fa-crystal-ball'], ['id' => 'kame', 'label' => 'Casa do Mestre Kame', 'href' => 'kame.php', 'icon' => 'fa-house-chimney-medical'], ['id' => 'sala_tempo', 'label' => 'Sala do Tempo e Espírito','href' => 'templo.php', 'icon' => 'fa-door-open'], ['id' => 'treinos', 'label' => 'Zona de Treinamento', 'href' => 'desafios/treinos.php', 'icon' => 'fa-dumbbell'], ['id' => 'desafios', 'label' => 'Desafios Z', 'href' => 'desafios.php', 'icon' => 'fa-trophy'], ['id' => 'perfil', 'label' => 'Perfil de Guerreiro', 'href' => 'perfil.php', 'icon' => 'fa-user-pen'] ];
                     foreach ($sidebarItems as $item) { $href = htmlspecialchars($item['href']); $label = htmlspecialchars($item['label']); $iconClass = htmlspecialchars($item['icon']); $idItem = $item['id']; $liClass = ''; $aClass = ''; $extraAttributes = ''; if ($idItem === 'sala_tempo') { if (!$pode_entrar_templo_base) { $aClass .= ' disabled'; $extraAttributes .= ' onclick="alert(\'' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '\'); return false;"'; $extraAttributes .= ' title="' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '"'; $href = '#'; } else { $aClass .= ' entrar-templo-btn'; $extraAttributes .= ' data-url="' . htmlspecialchars($item['href']) . '"'; $extraAttributes .= ' title="Entrar na Sala do Tempo e Espírito"'; } }
                     // Marcar 'Desafios Z' como ativo
                     if ($idItem === 'desafios' && $href !== '#') { $liClass = 'active'; }
                     echo "<li class=\"{$liClass}\">"; echo "<a href=\"{$href}\" class=\"{$aClass}\" {$extraAttributes}>"; echo "<i class=\"icon-placeholder fas {$iconClass}\"></i> "; echo $label; echo "</a></li>"; } ?>
                      <li><a href="sair.php"><i class="icon-placeholder fas fa-sign-out-alt"></i> Sair</a></li>
                 </ul>
             </nav>
        </div>

    </div> <nav id="bottom-nav">
        <?php /* Adaptação para Desafios */ $bottomNavItems = []; foreach($sidebarItems as $item) { if (in_array($item['id'], ['praca', 'treinos', 'desafios', 'sala_tempo', 'kame', 'perfil'])) { $icon = ''; switch($item['id']) { case 'praca': $icon = '👥'; break; case 'treinos': $icon = '💪'; break; case 'desafios': $icon = '⚔️'; break; case 'sala_tempo': $icon = '🏛️'; break; case 'kame': $icon = '🐢'; break; case 'perfil': $icon = '👤'; break; default: $icon = '❓'; break; } $bottomNavItems[] = ['label' => $item['label'], 'href' => $item['href'], 'icon' => $icon, 'id' => $item['id']]; } } ?>
        <?php foreach ($bottomNavItems as $item): ?> <?php $url_destino_item = $item['href']; $final_href = '#'; $final_onclick = ''; $final_title = ''; $extra_class = ''; $is_temple_button = ($item['id'] === 'sala_tempo'); $is_current_page = (basename($url_destino_item) === $currentPage); if ($is_temple_button) { if (!$pode_entrar_templo_base) { $final_onclick = ' onclick="alert(\'' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '\'); return false;"'; $final_title = 'HP ou Ki base em 0'; $extra_class = ' disabled'; } else { $final_href = htmlspecialchars($url_destino_item); $final_title = 'Entrar na Sala do Templo'; $extra_class = ' entrar-templo-btn'; } } else { $final_href = htmlspecialchars($url_destino_item); $final_title = 'Ir para ' . htmlspecialchars($item['label']); } if ($is_current_page && $final_href !== '#') { $extra_class .= ' active'; } echo "<a href='{$final_href}' class='bottom-nav-item{$extra_class}' {$final_onclick} title='" . htmlspecialchars($final_title) . "'"; if ($is_temple_button && $pode_entrar_templo_base) { echo " data-url='" . htmlspecialchars($url_destino_item) . "'"; } echo ">"; echo "<span style='font-size: 1.4rem; line-height: 1; margin-bottom: 3px;'>{$item['icon']}</span>"; echo "<span>" . htmlspecialchars($item['label']) . "</span>"; echo "</a>"; ?> <?php endforeach; ?>
    </nav>

    <script>
        // --- Funções Utilitárias Globais ---
        function escapeHtml(unsafe) { if (typeof unsafe !== 'string') return ''; return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); }
        function formatTimestamp(timestamp) { if (!timestamp) return ''; try { const date = new Date(timestamp.replace(/-/g, '/') + 'Z'); if (!isNaN(date)) { return date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' }); } } catch(e) {} return timestamp.split(' ')[1]?.substring(0, 5) || ''; }

        // --- Sistema de Notificações ---
        const notificationListDiv = document.getElementById('notification-list'); const currentUserId_Notif = <?php echo json_encode((int)$id); ?>; let lastNotificationId = 0; <?php if(!empty($initial_notifications) && is_array($initial_notifications) && count($initial_notifications) > 0 && isset($initial_notifications[0]['id'])): ?> lastNotificationId = <?= (int)$initial_notifications[0]['id'] ?>; <?php endif; ?> const notificationPollInterval = 15000; let notificationPollingIntervalId = null; let isFetchingNotifications = false; const notificationHandlerUrl = 'notification_handler_ajax.php';
        function initializeNotificationSystem() { /* ... Função completa ... */ if (!notificationListDiv) return; const initialNotifications = notificationListDiv.querySelectorAll('.notification-item'); initialNotifications?.forEach(item => { if(item.querySelector('.notification-actions') || item.classList.contains('is-read')) return; try { const relatedDataStr = item.dataset.related; if (relatedDataStr) { const relatedData = JSON.parse(relatedDataStr); const notifSimulated = { id: item.dataset.notificationId, type: item.classList.contains('type-invite') ? 'invite' : 'info', related_data: relatedData }; if (notifSimulated.type === 'invite') { const actionsHtml = generateNotificationActions(notifSimulated); if (actionsHtml) { const actionsContainer = document.createElement('div'); actionsContainer.classList.add('notification-actions'); actionsContainer.innerHTML = actionsHtml; item.appendChild(actionsContainer); } } } } catch(e) { console.error('Erro processar data-related inicial:', e, item.dataset.related); } }); if (notificationPollingIntervalId) clearInterval(notificationPollingIntervalId); setTimeout(fetchNotificationUpdates, 700); notificationPollingIntervalId = setInterval(fetchNotificationUpdates, notificationPollInterval); document.addEventListener("visibilitychange", handleVisibilityChangeNotifications); if (notificationListDiv) { notificationListDiv.scrollTop = 0; } }
        function handleVisibilityChangeNotifications() { /* ... Função completa ... */ if (document.hidden) { if (notificationPollingIntervalId) clearInterval(notificationPollingIntervalId); notificationPollingIntervalId = null; } else { if (!notificationPollingIntervalId) { setTimeout(fetchNotificationUpdates, 250); notificationPollingIntervalId = setInterval(fetchNotificationUpdates, notificationPollInterval); } } }
        function addNotificationToDisplay(notif) { /* ... Função completa ... */ if (!notificationListDiv || !notif || !notif.id) return; if (document.querySelector(`.notification-item[data-notification-id="${notif.id}"]`)) return; const loadingOrEmpty = notificationListDiv.querySelector('.notification-empty'); if(loadingOrEmpty) loadingOrEmpty.remove(); const notificationElement = document.createElement('div'); notificationElement.dataset.notificationId = notif.id; const relatedDataJson = JSON.stringify(notif.related_data || {}); notificationElement.dataset.related = relatedDataJson; notificationElement.classList.add('notification-item', `type-${escapeHtml(notif.type) || 'info'}`); let timestampDisplay = formatTimestamp(notif.timestamp); notificationElement.innerHTML = `<button class="mark-read-btn" title="Remover Notificação" onclick="markNotificationAsRead(${notif.id}, this)">×</button><span class="timestamp">${timestampDisplay}</span><span class="msg-text">${escapeHtml(notif.message_text)}</span>`; if (notif.type === 'invite') { const actionsHtml = generateNotificationActions(notif); if (actionsHtml) { const actionsContainer = document.createElement('div'); actionsContainer.classList.add('notification-actions'); actionsContainer.innerHTML = actionsHtml; notificationElement.appendChild(actionsContainer); } } notificationListDiv.insertBefore(notificationElement, notificationListDiv.firstChild); const maxNotificationsToShow = 50; while (notificationListDiv.children.length > maxNotificationsToShow) { notificationListDiv.removeChild(notificationListDiv.lastChild); } if (notif.id && parseInt(notif.id) > lastNotificationId) { lastNotificationId = parseInt(notif.id); } }
        function generateNotificationActions(notif) { /* ... Função completa ... */ if (notif.type === 'invite' && notif.related_data && typeof notif.related_data === 'object') { try { const data = notif.related_data; if (data.invite_type === 'fusion' && notif.id) { const pageName = 'praca.php'; const acceptUrl = `${pageName}?acao=aceitar_convite&convite_id=${notif.id}`; const rejectUrl = `${pageName}?acao=recusar_convite&convite_id=${notif.id}`; return `<a href="${acceptUrl}" class='btn-action btn-aceitar'>Aceitar Fusão</a><a href="${rejectUrl}" class='btn-action btn-recusar'>Recusar</a>`; } } catch (e) { console.error("Erro processar related_data:", e); } } return ''; }
        async function markNotificationAsRead(notificationId, buttonElement) { /* ... Função completa ... */ if (!notificationId) return; const notificationItem = buttonElement?.closest('.notification-item'); if (notificationItem) { notificationItem.style.transition = 'opacity 0.3s ease-out, transform 0.3s ease-out'; notificationItem.style.opacity = '0'; notificationItem.style.transform = 'translateX(50px)'; setTimeout(() => { notificationItem.remove(); if (notificationListDiv && notificationListDiv.children.length === 0) { const emptyMsg = document.createElement('p'); emptyMsg.classList.add('notification-empty'); emptyMsg.style.textAlign = 'center'; emptyMsg.style.color = 'var(--dbz-grey)'; emptyMsg.style.padding = '10px'; emptyMsg.textContent = 'Nenhuma notificação.'; notificationListDiv.appendChild(emptyMsg); } }, 300); } try { const formData = new FormData(); formData.append('notification_id', notificationId); fetch(`${notificationHandlerUrl}?action=mark_notification_read`, { method: 'POST', body: formData }).then(response => { if (!response.ok) { console.error('Erro backend mark read:', response.statusText); } }).catch(e => { console.error("Erro rede mark read:", e); }); } catch (e) { console.error("Erro JS mark read:", e); } }
        async function fetchNotificationUpdates() { /* ... Função completa ... */ if (isFetchingNotifications || document.hidden) return; isFetchingNotifications = true; try { const fetchUrl = `${notificationHandlerUrl}?action=fetch&last_notification_id=${lastNotificationId}&t=${Date.now()}`; const response = await fetch(fetchUrl); if (!response.ok) { isFetchingNotifications = false; return; } const data = await response.json(); if (data.success && data.notifications && data.notifications.length > 0) { data.notifications.reverse().forEach(notif => { if (typeof notif.related_data === 'string') { try { notif.related_data = JSON.parse(notif.related_data); } catch (e) { notif.related_data = {}; } } addNotificationToDisplay(notif); }); const latestReceivedId = Math.max(...data.notifications.map(n => parseInt(n.id))); if (latestReceivedId > lastNotificationId) { lastNotificationId = latestReceivedId; } } else if (!data.success && data.error) { console.error('Erro API fetch Notif:', data.error); } } catch (error) { console.error("Erro JS fetch Notif: ", error); } finally { isFetchingNotifications = false; } }
        // --- Fim Notificações ---

        // --- JavaScript Específico Desafios PvP ---
        const feedbackRespostaDiv = document.getElementById('resposta-desafio-feedback'); const feedbackNovoDesafioDiv = document.getElementById('novo-desafio-feedback'); const listaDesafiosRecebidosDiv = document.getElementById('lista-desafios-recebidos'); const handlerUrl = 'challenge_handler.php'; // URL do handler AJAX
        function showFeedback(divElement, message, isSuccess) { /* ... Colar função ... */ if (!divElement) return; divElement.textContent = message; divElement.className = 'feedback-container'; if (message) { divElement.classList.add(isSuccess ? 'feedback-success' : 'feedback-error'); divElement.style.display = 'block'; } else { divElement.style.display = 'none'; } }
        function carregarDesafiosRecebidos() { /* ... Colar função ... */ if (!listaDesafiosRecebidosDiv) return; listaDesafiosRecebidosDiv.innerHTML = '<p style="color: var(--dbz-grey); text-align: center;">Atualizando desafios...</p>'; showFeedback(feedbackRespostaDiv, '', true); fetch(`${handlerUrl}?action=check`).then(response => { const rc = response.clone(); if (!response.ok) { return response.json().catch(()=>null).then(eD => { throw new Error(`Erro HTTP ${response.status}: ${eD?.error||response.statusText||'?'}`); }); } return response.json(); }).then(data => { if (data.success) { listaDesafiosRecebidosDiv.innerHTML = ''; if (data.challenges && data.challenges.length > 0) { data.challenges.forEach(desafio => { const divDesafio = document.createElement('div'); divDesafio.classList.add('desafio-item'); const challengerName = desafio.challenger_name_formatted || `ID ${desafio.challenger_id}`; const betXpDisplay = Number(desafio.bet_xp || 0).toLocaleString('pt-BR'); const betZeniDisplay = Number(desafio.bet_zeni || 0).toLocaleString('pt-BR'); divDesafio.innerHTML = `<div class="desafio-info"> Desafio de: <strong>${challengerName}</strong><br> <span class="desafio-aposta">Aposta: ${betXpDisplay} XP / ${betZeniDisplay} Zeni</span> </div> <div class="desafio-actions"> <button class="btn-aceitar" onclick="responderDesafio(${desafio.challenge_id}, 'accept', this)">Aceitar</button> <button class="btn-recusar" onclick="responderDesafio(${desafio.challenge_id}, 'decline', this)">Recusar</button> </div>`; listaDesafiosRecebidosDiv.appendChild(divDesafio); }); } else { listaDesafiosRecebidosDiv.innerHTML = '<p class="notification-empty">Nenhum desafio pendente recebido.</p>'; } } else { listaDesafiosRecebidosDiv.innerHTML = `<p class="feedback-error">Erro ao buscar desafios: ${data.error || 'Erro desconhecido'}</p>`; } }).catch(error => { console.error('Erro ao buscar desafios (Catch):', error); listaDesafiosRecebidosDiv.innerHTML = `<p class="feedback-error">Falha na comunicação: ${error.message || ''}</p>`; }); }
        function responderDesafio(challengeId, responseAction) { /* ... Colar função ... */ showFeedback(feedbackRespostaDiv, 'Processando...', true); const formData = new FormData(); formData.append('challenge_id', challengeId); formData.append('response_action', responseAction); fetch(`${handlerUrl}?action=respond`, { method: 'POST', body: formData }).then(response => { const rc = response.clone(); if (!response.ok) { return response.json().catch(()=>null).then(eD => { throw new Error(`Erro HTTP ${response.status}: ${eD?.error||response.statusText||'?'}`); }); } return response.json(); }).then(data => { if (data.success) { if (responseAction === 'accept' && data.redirect_url) { showFeedback(feedbackRespostaDiv, 'Desafio aceito! Redirecionando...', true); setTimeout(() => { window.location.href = data.redirect_url; }, 1500); } else if (responseAction === 'accept') { showFeedback(feedbackRespostaDiv, 'Aceito, mas erro ao iniciar batalha.', false); carregarDesafiosRecebidos(); } else { showFeedback(feedbackRespostaDiv, 'Desafio recusado.', true); carregarDesafiosRecebidos(); } } else { showFeedback(feedbackRespostaDiv, `Erro: ${data.error || 'Desconhecido.'}`, false); carregarDesafiosRecebidos(); } }).catch(error => { console.error('Erro resposta (Catch):', error); showFeedback(feedbackRespostaDiv, `Erro comunicação: ${error.message}`, false); carregarDesafiosRecebidos(); }); }
        // --- Fim JS Desafios PvP ---

        // --- Inicialização Geral ---
        document.addEventListener('DOMContentLoaded', () => {
            // Handler Botão Templo (Padrão)
            const botoesEntrarTemplo = document.querySelectorAll('.entrar-templo-btn'); if (botoesEntrarTemplo.length > 0 && typeof handleTemploButtonClick === 'undefined') { function handleTemploButtonClick(event) { const targetUrl = this.dataset.url || this.href; if (this.classList.contains('disabled')) { event.preventDefault(); let alertMsg = 'Acesso bloqueado.'; const onclickAttr = this.getAttribute('onclick'); if (onclickAttr && onclickAttr.includes('alert(')) { try { alertMsg = onclickAttr.split("alert('")[1].split("')")[0]; } catch (e) {} } alert(alertMsg); return; } if (targetUrl && targetUrl !== '#') { event.preventDefault(); window.location.href = targetUrl; } } botoesEntrarTemplo.forEach(botao => { botao.addEventListener('click', handleTemploButtonClick); }); }

            // Inicializa Notificações
             if (typeof initializeNotificationSystem === 'function') { initializeNotificationSystem(); }

             // Inicializa Desafios PvP
             if (document.getElementById('lista-desafios-recebidos') && typeof carregarDesafiosRecebidos === 'function') { carregarDesafiosRecebidos(); const btnAtualizar = document.getElementById('btnAtualizarLista'); if(btnAtualizar) btnAtualizar.addEventListener('click', carregarDesafiosRecebidos); }
             const formNovoDesafio = document.getElementById('form-novo-desafio'); if(formNovoDesafio) { formNovoDesafio.addEventListener('submit', function(event) { event.preventDefault(); showFeedback(feedbackNovoDesafioDiv, 'Enviando...', true); const targetId = document.getElementById('desafio-target-id').value; const betXp = document.getElementById('desafio-bet-xp').value; const betZeni = document.getElementById('desafio-bet-zeni').value; if (!targetId || parseInt(targetId) <= 0) { showFeedback(feedbackNovoDesafioDiv, 'ID Alvo inválido.', false); return; } if (parseInt(betXp) < 0 || parseInt(betZeni) < 0) { showFeedback(feedbackNovoDesafioDiv, 'Apostas não podem ser negativas.', false); return; } const formData = new FormData(this); fetch(`${handlerUrl}?action=send`, { method: 'POST', body: formData }).then(response => { const rc = response.clone(); if (!response.ok) { return response.json().catch(()=>null).then(eD => { throw new Error(`Erro HTTP ${response.status}: ${eD?.error||response.statusText||'?'}`); }); } return response.json(); }).then(data => { if (data.success) { showFeedback(feedbackNovoDesafioDiv, 'Desafio enviado!', true); this.reset(); } else { showFeedback(feedbackNovoDesafioDiv, `Erro: ${data.error || 'Ocorreu um problema.'}`, false); } }).catch(error => { console.error('Erro envio (Catch):', error); showFeedback(feedbackNovoDesafioDiv, `Erro comunicação: ${error.message}`, false); }); }); }

        }); // Fim DOMContentLoaded
    </script>

</body>
</html>
<?php
// Fecha a conexão PDO
if (isset($conn)) { $conn = null; }
if (isset($pdo)) { $pdo = null; }
?>