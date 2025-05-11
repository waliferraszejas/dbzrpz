<?php
// Arquivo: perfil.php (Layout "Terreno de Treinamento")

// Exibe erros para facilitar a depuração
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); } // <<<<< VERIFIQUE PATH

// <<<<< VERIFIQUE PATHS >>>>>
require_once("conexao.php"); // Assume na raiz?
require_once('script/sala_functions.php'); // Assume na raiz?

$id = $_SESSION['user_id'];
$id_usuario_logado = $id;
$erro_atualizacao = null; // Armazena erros do POST para exibir na página
$mensagem_final_feedback = ""; // Mensagem geral de feedback

// ---> Bloco para processar o formulário POST (Movido para o TOPO) <---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verifica conexão
    if (!isset($conn) || !$conn instanceof PDO) { if (isset($pdo) && $pdo instanceof PDO) { $conn = $pdo; } else { die("Erro crítico: Conexão PDO não estabelecida (Perfil POST)."); } }

    $novo_nome = trim($_POST['nome'] ?? '');
    $pontos_para_hp = max(0, filter_input(INPUT_POST, 'hp', FILTER_VALIDATE_INT) ?: 0);
    $pontos_para_ki = max(0, filter_input(INPUT_POST, 'ki', FILTER_VALIDATE_INT) ?: 0);
    $pontos_para_forca = max(0, filter_input(INPUT_POST, 'forca', FILTER_VALIDATE_INT) ?: 0);
    $pontos_para_defesa = max(0, filter_input(INPUT_POST, 'defesa', FILTER_VALIDATE_INT) ?: 0);
    $pontos_para_velocidade = max(0, filter_input(INPUT_POST, 'velocidade', FILTER_VALIDATE_INT) ?: 0);
    $total_pontos_distribuidos = $pontos_para_hp + $pontos_para_ki + $pontos_para_forca + $pontos_para_defesa + $pontos_para_velocidade;

    if (empty($novo_nome)) {
        $erro_atualizacao = "O nome não pode ficar vazio.";
    } elseif (mb_strlen($novo_nome) > 50) { // Adiciona validação de tamanho
         $erro_atualizacao = "O nome não pode ter mais que 50 caracteres.";
    } else {
        try {
            if (!$conn->beginTransaction()) { throw new PDOException("Não foi possível iniciar a transação."); }

            // Pega dados atuais e bloqueia a linha
            $sql_get_current = "SELECT nome, pontos, foto FROM usuarios WHERE id = :id FOR UPDATE";
            $stmt_get = $conn->prepare($sql_get_current);
            $stmt_get->execute([':id' => $id]);
            $dados_atuais = $stmt_get->fetch(PDO::FETCH_ASSOC);

            if (!$dados_atuais) { throw new PDOException("Usuário não encontrado durante a atualização."); }

            $nome_atual_db = $dados_atuais['nome'];
            $pontos_atuais_db = (int)($dados_atuais['pontos'] ?? 0);
            $foto_antiga_db = $dados_atuais['foto'] ?? 'default.jpg';

            // Valida pontos distribuídos
            if ($total_pontos_distribuidos > $pontos_atuais_db) {
                throw new PDOException("Você tentou distribuir mais pontos (". $total_pontos_distribuidos .") do que possui (". $pontos_atuais_db .")!");
            }

            // Valida nome (se mudou e se já existe)
            if ($novo_nome !== $nome_atual_db) {
                $sql_check_name = "SELECT id FROM usuarios WHERE nome = :nome AND id != :id LIMIT 1";
                $stmt_check_name = $conn->prepare($sql_check_name);
                $stmt_check_name->execute([':nome' => $novo_nome, ':id' => $id]);
                if ($stmt_check_name->fetchColumn()) {
                    throw new PDOException("Este nome de personagem já está em uso por outro jogador.");
                }
            }

            // --- Processamento da Foto ---
            $nova_foto_db = null; // Nome do arquivo a ser salvo no DB
            $diretorio_uploads = __DIR__ . '/uploads/'; // <<<<< VERIFIQUE PATH

            if (isset($_FILES["foto"]) && $_FILES["foto"]["error"] == UPLOAD_ERR_OK) {
                $foto_info = $_FILES["foto"];
                $foto_tmp = $foto_info["tmp_name"];
                $foto_nome_original = basename($foto_info["name"]);
                $foto_tamanho = $foto_info["size"];

                // Validação rigorosa do tipo MIME
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $foto_tipo = finfo_file($finfo, $foto_tmp);
                finfo_close($finfo);

                $tipos_permitidos = ['image/jpeg', 'image/png', 'image/gif'];
                $tamanho_maximo = 2 * 1024 * 1024; // 2MB

                if (!in_array($foto_tipo, $tipos_permitidos)) { throw new PDOException("Formato de imagem inválido (Permitidos: JPG, PNG, GIF). Tipo detectado: " . $foto_tipo); }
                if ($foto_tamanho > $tamanho_maximo) { throw new PDOException("Imagem muito grande (máximo 2MB)."); }

                // Validação da extensão (redundante mas boa prática)
                $extensao = strtolower(pathinfo($foto_nome_original, PATHINFO_EXTENSION));
                if (!in_array($extensao, ['jpg', 'jpeg', 'png', 'gif'])) { throw new PDOException("Extensão de arquivo inválida."); }

                // Gera nome único
                $nome_unico = uniqid('user_'.$id.'_', true) . '.' . $extensao;

                // Cria diretório se não existir
                if (!is_dir($diretorio_uploads)) {
                    if (!mkdir($diretorio_uploads, 0775, true)) { throw new PDOException("Falha ao criar diretório de uploads."); }
                }

                $caminho_destino = $diretorio_uploads . $nome_unico;

                // Move o arquivo
                if (move_uploaded_file($foto_tmp, $caminho_destino)) {
                    $nova_foto_db = $nome_unico; // Salva o nome do novo arquivo

                    // Apaga foto antiga (se não for default)
                    if ($foto_antiga_db && $foto_antiga_db !== 'default.jpg' && file_exists($diretorio_uploads . $foto_antiga_db)) {
                        @unlink($diretorio_uploads . $foto_antiga_db); // Usa @ para suprimir erros se não conseguir deletar
                    }
                } else { throw new PDOException("Erro ao salvar a nova imagem. Verifique permissões do diretório '$diretorio_uploads'."); }

            } elseif (isset($_FILES["foto"]) && $_FILES["foto"]["error"] != UPLOAD_ERR_NO_FILE) {
                // Trata outros erros de upload
                throw new PDOException("Erro no upload da imagem (Código: " . $_FILES["foto"]["error"] . ").");
            }
            // --- Fim Processamento Foto ---

            // --- Monta Query de Atualização ---
            $sql_update_parts = [];
            $params_pdo = [];
            $alguma_alteracao = false;

            if ($novo_nome !== $nome_atual_db) { $sql_update_parts[] = "nome = :nome"; $params_pdo[':nome'] = $novo_nome; $alguma_alteracao = true; }
            if ($nova_foto_db !== null) { $sql_update_parts[] = "foto = :foto"; $params_pdo[':foto'] = $nova_foto_db; $alguma_alteracao = true; }
            if ($total_pontos_distribuidos > 0) {
                $sql_update_parts[] = "hp = hp + :add_hp"; $params_pdo[':add_hp'] = $pontos_para_hp;
                $sql_update_parts[] = "ki = ki + :add_ki"; $params_pdo[':add_ki'] = $pontos_para_ki;
                $sql_update_parts[] = "forca = forca + :add_forca"; $params_pdo[':add_forca'] = $pontos_para_forca;
                $sql_update_parts[] = "defesa = defesa + :add_defesa"; $params_pdo[':add_defesa'] = $pontos_para_defesa;
                $sql_update_parts[] = "velocidade = velocidade + :add_velocidade"; $params_pdo[':add_velocidade'] = $pontos_para_velocidade;
                $sql_update_parts[] = "pontos = pontos - :total_distribuidos"; $params_pdo[':total_distribuidos'] = $total_pontos_distribuidos;
                $alguma_alteracao = true;
            }

            if ($alguma_alteracao) {
                $sql_update = "UPDATE usuarios SET " . implode(", ", $sql_update_parts) . " WHERE id = :id";
                $params_pdo[':id'] = $id;
                $stmt_update = $conn->prepare($sql_update);

                if (!$stmt_update || !$stmt_update->execute($params_pdo)) { throw new PDOException("Falha ao executar a atualização do perfil."); }
                if ($stmt_update->rowCount() == 0 && $alguma_alteracao) { error_log("Alerta: Update perfil user {$id} não afetou linhas."); } // Aviso se não afetou

                if (!$conn->commit()) { throw new PDOException("Falha ao confirmar atualização (commit)."); }
                $_SESSION['feedback_perfil'] = "Perfil atualizado com sucesso!"; // Mensagem de sucesso na sessão

            } else {
                // Se não houve alterações, apenas desfaz a transação (se iniciada)
                if ($conn->inTransaction()) { $conn->rollBack(); }
                $_SESSION['feedback_perfil'] = "Nenhuma alteração foi feita no perfil."; // Mensagem informativa
            }

            // Redireciona para evitar reenvio do formulário com F5
            header("Location: perfil.php");
            exit();

        } catch (PDOException | Exception $e) { // Captura ambos PDO e Exception geral
            // Desfaz a transação em caso de erro
            if ($conn->inTransaction()) { $conn->rollBack(); }
            error_log("Erro ao atualizar perfil user {$id}: " . $e->getMessage());
            // Armazena o erro para exibir abaixo, não na sessão para não persistir após redirect
            $erro_atualizacao = "Erro ao atualizar perfil: " . $e->getMessage();
        }
    }
}
// --- FIM DO BLOCO DE PROCESSAMENTO POST ---


