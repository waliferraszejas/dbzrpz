<?php
/*
 * Layout Enxuto - Coluna Central Removida
 * CSS e Funções PHP separados em includes.
 * Funcionalidade "Jogadores Online" REMOVIDA.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); } // <<<<< VERIFIQUE PATH login.php

// Includes Essenciais
require_once('conexao.php');             // <<<<< VERIFIQUE PATH conexao.php
require_once('home/home_functions.php');       // <<<<< VERIFIQUE PATH home_functions.php (NOVO ARQUIVO)

// --- Variáveis e Configurações ---
$id = $_SESSION['user_id'];
$id_usuario_logado = $id; // Mantido para clareza, embora não usado após remoção do online list
$usuario = null;
$initial_notifications = [];
$mensagem_final_feedback = "";
$currentPage = basename(__FILE__); // Nome do arquivo atual
$nome_formatado_usuario = 'ErroNome';
$rank_nivel = 'N/A';
$rank_sala = 'N/A';
$notification_limit = 20;

// REMOVIDO: Variáveis de Online Users
// REMOVIDO: Variáveis de Reset/Chat

// Constantes (podem ser necessárias para level up em home_functions.php)
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);
if (!defined('PONTOS_GANHO_POR_NIVEL')) define('PONTOS_GANHO_POR_NIVEL', 100);

// --- Coleta de Feedback (Sessão) ---
$mensagem_final_feedback = ''; // Inicializa
if (isset($_SESSION['feedback_wali'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_wali']; unset($_SESSION['feedback_wali']); }
if (isset($_SESSION['feedback_geral'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_geral']; unset($_SESSION['feedback_geral']); }
if (isset($_SESSION['feedback_cooldown'])) { $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_cooldown']; unset($_SESSION['feedback_cooldown']); }

// --- Arrays de Navegação ---
$sidebarItems = [
    ['id' => 'praca',        'label' => 'Praça Central',         'href' => 'home.php',             'icon' => 'img/praca.jpg'],
    ['id' => 'guia',         'label' => 'Guia do Guerreiro Z',   'href' => 'guia_guerreiro.php',   'icon' => 'img/guiaguerreiro.jpg'],
    ['id' => 'equipe',       'label' => 'Equipe Z',              'href' => 'script/grupos.php',    'icon' => 'img/grupos.jpg'], // <<<<< VERIFIQUE PATH
    ['id' => 'banco',        'label' => 'Banco Galáctico',       'href' => 'script/banco.php',     'icon' => 'img/banco.jpg'],  // <<<<< VERIFIQUE PATH
    ['id' => 'urunai',       'label' => 'Palácio da Vovó Uranai','href' => 'urunai_limpo.php',     'icon' => 'img/urunai.jpg'], // <<<<< VERIFIQUE PATH (aponta para este arquivo)
    ['id' => 'kame',         'label' => 'Casa do Mestre Kame',   'href' => 'kame.php',             'icon' => 'img/kame.jpg'],
    ['id' => 'sala_tempo',   'label' => 'Sala do Tempo',         'href' => 'templo.php',           'icon' => 'img/templo.jpg'],
    ['id' => 'treinos',      'label' => 'Zona de Treinamento',   'href' => 'desafios/treinos.php', 'icon' => 'img/treinos.jpg'], // <<<<< VERIFIQUE PATH
    ['id' => 'desafios',     'label' => 'Desafios Z',            'href' => 'desafios.php',         'icon' => 'img/desafios.jpg'],
    ['id' => 'esferas',      'label' => 'Buscar Esferas',        'href' => 'esferas.php',          'icon' => 'img/esferar.jpg'],
    ['id' => 'erros',        'label' => 'Relatar Erros',         'href' => 'erros.php',            'icon' => 'img/erro.png'],
    ['id' => 'perfil',       'label' => 'Perfil de Guerreiro',   'href' => 'perfil.php',           'icon' => 'img/perfil.jpg']
];
// <<<<< VERIFIQUE TODOS OS PATHS DOS ÍCONES E HREF ACIMA >>>>>

// --- Início da lógica de busca de dados para exibição ---
try {
    // Garante conexão (já feita em conexao.php, $conn deve estar disponível)
    if (!isset($conn) || !$conn instanceof PDO) {
       throw new Exception("Conexão PDO não está disponível após incluir conexao.php.");
    }

    // ***** Busca dados do usuário *****
    // REMOVIDO: ultima_atividade_global da query (não mais necessário sem online list)
    $sql_usuario = "SELECT nome, nivel, hp, pontos, xp, ki, forca, id, defesa, velocidade, foto, tempo_sala, raca, zeni, ranking_titulo
                    FROM usuarios WHERE id = :id_usuario";
    $stmt_usuario = $conn->prepare($sql_usuario);
    $stmt_usuario->execute([':id_usuario' => $id]);
    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        session_destroy();
        header("Location: login.php?erro=UsuarioNaoEncontradoFatal"); // <<<<< VERIFIQUE PATH login.php
        exit();
    }

    // REMOVIDO: Bloco de update 'ultima_atividade_global'

    // Verifica Level Up e adiciona ao feedback (passando $conn)
    $mensagens_level_up_detectado = verificarLevelUpHome($conn, $id); // Passa $conn
    if (!empty($mensagens_level_up_detectado)) {
        $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . "<span class='levelup-message'>" . implode("<br>", $mensagens_level_up_detectado) . "</span>";
        // Recarrega os dados do usuário SE houve level up
        $stmt_usuario->execute([':id_usuario' => $id]);
        $usuario_reloaded = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
        if ($usuario_reloaded) { $usuario = $usuario_reloaded; error_log("User {$id} dados recarregados pós LUP."); }
        else { error_log("ALERTA: Falha ao recarregar dados pós-LUP user {$id}. Usando dados antigos."); }
    }

    // Busca Ranks
    try {
        $sql_rank_nivel = "SELECT rank_nivel FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC, id ASC) as rank_nivel FROM usuarios) AS ranked_by_level WHERE id = :user_id";
        $stmt_rank_nivel = $conn->prepare($sql_rank_nivel);
        $stmt_rank_nivel->execute([':user_id' => $id]);
        $result_nivel = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC);
        if ($result_nivel) { $rank_nivel = $result_nivel['rank_nivel']; }

        $sql_rank_sala = "SELECT rank_sala FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala FROM usuarios) AS ranked_by_time WHERE id = :user_id";
        $stmt_rank_sala = $conn->prepare($sql_rank_sala);
        $stmt_rank_sala->execute([':user_id' => $id]);
        $result_sala = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC);
        if ($result_sala) { $rank_sala = $result_sala['rank_sala']; }
    } catch (PDOException $e_rank) { error_log("PDOException ranks user {$id}: " . $e_rank->getMessage()); $rank_nivel = 'Erro'; $rank_sala = 'Erro'; }

    // REMOVIDO: Bloco de busca de Jogadores Online

    // Busca Notificações Iniciais
    try {
        $stmt_notif_init = $conn->prepare("SELECT id, message_text, type, is_read, timestamp, related_data FROM user_notifications WHERE user_id = :user_id ORDER BY timestamp DESC LIMIT :limit_notif");
        $stmt_notif_init->bindValue(':user_id', $id, PDO::PARAM_INT);
        $stmt_notif_init->bindValue(':limit_notif', $notification_limit, PDO::PARAM_INT);
        if (!$stmt_notif_init->execute()) { error_log("Erro PDO Notif Init user {$id}: ".print_r($stmt_notif_init->errorInfo(), true)); $initial_notifications=[]; }
        else { $initial_notifications = $stmt_notif_init->fetchAll(PDO::FETCH_ASSOC); }
    } catch(PDOException $e_notif) { error_log("PDOException Notif Init user {$id}: ".$e_notif->getMessage()); $initial_notifications=[]; }

} catch (PDOException $e) {
    error_log("Erro PDO GERAL (Setup) " . basename(__FILE__) . " user {$id}: " . $e->getMessage());
    $mensagem_final_feedback .= "<br><span class='error-message'>Erro crítico DB (Setup). Avise um Admin.</span>";
    // Evita erro fatal se $usuario não foi carregado
    if ($usuario === null) { echo "Erro crítico de banco de dados ao carregar a página."; exit(); }
} catch (Exception $ex) {
    error_log("Erro GERAL (Setup) " . basename(__FILE__) . " user {$id}: " . $ex->getMessage());
    $mensagem_final_feedback .= "<br><span class='error-message'>Erro crítico APP (Setup). Avise um Admin.</span>";
    if ($usuario === null) { echo "Erro crítico inesperado ao carregar a página."; exit(); }
}

// --- Cálculos e Formatações Finais ---
// $nome_formatado_usuario = htmlspecialchars($usuario['nome'] ?? 'ErroNome'); // Linha redundante
$nome_formatado_usuario = formatarNomeUsuarioComTag($conn, $usuario); // Passa $conn

// Cálculo XP Bar
$xp_necessario_calc = 0; $xp_percent = 0; $xp_necessario_display = 'N/A';
if (isset($usuario['nivel'])) {
    $nivel_atual_num = filter_var($usuario['nivel'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($nivel_atual_num === false) $nivel_atual_num = 1;
    $xp_necessario_calc = (int)ceil(XP_BASE_LEVEL_UP * pow($nivel_atual_num, XP_EXPOENTE_LEVEL_UP));
    $xp_necessario_calc = max(XP_BASE_LEVEL_UP, $xp_necessario_calc);
    $xp_necessario_display = formatNumber($xp_necessario_calc); // Usa a função
    if ($xp_necessario_calc > 0 && isset($usuario['xp'])) {
        $xp_atual_num = filter_var($usuario['xp'] ?? 0, FILTER_VALIDATE_INT);
        if ($xp_atual_num !== false) { $xp_percent = min(100, max(0, ($xp_atual_num / $xp_necessario_calc) * 100)); }
        else { $xp_percent = 0; }
    }
} else {
     // Define valores padrão se 'nivel' não estiver definido
     $xp_necessario_display = formatNumber(XP_BASE_LEVEL_UP);
     $xp_percent = 0;
}


// Foto do PERFIL
$nome_arquivo_foto = !empty($usuario['foto']) && file_exists('uploads/'.$usuario['foto']) ? $usuario['foto'] : 'default.jpg'; // <<<<< VERIFIQUE PATH uploads/
$caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto); // <<<<< VERIFIQUE PATH uploads/

// Lógica Templo (para botão de navegação)
$hp_atual_base = (int)($usuario['hp'] ?? 0);
$ki_atual_base = (int)($usuario['ki'] ?? 0);
$pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0);
$mensagem_bloqueio_templo_base = ($pode_entrar_templo_base) ? '' : 'Recupere HP/Ki base!';

// --- Array de Navegação Inferior (Bottom Nav) ---
$bottomNavItems = [
    ['label' => 'Home', 'href' => 'home.php', 'icon' => '🏠'],
    ['label' => 'Praça', 'href' => 'praca.php', 'icon' => '👥'],
    ['label' => 'Templo', 'href' => 'templo.php', 'icon' => '🏛️', 'id' => 'sala_tempo'],
    ['label' => 'Kame', 'href' => 'kame.php', 'icon' => '🐢'],
    ['label' => 'Perfil', 'href' => 'perfil.php', 'icon' => '👤']
];
// Adiciona itens faltantes ao Bottom Nav dinamicamente
$foundArena = false; $foundTreinos = false;
foreach ($bottomNavItems as $item) {
    if (strpos($item['href'], 'arena') !== false) $foundArena = true;
    if (strpos($item['href'], 'treinos') !== false || strpos($item['href'], 'desafios') !== false) $foundTreinos = true;
}
if (!$foundArena) { $bottomNavItems[] = ['label' => 'Arena', 'href' => 'arena/arenaroyalle.php', 'icon' => '⚔️']; } // <<<<< VERIFIQUE PATH arena/
if (!$foundTreinos) { $bottomNavItems[] = ['label' => 'Treinos', 'href' => 'desafios/treinos.php', 'icon' => '💪']; } // <<<<< VERIFIQUE PATH desafios/

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Layout Enxuto - <?= htmlspecialchars($usuario['nome'] ?? 'Jogador') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="home/home.css"> <?php // <<<<< VERIFIQUE PATH home.css (NOVO ARQUIVO) ?>
    </head>
<body>

    <?php if (!empty($mensagem_final_feedback)): ?>
      <div class="feedback-container">
          <?= $mensagem_final_feedback; // Contém spans com classes error/success/info/levelup ?>
      </div>
    <?php endif; ?>

    <div class="container">

        <?php // --- COLUNA ESQUERDA (User Info, Stats, Notificações) --- ?>
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
            <?php if ($pontos_num > 0): ?>
                <?php if ($currentPage !== 'perfil.php'): // <<<<< VERIFIQUE PATH perfil.php ?>
                    <a href="perfil.php" class="btn-pontos"> <?= formatNumber($pontos_num) ?> Pontos! <span style="font-size: 0.8em;">(Distribuir)</span> </a>
                <?php else: ?>
                    <div class="btn-pontos" style="cursor: default;"><?= formatNumber($pontos_num) ?> Pontos <span style="font-size: 0.8em;">(Disponíveis)</span></div>
                <?php endif; ?>
            <?php endif; ?>
            <div class="stats-grid">
                <strong>HP Base</strong><span><?= formatNumber($usuario['hp'] ?? 0) ?></span>
                <strong>Ki Base</strong><span><?= formatNumber($usuario['ki'] ?? 0) ?></span>
                <strong>Força</strong><span><?= formatNumber($usuario['forca'] ?? 0) ?></span>
                <strong>Defesa</strong><span><?= formatNumber($usuario['defesa'] ?? 0) ?></span>
                <strong>Velocidade</strong><span><?= formatNumber($usuario['velocidade'] ?? 0) ?></span>
                <strong>Pontos</strong><span style="color: var(--accent-color-2);"><?= formatNumber($usuario['pontos'] ?? 0) ?></span>
                <strong>Rank (Nível)</strong><span style="color: var(--accent-color-1);"><?= htmlspecialchars($rank_nivel) ?><?= ($rank_nivel !== 'N/A' && $rank_nivel !== 'Erro' ? 'º' : '') ?></span>
                <strong>Rank (Sala)</strong><span style="color: var(--accent-color-1);"><?= htmlspecialchars($rank_sala) ?><?= ($rank_sala !== 'N/A' && $rank_sala !== 'Erro' ? 'º' : '') ?></span>
            </div>
            <div class="divider"></div>
            <div class="notifications-area">
                <h3>Notificações</h3>
                <div id="notifications-container" class="notifications-container-style">
                    <div id="notification-list">
                        <?php if (!empty($initial_notifications)): ?>
                            <?php foreach (array_reverse($initial_notifications) as $notif): // Mostra mais recentes primeiro ?>
                                <?php
                                    $notif_class = 'notification-item type-' . htmlspecialchars($notif['type'] ?? 'info');
                                    // Usando a função formatTimestamp do JS, então não precisamos formatar aqui
                                    // $timestamp_fmt = isset($notif['timestamp']) ? date('H:i', strtotime($notif['timestamp'])) : '??:??';
                                    $related_data_sanitized = null;
                                    if (isset($notif['related_data'])) {
                                        if (is_string($notif['related_data'])) { try { $decoded = json_decode($notif['related_data'], true); $related_data_sanitized = ($decoded === null && json_last_error() !== JSON_ERROR_NONE) ? null : $decoded; } catch (Exception $e) { $related_data_sanitized = null; } }
                                        elseif (is_array($notif['related_data']) || is_object($notif['related_data'])) { $related_data_sanitized = $notif['related_data']; }
                                    }
                                    $related_data_json = json_encode($related_data_sanitized ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                ?>
                                <div class="<?= $notif_class ?>" data-notification-id="<?= (int)$notif['id'] ?>" data-timestamp="<?= htmlspecialchars($notif['timestamp'] ?? '') ?>" data-related='<?= htmlspecialchars($related_data_json, ENT_QUOTES, 'UTF-8') ?>'>
                                    <button class="mark-read-btn" title="Remover Notificação" onclick="markNotificationAsRead(<?= (int)$notif['id'] ?>, this)">×</button>
                                    <span class="timestamp"></span>
                                    <span class="msg-text"><?= htmlspecialchars($notif['message_text']) // Mensagem já sanitizada ?></span>
                                    <?php // Lógica JS adiciona botões de ação se necessário (ex: convites) ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="notification-empty">Nenhuma notificação nova.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
             <div class="stats-grid" style="font-size: 0.85rem; margin-top: 10px;">
                 <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span>
                 <strong>Tempo Sala</strong><span><?= formatNumber($usuario['tempo_sala'] ?? 0) ?> min</span>
             </div>
             <div style="margin-top: auto; padding-top: 15px; display: flex; flex-direction: column; gap: 10px; border-top: 1px solid var(--panel-border);">
                 <a class="action-button btn-perfil" href="perfil.php">Meu Perfil</a> <?php // <<<<< VERIFIQUE PATH perfil.php ?>
                 <a class="action-button btn-logout" href="sair.php">Sair (Logout)</a> <?php // <<<<< VERIFIQUE PATH sair.php ?>
             </div>
        </div>

        <?php // --- COLUNA CENTRAL (Vazia) --- ?>
        <div class="coluna coluna-central">
             </div>

        <?php // --- COLUNA DIREITA (Navegação) --- ?>
        <div class="coluna coluna-direita">
            <?php
                foreach ($sidebarItems as $item) {
                    $href = $item['href'];
                    $label = htmlspecialchars($item['label']);
                    $iconPath = $item['icon'] ?? '';
                    $idItem = $item['id'];
                    $classe_extra = '';
                    $atributos_extra = '';
                    $title = $label;
                    $iconHtml = '<i class="fas fa-question-circle" style="opacity: 0.5;"></i>'; // Ícone padrão

                    // Verifica se $iconPath é arquivo ou classe FA
                    if (!empty($iconPath)) {
                        $iconFullPath = $iconPath; // Assume caminho relativo à raiz ou URL absoluta
                        // Verifica se é um caminho de arquivo plausível E existe
                        if ((strpos($iconFullPath, '/') !== false || strpos($iconFullPath, '.') !== false) && !filter_var($iconFullPath, FILTER_VALIDATE_URL)) {
                            if (file_exists($iconFullPath)) { // <<<<< VERIFIQUE PATH BASE AQUI (se relativo)
                                $iconHtml = '<img src="' . htmlspecialchars($iconFullPath) . '" class="nav-button-icon" alt="">';
                            } else {
                                error_log("Icone (IMG) nao encontrado Nav Direita: " . $iconFullPath . " (Arquivo: " . basename(__FILE__) . ")");
                                // Mantém ícone padrão se não encontrado
                            }
                        // Se for URL, usa direto
                        } elseif (filter_var($iconFullPath, FILTER_VALIDATE_URL)) {
                             $iconHtml = '<img src="' . htmlspecialchars($iconFullPath) . '" class="nav-button-icon" alt="">';
                        // Se for classe FontAwesome
                        } elseif (strpos($iconFullPath, 'fa-') !== false) {
                            $iconHtml = '<i class="icon-placeholder fas '.htmlspecialchars($iconFullPath).'"></i>';
                        }
                    }


                    // Lógica para botão da Sala do Tempo
                    if ($idItem === 'sala_tempo') {
                        if (!$pode_entrar_templo_base) {
                            $classe_extra = ' disabled';
                            $atributos_extra = ' onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES).'\'); return false;"';
                            $title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                            $href = '#'; // Desativa link
                        } else { $title = 'Entrar na Sala do Templo'; }
                    }

                    // Verifica se é a página atual
                    $baseItemHrefForCompare = basename(parse_url($href, PHP_URL_PATH));
                    $isCurrent = ($currentPage == $baseItemHrefForCompare && $href !== '#');
                    // Ajustes para páginas alias
                    if (($idItem == 'praca' || $idItem == 'home') && ($currentPage == 'home.php' || $currentPage == 'praca.php')) { $isCurrent = true; }
                    if (($idItem == 'treinos' || $idItem == 'desafios') && ($currentPage == 'treinos.php' || $currentPage == 'desafios.php')) { $isCurrent = true; }
                    // if ($idItem == 'urunai' && $currentPage == basename(__FILE__)) { $isCurrent = true; } // Página atual

                    if ($isCurrent && strpos($classe_extra, 'disabled') === false) { $classe_extra .= ' active'; }
                    $finalHref = htmlspecialchars($href);

                    echo "<a class=\"nav-button{$classe_extra}\" href=\"{$finalHref}\" title=\"{$title}\" {$atributos_extra}>";
                    echo "<div class='nav-button-icon-area'>{$iconHtml}</div>";
                    echo "<span>{$label}</span>";
                    echo "</a>";
                }

                // REMOVIDO: Bloco Jogadores Online

                // Botão Sair (Adicionado margem superior para compensar remoção do online list)
                echo '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--panel-border);">'; // Wrapper para espaçamento
                $sairIconPath = 'img/sair.jpg'; // <<<<< VERIFIQUE PATH img/sair.jpg
                $sairIconHtml = '<i class="fas fa-sign-out-alt"></i>'; // Ícone padrão FA
                if(file_exists($sairIconPath)) { $sairIconHtml = '<img src="'.htmlspecialchars($sairIconPath).'" class="nav-button-icon" alt="">'; }
                 echo '<a class="nav-button logout" href="sair.php" title="Sair do Jogo">'; // <<<<< VERIFIQUE PATH sair.php
                 echo "<div class='nav-button-icon-area'>{$sairIconHtml}</div>";
                 echo '<span>Sair</span></a>';
                 echo '</div>'; // Fim do wrapper
            ?>
        </div>
    </div> <?php // Fechamento do .container ?>

    <?php // --- Bottom Nav --- ?>
    <nav id="bottom-nav">
        <?php foreach ($bottomNavItems as $item): ?>
            <?php
                $url_destino_item = $item['href'];
                $final_href = '#'; $final_onclick = ''; $final_title = ''; $extra_class = '';
                $is_temple_button = (isset($item['id']) && $item['id'] === 'sala_tempo');
                $is_current_page = (basename(parse_url($url_destino_item, PHP_URL_PATH)) === $currentPage);
                 // Ajustes para páginas alias
                 if (($item['label'] === 'Home' || $item['label'] === 'Praça') && ($currentPage === 'home.php' || $currentPage === 'praca.php')) { $is_current_page = true; }
                 if ($item['label'] === 'Treinos' && ($currentPage === 'treinos.php' || $currentPage === 'desafios.php')) { $is_current_page = true; }
                 // if ($item['label'] === 'Urunai' && $currentPage === basename(__FILE__)) { $is_current_page = true; } // Página atual

                if ($is_temple_button) {
                    if (!$pode_entrar_templo_base) {
                        $final_onclick = ' onclick="alert(\'' . htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES) . '\'); return false;"';
                        $final_title = htmlspecialchars($mensagem_bloqueio_templo_base, ENT_QUOTES);
                        $extra_class = ' disabled';
                    } else { $final_href = htmlspecialchars($url_destino_item); $final_title = 'Entrar na Sala do Templo'; }
                } else { $final_href = htmlspecialchars($url_destino_item); $final_title = 'Ir para ' . htmlspecialchars($item['label']); }

                $active_class = ($is_current_page && strpos($extra_class, 'disabled') === false) ? ' active' : '';

                echo "<a href='{$final_href}' class='bottom-nav-item{$active_class}{$extra_class}' {$final_onclick} title='{$final_title}'>";
                echo "<span style='font-size: 1.4rem; line-height: 1; margin-bottom: 3px;'>{$item['icon']}</span>";
                echo "<span>" . htmlspecialchars($item['label']) . "</span>";
                echo "</a>";
            ?>
        <?php endforeach; ?>
    </nav>

    <?php // --- JavaScript Essencial (Apenas Notificações) --- ?>
    <script>
        // Variáveis JS Globais
        const currentUserId_Notif=<?php echo json_encode((int)$id);?>;
        const notificationHandlerUrl='notification_handler_ajax.php'; // <<<<< VERIFIQUE PATH

        // Variáveis de Estado e Controle (Notificações)
        let lastNotificationId=0;
        <?php if(!empty($initial_notifications)){$lid=0;foreach($initial_notifications as $n){if((int)$n['id']>$lid){$lid=(int)$n['id'];}} echo 'lastNotificationId = '.$lid.';';} ?>
        const notificationPollInterval=10000; // 10 segundos
        let notificationPollingIntervalId=null;
        let isFetchingNotifications=false;
        const notificationListDiv=document.getElementById('notification-list');

        // Função Utilitária para escapar HTML
        function escapeHtml(unsafe){if(typeof unsafe!=='string'){if(typeof unsafe==='number'){unsafe=String(unsafe);}else{return'';}} return unsafe.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");}

        // Função Utilitária para formatar Timestamp
        function formatTimestamp(timestamp){if(!timestamp)return'??:??'; try{ let ds=timestamp.replace(/-/g,'/'); if(ds.indexOf(' ')>0&&ds.indexOf('T')===-1&&!ds.endsWith('Z')&&!ds.match(/[+-]\d{2}:?\d{2}$/)){/*Assume UTC if no tz info*/} const d=new Date(ds); if(!isNaN(d.getTime())){ return d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'}); } }catch(e){console.error("Erro formatar timestamp:", e, timestamp);} const parts=timestamp.split(' '); if(parts.length>1&&parts[1].includes(':')){ const timePart = parts[1].substring(0,5); if (/^\d{2}:\d{2}$/.test(timePart)) return timePart; } return'??:??'; }


        // --- Sistema de Notificações ---
        function initializeNotificationSystem(){
            if(!notificationListDiv) {console.warn("Div 'notification-list' não encontrada."); return;}
            const initialItems = notificationListDiv.querySelectorAll('.notification-item');
            // Preenche timestamp inicial e processa botões
            initialItems.forEach(item => {
                const timestampSpan = item.querySelector('.timestamp');
                const timestampData = item.dataset.timestamp;
                if (timestampSpan && timestampData) {
                    timestampSpan.textContent = formatTimestamp(timestampData);
                }
                processNotificationItem(item);
            });
            if(notificationPollingIntervalId) clearInterval(notificationPollingIntervalId);
            setTimeout(fetchNotificationUpdates, 700); // Busca inicial com pequeno delay
            notificationPollingIntervalId = setInterval(fetchNotificationUpdates, notificationPollInterval);
            document.addEventListener("visibilitychange", handleVisibilityChangeNotifications);
            if(notificationListDiv) { notificationListDiv.scrollTop = 0; } // Garante scroll no topo ao carregar
            checkEmptyNotifications(); // Verifica se está vazio inicialmente
        }


        function processNotificationItem(item) { // Adiciona botões dinamicamente se for convite
             if (!item || item.querySelector('.notification-actions')) return; // Já processado ou sem dados
             try {
                 const relatedDataStr = item.dataset.related;
                 const notificationId = item.dataset.notificationId;
                 const isInvite = item.classList.contains('type-invite'); // Checa a classe

                 if (isInvite && relatedDataStr && notificationId) {
                     const relatedData = JSON.parse(relatedDataStr);
                     const actionsHtml = generateNotificationActions({ id: notificationId, type: 'invite', related_data: relatedData });
                     if (actionsHtml) {
                         const actionsContainer = document.createElement('div');
                         actionsContainer.classList.add('notification-actions');
                         actionsContainer.innerHTML = actionsHtml;
                         item.appendChild(actionsContainer);
                     }
                 }
             } catch (e) { console.error("Erro processar notif item:", e, item.dataset.related); }
         }

         function generateNotificationActions(notif) { // Gera HTML dos botões (exemplo)
             let relatedData = notif.related_data;
             if (!relatedData || typeof relatedData !== 'object') return '';
             if (notif.type === 'invite') {
                 try {
                     // ***** ADAPTE AQUI para seus tipos de convite (ex: 'fusion', 'group') *****
                     // if (relatedData.invite_type === 'fusion' && notif.id) { ... }
                     if (notif.id) {
                         const acceptAction = `handleInviteAction(${notif.id}, 'accept', this)`;
                         const rejectAction = `handleInviteAction(${notif.id}, 'reject', this)`;
                         return `<button onclick="${acceptAction}" class='btn-action btn-aceitar'>Aceitar</button>
                                 <button onclick="${rejectAction}" class='btn-action btn-recusar'>Recusar</button>`;
                     }
                 } catch (e) { console.error("Erro gerar ações notif:", e); }
             }
             return '';
         }

        async function handleInviteAction(notificationId, action, buttonElement) { // AJAX para responder convite
             if (!notificationId || !action || !buttonElement) return;
             const actionsContainer = buttonElement.closest('.notification-actions');
             if (actionsContainer) { actionsContainer.querySelectorAll('.btn-action').forEach(btn => btn.disabled = true); actionsContainer.style.opacity = '0.5'; }
             try {
                 const formData = new FormData();
                 formData.append('notification_id', notificationId);
                 formData.append('invite_action', action);
                 // ***** VERIFIQUE A URL E O PARÂMETRO 'action' DO SEU HANDLER PHP *****
                 const response = await fetch(`${notificationHandlerUrl}?action=handle_invite`, { method: 'POST', body: formData });
                 if (!response.ok) { throw new Error(`Erro servidor: ${response.status}`); }
                 const data = await response.json();
                 if (data.success) {
                     const notificationItem = buttonElement.closest('.notification-item');
                     if (notificationItem) { // Animação de remoção
                         notificationItem.style.transition = 'opacity 0.3s, transform 0.3s, height 0.3s, margin 0.3s, padding 0.3s, border 0.3s';
                         notificationItem.style.opacity = '0'; notificationItem.style.transform = 'translateX(50px)';
                         notificationItem.style.height = '0'; notificationItem.style.margin = '0';
                         notificationItem.style.paddingTop = '0'; notificationItem.style.paddingBottom = '0';
                         notificationItem.style.borderWidth = '0';
                         setTimeout(() => { if(notificationItem.parentNode) { notificationItem.remove(); checkEmptyNotifications(); } }, 300);
                     }
                     console.log(`Ação '${action}' id ${notificationId} OK.`);
                     // Opcional: Mostrar feedback visual
                     // if(data.feedback) { /* Adicione feedback aqui */ }
                 } else { throw new Error(data.error || 'Falha ao processar no servidor.'); }
             } catch (error) {
                 console.error('Erro ao processar ação do convite:', error);
                 alert(`Erro ao ${action === 'accept' ? 'aceitar' : 'recusar'} o convite. Tente novamente.\n${error.message}`);
                 if (actionsContainer) { actionsContainer.querySelectorAll('.btn-action').forEach(btn => btn.disabled = false); actionsContainer.style.opacity = '1'; }
             }
         }

        function handleVisibilityChangeNotifications() {
            if (document.hidden) {
                if (notificationPollingIntervalId) { clearInterval(notificationPollingIntervalId); notificationPollingIntervalId = null; }
            } else {
                if (!notificationPollingIntervalId) {
                    fetchNotificationUpdates(); // Busca imediatamente ao voltar
                    notificationPollingIntervalId = setInterval(fetchNotificationUpdates, notificationPollInterval);
                }
            }
        }

        function addNotificationToDisplay(notif) {
            if (!notificationListDiv || !notif || !notif.id) return;
            if (document.querySelector(`.notification-item[data-notification-id="${notif.id}"]`)) return; // Evita duplicados

            checkEmptyNotifications(true); // Remove msg "vazio" se existir

            const item = document.createElement('div');
            item.classList.add('notification-item', `type-${escapeHtml(notif.type) || 'info'}`);
            item.dataset.notificationId = notif.id;
            // Adiciona timestamp ao data attribute para formatação inicial
            item.dataset.timestamp = notif.timestamp || '';
            const relatedDataSanitized = notif.related_data || {};
            const relatedDataJson = JSON.stringify(relatedDataSanitized, (key, value) => value === undefined ? null : value); // Garante JSON válido
            item.dataset.related = relatedDataJson;

            const timestamp = formatTimestamp(notif.timestamp || '');
            const message = escapeHtml(notif.message_text || 'Notificação inválida.');

            item.innerHTML = `
                <button class="mark-read-btn" title="Remover Notificação" onclick="markNotificationAsRead(${notif.id}, this)">×</button>
                <span class="timestamp">${timestamp}</span>
                <span class="msg-text">${message}</span>
                ${generateNotificationActions(notif)}
            `;

            notificationListDiv.insertBefore(item, notificationListDiv.firstChild); // Adiciona no topo
            // Limita o número de notificações visíveis (opcional)
            const maxNotifications = 30;
            while (notificationListDiv.children.length > maxNotifications) {
                if(notificationListDiv.lastChild && notificationListDiv.lastChild.nodeType === Node.ELEMENT_NODE) { // Check if it's an element node
                    notificationListDiv.removeChild(notificationListDiv.lastChild);
                } else {
                    break; // Avoid infinite loop if lastChild is not an element
                }
            }


            // Efeito visual de entrada
            item.style.opacity = '0'; item.style.transform = 'translateY(-10px)';
            requestAnimationFrame(() => { item.style.transition = 'opacity 0.3s ease, transform 0.3s ease'; item.style.opacity = '1'; item.style.transform = 'translateY(0)'; });
        }


        async function markNotificationAsRead(notificationId, buttonElement) {
             if (!notificationId || !buttonElement) return;
             const notificationItem = buttonElement.closest('.notification-item');
             if (!notificationItem) return;

             // Animação de remoção
              notificationItem.style.transition = 'opacity 0.3s, transform 0.3s, height 0.3s, margin 0.3s, padding 0.3s, border 0.3s';
              notificationItem.style.opacity = '0'; notificationItem.style.transform = 'translateX(50px)';
              notificationItem.style.height = '0'; notificationItem.style.margin = '0';
              notificationItem.style.paddingTop = '0'; notificationItem.style.paddingBottom = '0';
              notificationItem.style.borderWidth = '0';

              setTimeout(() => {
                  if(notificationItem.parentNode) { // Check if still attached
                     notificationItem.remove();
                     checkEmptyNotifications();
                  }
              }, 300);


             try {
                 const formData = new FormData();
                 formData.append('notification_id', notificationId);
                 // ***** VERIFIQUE A URL E O PARÂMETRO 'action' DO SEU HANDLER PHP *****
                 const response = await fetch(`${notificationHandlerUrl}?action=mark_read`, { method: 'POST', body: formData });
                 if (!response.ok) throw new Error(`Erro servidor: ${response.status}`);
                 const data = await response.json();
                 if (!data.success) console.warn('Falha ao marcar notificação como lida no servidor:', data.error);
                 else console.log(`Notificação ${notificationId} marcada como lida.`);
             } catch (error) {
                 console.error('Erro ao marcar notificação como lida:', error);
                 // Opcional: Reverter a remoção visual em caso de erro de comunicação?
                 // Se a remoção falhou, talvez re-adicionar o item ou remover a animação
             }
         }

        function checkEmptyNotifications(forceRemoveMsg = false) {
            if (!notificationListDiv) return;
            const existingEmptyMsg = notificationListDiv.querySelector('.notification-empty');
            const hasItems = notificationListDiv.querySelector('.notification-item');

            if (forceRemoveMsg && existingEmptyMsg) {
                 existingEmptyMsg.remove();
            } else if (!hasItems && !existingEmptyMsg) {
                const p = document.createElement('p');
                p.classList.add('notification-empty');
                p.textContent = 'Nenhuma notificação nova.';
                notificationListDiv.appendChild(p);
            } else if (hasItems && existingEmptyMsg) {
                existingEmptyMsg.remove();
            }
        }

        async function fetchNotificationUpdates() {
            if (isFetchingNotifications || document.hidden) return;
            isFetchingNotifications = true;
            // console.log('Buscando notificações após ID:', lastNotificationId); // Debug
            try {
                // ***** VERIFIQUE A URL E O PARÂMETRO 'action' DO SEU HANDLER PHP *****
                const response = await fetch(`${notificationHandlerUrl}?action=get_updates&last_id=${lastNotificationId}`);
                if (!response.ok) throw new Error(`Erro servidor: ${response.status}`);
                const data = await response.json();
                 // console.log('Notificações recebidas:', data); // Debug

                if (data.success && Array.isArray(data.notifications) && data.notifications.length > 0) {
                    let newLastId = lastNotificationId;
                     // Processa em ordem reversa (mais antiga primeiro) para inserir corretamente
                    data.notifications.reverse().forEach(notif => {
                        if (notif && notif.id) {
                             addNotificationToDisplay(notif);
                             if (parseInt(notif.id) > newLastId) { newLastId = parseInt(notif.id); }
                        }
                    });
                    lastNotificationId = newLastId;
                } else if (!data.success) {
                    console.error('Erro ao buscar notificações:', data.error);
                }
                 // Atualiza lastId mesmo se vazio para evitar rebuscar sempre
                 if (data.last_processed_id && parseInt(data.last_processed_id) > lastNotificationId) {
                    lastNotificationId = parseInt(data.last_processed_id);
                 }
            } catch (error) {
                console.error('Falha na requisição de notificações:', error);
            } finally {
                isFetchingNotifications = false;
            }
        }


        // --- Inicialização Geral ---
        document.addEventListener('DOMContentLoaded', () => {
            // Adiciona listeners para botões específicos
            document.querySelectorAll('.nav-button[onclick*="alert"], .bottom-nav-item[onclick*="alert"]').forEach(button => {
                button.addEventListener('click', e => {
                    if (button.classList.contains('disabled')) {
                        button.style.transition = 'box-shadow 0.1s ease-in-out';
                        button.style.boxShadow = '0 0 5px 2px var(--danger-color)';
                        setTimeout(() => { button.style.boxShadow = ''; }, 300);
                    }
                });
            });
            // Adiciona listeners para botões com data-url
            document.querySelectorAll('[data-url]').forEach(button => {
                if (!button.classList.contains('disabled') && button.getAttribute('href') === '#') {
                    button.addEventListener('click', event => { event.preventDefault(); const url = button.dataset.url; if (url) { window.location.href = url; } });
                }
            });

            // Controle de visibilidade do Bottom Nav vs Coluna Direita
            const bottomNav = document.getElementById('bottom-nav');
            const rightColumn = document.querySelector('.coluna-direita');
            function checkBottomNavVisibility() {
                if (!bottomNav || !rightColumn) return;
                if (window.getComputedStyle(rightColumn).display === 'none') {
                    bottomNav.style.display = 'flex';
                    document.body.style.paddingBottom = (bottomNav.offsetHeight + 10) + 'px';
                } else {
                    bottomNav.style.display = 'none';
                    const defaultPadding = window.getComputedStyle(document.body).getPropertyValue('--body-original-padding-bottom') || '20px';
                    document.body.style.paddingBottom = defaultPadding;
                }
            }
            checkBottomNavVisibility();
            window.addEventListener('resize', checkBottomNavVisibility);
            setTimeout(checkBottomNavVisibility, 300); // Check again after potential reflow

            // Inicializa os sistemas de AJAX
            initializeNotificationSystem();
        });
    </script>

</body>
</html>
<?php
// Fecha conexão PDO no final do script
if (isset($conn)) { $conn = null; }
?>