// --- Início da lógica de busca de dados para exibição ---
$usuario = null; $rank_nivel = 'N/A'; $rank_sala = 'N/A';
$pode_entrar_templo_base = false; $mensagem_bloqueio_templo_base = '';
$initial_notifications = [];

// Constantes (Redefinidas caso não estejam no escopo global)
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);
if (!defined('PONTOS_GANHO_POR_NIVEL')) define('PONTOS_GANHO_POR_NIVEL', 100);

// Combina feedback da sessão com erro local do POST
$mensagem_final_feedback = ""; // Limpa para evitar duplicidade em reloads
if (isset($_SESSION['feedback_perfil'])) {
    // Assume sucesso se veio da sessão após redirect
    $mensagem_final_feedback = "<span class='success-message'>".$_SESSION['feedback_perfil']."</span>";
    unset($_SESSION['feedback_perfil']);
} elseif ($erro_atualizacao) {
    // Exibe erro que ocorreu durante o POST desta página
    $mensagem_final_feedback = "<span class='error-message'>".htmlspecialchars($erro_atualizacao)."</span>";
}

// Função Level Up
if (!function_exists('verificarLevelUpHome')) {
    // Colar a função completa aqui se não estiver no require_once
    function verificarLevelUpHome(PDO $pdo_conn, int $id_usuario): array { return []; } // Dummy
}

try {
    if (!isset($conn) || !$conn instanceof PDO) { if (isset($pdo) && $pdo instanceof PDO) { $conn = $pdo; } else { throw new Exception("Conexão PDO não disponível."); } }

    // Busca dados ATUALIZADOS do usuário
    $sql_usuario = "SELECT nome, nivel, hp, pontos, xp, ki, forca, id, defesa, velocidade, foto, tempo_sala, raca, zeni, ranking_titulo FROM usuarios WHERE id = :id_usuario";
    $stmt_usuario = $conn->prepare($sql_usuario); $stmt_usuario->execute([':id_usuario' => $id]); $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
    if (!$usuario) { session_destroy(); header("Location: login.php?erro=UsuarioInexistentePerfilLoad"); exit(); } // <<<<< VERIFIQUE PATH

    // Verifica Level Up (pode adicionar ao feedback se ocorrer)
    $mensagens_lup = verificarLevelUpHome($conn, $id);
    if (!empty($mensagens_lup)) { $mensagem_final_feedback .= ($mensagem_final_feedback ? "<br>" : "") . implode("<br>", $mensagens_lup); $stmt_usuario->execute([':id_usuario' => $id]); $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC); }

    // Lógica Templo
    $hp_atual_base = (int)($usuario['hp'] ?? 0); $ki_atual_base = (int)($usuario['ki'] ?? 0); $pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0); $mensagem_bloqueio_templo_base = 'Recupere HP/Ki base!';

    // Ranks
    try { $sql_rank_nivel = "SELECT rank_nivel FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC) as rank_nivel FROM usuarios) AS ranked_by_level WHERE id = :user_id"; $stmt_rank_nivel = $conn->prepare($sql_rank_nivel); $stmt_rank_nivel->execute([':user_id' => $id]); $result_nivel = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC); if ($result_nivel) $rank_nivel = $result_nivel['rank_nivel']; $sql_rank_sala = "SELECT rank_sala FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala FROM usuarios) AS ranked_by_time WHERE id = :user_id"; $stmt_rank_sala = $conn->prepare($sql_rank_sala); $stmt_rank_sala->execute([':user_id' => $id]); $result_sala = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC); if ($result_sala) $rank_sala = $result_sala['rank_sala']; } catch (PDOException $e_rank) { error_log("PDOException ranks user {$id} (perfil.php): " . $e_rank->getMessage()); }

    // Notificações
    $notification_limit = 20;
    $sql_notif_init = "SELECT id, message_text, type, is_read, timestamp, related_data FROM user_notifications WHERE user_id = :user_id ORDER BY timestamp DESC LIMIT :limit_notif"; $stmt_notif_init = $conn->prepare($sql_notif_init); $notif_params = [':user_id' => $id, ':limit_notif' => $notification_limit]; if ($stmt_notif_init && $stmt_notif_init->execute($notif_params)) { $initial_notifications = $stmt_notif_init->fetchAll(PDO::FETCH_ASSOC); } else { error_log("Erro PDO Notif Init (perfil.php) user {$id}: " . print_r($conn->errorInfo() ?: ($stmt_notif_init ? $stmt_notif_init->errorInfo() : 'Prep Fail'), true)); $initial_notifications = []; }

} catch (PDOException | Exception $e) {
    error_log("Erro GERAL/PDO load perfil user {$id}: " . $e->getMessage());
    $usuario = $usuario ?? [];
    $mensagem_final_feedback .= ($mensagem_final_feedback ? "<br>" : "") . "<span class='error-message'>Erro carregar dados. Recarregue.</span>";
    if (empty($usuario['id'])) { die("Erro crítico: Falha dados essenciais."); }
}

// --- Cálculos e Formatações Finais ---
$xp_necessario_calc=0; $xp_percent=0; $xp_necessario_display='N/A'; if(isset($usuario['nivel'])){ $nvl=filter_var($usuario['nivel']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]); if($nvl===false)$nvl=1; $xp_nec=(int)ceil(XP_BASE_LEVEL_UP*pow($nvl,XP_EXPOENTE_LEVEL_UP)); $xp_nec=max(XP_BASE_LEVEL_UP,$xp_nec); $xp_necessario_display=number_format($xp_nec); if($xp_nec>0&&isset($usuario['xp'])){ $xp_at=filter_var($usuario['xp']??0,FILTER_VALIDATE_INT); if($xp_at!==false){$xp_percent=min(100,max(0,($xp_at/$xp_nec)*100));}}}
$nome_arquivo_foto = !empty($usuario['foto']) && file_exists('uploads/' . $usuario['foto']) ? $usuario['foto'] : 'default.jpg'; // <<<<< VERIFIQUE PATH
$caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto); // <<<<< VERIFIQUE PATH
if (!function_exists('formatarNomeUsuarioComTag')) { function formatarNomeUsuarioComTag($c, $u) { return htmlspecialchars($u['nome'] ?? 'ErroNome'); } $nome_formatado = formatarNomeUsuarioComTag(null, $usuario); } else { $nome_formatado = isset($conn) ? formatarNomeUsuarioComTag($conn, $usuario) : formatarNomeUsuarioComTag(null, $usuario); }
if (!function_exists('formatNumber')) { function formatNumber($n) { return number_format($n ?? 0, 0, ',', '.'); } }
$currentPage = basename($_SERVER['PHP_SELF']); // perfil.php

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Editar Perfil - <?= htmlspecialchars($usuario['nome'] ?? 'Jogador') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Montserrat:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="style.css"> <style>
        /* ================================================== */
        /* === INÍCIO DO LAYOUT "TERRENO DE TREINAMENTO" === */
        /* ================================================== */
        :root {
            --terra-bg-main: #795548; --terra-bg-container-dark: #4e342e; --terra-bg-container-light: #efebe9; --terra-stone-grey: #9e9e9e; --terra-stone-dark: #616161; --terra-forest-green: #388e3c; --terra-moss-green: #689f38; --terra-sky-blue: #64b5f6; --terra-energy-orange: #ff7043; --terra-danger-red: #e53935; --terra-text-light: #f5f5f5; --terra-text-dark: #3e2723; --terra-border: #5d4037; --success-color: var(--terra-forest-green); --danger-color: var(--terra-danger-red); --info-color: var(--terra-sky-blue);
            --terra-font-title: 'Montserrat', 'Roboto', sans-serif; --terra-font-body: 'Roboto', sans-serif;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--terra-font-body); background-color: #bdbdbd;
            /* background-image: url('images/stone-texture.png'); */ /* <<<<< CAMINHO TEXTURA */
            color: var(--terra-text-light); margin: 0; padding: 20px; min-height: 100vh; line-height: 1.6; font-size: 15px;
        }
        .container.page-container { width: 100%; max-width: 1400px; display: flex; justify-content: center; gap: 20px; align-items: flex-start; margin-left: auto; margin-right: auto; }
        .coluna { border-radius: 6px; padding: 20px; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); border: 1px solid var(--terra-border); }
        .coluna-esquerda { flex: 0 0 280px; display: flex; flex-direction: column; gap: 18px; background-color: var(--terra-bg-container-dark); color: var(--terra-text-light); }
        .coluna-centro-wrapper { flex: 1; display: flex; flex-direction: column; gap: 20px; min-width: 0; } /* Necessário se central tiver mais de um bloco */
        .coluna-central { flex: 1; background-color: var(--terra-bg-container-light); color: var(--terra-text-dark); border: 1px solid #d7ccc8; }
        .coluna-direita { flex: 0 0 260px; padding: 0 !important; background: var(--terra-bg-container-dark); border: 1px solid var(--terra-border); border-radius: 6px; display: flex; flex-direction: column; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3); }
        .feedback-container { width: calc(100% - 40px); max-width: 1200px; margin: 0 auto 20px auto; padding: 15px 25px; background-color: var(--terra-bg-container-dark); border-radius: 6px; text-align: center; font-weight: 500; font-size: 1rem; line-height: 1.6; border: 1px solid var(--terra-border); border-left-width: 5px; border-left-color: var(--info-color); color: var(--terra-text-light); box-shadow: 0 3px 10px rgba(0,0,0,0.2); display: <?php echo !empty($mensagem_final_feedback) ? 'block' : 'none'; ?>; }
        .feedback-container .levelup-message, .feedback-container strong { color: var(--terra-moss-green); font-weight: 700; } .feedback-container:has(.levelup-message) { border-left-color: var(--terra-moss-green); background-color: #4e342e; } .feedback-container .success-message { color: var(--success-color) !important; } .feedback-container:has(.success-message) { border-left-color: var(--success-color) !important; background-color: rgba(56, 142, 60, 0.2); color: var(--success-color) !important;} .feedback-container .error-message { color: var(--danger-color) !important; font-weight: 700; } .feedback-container:has(.error-message) { border-left-color: var(--danger-color) !important; background-color: rgba(229, 57, 53, 0.15) !important; color: var(--danger-color) !important; } .feedback-container.hidden { display: none; }

        /* --- Coluna Esquerda (Igual aos outros) --- */
        .player-card { background-color: rgba(0,0,0,0.2); border: none; border-bottom: 1px solid rgba(245, 245, 245, 0.3); border-radius: 4px; padding: 15px; text-align: center; box-shadow: inset 0 1px 4px rgba(0,0,0,0.2); margin-bottom: 10px; } .player-card .player-name { font-family: var(--terra-font-title); font-weight: 700; color: var(--terra-energy-orange); font-size: 1.7em; text-shadow: 1px 1px 2px rgba(0,0,0,0.5); margin-bottom: 10px; line-height: 1.2; word-break: break-word; } .player-card .foto { display: block; margin: 0 auto 15px auto; max-width: 100px; border-radius: 50%; border: 4px solid var(--terra-stone-dark); box-shadow: 0 2px 5px rgba(0,0,0,0.3); } .player-card .player-level, .player-card .player-race, .player-card .player-rank { font-size: 0.95em; color: #e0e0e0; margin-bottom: 5px; font-weight: 400; } .player-card .player-level span, .player-card .player-race span, .player-card .player-rank span { font-weight: 600; color: var(--terra-text-light); margin-left: 5px; } .player-card .player-rank span { color: var(--terra-energy-orange); }
        .stats-grid-main { background-color: rgba(0,0,0,0.15); border: 1px solid var(--terra-border); padding: 15px; border-radius: 4px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px 15px; font-size: 0.9em; } .stats-grid-main strong { color: #bdbdbd; font-weight: 500; text-align: left; text-transform: uppercase; font-size: 0.85em; letter-spacing: 0.5px; } .stats-grid-main span { color: var(--terra-text-light); font-weight: 600; text-align: right; font-size: 1.05em; } .stats-grid-main span[data-stat="pontos"], .stats-grid-main span[data-stat="rank-nivel"], .stats-grid-main span[data-stat="rank-sala"] { color: var(--terra-sky-blue); font-weight: 700; }
        .zeni-display { font-family: var(--terra-font-title); font-size: 1.3em; font-weight: 700; color: #fff176; text-shadow: 1px 1px 1px rgba(0,0,0,0.4); background-color: var(--terra-stone-dark); text-align: center; padding: 10px 15px; border-radius: 6px; border: 1px solid #757575; } .zeni-display::before { content: "💰 "; }
        .xp-bar-container { margin-top: 8px; } .xp-bar-container .xp-label { font-weight: 500; color: #bdbdbd; display: block; margin-bottom: 8px; text-align: center; font-size: 0.85em; text-transform: uppercase; } .xp-bar { background-color: var(--terra-stone-dark); border: 1px solid var(--terra-border); border-radius: 20px; overflow: hidden; height: 18px; position: relative; } .xp-bar-fill { background: linear-gradient(to right, var(--terra-forest-green), var(--terra-moss-green)); height: 100%; border-radius: 20px; transition: width 0.5s ease-in-out; box-shadow: inset 0 1px 2px rgba(0,0,0,0.3); } .xp-text { position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; justify-content: center; align-items: center; color: var(--terra-text-light); font-size: 0.75em; font-weight: 500; text-shadow: 1px 1px 1px rgba(0,0,0,0.5); }
        .coluna-comunicacao { margin-top: 15px; padding: 0; background: none; border: none; box-shadow: none; } .notificacoes-titulo { font-family: var(--terra-font-title); font-weight: 600; color: var(--terra-text-light); text-align: center; margin-bottom: 12px; font-size: 1.25em; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid rgba(245, 245, 245, 0.3); padding-bottom: 10px; } .notifications-container { background-color: rgba(0,0,0,0.25); border: 1px solid var(--terra-border); border-radius: 6px; max-height: 200px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--terra-border) rgba(0,0,0,0.25); } .notifications-container::-webkit-scrollbar { width: 6px; } .notifications-container::-webkit-scrollbar-track { background: rgba(0,0,0,0.25); border-radius: 3px;} .notifications-container::-webkit-scrollbar-thumb { background-color: var(--terra-border); border-radius: 3px; } .notifications-container::-webkit-scrollbar-thumb:hover { background-color: var(--terra-stone-grey); } .notification-list { padding: 10px; } .notification-item { background-color: var(--terra-bg-container-dark); opacity: 0.95; border: 1px solid var(--terra-border); border-left-width: 4px; border-left-style: solid; border-radius: 4px; padding: 10px 15px; margin-bottom: 8px; position: relative; transition: background-color 0.2s ease; color: var(--terra-text-light); line-height: 1.5; font-size: 0.9rem; } .notification-item:last-child { margin-bottom: 0; } .notification-item.type-invite { border-left-color: var(--terra-energy-orange); } .notification-item.type-info { border-left-color: var(--terra-sky-blue); } .notification-item.type-system { border-left-color: var(--terra-forest-green); } .notification-item.type-warning { border-left-color: var(--terra-danger-red); } .notification-item:hover { background-color: rgba(255,255,255,0.05); } .notification-item .mark-read-btn { position: absolute; top: 4px; right: 4px; background: none; border: none; color: var(--terra-stone-grey); font-size: 1.5em; line-height: 1; cursor: pointer; padding: 0 4px; opacity: 0.6; transition: all 0.2s; } .notification-item .mark-read-btn:hover { color: var(--terra-danger-red); opacity: 1; transform: scale(1.1); } .notification-item .timestamp { font-size: 0.75em; color: #bdbdbd; position: absolute; top: 6px; right: 30px; } .notification-item .msg-text { display: block; padding-right: 60px; } .notification-item .msg-text strong { color: var(--terra-energy-orange); font-weight: 600; } .notification-actions { margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(245, 245, 245, 0.2); text-align: right; } .notification-actions .btn-action { display: inline-block; text-decoration: none; font-family: var(--terra-font-body); font-weight: 600; text-transform: uppercase; padding: 6px 12px; border-radius: 4px; text-align: center; cursor: pointer; transition: all 0.2s ease; margin-left: 8px; font-size: 0.75em; border: none; color: var(--terra-text-light); } .notification-actions .btn-action:active { transform: scale(0.97); } .notification-actions .btn-action.btn-aceitar { background-color: var(--terra-forest-green); } .notification-actions .btn-action.btn-aceitar:hover { background-color: var(--terra-moss-green); } .notification-actions .btn-action.btn-recusar { background-color: var(--terra-danger-red); } .notification-actions .btn-action.btn-recusar:hover { background-color: #d32f2f; } .notification-empty { color: #bdbdbd; text-align: center; padding: 15px; font-style: italic; font-size: 0.9em; }
        .stats-grid-secondary { font-size: 0.8rem; border-top: 1px solid rgba(245, 245, 245, 0.2); padding-top: 12px; margin-top: 12px; display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; } .stats-grid-secondary strong { color: #bdbdbd; text-align: left; font-weight: 500;} .stats-grid-secondary span { color: var(--terra-text-light); text-align: right; font-weight: 500;}
        /* Botão distribuir pontos (aqui só exibe texto) */
        .btn-pontos { background:none; border: none; color: var(--terra-moss-green); font-weight: bold; text-align: center; padding: 10px 0; font-size: 0.95rem;}
        /* Botões inferiores */
        .left-column-bottom-buttons { margin-top: auto; padding-top: 18px; display: flex; flex-direction: column; gap: 12px; border-top: 1px solid rgba(245, 245, 245, 0.2); }
        .action-button { display: block; width: 100%; padding: 11px 16px; border-radius: 4px; font-family: var(--terra-font-title); font-weight: 600; text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.8px; text-align: center; text-decoration: none; cursor: pointer; transition: all 0.2s ease; border: 1px solid rgba(0,0,0,0.3); color: var(--terra-text-light); text-shadow: 1px 1px 1px rgba(0,0,0,0.3); box-shadow: 0 2px 3px rgba(0,0,0,0.2); } .action-button:hover { transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,0,0,0.25); filter: brightness(105%); } .action-button:active { transform: translateY(0px); box-shadow: inset 0 1px 3px rgba(0,0,0,0.3); filter: brightness(95%); }
        .action-button.btn-logout { background-color: var(--terra-stone-dark); border-color: #424242; } /* Apenas logout aqui */
        /* --- FIM COLUNA ESQUERDA --- */

        /* --- COLUNA CENTRAL (Formulário Perfil - Estilo Terra) --- */
        .coluna-central { flex: 1; background-color: var(--terra-bg-container-light); color: var(--terra-text-dark); border: 1px solid #d7ccc8; }
        .coluna-central h2 { font-family: var(--terra-font-title); color: var(--terra-text-dark); font-size: 1.8em; font-weight: 700; text-align: center; margin-bottom: 25px; padding-bottom: 10px; border-bottom: 1px solid var(--terra-border); }
        .coluna-central h3 { font-family: var(--terra-font-title); color: var(--terra-text-dark); font-size: 1.4em; font-weight: 600; text-align: center; margin-top: 30px; margin-bottom: 15px; padding-top: 20px; border-top: 1px dashed #c5ae9f; }

        .form-editar-perfil { display: flex; flex-direction: column; gap: 20px; }
        .form-group { display: flex; flex-direction: column; gap: 6px; }
        .form-editar-perfil label { font-weight: 600; color: var(--terra-text-dark); font-size: 0.9rem; margin-bottom: 2px; }
        .form-editar-perfil input[type="text"], .form-editar-perfil input[type="number"] { width: 100%; padding: 9px 12px; border-radius: 4px; border: 1px solid #bcaaa4; /* Borda marrom clara */ background-color: #fff; color: var(--terra-text-dark); font-size: 1rem; transition: border-color 0.3s, box-shadow 0.3s; }
        .form-editar-perfil input[type="text"]:focus, .form-editar-perfil input[type="number"]:focus { outline: none; border-color: var(--terra-forest-green); /* Foco verde */ box-shadow: 0 0 0 2px rgba(56, 142, 60, 0.2); }
        .form-editar-perfil input[type="file"] { width: 100%; padding: 8px; border-radius: 4px; border: 1px dashed var(--terra-stone-grey); background-color: #f5f5f5; color: var(--terra-text-medium); font-size: 0.9rem; cursor: pointer; }
        .form-editar-perfil input[type="file"]::-webkit-file-upload-button { display: none; }
        .form-editar-perfil input[type="file"]::before { content: 'Escolher Foto'; display: inline-block; background: var(--terra-stone-dark); color: var(--terra-text-light); border: none; border-radius: 3px; padding: 6px 10px; outline: none; white-space: nowrap; cursor: pointer; font-weight: 500; font-size: 0.8rem; margin-right: 10px; transition: background-color 0.2s ease; }
        .form-editar-perfil input[type="file"]:hover::before { background-color: var(--terra-stone-grey); }
        .form-editar-perfil small { color: var(--terra-text-medium); font-size: 0.8rem; margin-top: 4px; display: block;}

        /* Distribuição de Pontos */
        .pontos-distribuicao { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px 18px; margin-bottom: 10px; padding: 15px; background-color: rgba(0,0,0,0.03); border: 1px solid var(--terra-border); border-radius: 5px; }
        .ponto-item { display: flex; flex-direction: column; }
        .ponto-item label { font-size: 0.85rem; font-weight: 500; text-align: left; color: var(--terra-text-dark); margin-bottom: 4px; }
        .ponto-item input[type="number"] { padding: 8px 10px; font-size: 0.9rem; width: 100%; -moz-appearance: textfield; }
        .ponto-item input[type=number]::-webkit-inner-spin-button, .ponto-item input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }

        #pontos-aviso { color: var(--danger-color); text-align: center; display: none; font-weight: bold; margin-top: 10px; font-size: 0.9rem; background-color: rgba(229, 57, 53, 0.08); padding: 8px; border-radius: 4px; border: 1px solid var(--danger-color); }
        .mensagem-sem-pontos { color: var(--terra-text-medium); text-align: center; font-weight: 500; margin-top: 20px; font-style: italic;}

        /* Botão Salvar (usa action-button como base) */
        .action-button.btn-salvar { background-color: var(--terra-forest-green); border-color: #1b5e20; align-self: center; margin-top: 25px; padding: 12px 30px; font-size: 1rem; }
        .action-button.btn-salvar:hover { background-color: var(--terra-moss-green); }
        .action-button.btn-salvar:disabled { background: var(--terra-stone-grey) !important; border-color: #757575 !important; color: #e0e0e0 !important; opacity: 0.7; cursor: not-allowed; filter: grayscale(60%); box-shadow: none; }
        /* --- FIM COLUNA CENTRAL --- */

        /* --- COLUNA DIREITA (SIDEBAR) --- */
        .sidebar-dbz { width: 100%; font-family: var(--terra-font-body); flex-grow: 1; overflow-y: auto; padding: 15px 0; scrollbar-width: thin; scrollbar-color: var(--terra-border) var(--terra-bg-container-dark); } .sidebar-dbz::-webkit-scrollbar { width: 6px; } .sidebar-dbz::-webkit-scrollbar-track { background: var(--terra-bg-container-dark); } .sidebar-dbz::-webkit-scrollbar-thumb { background-color: var(--terra-border); border-radius: 3px; } .sidebar-dbz::-webkit-scrollbar-thumb:hover { background-color: var(--terra-stone-grey); }
        .sidebar-dbz ul { list-style: none; padding: 0; margin: 0; } .sidebar-dbz li { margin-bottom: 0; } .sidebar-dbz li a { display: flex; align-items: center; padding: 12px 18px; color: #e0e0e0; text-decoration: none; font-size: 0.95em; transition: all 0.2s ease; border-left: 4px solid transparent; font-weight: 500; } .sidebar-dbz li a .icon-placeholder { width: 24px; text-align: center; margin-right: 12px; font-size: 1.1em; color: #bdbdbd; transition: color 0.2s ease; } .sidebar-dbz li a:hover { background-color: rgba(255,255,255,0.05); color: var(--terra-text-light); border-left-color: var(--terra-moss-green); } .sidebar-dbz li a:hover .icon-placeholder { color: var(--terra-moss-green); } .sidebar-dbz li a.disabled, .sidebar-dbz li a.disabled:hover { color: #757575 !important; cursor: not-allowed; background: transparent !important; border-left-color: transparent !important; opacity: 0.6; } .sidebar-dbz li a.disabled .icon-placeholder { color: #757575 !important; } .sidebar-dbz li.active a, .sidebar-dbz li a:active { background-color: var(--terra-forest-green) !important; color: var(--terra-text-light) !important; font-weight: 600; border-left-color: var(--terra-energy-orange) !important; } .sidebar-dbz li.active a .icon-placeholder, .sidebar-dbz li a:active .icon-placeholder { color: var(--terra-energy-orange) !important; } .sidebar-dbz li a[href$="sair.php"] { margin-top: 15px; border-top: 1px solid rgba(245, 245, 245, 0.2); padding-top: 12px; } .sidebar-dbz li a[href$="sair.php"] .icon-placeholder { color: var(--terra-danger-red); } .sidebar-dbz li a[href$="sair.php"]:hover { color: var(--terra-danger-red) !important; background-color: rgba(229, 57, 53, 0.1) !important; border-left-color: var(--terra-danger-red) !important; } .sidebar-dbz li a[href$="sair.php"]:hover .icon-placeholder { color: var(--terra-danger-red) !important; }
        /* --- FIM COLUNA DIREITA --- */

        /* --- NAVEGAÇÃO INFERIOR (MOBILE) --- */
        #bottom-nav { display: none; } @media (max-width: 768px) { #bottom-nav { display: flex; position: fixed; bottom: 0; left: 0; right: 0; background-color: var(--terra-bg-container-dark); border-top: 1px solid var(--terra-border); padding: 4px 0; box-shadow: 0 -3px 10px rgba(0,0,0,0.3); justify-content: space-around; align-items: stretch; z-index: 1000; height: 60px; } .bottom-nav-item { display: flex; flex-direction: column; align-items: center; justify-content: center; flex-grow: 1; text-decoration: none; color: #bdbdbd; padding: 4px 2px; font-size: 0.7rem; text-align: center; transition: color 0.2s ease, background-color 0.2s ease; cursor: pointer; border-radius: 4px; margin: 2px; line-height: 1.2; font-family: var(--terra-font-body); font-weight: 500; text-transform: none; } .bottom-nav-item span:first-child { font-size: 1.6rem; line-height: 1; margin-bottom: 3px; } .bottom-nav-item:not(.active):not(.disabled):hover { color: var(--terra-text-light); background-color: rgba(255,255,255,0.05); } .bottom-nav-item.active { color: var(--terra-energy-orange); font-weight: 700; } .bottom-nav-item.active span:first-child { transform: scale(1.1); } .bottom-nav-item.disabled { cursor: not-allowed; opacity: 0.5; color: #757575 !important; background-color: transparent !important; } .bottom-nav-item.disabled:hover { color: #757575 !important; } }
        @media (max-width: 480px) { #bottom-nav { height: 55px; } .bottom-nav-item span:first-child { font-size: 1.4rem; } .bottom-nav-item span:last-child { font-size: 0.65rem; } }
        /* --- FIM NAVEGAÇÃO INFERIOR --- */

        /* --- RESPONSIVO GERAL --- */
        @media (max-width: 1200px) { body{padding:15px;} .container.page-container{flex-direction:column;align-items:center;width:100%;max-width:95%;padding:0;gap:15px;} .coluna-esquerda, .coluna-centro-wrapper, .coluna-central, .coluna-direita{flex-basis:auto!important;width:100%!important;max-width:800px;margin:0 auto 15px auto;min-width:unset;} .coluna-direita{margin-bottom:0;} }
        @media (max-width: 768px) { body{padding:10px;padding-bottom:75px;} .coluna-esquerda, .coluna-direita{display:none;} .coluna-centro-wrapper, .coluna-central{max-width:100%;width:100%;} .coluna{padding:15px;} .feedback-container{font-size:0.9rem;padding:10px 15px;margin-bottom:10px;} }
        @media (max-width: 480px) { body{padding:8px;padding-bottom:70px;} .container.page-container{gap:10px;} .coluna{padding:12px;} .coluna-central h2{font-size:1.5em;} .coluna-central h3{font-size:1.2em;} .pontos-distribuicao{grid-template-columns: 1fr;} .feedback-container{font-size:0.85rem;padding:8px 12px;max-width:calc(100% - 16px);} }

    </style>
</head>
<body>

    <?php if (!empty($mensagem_final_feedback)): ?>
        <?php // Detecta classe de erro/sucesso/info
              $feedback_container_class = '';
              if (strpos($mensagem_final_feedback, 'error-message') !== false) $feedback_container_class = 'has-error';
              elseif (strpos($mensagem_final_feedback, 'success-message') !== false || strpos($mensagem_final_feedback, 'levelup-message') !== false) $feedback_container_class = 'has-success';
              elseif (strpos($mensagem_final_feedback, 'info-message') !== false) $feedback_container_class = 'has-info'; // Para outras msgs
        ?>
        <div class="feedback-container <?php echo $feedback_container_class; ?>">
            <?php echo $mensagem_final_feedback; ?>
        </div>
    <?php endif; ?>


    <div class="container page-container">

        <div class="coluna coluna-esquerda">
             <div class="player-card">
                 <img class="foto" src="<?= htmlspecialchars($caminho_web_foto) ?>" alt="Foto de perfil">
                 <div class="player-name"><?= $nome_formatado ?></div>
                 <div class="player-level">Nível <span><?= htmlspecialchars($usuario['nivel'] ?? '?') ?></span></div>
                 <div class="player-race">Raça <span><?= htmlspecialchars($usuario['raca'] ?? 'N/D') ?></span></div>
                 <div class="player-rank">Título <span><?= htmlspecialchars($usuario['ranking_titulo'] ?? 'Iniciante') ?></span></div>
             </div>
             <div class="stats-grid-main">
                  <strong>HP Base</strong><span data-stat="hp"><?= formatNumber($usuario['hp'] ?? 0) ?></span>
                 <strong>Ki Base</strong><span data-stat="ki"><?= formatNumber($usuario['ki'] ?? 0) ?></span>
                 <strong>Força</strong><span data-stat="forca"><?= formatNumber($usuario['forca'] ?? 0) ?></span>
                 <strong>Defesa</strong><span data-stat="defesa"><?= formatNumber($usuario['defesa'] ?? 0) ?></span>
                 <strong>Velocidade</strong><span data-stat="velocidade"><?= formatNumber($usuario['velocidade'] ?? 0) ?></span>
                 <strong>Pontos</strong><span data-stat="pontos"><?= formatNumber($usuario['pontos'] ?? 0) ?></span>
                 <strong>Rank (Nível)</strong><span data-stat="rank-nivel"><?= htmlspecialchars($rank_nivel) ?><?= ($rank_nivel !== 'N/A' ? 'º' : '') ?></span>
                 <strong>Rank (Sala)</strong><span data-stat="rank-sala"><?= htmlspecialchars($rank_sala) ?><?= ($rank_sala !== 'N/A' ? 'º' : '') ?></span>
             </div>
             <div class="zeni-display"><?= formatNumber($usuario['zeni'] ?? 0) ?></div>
             <div class="xp-bar-container"> <span class="xp-label">Experiência</span> <div class="xp-bar"> <div class="xp-bar-fill" style="width: <?= $xp_percent ?>%;"></div> <div class="xp-text"><?= formatNumber($usuario['xp'] ?? 0) ?> / <?= $xp_necessario_display ?></div> </div> </div>
             <div class="coluna-comunicacao"> <h3 class="notificacoes-titulo">Notificações</h3> <div id="notifications-container" class="notifications-container"> <div id="notification-list" class="notification-list"> <?php if (!empty($initial_notifications)): foreach (array_reverse($initial_notifications) as $notif): $n_cls='notification-item type-'.htmlspecialchars($notif['type']??'info'); $ts_fmt=isset($notif['timestamp'])?date('H:i',strtotime($notif['timestamp'])):'??:??'; $rd_json=json_encode($notif['related_data']??new stdClass()); ?> <div class="<?= $n_cls ?>" data-notification-id="<?= (int)$notif['id'] ?>" data-related='<?= htmlspecialchars($rd_json, ENT_QUOTES, 'UTF-8') ?>'> <button class="mark-read-btn" title="Remover" onclick="markNotificationAsRead(<?= (int)$notif['id'] ?>, this)">×</button> <span class="timestamp"><?= $ts_fmt ?></span> <span class="msg-text"><?= htmlspecialchars($notif['message_text']) ?></span> </div> <?php endforeach; else: ?> <p class="notification-empty">Nenhuma notificação.</p> <?php endif; ?> </div> </div> </div>
             <?php $p_num = filter_var($usuario['pontos'] ?? 0, FILTER_VALIDATE_INT); ?>
             <?php if ($p_num !== false && $p_num > 0): ?>
                 <div class="btn-pontos"> <?= number_format($p_num) ?> Pontos Disponíveis</div>
             <?php endif; ?>
             <div class="stats-grid-secondary"> <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span> <strong>Tempo Sala</strong><span><?= number_format($usuario['tempo_sala'] ?? 0) ?> min</span> </div>
             <div class="left-column-bottom-buttons">
                 <a class="action-button btn-logout" href="sair.php">Sair</a> <?php // <<<<< VERIFIQUE PATH ?>
             </div>
        </div><div class="coluna coluna-central">
            <h2>Editar Perfil de Guerreiro</h2>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" enctype="multipart/form-data" class="form-editar-perfil" id="edit-profile-form">

                <div class="form-group">
                    <label for="nome">Nome do Personagem:</label>
                    <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($usuario['nome'] ?? '') ?>" required maxlength="50">
                </div>

                <div class="form-group">
                    <label for="foto">Alterar Foto de Perfil (Opcional):</label>
                     <input type="file" id="foto" name="foto" accept="image/png, image/jpeg, image/gif">
                    <small>Atual: <?= htmlspecialchars($nome_arquivo_foto) ?>. Formatos: JPG, PNG, GIF (máx 2MB).</small>
                </div>

                <?php $pontos_disponiveis = filter_var($usuario['pontos'] ?? 0, FILTER_VALIDATE_INT); ?>
                <?php if ($pontos_disponiveis !== false && $pontos_disponiveis > 0): ?>
                    <h3>Distribuir Pontos (<span id="pontos-restantes"><?= $pontos_disponiveis ?></span> restantes)</h3>
                    <input type="hidden" id="pontos-total" value="<?= $pontos_disponiveis ?>">

                    <div class="pontos-distribuicao">
                        <?php foreach (['hp' => 'HP Base', 'ki' => 'KI Base', 'forca' => 'Força', 'defesa' => 'Defesa', 'velocidade' => 'Velocidade'] as $campo => $label): ?>
                            <div class="ponto-item">
                                <label for="p_<?= $campo ?>"><?= $label ?> (+):</label>
                                <input type="number" id="p_<?= $campo ?>" name="<?= $campo ?>" value="0" min="0" max="<?= $pontos_disponiveis ?>" step="1" oninput="validarPontos()">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small id="pontos-aviso" style="display: none;">Total distribuído excede o disponível!</small>
                <?php else: ?>
                    <p class="mensagem-sem-pontos">Você não possui pontos disponíveis para distribuir.</p>
                     <div class="pontos-distribuicao" style="display:none;">
                        <?php foreach (['hp', 'ki', 'forca', 'defesa', 'velocidade'] as $campo): ?>
                            <input type="hidden" name="<?= $campo ?>" value="0">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <button type="submit" class="action-button btn-salvar" id="btn-salvar-perfil">Salvar Alterações</button>
            </form>
        </div><div class="coluna coluna-direita">
             <nav class="sidebar-dbz">
                 <ul>
                      <?php // Sidebar PHP igual anterior
                      $sidebarItems = [ ['id'=>'praca','label'=>'Praça Central','href'=>'praca.php','icon'=>'fa-users'], ['id'=>'guia','label'=>'Guia','href'=>'guia_guerreiro.php','icon'=>'fa-book-open'], ['id'=>'equipe','label'=>'Equipe Z','href'=>'script/grupos.php','icon'=>'fa-shield-alt'], ['id'=>'banco','label'=>'Banco','href'=>'script/banco.php','icon'=>'fa-piggy-bank'], ['id'=>'urunai','label'=>'Vovó Uranai','href'=>'urunai.php','icon'=>'fa-crystal-ball'], ['id'=>'kame','label'=>'Mestre Kame','href'=>'kame.php','icon'=>'fa-house-chimney-medical'], ['id'=>'sala_tempo','label'=>'Sala do Templo','href'=>'templo.php','icon'=>'fa-door-open'], ['id'=>'treinos','label'=>'Treinamento','href'=>'desafios/treinos.php','icon'=>'fa-dumbbell'], ['id'=>'desafios','label'=>'Desafios Z','href'=>'desafios.php','icon'=>'fa-trophy'], ['id'=>'perfil','label'=>'Perfil','href'=>'perfil.php','icon'=>'fa-user-pen'] ]; // <<<<< VERIFIQUE PATHS
                      foreach ($sidebarItems as $item) { $href=htmlspecialchars($item['href']); $label=htmlspecialchars($item['label']); $icon=htmlspecialchars($item['icon']); $idItem=$item['id']; $liClass=''; $aClass=''; $extra=''; if($idItem==='sala_tempo'){if(!$pode_entrar_templo_base){$aClass.=' disabled';$extra.=' onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base,ENT_QUOTES).'\');return false;" title="'.htmlspecialchars($mensagem_bloqueio_templo_base,ENT_QUOTES).'"';$href='#';}else{$aClass.=' entrar-templo-btn';$extra.=' data-url="'.$href.'" title="Entrar"';}} if(basename($href)===$currentPage&&$href!=='#'){$liClass='active';} echo "<li class=\"{$liClass}\"><a href=\"{$href}\" class=\"{$aClass}\" {$extra}><i class=\"icon-placeholder fas {$icon}\"></i> {$label}</a></li>"; }
                      ?>
                      <li><a href="sair.php"><i class="icon-placeholder fas fa-sign-out-alt"></i> Sair</a></li> <?php // <<<<< VERIFIQUE PATH ?>
                 </ul>
             </nav>
        </div></div> <nav id="bottom-nav">
        <?php /* Bottom Nav PHP igual anterior */
        $bottomNavItems = []; $bottomNavIds = ['praca', 'treinos', 'sala_tempo', 'kame', 'perfil', 'urunai']; $foundArena = false; foreach($sidebarItems as $item) { if(in_array($item['id'], $bottomNavIds)){ $icon=''; switch($item['id']){ case 'praca':$icon='<i class="fas fa-users"></i>'; break; case 'treinos':$icon='<i class="fas fa-dumbbell"></i>'; break; case 'sala_tempo':$icon='<i class="fas fa-door-open"></i>'; break; case 'kame':$icon='<i class="fas fa-house-chimney-medical"></i>'; break; case 'perfil':$icon='<i class="fas fa-user-pen"></i>'; break; case 'urunai': $icon='<i class="fas fa-crystal-ball"></i>'; break; default:$icon='<i class="fas fa-question-circle"></i>'; break; } $bottomNavItems[] = ['label'=>$item['label'], 'href'=>$item['href'], 'icon'=>$icon, 'id'=>$item['id']]; } if (strpos($item['href'],'arena')!==false||$item['id']==='arena'){$foundArena=true;}}
        if (!$foundArena) { $bottomNavItems[] = ['label' => 'Arena', 'href' => 'arena/arenaroyalle.php', 'icon' => '<i class="fas fa-shield-halved"></i>', 'id' => 'arena']; } // <<<<< VERIFIQUE PATH
        $bottomNavItems = array_slice($bottomNavItems, 0, 5);
        ?>
         <?php foreach ($bottomNavItems as $item): ?>
             <?php $url=$item['href']; $href='#'; $onclick=''; $title=''; $class=''; $is_temple=($item['id']==='sala_tempo'); $is_curr=(basename($url)===$currentPage); if($is_temple){if(!$pode_entrar_templo_base){$onclick=' onclick="alert(\''.htmlspecialchars($mensagem_bloqueio_templo_base,ENT_QUOTES).'\');return false;"';$title=htmlspecialchars($mensagem_bloqueio_templo_base,ENT_QUOTES);$class=' disabled';}else{$href=htmlspecialchars($url);$title='Entrar';$class=' entrar-templo-btn';}}else{$href=htmlspecialchars($url);$title='Ir para '.htmlspecialchars($item['label']);} if($is_curr&&$href!=='#'){$class.=' active';} echo "<a href='{$href}' class='bottom-nav-item{$class}' {$onclick} title='{$title}'"; if($is_temple&&$pode_entrar_templo_base){echo " data-url='".htmlspecialchars($url)."'";} echo "><span style='font-size:1.6rem;line-height:1;margin-bottom:3px;'>{$item['icon']}</span><span>".htmlspecialchars($item['label'])."</span></a>"; ?>
         <?php endforeach; ?>
    </nav>

     <script>
        function escapeHtml(unsafe){if(typeof unsafe!=='string')return'';return unsafe.replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;");}
        function formatTimestamp(ts){if(!ts)return'';try{let d=ts.replace(/-/g,'/')+'Z';const dt=new Date(d);if(!isNaN(dt))return dt.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',timeZone:'America/Sao_Paulo'});}catch(e){}return ts.split(' ')[1]?.substring(0,5)||'';}
        const notificationListDiv=document.getElementById('notification-list');const currentUserId_Notif=<?php echo json_encode((int)$id);?>;let lastNotificationId=0;<?php if(!empty($initial_notifications)):$lid=0;foreach($initial_notifications as $n){if((int)$n['id']>$lid){$lid=(int)$n['id'];}}echo 'lastNotificationId='.$lid.';';endif;?>;const notificationPollInterval=15000;let notificationPollingIntervalId=null;let isFetchingNotifications=false;const notificationHandlerUrl='notification_handler_ajax.php'; // <<<<< VERIFIQUE PATH
        function initializeNotificationSystem(){if(!notificationListDiv)return;const ins=notificationListDiv.querySelectorAll('.notification-item');ins?.forEach(i=>{if(i.querySelector('.notification-actions')||i.classList.contains('is-read'))return;try{const rds=i.dataset.related;const nid=i.dataset.notificationId;const ii=i.classList.contains('type-invite');if(ii&&rds&&nid){const rd=JSON.parse(rds);const ah=generateNotificationActions({id:nid,type:'invite',related_data:rd});if(ah){const ac=document.createElement('div');ac.classList.add('notification-actions');ac.innerHTML=ah;i.appendChild(ac);}}}catch(e){}});if(notificationPollingIntervalId)clearInterval(notificationPollingIntervalId);setTimeout(fetchNotificationUpdates,700);notificationPollingIntervalId=setInterval(fetchNotificationUpdates,notificationPollInterval);document.addEventListener("visibilitychange",handleVisibilityChangeNotifications);if(notificationListDiv){notificationListDiv.scrollTop=0;}}
        function handleVisibilityChangeNotifications(){if(document.hidden){if(notificationPollingIntervalId)clearInterval(notificationPollingIntervalId);notificationPollingIntervalId=null;}else{if(!notificationPollingIntervalId){setTimeout(fetchNotificationUpdates,250);notificationPollingIntervalId=setInterval(fetchNotificationUpdates,notificationPollInterval);}}}
        function addNotificationToDisplay(notif){if(!notificationListDiv||!notif||!notif.id)return;if(document.querySelector(`.notification-item[data-notification-id="${notif.id}"]`))return;const le=notificationListDiv.querySelector('.notification-empty');if(le)le.remove();const ne=document.createElement('div');ne.dataset.notificationId=notif.id;let rdj='{}';try{let rd=notif.related_data;if(typeof rd==='string'){try{rd=JSON.parse(rd);}catch(e){rd={};}}if(typeof rd!=='object'||rd===null){rd={};}rdj=JSON.stringify(rd);}catch(e){}ne.dataset.related=rdj;ne.classList.add('notification-item',`type-${escapeHtml(notif.type)||'info'}`);let ts=formatTimestamp(notif.timestamp);ne.innerHTML=`<button class="mark-read-btn" title="Remover" onclick="markNotificationAsRead(${notif.id},this)">×</button><span class="timestamp">${ts}</span><span class="msg-text">${escapeHtml(notif.message_text)}</span>`;if(notif.type==='invite'){const ah=generateNotificationActions(notif);if(ah){const ac=document.createElement('div');ac.classList.add('notification-actions');ac.innerHTML=ah;ne.appendChild(ac);}}notificationListDiv.insertBefore(ne,notificationListDiv.firstChild);const max=50;while(notificationListDiv.children.length>max){notificationListDiv.removeChild(notificationListDiv.lastChild);}if(notif.id&&parseInt(notif.id)>lastNotificationId){lastNotificationId=parseInt(notif.id);}}
        function generateNotificationActions(notif){let rd=notif.related_data;if(typeof rd==='string'){try{rd=JSON.parse(rd);}catch(e){rd=null;}}if(notif.type==='invite'&&rd&&typeof rd==='object'){try{if(rd.invite_type==='fusion'&&notif.id){const page='praca.php';const aU=`${page}?acao=aceitar_convite&convite_id=${notif.id}`;const rU=`${page}?acao=recusar_convite&convite_id=${notif.id}`;return`<a href="${aU}" class='btn-action btn-aceitar'>Aceitar</a><a href="${rU}" class='btn-action btn-recusar'>Recusar</a>`;}}catch(e){}}return '';} // <<<<< VERIFIQUE PATH 'praca.php'
        async function markNotificationAsRead(nid,btn){if(!nid)return;const ni=btn?.closest('.notification-item');if(ni){ni.style.transition='opacity 0.3s, transform 0.3s';ni.style.opacity='0';ni.style.transform='translateX(50px)';setTimeout(()=>{ni.remove();if(notificationListDiv&&notificationListDiv.children.length===0){const em=document.createElement('p');em.classList.add('notification-empty');em.textContent='Nenhuma notificação.';notificationListDiv.appendChild(em);}},300);}try{const fd=new FormData();fd.append('notification_id',nid);fetch(`${notificationHandlerUrl}?action=mark_notification_read`,{method:'POST',body:fd}).then(r=>{if(!r.ok){}}).catch(e=>{});}catch(e){}}
        async function fetchNotificationUpdates(){if(isFetchingNotifications||document.hidden)return;isFetchingNotifications=true;try{const fU=`${notificationHandlerUrl}?action=fetch&last_notification_id=${lastNotificationId}&t=${Date.now()}`;const r=await fetch(fU);if(!r.ok){isFetchingNotifications=false;return;}const d=await r.json();if(d.success&&d.notifications&&d.notifications.length>0){d.notifications.reverse().forEach(n=>{if(typeof n.related_data==='string'){try{n.related_data=JSON.parse(n.related_data);}catch(e){n.related_data={};}}addNotificationToDisplay(n);});const lid=Math.max(...d.notifications.map(n=>parseInt(n.id)));if(lid>lastNotificationId){lastNotificationId=lid;}}}catch(e){}finally{isFetchingNotifications=false;}}
        function validarPontos(){const totEl=document.getElementById('pontos-total');const restSp=document.getElementById('pontos-restantes');const aviso=document.getElementById('pontos-aviso');const btnS=document.getElementById('btn-salvar-perfil');const inputs=document.querySelectorAll('.pontos-distribuicao input[type="number"]');if(!totEl||!restSp||!aviso||!btnS||inputs.length===0){if(btnS)btnS.disabled=false;return;}const totPts=parseInt(totEl.value)||0;let ptsDist=0;inputs.forEach(i=>{let v=parseInt(i.value)||0;if(v<0){v=0;i.value=0;}ptsDist+=v;});const ptsRest=totPts-ptsDist;restSp.textContent=Math.max(0,ptsRest);if(ptsDist>totPts){aviso.textContent=`Distribuído (${ptsDist}) excede os ${totPts} pontos disponíveis!`;aviso.style.display='block';btnS.disabled=true;}else{aviso.style.display='none';btnS.disabled=false;}}
        document.addEventListener('DOMContentLoaded',()=>{const btnsTemplo=document.querySelectorAll('.entrar-templo-btn');if(btnsTemplo.length>0&&typeof handleTemploButtonClick==='undefined'){function handleTemploButtonClick(e){const url=this.dataset.url||this.href;if(this.classList.contains('disabled')){e.preventDefault();let msg='Acesso bloqueado.';try{msg=this.getAttribute('onclick').split("alert('")[1].split("')")[0];}catch(ex){}alert(msg);return;}if(url&&url!=='#'){e.preventDefault();window.location.href=url;}}btnsTemplo.forEach(b=>{b.addEventListener('click',handleTemploButtonClick);});} const inputsPontos=document.querySelectorAll('.pontos-distribuicao input[type="number"]');if(inputsPontos.length>0){inputsPontos.forEach(i=>{i.addEventListener('input',validarPontos);});validarPontos();} if(typeof initializeNotificationSystem==='function'){initializeNotificationSystem();} const bNav=document.getElementById('bottom-nav'); function checkNav(){if(!bNav)return; const rc=document.querySelector('.coluna-direita'); if(rc&&window.getComputedStyle(rc).display==='none'){bNav.style.display='flex'; document.body.style.paddingBottom=(bNav.offsetHeight+5)+'px';}else{bNav.style.display='none'; document.body.style.paddingBottom='0';}} checkNav(); window.addEventListener('resize', checkNav); });
     </script>

</body>
</html>
<?php
// Fecha conexão PDO
if (isset($conn)) { $conn = null; }
if (isset($pdo)) { $pdo = null; }
?>