<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();

// Verifica se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Inclui a conexão com o banco de dados
require_once('conexao.php');

// Funções utilitárias (mantidas como stubs ou implementadas)
// Se verificarLevelUpHome não estiver definida em outro lugar, defina-a aqui
if (!function_exists('verificarLevelUpHome')) {
    // Exemplo de stub - substitua pela sua lógica real se necessário
    function verificarLevelUpHome(PDO $pdo_conn, int $id_usuario): array {
        // Implemente a lógica real de verificação de level up aqui
        // Exemplo: Buscar o usuário, calcular XP necessário, comparar, atualizar se necessário, retornar mensagens.
        // Por agora, retorna um array vazio para evitar erros.
        return [];
    }
}

// Função para formatar números
if (!function_exists('formatNumber')) {
    function formatNumber($num) {
        // Garante que $num seja numérico ou zero antes de formatar
        $number = is_numeric($num) ? (float)$num : 0;
        return number_format($number, 0, ',', '.');
    }
}

// Função para formatar nome de usuário (exemplo simples)
if (!function_exists('formatarNomeUsuarioComTag')) {
    function formatarNomeUsuarioComTag(PDO $conn, array $usuario_data): string {
        // Implemente tags ou formatação especial se necessário
        return htmlspecialchars($usuario_data['nome'] ?? 'Usuário');
    }
}

// Variáveis Globais da Página
$id = $_SESSION['user_id'];
$id_usuario_logado = $id; // Alias para clareza
$usuario = null; // Dados do usuário logado
$unread_notifications_for_popup = []; // Notificações não lidas
$chat_history = []; // Histórico de chat
$mensagem_final_feedback = ""; // Feedback para o usuário
$currentPage = basename(__FILE__); // Nome do arquivo atual
$nome_formatado_usuario = 'ErroNome'; // Nome formatado do usuário
$rank_nivel = 'N/A'; // Rank de nível
$rank_sala = 'N/A'; // Rank de tempo de sala
$chat_history_limit = 50; // Limite de mensagens no chat

// Constantes de Level Up (se não definidas globalmente)
if (!defined('XP_BASE_LEVEL_UP')) define('XP_BASE_LEVEL_UP', 1000);
if (!defined('XP_EXPOENTE_LEVEL_UP')) define('XP_EXPOENTE_LEVEL_UP', 1.5);
if (!defined('PONTOS_GANHO_POR_NIVEL')) define('PONTOS_GANHO_POR_NIVEL', 100);

// Estrutura inicial para dados da Arena
$arena_data_for_js = [
    'status' => 'INDISPONIVEL', // Status inicial
    'eventId' => null,           // ID do evento atual/próximo
    'secondsToNextOpen' => null, // Segundos até a próxima abertura de registro
    'secondsToStart' => null,    // Segundos até o início da arena (se registro aberto)
    'registeredCount' => 0,      // Quantidade de jogadores registrados
    'requiredPlayers' => 100     // Jogadores necessários (padrão)
];

// Conexão DB Local (para garantir que $conn esteja disponível)
$local_conn_arena = null;
try {
    // Reutiliza $conn de conexao.php se já estiver definida e for PDO
    if (isset($conn) && $conn instanceof PDO) {
        $local_conn_arena = $conn;
    } else {
        // Tenta incluir novamente ou criar nova conexão se $conn não existir ou for inválida
        // (A inclusão de conexao.php já deve ter sido feita no topo)
        if (!isset($conn) || !$conn instanceof PDO) {
             require('conexao.php'); // Garante que a conexão seja estabelecida
             if (!isset($conn) || !$conn instanceof PDO) {
                  throw new Exception("Falha ao estabelecer conexão PDO inicial.");
             }
             $local_conn_arena = $conn;
        } else {
             $local_conn_arena = $conn; // $conn era válida
        }
    }
    // Define o modo de erro para exceções para a conexão local
    $local_conn_arena->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (Exception $e) {
    error_log("Erro Conexão Inicial Home: " . $e->getMessage());
    // Exibe uma mensagem genérica para o usuário e encerra
    die("Erro crítico na conexão com o banco de dados. Tente novamente mais tarde.");
}

// --- Handler POST para Registro na Arena ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_home'])) {
    if (!$local_conn_arena) {
        $_SESSION['feedback_geral'] = "<span class='error-message'>Erro de conexão ao tentar registrar. Tente novamente.</span>";
        header("Location: home.php");
        exit();
    }

    $local_conn_arena->beginTransaction();
    try {
        // 1. Busca o evento ATUAL com status 'REGISTRO_ABERTO' (bloqueia a linha)
        $stmt_evento_aberto = $local_conn_arena->prepare(
            "SELECT id, horario_inicio_agendado_utc FROM arena_eventos
             WHERE status = 'REGISTRO_ABERTO'
             ORDER BY data_inicio_registro DESC
             LIMIT 1 FOR UPDATE"
        );
        $stmt_evento_aberto->execute();
        $evento_aberto = $stmt_evento_aberto->fetch(PDO::FETCH_ASSOC);

        // Se não há evento aberto para registro
        if (!$evento_aberto) {
            $local_conn_arena->rollBack();
            $_SESSION['feedback_geral'] = "<span class='warning-message'>O registro para a Arena não está aberto no momento.</span>";
            error_log("Tentativa de registro em arena sem evento REGISTRO_ABERTO. User: " . $id);
            header("Location: home.php");
            exit();
        }

        $id_evento_registro = $evento_aberto['id'];
        $horario_inicio_evento_utc = $evento_aberto['horario_inicio_agendado_utc'];

        // Verifica se o horário de início já passou (segurança extra)
        if ($horario_inicio_evento_utc) {
            try {
                $start_dt = new DateTime($horario_inicio_evento_utc, new DateTimeZone('UTC'));
                $now_dt = new DateTime('now', new DateTimeZone('UTC'));
                if ($now_dt >= $start_dt) {
                    $local_conn_arena->rollBack();
                    $_SESSION['feedback_geral'] = "<span class='warning-message'>O período de registro para esta Arena já encerrou.</span>";
                    error_log("Tentativa de registro após horário de início. User: {$id}, Event: {$id_evento_registro}");
                    header("Location: home.php");
                    exit();
                }
            } catch (Exception $dt_err){
                 error_log("Erro ao validar data de início no registro POST: ".$dt_err->getMessage());
                 // Prosseguir com cautela ou bloquear, dependendo da política
            }
        }


        // 2. Verifica se o usuário JÁ está REGISTRADO neste evento específico
        $stmt_check_reg_aberto = $local_conn_arena->prepare(
            "SELECT id FROM arena_registros WHERE id_usuario = ? AND id_evento = ?"
        );
        $stmt_check_reg_aberto->execute([$id, $id_evento_registro]);
        if ($stmt_check_reg_aberto->fetch()) {
            $local_conn_arena->commit(); // Comita pois não houve erro, apenas já estava registrado
            $_SESSION['feedback_geral'] = "<span class='info-message'>Você já está registrado para esta Arena.</span>";
            header("Location: home.php");
            exit();
        }

        // 3. Verifica se o usuário já está PARTICIPANDO de uma arena EM_ANDAMENTO
        $stmt_check_participando_andamento = $local_conn_arena->prepare(
            "SELECT ar.id_evento FROM arena_registros ar
             JOIN arena_eventos ae ON ar.id_evento = ae.id
             WHERE ar.id_usuario = ? AND ae.status = 'EM_ANDAMENTO' AND ar.status_participacao = 'PARTICIPANDO'
             LIMIT 1"
        );
        $stmt_check_participando_andamento->execute([$id]);
        if ($active_reg = $stmt_check_participando_andamento->fetch(PDO::FETCH_ASSOC)) {
            $local_conn_arena->rollBack(); // Rollback pois a ação não pode ser concluída
            $_SESSION['feedback_geral'] = "<span class='warning-message'>Você já está participando de outra Arena!</span>";
            // Redireciona para a arena em que já está participando
            header("Location: script/arena/arenaroyalle.php?event_id=" . $active_reg['id_evento']);
            exit();
        }

        // 4. Procede com o registro do usuário no evento aberto
        $stmt_register = $local_conn_arena->prepare(
            "INSERT INTO arena_registros (id_usuario, id_evento, status_participacao, data_registro)
             VALUES (?, ?, 'REGISTRADO', NOW())"
        );
        // Executa a inserção
        if (!$stmt_register->execute([$id, $id_evento_registro])) {
            // Se a execução falhar, loga o erro e lança uma exceção
            $errorInfo = $stmt_register->errorInfo();
            error_log("Erro PDO ao inserir registro na Arena para user {$id}, event {$id_evento_registro}. DB Error: " . ($errorInfo[2] ?? 'N/A') . ". SQLSTATE: " . ($errorInfo[0] ?? 'N/A'));
            throw new Exception("Falha ao registrar no banco de dados da Arena.");
        }

        // Se tudo correu bem, comita a transação
        $local_conn_arena->commit();
        $_SESSION['feedback_geral'] = "<span class='success-message'>Registrado na Arena ID: {$id_evento_registro}! Aguarde o início.</span>";
        header("Location: home.php");
        exit();

    } catch (Throwable $e) { // Captura PDOException e Exception genérica
        // Garante rollback em caso de erro
        if ($local_conn_arena->inTransaction()) {
            $local_conn_arena->rollBack();
        }
        error_log("Erro no Handler POST Registro Arena Home user {$id}: " . $e->getMessage());
        // Define uma mensagem de feedback genérica ou específica (cuidado com exposição de detalhes)
        $feedback_error = ($e instanceof PDOException) ? "Erro interno ao tentar registrar. Tente novamente." : $e->getMessage();
        $_SESSION['feedback_geral'] = "<span class='error-message'>" . htmlspecialchars($feedback_error) . "</span>";
        header("Location: home.php");
        exit();
    }
}

// --- Coleta Mensagens de Feedback da Sessão ---
if (isset($_SESSION['feedback_wali'])) {
    $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_wali'];
    unset($_SESSION['feedback_wali']);
}
if (isset($_SESSION['feedback_geral'])) {
    $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_geral'];
    unset($_SESSION['feedback_geral']);
}
if (isset($_SESSION['feedback_cooldown'])) {
    $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . $_SESSION['feedback_cooldown'];
    unset($_SESSION['feedback_cooldown']);
}

// --- Lógica GET Principal (Carregamento da Página) ---
try {
    if (!$local_conn_arena) {
        throw new Exception("Conexão com o banco de dados não disponível para GET principal.");
    }

    // --- Busca Dados do Usuário Logado ---
    $sql_usuario = "SELECT u.id, u.nome, u.nivel, u.hp, u.pontos, u.xp, u.ki, u.forca,
                           u.defesa, u.velocidade, u.foto, u.tempo_sala, u.raca, u.zeni,
                           u.ranking_titulo
                    FROM usuarios u
                    WHERE u.id = :id_usuario";
    $stmt_usuario = $local_conn_arena->prepare($sql_usuario);
    $stmt_usuario->execute([':id_usuario' => $id]);
    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

    // Se o usuário não for encontrado (pode ter sido deletado enquanto logado)
    if (!$usuario) {
        session_destroy(); // Destrói a sessão inválida
        header("Location: login.php?erro=UsuarioNaoEncontradoFatalHome");
        exit();
    }
    $nome_formatado_usuario = formatarNomeUsuarioComTag($local_conn_arena, $usuario);


    // --- Verifica Level Up ---
    // (Assume que verificarLevelUpHome pode modificar o DB e retornar mensagens)
    $mensagens_level_up_detectado = verificarLevelUpHome($local_conn_arena, $id);
    if (!empty($mensagens_level_up_detectado)) {
        // Adiciona mensagens de level up ao feedback geral
        $mensagem_final_feedback .= (!empty($mensagem_final_feedback) ? "<br>" : "") . implode("<br>", $mensagens_level_up_detectado);

        // Recarrega os dados do usuário pois podem ter mudado (nível, pontos, etc.)
        $stmt_usuario->execute([':id_usuario' => $id]);
        $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) { // Verifica novamente se o usuário ainda existe após LUP
             error_log("Falha CRÍTICA ao recarregar dados do usuário {$id} pós-LUP (home.php).");
             session_destroy();
             header("Location: login.php?erro=UsuarioRecargaFalhouHome");
             exit();
        }
        // Atualiza nome formatado caso a tag tenha mudado com o nível
         $nome_formatado_usuario = formatarNomeUsuarioComTag($local_conn_arena, $usuario);
    }

    // --- Busca Ranks do Usuário ---
    try {
        // Rank por Nível
        $sql_rank_nivel = "SELECT rank_nivel FROM (
                              SELECT id, ROW_NUMBER() OVER (ORDER BY nivel DESC, xp DESC, tempo_sala DESC, id ASC) as rank_nivel
                              FROM usuarios
                           ) AS ranked_by_level WHERE id = :user_id";
        $stmt_rank_nivel = $local_conn_arena->prepare($sql_rank_nivel);
        $stmt_rank_nivel->execute([':user_id' => $id]);
        $result_nivel = $stmt_rank_nivel->fetch(PDO::FETCH_ASSOC);
        if ($result_nivel) { $rank_nivel = $result_nivel['rank_nivel']; }

        // Rank por Tempo de Sala
        $sql_rank_sala = "SELECT rank_sala FROM (
                            SELECT id, ROW_NUMBER() OVER (ORDER BY tempo_sala DESC, id ASC) as rank_sala
                            FROM usuarios
                         ) AS ranked_by_time WHERE id = :user_id";
        $stmt_rank_sala = $local_conn_arena->prepare($sql_rank_sala);
        $stmt_rank_sala->execute([':user_id' => $id]);
        $result_sala = $stmt_rank_sala->fetch(PDO::FETCH_ASSOC);
        if ($result_sala) { $rank_sala = $result_sala['rank_sala']; }

    } catch (PDOException $e_rank) {
        error_log("Erro ao buscar ranks do usuário {$id} na home: " . $e_rank->getMessage());
        // Mantém os ranks como N/A em caso de erro
    }

    // --- Busca Notificações Não Lidas para Popup ---
    try {
        $sql_unread_notif = "SELECT id, message_text, type, timestamp, related_data
                             FROM user_notifications
                             WHERE user_id = :user_id AND is_read = 0
                             ORDER BY timestamp ASC"; // Mais antigas primeiro para o popup
        $stmt_unread_notif = $local_conn_arena->prepare($sql_unread_notif);
        if ($stmt_unread_notif && $stmt_unread_notif->execute([':user_id' => $id])) {
            $unread_notifications_for_popup = $stmt_unread_notif->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $errorInfo = $stmt_unread_notif ? $stmt_unread_notif->errorInfo() : ['N/A', 'N/A', 'Statement falhou'];
            error_log("Erro ao buscar notificações popup para user {$id}: SQLSTATE[{$errorInfo[0]}] - {$errorInfo[2]}");
        }
    } catch (PDOException $e_notif_popup) {
        error_log("Erro DB ao buscar notificações popup para user {$id}: " . $e_notif_popup->getMessage());
    }

    // --- Busca Histórico de Chat ---
    try {
        // Busca as últimas N mensagens, ordenadas por ID ASC para exibição correta
        $sql_hist = "SELECT id, user_id, username, message_text, timestamp
                     FROM (
                         SELECT id, user_id, username, message_text, timestamp
                         FROM chat_messages
                         ORDER BY id DESC
                         LIMIT :limit_chat
                     ) sub
                     ORDER BY id ASC";
        $stmt_hist = $local_conn_arena->prepare($sql_hist);
        $stmt_hist->bindValue(':limit_chat', $chat_history_limit, PDO::PARAM_INT);
        $stmt_hist->execute();
        $chat_history = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e_chat) {
        error_log("Erro DB ao buscar histórico de chat na home: " . $e_chat->getMessage());
        $chat_history = []; // Garante que seja um array vazio em caso de erro
    }

    // --- Lógica Principal da Arena ---

    // 1. VERIFICA SE O USUÁRIO JÁ ESTÁ PARTICIPANDO DE UMA ARENA EM ANDAMENTO
    //    (Se estiver, redireciona imediatamente)
    $stmt_check_participando = $local_conn_arena->prepare(
        "SELECT ar.id_evento FROM arena_registros ar
         JOIN arena_eventos ae ON ar.id_evento = ae.id
         WHERE ar.id_usuario = ? AND ae.status = 'EM_ANDAMENTO' AND ar.status_participacao = 'PARTICIPANDO'
         LIMIT 1"
    );
    $stmt_check_participando->execute([$id]);
    $participando_agora = $stmt_check_participando->fetch(PDO::FETCH_ASSOC);

    if ($participando_agora) {
        // Se o usuário está ativamente participando, redireciona para a página da arena
        header("Location: script/arena/arenaroyalle.php?event_id=" . $participando_agora['id_evento']);
        exit();
    }

    // 2. BUSCA O PRÓXIMO EVENTO RELEVANTE (Agendado ou Registro Aberto)
    //    Prioriza REGISTRO_ABERTO, depois o AGENDADO mais próximo de abrir registro/iniciar.
    //    EM_ANDAMENTO é incluído para exibir o status corretamente se nenhuma outra arena estiver agendada/aberta.
     $sql_find_event = "SELECT id, status, titulo, base_player_count,
                               horario_inicio_agendado_utc,
                               data_inicio_registro,
                               horario_abertura_registro_agendado_utc
                        FROM arena_eventos
                        WHERE status IN ('AGENDADO', 'REGISTRO_ABERTO', 'EM_ANDAMENTO')
                        ORDER BY
                            FIELD(status, 'REGISTRO_ABERTO', 'EM_ANDAMENTO', 'AGENDADO') ASC, -- Prioridade de Status
                            -- Ordenação temporal dentro de cada status
                            CASE status
                                WHEN 'AGENDADO' THEN horario_abertura_registro_agendado_utc -- Próximo a abrir registro
                                WHEN 'REGISTRO_ABERTO' THEN horario_inicio_agendado_utc -- Próximo a iniciar
                                WHEN 'EM_ANDAMENTO' THEN data_inicio_registro -- Evento em andamento mais recente
                                ELSE NOW() -- Fallback
                            END ASC
                        LIMIT 1";

    $stmt_find_event = $local_conn_arena->prepare($sql_find_event);
    $stmt_find_event->execute();
    $current_or_next_event = $stmt_find_event->fetch(PDO::FETCH_ASSOC);

    // Reseta a estrutura de dados da arena para JS
    $arena_data_for_js = [
        'status' => 'INDISPONIVEL', // Padrão
        'eventId' => null,
        'secondsToNextOpen' => null,
        'secondsToStart' => null,
        'registeredCount' => 0,
        'requiredPlayers' => 100 // Padrão pode ser sobrescrito pelo evento
    ];

    // Se encontrou um evento relevante
    if ($current_or_next_event) {
        $current_event_id = $current_or_next_event['id'];
        $current_event_status_db = $current_or_next_event['status'];
        $required_players_from_db = $current_or_next_event['base_player_count'] ?? 100;

        $arena_data_for_js['eventId'] = $current_event_id;
        $arena_data_for_js['requiredPlayers'] = (int)$required_players_from_db;

        $now_utc_dt = new DateTime('now', new DateTimeZone('UTC')); // Data/hora atual em UTC

        // Lógica baseada no STATUS do evento encontrado
        switch ($current_event_status_db) {
            case 'AGENDADO':
                $arena_data_for_js['status'] = 'FECHADO';
                if (!empty($current_or_next_event['horario_abertura_registro_agendado_utc'])) {
                    try {
                        $open_utc_dt = new DateTime($current_or_next_event['horario_abertura_registro_agendado_utc'], new DateTimeZone('UTC'));
                        // Calcula segundos restantes apenas se a data de abertura for futura
                        if ($open_utc_dt > $now_utc_dt) {
                             $seconds_remaining_opening = $open_utc_dt->getTimestamp() - $now_utc_dt->getTimestamp();
                             $arena_data_for_js['secondsToNextOpen'] = max(0, $seconds_remaining_opening);
                        } else {
                             // Se a data de abertura já passou mas status ainda é AGENDADO (atraso no cron?), loga erro.
                             error_log("Evento AGENDADO ID {$current_event_id} encontrado com horario_abertura_registro já passado.");
                             // Poderia tentar forçar um reload ou marcar como INDISPONIVEL dependendo da regra.
                             // Por segurança, não define o timer. JS tratará como "Em breve..." e recarregará.
                             $arena_data_for_js['secondsToNextOpen'] = 0; // Ou null
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao calcular timer ABERTURA (AGENDADO) ID {$current_event_id}: " . $e->getMessage());
                        $arena_data_for_js['secondsToNextOpen'] = null; // Indica erro no cálculo
                    }
                } else {
                     error_log("Evento AGENDADO ID {$current_event_id} sem horario_abertura_registro_agendado_utc.");
                     $arena_data_for_js['secondsToNextOpen'] = null;
                }
                break;

            case 'REGISTRO_ABERTO':
                // Verifica se o usuário LOGADO está registrado NESTE evento
                 $stmt_cr = $local_conn_arena->prepare(
                    "SELECT id FROM arena_registros WHERE id_usuario = ? AND id_evento = ? AND status_participacao = 'REGISTRADO'"
                 );
                $stmt_cr->execute([$id, $current_event_id]);
                $is_registered_current_event = $stmt_cr->fetch() ? true : false;

                // Define status JS como REGISTRADO ou ABERTO
                $arena_data_for_js['status'] = $is_registered_current_event ? 'REGISTRADO' : 'ABERTO';

                // Calcula tempo restante para o INÍCIO
                if (!empty($current_or_next_event['horario_inicio_agendado_utc'])) {
                    try {
                        $start_utc_dt = new DateTime($current_or_next_event['horario_inicio_agendado_utc'], new DateTimeZone('UTC'));
                         // Calcula segundos restantes apenas se a data de início for futura
                         if($start_utc_dt > $now_utc_dt) {
                            $seconds_remaining_start = $start_utc_dt->getTimestamp() - $now_utc_dt->getTimestamp();
                            $arena_data_for_js['secondsToStart'] = max(0, $seconds_remaining_start);
                         } else {
                             // Se horário de início já passou mas status ainda é REGISTRO_ABERTO (atraso no cron?)
                             error_log("Evento REGISTRO_ABERTO ID {$current_event_id} encontrado com horario_inicio já passado.");
                             // JS tratará como "Em breve..." e recarregará/redirecionará.
                             $arena_data_for_js['secondsToStart'] = 0; // Ou null
                         }
                    } catch (Exception $e) {
                        error_log("Erro calcular timer INICIO (REGISTRO_ABERTO) ID {$current_event_id}: " . $e->getMessage());
                        $arena_data_for_js['secondsToStart'] = null; // Indica erro
                    }
                } else {
                     error_log("Evento REGISTRO_ABERTO ID {$current_event_id} sem horario_inicio_agendado_utc.");
                     $arena_data_for_js['secondsToStart'] = null;
                }

                // Conta quantos jogadores estão registrados atualmente
                try {
                    $stmt_c = $local_conn_arena->prepare(
                        "SELECT COUNT(*) FROM arena_registros WHERE id_evento = ? AND status_participacao = 'REGISTRADO'"
                    );
                    $stmt_c->execute([$current_event_id]);
                    $current_reg_count = $stmt_c->fetchColumn();
                    $arena_data_for_js['registeredCount'] = (int)$current_reg_count;
                } catch (PDOException $e_count) {
                    error_log("Erro ao contar registros para Arena ID {$current_event_id}: " . $e_count->getMessage());
                    $arena_data_for_js['registeredCount'] = '?'; // Indica erro na contagem
                }
                break;

            case 'EM_ANDAMENTO':
                // Define o status para indicar que *uma* arena está em andamento (não necessariamente a do usuário)
                $arena_data_for_js['status'] = 'EM_ANDAMENTO_OTHER';

                // Tenta encontrar o próximo evento AGENDADO para mostrar o timer "Próxima Abertura"
                try {
                    $sql_next_agendado = "SELECT horario_abertura_registro_agendado_utc
                                          FROM arena_eventos
                                          WHERE status = 'AGENDADO'
                                            AND horario_abertura_registro_agendado_utc IS NOT NULL
                                            AND horario_abertura_registro_agendado_utc > UTC_TIMESTAMP()
                                          ORDER BY horario_abertura_registro_agendado_utc ASC
                                          LIMIT 1";
                    $stmt_next_agendado = $local_conn_arena->query($sql_next_agendado);

                    if ($stmt_next_agendado && $next_agendado_event = $stmt_next_agendado->fetch(PDO::FETCH_ASSOC)) {
                        $open_utc_dt = new DateTime($next_agendado_event['horario_abertura_registro_agendado_utc'], new DateTimeZone('UTC'));
                        // Calcula segundos restantes (já garantido ser futuro pela query)
                        $seconds_remaining_opening = $open_utc_dt->getTimestamp() - $now_utc_dt->getTimestamp();
                        $arena_data_for_js['secondsToNextOpen'] = max(0, $seconds_remaining_opening);
                    } else {
                        // Nenhum próximo evento agendado encontrado
                        $arena_data_for_js['secondsToNextOpen'] = null;
                    }
                } catch (Exception $e_next) { // Captura DateTime ou PDOException
                    error_log("Erro ao buscar/calcular próximo AGENDADO enquanto EM_ANDAMENTO_OTHER (ID: {$current_event_id}): " . $e_next->getMessage());
                    $arena_data_for_js['secondsToNextOpen'] = null;
                }
                break;

             default:
                 // Status inesperado encontrado na query principal
                 error_log("INFO HOME: Query principal encontrou evento ID {$current_event_id} com status inesperado para esta lógica: '{$current_event_status_db}'.");
                 $arena_data_for_js['status'] = 'INDISPONIVEL';
                 break;
        }

    } else {
        // Nenhum evento AGENDADO, REGISTRO_ABERTO ou EM_ANDAMENTO encontrado
        $arena_data_for_js['status'] = 'INDISPONIVEL';
        error_log("INFO HOME: Status Arena INDISPONIVEL. Nenhum evento AGENDADO/REGISTRO_ABERTO/EM_ANDAMENTO encontrado no DB ou todos já passaram.");
    }


    // --- Busca Resultados das Últimas Arenas Finalizadas ---
    try {
        $sql_past_arenas = "SELECT ae.id, ae.id_vencedor, u.nome as nome_vencedor
                            FROM arena_eventos ae
                            LEFT JOIN usuarios u ON ae.id_vencedor = u.id
                            WHERE ae.status = 'FINALIZADO'
                            ORDER BY ae.id DESC -- Mais recentes primeiro
                            LIMIT 3"; // Pega os últimos 3 resultados
        $stmt_past_arenas = $local_conn_arena->prepare($sql_past_arenas);
        $stmt_past_arenas->execute();
        $past_arena_results = $stmt_past_arenas->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e_arena_past) {
        error_log("Erro DB ao buscar resultados de arenas passadas na home: " . $e_arena_past->getMessage());
        $past_arena_results = []; // Garante array vazio em caso de erro
    }

} catch (PDOException | Exception $e) { // Captura erros gerais do bloco GET
    error_log("Erro GERAL (Setup/Logic) em " . basename(__FILE__) . " para user {$id}: " . $e->getMessage());

    // Adiciona mensagem de erro crítica ao feedback
    $mensagem_final_feedback .= "<br><span class='error-message'>Erro crítico ao carregar dados da página. Por favor, avise um Administrador.</span>";

    // Reseta dados da arena para um estado seguro/indisponível
    $arena_data_for_js = [
        'status' => 'INDISPONIVEL', 'eventId' => null, 'secondsToNextOpen' => null,
        'secondsToStart' => null, 'registeredCount' => 0, 'requiredPlayers' => 100
    ];

    // Se o erro ocorreu ANTES de carregar os dados do usuário, termina a execução
    if ($usuario === null) {
        // Log adicional se necessário
        // session_destroy(); // Opcional: Deslogar o usuário em caso de erro fatal
        echo "Erro crítico ao inicializar a página. Tente recarregar ou contate o suporte.";
        // Fecha a conexão se aberta
         if ($local_conn_arena instanceof PDO) { $local_conn_arena = null; }
         if (isset($conn) && $conn instanceof PDO) { $conn = null; }
         if (isset($pdo) && $pdo instanceof PDO) { $pdo = null; }
        exit();
    }
    // Se o erro ocorreu DEPOIS de carregar o usuário, a página pode renderizar parcialmente com a mensagem de erro.
}

// --- Formatações Finais para Exibição ---

// Calcula porcentagem de XP e XP necessário
$xp_percent = 0;
$xp_necessario_display = 'N/A';
if (isset($usuario['nivel'])) {
    $nivel_atual_num = (int)($usuario['nivel'] ?? 1);
    $nivel_atual_num = max(1, $nivel_atual_num); // Nível mínimo 1
    // Cálculo do XP necessário baseado nas constantes
    $xp_necessario_calc = (int)ceil(XP_BASE_LEVEL_UP * pow($nivel_atual_num, XP_EXPOENTE_LEVEL_UP));
    $xp_necessario_calc = max(XP_BASE_LEVEL_UP, $xp_necessario_calc); // Garante um valor mínimo
    $xp_necessario_display = formatNumber($xp_necessario_calc);

    if ($xp_necessario_calc > 0 && isset($usuario['xp'])) {
        $xp_atual_num = (int)($usuario['xp'] ?? 0);
        // Calcula a porcentagem, garantindo que esteja entre 0 e 100
        $xp_percent = min(100, max(0, ($xp_atual_num / $xp_necessario_calc) * 100));
    }
}

// Define o caminho da foto do perfil (com fallback para default)
$nome_arquivo_foto = 'default.jpg'; // Imagem padrão
if (!empty($usuario['foto'])) {
    $caminho_fisico = __DIR__ . '/uploads/' . $usuario['foto'];
    if (file_exists($caminho_fisico)) {
        $nome_arquivo_foto = $usuario['foto']; // Usa a foto do usuário se existir
    } else {
         error_log("Arquivo de foto não encontrado para usuário {$id}: {$caminho_fisico}");
         // Mantém 'default.jpg'
    }
}
$caminho_web_foto = 'uploads/' . htmlspecialchars($nome_arquivo_foto); // Caminho para usar no HTML

// Verifica condições para entrar na Sala do Tempo (HP e Ki base)
$hp_atual_base = (int)($usuario['hp'] ?? 0);
$ki_atual_base = (int)($usuario['ki'] ?? 0);
$pode_entrar_templo_base = ($hp_atual_base > 0 && $ki_atual_base > 0);
$mensagem_bloqueio_templo_base = 'Recupere HP/Ki base antes de entrar na Sala do Tempo!';
if ($pode_entrar_templo_base) {
    $mensagem_bloqueio_templo_base = ''; // Limpa mensagem se puder entrar
}


// --- Arrays de Navegação (Sidebar e Bottom Nav) ---
// (Mantidos como no original)
$sidebarItems = [ ['id' => 'home', 'label' => 'Goku House', 'href' => 'home.php', 'icon' => 'img/home.png', 'fa_icon' => 'fas fa-home'], ['id' => 'praca', 'label' => 'Praça Central', 'href' => 'praca.php', 'icon' => 'img/praca.jpg', 'fa_icon' => 'fas fa-users'], ['id' => 'guia', 'label' => 'Guia do Guerreiro Z', 'href' => 'guia_guerreiro.php','icon' => 'img/guiaguerreiro.jpg', 'fa_icon' => 'fas fa-book-open'], ['id' => 'equipe', 'label' => 'Equipe Z', 'href' => 'script/grupos.php', 'icon' => 'img/grupos.jpg', 'fa_icon' => 'fas fa-users-cog'], ['id' => 'banco', 'label' => 'Banco Galáctico', 'href' => 'script/banco.php', 'icon' => 'img/banco.jpg', 'fa_icon' => 'fas fa-landmark'], ['id' => 'urunai', 'label' => 'Palácio da Vovó Uranai', 'href' => 'urunai.php', 'icon' => 'img/urunai.jpg', 'fa_icon' => 'fas fa-hat-wizard'], ['id' => 'kame', 'label' => 'Casa do Mestre Kame', 'href' => 'kame.php', 'icon' => 'img/kame.jpg', 'fa_icon' => 'fas fa-home'], ['id' => 'sala_tempo', 'label' => 'Sala do Tempo', 'href' => 'templo.php', 'icon' => 'img/templo.jpg', 'fa_icon' => 'fas fa-hourglass-half'], ['id' => 'treinos', 'label' => 'Zona de Treinamento', 'href' => 'desafios/treinos.php', 'icon' => 'img/treinos.jpg', 'fa_icon' => 'fas fa-dumbbell'], ['id' => 'desafios', 'label' => 'Desafios Z', 'href' => 'desafios.php', 'icon' => 'img/desafios.jpg', 'fa_icon' => 'fas fa-scroll'], ['id' => 'esferas', 'label' => 'Buscar Esferas', 'href' => 'esferas.php', 'icon' => 'img/esferar.jpg', 'fa_icon' => 'fas fa-dragon'], ['id' => 'erros', 'label' => 'Relatar Erros', 'href' => 'erros.php', 'icon' => 'img/erro.png', 'fa_icon' => 'fas fa-bug'], ['id' => 'perfil', 'label' => 'Perfil de Guerreiro', 'href' => 'perfil.php', 'icon' => 'img/perfil.jpg', 'fa_icon' => 'fas fa-user-edit'] ];
$bottomNavItems = [ ['label' => 'Home', 'href' => 'home.php', 'icon' => '🏠', 'id' => 'home'], ['label' => 'Praça', 'href' => 'praca.php', 'icon' => '👥', 'id' => 'praca'], ['label' => 'Templo', 'href' => 'templo.php', 'icon' => '🏛️', 'id' => 'sala_tempo'], ['label' => 'Kame', 'href' => 'kame.php', 'icon' => '🐢', 'id' => 'kame'], ['label' => 'Perfil', 'href' => 'perfil.php', 'icon' => '👤', 'id' => 'perfil'], ['label' => 'Arena', 'href' => 'script/arena/arenaroyalle.php', 'icon' => '⚔️', 'id' => 'arena'], ['label' => 'Treinos','href' => 'desafios/treinos.php', 'icon' => '💪', 'id' => 'treinos'] ];

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Goku House - <?= htmlspecialchars($usuario['nome'] ?? 'Jogador') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <style>
        /* --- ESTILOS CSS (mantidos exatamente como no original) --- */
        :root {
            --bg-image-url: url('https://c4.wallpaperflare.com/wallpaper/870/538/510/dragon-ball-dragon-ball-z-west-city-hd-wallpaper-preview.jpg');
            --panel-bg: rgba(25, 28, 36, 0.9);
            --panel-border: rgba(255, 255, 255, 0.15);
            --text-primary: #e8eaed;
            --text-secondary: #bdc1c6;
            --accent-color-1: #00e676; /* Verde */
            --accent-color-2: #ffab00; /* Laranja/Amarelo */
            --accent-color-3: #2979ff; /* Azul */
            --danger-color: #ff5252;  /* Vermelho */
            --info-color: #29b6f6;    /* Azul Claro (Info) */
            --success-color: #00e676; /* Verde (Sucesso) */
            --warning-color: #ffa726; /* Laranja (Aviso) */
            --disabled-color: #5f6368; /* Cinza Desabilitado */
            --border-radius: 10px;
            --shadow: 0 8px 25px rgba(0, 0, 0, 0.5);
            --font-main: 'Roboto', sans-serif;
            --body-original-padding-bottom: 20px; /* Para JS responsivo */
            --dbz-blue: #1a2a4d;
            --dbz-orange: #f5a623;
            --dbz-orange-dark: #e47d1e;
            --dbz-red: #cc5500;
            --dbz-white: #ffffff;
            --dbz-grey: #aaaaaa;
            --dbz-light-grey: #f0f0f0;
            --dbz-dark-grey: #333333;
        }

        @keyframes pulse-glow-button {
            0% { box-shadow: 0 0 8px var(--accent-color-2), inset 0 1px 1px rgba(255, 255, 255, .2); transform: scale(1) }
            70% { box-shadow: 0 0 16px 4px var(--accent-color-2), inset 0 1px 1px rgba(255, 255, 255, .3); transform: scale(1.03) }
            100% { box-shadow: 0 0 8px var(--accent-color-2), inset 0 1px 1px rgba(255, 255, 255, .2); transform: scale(1) }
        }

        @keyframes ki-charge {
            0% { background-size: 100% 100%; opacity: .6 }
            50% { background-size: 150% 150%; opacity: 1 }
            100% { background-size: 100% 100%; opacity: .6 }
        }

        *, *::before, *::after {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background-image: var(--bg-image-url);
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: var(--text-primary);
            font-family: var(--font-main);
            margin: 0;
            padding: 20px; /* Padding original, guardado em --body-original-padding-bottom */
            min-height: 100vh;
            background-color: #0b0c10; /* Cor de fundo sólida como fallback */
            overflow-x: hidden; /* Previne scroll horizontal */
        }

        .container {
            width: 100%;
            max-width: 1500px; /* Largura máxima do layout */
            display: flex;
            justify-content: center;
            gap: 20px;
            align-items: stretch; /* Faz colunas terem mesma altura */
            margin-left: auto;
            margin-right: auto;
        }

        .coluna {
            background-color: var(--panel-bg);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--shadow);
            border: 1px solid var(--panel-border);
            backdrop-filter: blur(6px); /* Efeito de vidro fosco */
            display: flex;
            flex-direction: column; /* Padrão para colunas */
        }

        .feedback-container {
            width: 100%;
            max-width: 1200px; /* Largura máxima do feedback */
            margin: 0 auto 20px auto; /* Centralizado, com margem inferior */
            padding: 15px 25px;
            background-color: rgba(30, 34, 44, 0.95);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            text-align: center;
            font-weight: 500;
            font-size: 1rem;
            line-height: 1.6;
            border: 1px solid var(--panel-border);
            border-left-width: 5px; /* Borda esquerda mais grossa para destaque */
            /* Inicialmente escondido, mostrado via PHP se houver mensagem */
            display: <?php echo !empty($mensagem_final_feedback) ? 'block' : 'none'; ?>;
            border-left-color: var(--info-color); /* Cor padrão da borda (azul info) */
            color: var(--text-primary);
        }

        /* Estilos específicos para tipos de feedback */
        .feedback-container span.error-message   { color: var(--danger-color) !important; font-weight: bold; }
        .feedback-container span.success-message { color: var(--success-color) !important; font-weight: bold; }
        .feedback-container span.levelup-message { color: var(--success-color) !important; font-weight: bold; } /* Trata levelup como sucesso */
        .feedback-container span.info-message    { color: var(--info-color) !important; font-weight: bold; }
        .feedback-container span.warning-message { color: var(--warning-color) !important; font-weight: bold; }

        /* Muda a cor da borda e fundo baseado no tipo de mensagem contida */
        .feedback-container:has(span.success-message),
        .feedback-container:has(span.levelup-message) { border-left-color: var(--success-color); background-color: rgba(0, 230, 118, 0.1); }
        .feedback-container:has(span.error-message)   { border-left-color: var(--danger-color); background-color: rgba(255, 82, 82, 0.1); }
        .feedback-container:has(span.warning-message) { border-left-color: var(--warning-color); background-color: rgba(255, 167, 38, 0.1); }
        .feedback-container:has(span.info-message)    { border-left-color: var(--info-color); background-color: rgba(41, 182, 246, 0.1); }

        .feedback-container strong { color: inherit; font-weight: 700; }
        /* Destaque extra para level up */
        .feedback-container:has(.levelup-message) strong { color: var(--accent-color-2); }


        /* --- Coluna Esquerda (Perfil Rápido) --- */
        .coluna-esquerda {
            width: 25%;
            min-width: 270px; /* Largura mínima */
            text-align: center;
            gap: 12px; /* Espaçamento entre elementos */
            flex-shrink: 0; /* Não encolher */
        }

        .player-card { margin-bottom: 5px; flex-shrink: 0; } /* Card do jogador */
        .foto {
            width: 120px;
            height: 120px;
            border-radius: 50%; /* Foto redonda */
            object-fit: cover; /* Cobre a área sem distorcer */
            border: 4px solid var(--accent-color-1); /* Borda verde */
            margin: 0 auto 10px auto; /* Centraliza com margem inferior */
            box-shadow: 0 0 15px rgba(0, 230, 118, 0.5); /* Sombra verde */
            transition: transform 0.3s ease;
            display: block;
        }
        .foto:hover { transform: scale(1.05); } /* Efeito de zoom no hover */

        .player-name  { font-size: 1.3rem; font-weight: 700; color: #fff; margin-bottom: 2px; word-wrap: break-word; }
        .player-level { font-size: 1.0rem; font-weight: 500; color: var(--accent-color-1); margin-bottom: 3px; }
        .player-race  { font-size: 0.8rem; font-weight: 500; color: var(--text-secondary); margin-bottom: 3px; }
        .player-rank  { font-size: 0.8rem; font-weight: 500; color: var(--accent-color-3); margin-bottom: 10px; }
        .zeni-display { font-size: 1rem; color: var(--accent-color-2); font-weight: 700; margin-bottom: 6px; }

        /* Barra de XP */
        .xp-bar-container { width: 100%; margin-bottom: 10px; flex-shrink: 0; }
        .xp-label { font-size: 0.7rem; color: var(--text-secondary); text-align: left; margin-bottom: 3px; display: block; }
        .xp-bar {
            height: 11px;
            background-color: rgba(0, 0, 0, 0.3); /* Fundo escuro semi-transparente */
            border-radius: 6px;
            overflow: hidden;
            position: relative;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .xp-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent-color-3), var(--accent-color-1)); /* Gradiente azul para verde */
            border-radius: 6px;
            transition: width 0.5s ease-in-out; /* Animação suave da largura */
            animation: ki-charge 1.5s linear infinite; /* Animação de 'carga' */
            width: <?= $xp_percent ?>%; /* Largura definida pelo PHP */
        }
        .xp-text {
            position: absolute;
            top: -1px; /* Ajuste fino vertical */
            left: 0;
            right: 0;
            font-size: 0.65rem;
            font-weight: 500;
            color: #fff;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7); /* Sombra para legibilidade */
            line-height: 13px; /* Alinha verticalmente com a barra */
            text-align: center;
        }

        /* Botão de Pontos a Distribuir */
        .btn-pontos {
            display: inline-block;
            padding: 8px 15px;
            background: linear-gradient(45deg, var(--accent-color-2), #ffc107); /* Gradiente laranja/amarelo */
            color: #111; /* Texto escuro para contraste */
            text-decoration: none;
            font-weight: 700;
            font-size: 0.9rem;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
            margin-bottom: 8px;
            border: none;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
            animation: pulse-glow-button 2.5s infinite alternate ease-in-out; /* Animação de pulso */
        }
        .btn-pontos:hover { transform: translateY(-2px); box-shadow: 0 6px 12px rgba(0, 0, 0, 0.4); filter: brightness(1.1); }
        /* Estilo quando não clicável (ex: na página de perfil) */
        .btn-pontos[style*="cursor: default"] {
            background: var(--disabled-color);
            color: var(--text-secondary);
            filter: none;
            box-shadow: none;
            animation: none;
            cursor: default !important;
        }

        /* Grid de Status Primários */
        .stats-grid {
            display: grid;
            grid-template-columns: auto 1fr; /* Label | Valor */
            gap: 5px 10px; /* Espaçamento linha | coluna */
            text-align: left;
            font-size: 0.8rem;
            width: 100%;
            flex-shrink: 0;
        }
        .stats-grid strong { color: var(--text-secondary); font-weight: 500; font-size: 0.75rem;}
        .stats-grid span { color: var(--text-primary); font-weight: 500; text-align: right; font-size: 0.8rem;}
        /* Destaques para pontos e ranks */
        .stats-grid strong:last-of-type, /* Últimos 3 strong (Pontos, Rank Nivel, Rank Sala) */
        .stats-grid strong:nth-last-of-type(2),
        .stats-grid strong:nth-last-of-type(3) { color: var(--accent-color-1); }
        .stats-grid span[style*="--accent-color-2"], /* Span de Pontos */
        .stats-grid span[style*="--accent-color-1"]  /* Spans de Rank */
        { font-weight: 700; }


        .divider { height: 1px; background-color: var(--panel-border); border: none; margin: 10px 0; flex-shrink: 0;}

        /* Grid de Status Secundários (ID, Tempo Sala) */
        .stats-grid-secondary {
            font-size: 0.8rem;
            background-color: transparent;
            border: none;
            padding: 0;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 3px 10px;
            margin-top: 10px;
            flex-shrink: 0;
        }
        .stats-grid-secondary strong { color: var(--dbz-grey); text-align: left; font-weight: 500;}
        .stats-grid-secondary span { color: var(--dbz-light-grey); text-align: right; font-weight: normal;}

        /* Botões Inferiores (Perfil, Logout) */
        .left-column-bottom-buttons {
            margin-top: auto; /* Empurra para o fundo */
            padding-top: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
            border-top: 1px solid var(--panel-border);
            flex-shrink: 0; /* Não encolher */
        }
        .action-button { /* Estilo base para botões de ação */
            display: block;
            width: 100%;
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        .action-button:hover { transform: translateY(-1px) scale(1.01); filter: brightness(1.1);}
        .btn-perfil { background-color: var(--accent-color-1); color: #111; }
        .btn-logout { background-color: var(--danger-color); color: #fff; }


        /* --- Coluna Central (Conteúdo Principal) --- */
        .coluna-central {
            width: 48%; /* Largura da coluna central */
            /* height: calc(100vh - 40px); Altura baseada na viewport (menos padding do body) */
            /* max-height: 850px; Altura máxima */
            /* Removido height e max-height fixos para permitir que cresça com o conteúdo */
            display: flex; /* Re-adicionado flex */
            flex-direction: column;
            gap: 15px; /* Espaçamento entre título, scrollable e chat */
        }

        .coluna-central h1 {
            font-family: var(--font-main); /* Usa a fonte principal */
            font-weight: 700;
            color: var(--accent-color-2); /* Laranja */
            text-align: center;
            font-size: 2.1rem;
            margin: 0 0 5px 0;
            padding-bottom: 10px;
            border-bottom: 3px solid var(--accent-color-2);
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5);
            letter-spacing: 1px;
            text-transform: uppercase;
            flex-shrink: 0; /* Não encolher o título */
        }

        /* Área de Conteúdo Rolável */
        .main-content-scrollable {
            flex-grow: 1; /* Ocupa o espaço restante */
            overflow-y: auto; /* Habilita scroll vertical se necessário */
            min-height: 0; /* Necessário para flex-grow funcionar corretamente */
            padding: 15px 10px 10px 5px; /* Padding interno */
            /* Estilização da barra de rolagem (Webkit e Firefox) */
            scrollbar-width: thin;
            scrollbar-color: var(--panel-border) transparent;
        }
        .main-content-scrollable::-webkit-scrollbar { width: 6px; }
        .main-content-scrollable::-webkit-scrollbar-track { background: transparent; }
        .main-content-scrollable::-webkit-scrollbar-thumb { background-color: var(--panel-border); border-radius: 3px; }
        .main-content-scrollable::-webkit-scrollbar-thumb:hover { background-color: var(--text-secondary); }

        .main-content-scrollable h2 {
            color: #fff;
            font-weight: 700;
            font-size: 1.5rem;
            margin-top: 10px; /* Espaçamento acima do H2 */
            margin-bottom: 15px; /* Espaçamento abaixo do H2 */
            border-bottom: 2px solid var(--accent-color-1); /* Linha verde abaixo */
            padding-bottom: 8px;
            display: block; /* Garante que ocupe a largura */
            text-align: left;
            /* Removido flex-shrink: 0 pois está dentro do scrollable */
        }
        .main-content-scrollable h2 i.icon-h2 { margin-right: 8px; color: var(--accent-color-1); }

         /* Box de Resultados Anteriores da Arena */
        .arena-results-box {
            background-color: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--panel-border);
            padding: 20px 25px;
            border-radius: var(--border-radius);
            margin-top: 30px; /* Espaço acima da caixa */
        }

        .arena-results-box h2 {
            color: var(--success-color); /* Verde */
            border-bottom: 2px solid var(--success-color);
            font-size: 1.5rem;
            margin-top: 0; /* Remove margem superior padrão do h2 */
            margin-bottom: 15px;
            padding-bottom: 8px;
        }
        .arena-results-box h2 i.icon-h2 { color: var(--success-color); }

        .arena-results-box ul { list-style: none; padding-left: 0; margin-top: 15px; }
        .arena-results-box li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 18px;
            margin-bottom: 10px;
            border-radius: 8px;
            background-color: rgba(40, 44, 52, 0.7);
            border: 1px solid var(--panel-border);
            transition: background-color 0.2s;
        }
        .arena-results-box li:hover { background-color: rgba(55, 62, 76, 0.8); }

        .arena-results-box li .edition-label { font-weight: bold; color: var(--accent-color-2); flex-shrink: 0; margin-right: 15px; font-size: 0.95rem; }
        .arena-results-box li .winner-info { text-align: right; flex-grow: 1; font-size: 0.95rem; }
        .arena-results-box li .winner-icon { color: var(--accent-color-2); margin-right: 6px; font-size: 1.1em; vertical-align: text-bottom; display: inline-block; }
        .arena-results-box li .winner-name { color: var(--accent-color-1); font-weight: bold;}
        .arena-results-box li .winner-id { font-size: 0.8em; color: var(--text-secondary); margin-left: 3px; }
        .arena-results-box .no-results { color: var(--text-secondary); font-style: italic; text-align: center; padding: 15px 0; }

        /* --- Área do Chat (Comentada no HTML, mas estilos mantidos) --- */
        .chat-area {
             margin-top: auto; /* Empurra o chat para baixo se houver espaço */
             border-top: 1px solid var(--panel-border);
             padding-top: 15px;
             flex-shrink: 0; /* Não encolher a área do chat */
             background-color: rgba(15, 17, 22, 0.5); /* Fundo levemente diferente */
             padding: 15px;
             border-radius: 0 0 var(--border-radius) var(--border-radius); /* Arredonda cantos inferiores */
             margin: 0 -20px -20px -20px; /* Estica para preencher padding da coluna */
        }
        .chat-area h3 { margin-bottom: 10px; text-align: center; border-bottom: none; color: var(--accent-color-3); font-size: 1.1rem; }
        .chat-container-ajax { width: 100%; display: flex; flex-direction: column; }
        #chat-messages-ajax {
            flex-grow: 1; /* Tenta ocupar espaço, mas limitado por max-height */
            overflow-y: auto;
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-height: 250px; /* Altura máxima da caixa de mensagens */
            min-height: 120px; /* Altura mínima */
            background-color: rgba(0, 0, 0, 0.25);
            border-radius: 8px;
            margin-bottom: 10px;
            border: 1px solid var(--panel-border);
            scrollbar-width: thin;
            scrollbar-color: var(--panel-border) rgba(0, 0, 0, 0.2);
        }
        #chat-messages-ajax::-webkit-scrollbar { width: 5px; }
        #chat-messages-ajax::-webkit-scrollbar-track { background: rgba(0, 0, 0, 0.2); border-radius: 3px;}
        #chat-messages-ajax::-webkit-scrollbar-thumb { background-color: var(--panel-border); border-radius: 3px; }
        #chat-messages-ajax::-webkit-scrollbar-thumb:hover { background-color: var(--accent-color-3); }

        .chat-message-ajax {
            max-width: 80%; /* Mensagens não ocupam a largura toda */
            padding: 8px 14px;
            border-radius: 15px; /* Cantos arredondados */
            line-height: 1.4;
            font-size: 0.85rem;
            word-wrap: break-word; /* Quebra palavras longas */
            position: relative;
        }
        .chat-message-ajax.other { /* Mensagens de outros */
            background-color: #4a5568; /* Fundo cinza azulado */
            border-bottom-left-radius: 4px; /* Canto inferior esquerdo menos redondo */
            align-self: flex-start; /* Alinha à esquerda */
            color: var(--text-primary);
        }
        .chat-message-ajax.mine { /* Mensagens do usuário logado */
            background-color: var(--accent-color-3); /* Fundo azul */
            color: #fff;
            align-self: flex-end; /* Alinha à direita */
            border-bottom-right-radius: 4px; /* Canto inferior direito menos redondo */
        }
        .chat-message-ajax .sender { display: block; font-size: 0.75em; font-weight: 600; color: var(--accent-color-2); margin-bottom: 3px; opacity: 0.9; }
        .chat-message-ajax.mine .sender { color: rgba(255, 255, 255, 0.8); text-align: right; } /* Nome do remetente nas minhas mensagens */
        .chat-message-ajax .sender small { font-size: 0.9em; margin-left: 4px; color: var(--text-secondary); }
        .chat-message-ajax.mine .sender small { margin-left: 0; margin-right: 4px; }
        .chat-message-ajax .msg-text { font-size: inherit; }
        .chat-empty { text-align: center; color: var(--text-secondary); padding: 20px; font-style: italic; font-size: 0.85rem; }

        #chat-form-ajax { display: flex; gap: 8px; }
        #chat-message-input {
            flex-grow: 1; /* Ocupa espaço restante */
            padding: 10px 15px;
            border: 1px solid var(--panel-border);
            border-radius: 20px; /* Input arredondado */
            background-color: rgba(0, 0, 0, 0.3);
            color: var(--text-primary);
            font-size: 0.9rem;
            outline: none; /* Remove outline padrão do foco */
            transition: border-color 0.2s;
        }
        #chat-message-input:focus { border-color: var(--accent-color-3); } /* Borda azul ao focar */
        #chat-form-ajax button {
            padding: 10px 18px;
            border: none;
            background: var(--accent-color-3); /* Botão azul */
            color: #fff;
            border-radius: 20px; /* Botão arredondado */
            cursor: pointer;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s;
            flex-shrink: 0; /* Não encolher o botão */
        }
        #chat-form-ajax button:hover { background-color: #1e88e5; filter: brightness(1.1); transform: scale(1.02); }
        #chat-form-ajax button:disabled { background-color: var(--disabled-color); cursor: not-allowed; opacity: 0.7;}


        /* --- Coluna Direita (Navegação) --- */
        .coluna-direita {
            width: 25%;
            min-width: 250px; /* Largura mínima */
            padding: 15px; /* Padding menor */
            gap: 8px; /* Espaçamento menor entre botões */
            flex-shrink: 0; /* Não encolher */
        }

        .nav-button { /* Estilo base do botão de navegação */
            display: grid;
            grid-template-columns: 45px 1fr; /* Coluna Ícone | Coluna Texto */
            align-items: center;
            gap: 12px;
            padding: 10px 12px 10px 8px;
            background: linear-gradient(to bottom, rgba(55, 62, 76, 0.85), rgba(40, 44, 52, 0.85)); /* Gradiente cinza escuro */
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 8px;
            border: 1px solid var(--panel-border);
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden; /* Para efeitos futuros */
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            cursor: pointer;
        }
        .nav-button-icon-area { grid-column: 1 / 2; display: flex; justify-content: center; align-items: center; width: 100%; height: 28px; }
        .nav-button-icon { width: 28px; height: 28px; object-fit: contain; opacity: 0.7; transition: opacity 0.2s ease, transform 0.2s ease; display: block; margin: 0; flex-shrink: 0;}
        .nav-button i.fas { font-size: 22px; opacity: 0.7; transition: opacity 0.2s ease, transform 0.2s ease; color: inherit; text-align: center; width: 100%;}
        .nav-button span { grid-column: 2 / 3; text-align: left; }

        .nav-button:hover {
            background: linear-gradient(to bottom, rgba(65, 72, 86, 0.95), rgba(50, 54, 62, 0.95));
            border-color: rgba(255, 255, 255, 0.3);
            color: #fff;
            transform: translateY(-2px) scale(1.01); /* Efeito leve de levantar */
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.3), 0 0 8px rgba(41, 121, 255, 0.4); /* Sombra mais pronunciada e brilho azul */
        }
        .nav-button:hover .nav-button-icon, .nav-button:hover i.fas { opacity: 1; transform: scale(1.05); }
        .nav-button:active { transform: translateY(0px) scale(1.0); box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2); filter: brightness(0.95); } /* Efeito ao clicar */

        .nav-button.active { /* Botão da página atual */
            background: linear-gradient(to bottom, var(--accent-color-1), #00c853); /* Gradiente verde */
            color: #000; /* Texto escuro */
            border-color: rgba(0, 230, 118, 0.5);
            font-weight: 700;
            box-shadow: 0 3px 6px rgba(0, 0, 0, 0.3), inset 0 1px 1px rgba(255, 255, 255, 0.2);
        }
        .nav-button.active .nav-button-icon, .nav-button.active i.fas { opacity: 1; filter: brightness(0.8); }
        .nav-button.active:hover { filter: brightness(1.1); box-shadow: 0 5px 10px rgba(0, 0, 0, 0.3), 0 0 8px var(--accent-color-1); }

        .nav-button.disabled { /* Botão desabilitado (ex: Sala do Tempo) */
            background: var(--disabled-color) !important; /* Cor cinza */
            color: #8894a8 !important;
            cursor: not-allowed !important;
            border-color: rgba(0, 0, 0, 0.2) !important;
            opacity: 0.6 !important;
            box-shadow: none !important;
             transform: none !important; /* Remove efeito hover */
        }
        .nav-button.disabled:hover {
            /* Mantém o estilo desabilitado no hover */
             background: var(--disabled-color) !important;
             transform: none !important;
             border-color: rgba(0, 0, 0, 0.2) !important;
             color: #8894a8 !important;
             box-shadow: none !important;
        }
        .nav-button.disabled .nav-button-icon, .nav-button.disabled i.fas { filter: grayscale(100%); opacity: 0.5; transform: none !important; }

        .nav-button.logout {} /* Estilo base herdado */
        .nav-button.logout:hover { /* Hover específico para logout */
            background: linear-gradient(to bottom, var(--danger-color), #c0392b) !important; /* Gradiente vermelho */
            border-color: rgba(255, 255, 255, 0.2) !important;
            box-shadow: 0 5px 10px rgba(0, 0, 0, 0.3), 0 0 8px var(--danger-color) !important; /* Sombra e brilho vermelho */
        }
         .nav-button.logout span { grid-column: 2 / 3; } /* Garante posição do texto */
         .nav-button.logout .nav-button-icon-area { grid-column: 1 / 2; } /* Garante posição do ícone */


        /* --- Navegação Inferior (Mobile) --- */
        #bottom-nav {
            display: none; /* Escondido por padrão */
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: rgba(20, 23, 31, 0.97); /* Fundo escuro com transparência */
            border-top: 1px solid var(--panel-border);
            padding: 2px 0;
            box-shadow: 0 -5px 15px rgba(0, 0, 0, 0.35);
            justify-content: space-around; /* Distribui itens igualmente */
            align-items: stretch;
            z-index: 1000;
            backdrop-filter: blur(6px); /* Efeito fosco */
            height: 60px; /* Altura da barra */
        }
        .bottom-nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex-grow: 1; /* Ocupa espaço igual */
            text-decoration: none;
            color: var(--text-secondary);
            padding: 4px 2px;
            font-size: 0.65rem; /* Tamanho da fonte do label */
            text-align: center;
            transition: background-color 0.2s ease, color 0.2s ease;
            cursor: pointer;
            border-radius: 5px;
            margin: 2px;
            line-height: 1.2;
        }
        .bottom-nav-item:not(.active):not(.disabled):hover { background-color: rgba(255, 255, 255, 0.05); color: var(--text-primary); }
        .bottom-nav-item.active { color: var(--accent-color-1); font-weight: bold; background-color: rgba(0, 230, 118, 0.1); } /* Item ativo verde */
        .bottom-nav-icon { font-size: 1.5rem; line-height: 1; margin-bottom: 4px; }
        .bottom-nav-label { font-size: inherit; }
        .bottom-nav-item.disabled {
            cursor: not-allowed;
            opacity: 0.5;
            color: #6a7383 !important;
            background-color: transparent !important;
        }
        .bottom-nav-item.disabled:hover { color: #6a7383 !important; }


        /* --- Modal Popup (Notificações) --- */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.7); /* Fundo escuro semi-transparente */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1100; /* Acima da navegação inferior */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0s linear 0.3s; /* Transição suave */
        }
        .modal-overlay.visible { opacity: 1; visibility: visible; transition: opacity 0.3s ease; }
        .modal-popup {
            background-color: var(--panel-bg, #1e2129);
            padding: 25px 30px;
            border-radius: var(--border-radius, 10px);
            border: 1px solid var(--panel-border, rgba(255, 255, 255, 0.15));
            box-shadow: var(--shadow, 0 8px 25px rgba(0, 0, 0, 0.5));
            width: 90%; /* Responsivo */
            max-width: 450px; /* Largura máxima */
            text-align: center;
            position: relative;
            transform: scale(0.9); /* Efeito de entrada */
            transition: transform 0.3s ease;
        }
        .modal-overlay.visible .modal-popup { transform: scale(1); } /* Animação de entrada */
        .modal-popup h3 {
            color: var(--dbz-orange, #f5a623); /* Laranja DBZ */
            font-family: var(--font-main);
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 1.4em;
            border-bottom: 1px solid var(--dbz-orange-dark, #e47d1e);
            padding-bottom: 10px;
        }
        .modal-popup #popup-message {
            color: var(--text-primary, #e8eaed);
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 25px;
            min-height: 40px; /* Garante espaço mínimo para o texto */
            word-wrap: break-word; /* Quebra texto longo */
        }
        .modal-popup #popup-actions { display: flex; justify-content: center; gap: 15px; flex-wrap: wrap; }
        .modal-popup .popup-action-btn {
            display: inline-block;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            text-decoration: none;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 100px; /* Largura mínima para botões */
        }
        .popup-action-btn.btn-ok { background-color: var(--accent-color-3, #2979ff); color: #fff; } /* Botão OK Azul */
        .popup-action-btn.btn-accept { background-color: var(--accent-color-1, #00e676); color: #111; } /* Aceitar Verde */
        .popup-action-btn.btn-reject { background-color: var(--danger-color, #ff5252); color: #fff; } /* Rejeitar Vermelho */
        .popup-action-btn:hover { transform: translateY(-2px); filter: brightness(1.1); }
        .modal-close-btn { /* Botão 'X' para fechar */
            position: absolute;
            top: 10px; right: 15px;
            background: none; border: none;
            font-size: 1.8em;
            color: var(--text-secondary, #bdc1c6);
            cursor: pointer;
            line-height: 1;
            padding: 0;
            opacity: 0.7;
            transition: all 0.2s;
        }
        .modal-close-btn:hover { color: var(--text-primary, #e8eaed); opacity: 1; transform: scale(1.1); }

        /* --- Widget Dinâmico da Arena --- */
        .arena-dynamic-status {
            background: rgba(41, 121, 255, 0.1); /* Fundo azul claro transparente */
            border: 1px solid var(--accent-color-3, #2979ff); /* Borda azul */
            border-left-width: 4px; /* Borda esquerda mais grossa */
            padding: 15px 20px;
            border-radius: var(--border-radius, 10px);
            margin-bottom: 25px; /* Espaço abaixo do widget */
            text-align: center;
        }
        .arena-dynamic-status h3 {
            color: var(--info-color, #29b6f6); /* Título em azul claro */
            border-bottom: 1px solid var(--info-color, #29b6f6);
            font-size: 1.2rem;
            margin-top: 0;
            margin-bottom: 15px;
            padding-bottom: 8px;
            display: inline-block; /* Para a borda não ocupar a largura toda */
            font-weight: bold;
        }
        .arena-dynamic-status h3 i.icon-h3 { margin-right: 8px; }
        .arena-dynamic-status p { font-size: 0.95rem; line-height: 1.5; color: var(--text-primary, #e0e0e0); margin-bottom: 10px; }
        .arena-dynamic-status p strong { color: var(--accent-color-1, #00e676); font-weight: bold; }
        .arena-dynamic-status .arena-id { font-size: 0.9em; color: var(--text-secondary, #a0a0a0); }
        .arena-timer { /* Estilo do contador */
            font-size: 1.3rem;
            font-weight: bold;
            color: var(--accent-color-2, #ffab00); /* Cor laranja/amarela */
            margin: 10px 0;
            padding: 6px 12px;
            background-color: rgba(0, 0, 0, 0.2); /* Fundo escuro sutil */
            border-radius: 6px;
            display: inline-block; /* Para não ocupar largura toda */
            min-width: 130px; /* Largura mínima para evitar 'pulos' */
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .arena-timer.loading { /* Estilo enquanto calcula */
             font-style: italic;
             color: var(--text-secondary, #a0a0a0);
             font-size: 0.9rem;
             background: none;
             border: none;
         }
        .arena-dynamic-status form { margin-top: 12px; }
        .arena-dynamic-status button { /* Botão de Registrar/Registrado */
            background-color: var(--accent-color-1, #00e676); /* Verde */
            color: #111; /* Texto escuro */
            padding: 9px 20px;
            border: none;
            border-radius: 6px;
            font-size: 0.95em;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .arena-dynamic-status button:hover:not(:disabled) { background-color: #00cf68; transform: translateY(-1px); }
        .arena-dynamic-status button:disabled {
            background-color: var(--disabled-color, #555e6d); /* Cinza desabilitado */
            color: var(--text-secondary, #a0a0a0);
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        .arena-dynamic-status .registered-message { color: var(--success-color, #00e676); font-weight: bold; margin-top: 8px; font-size: 0.9em; }
        .arena-dynamic-status .return-link a { /* Link 'Retornar à Batalha' */
            display: inline-block;
            margin-top: 10px;
            padding: 9px 18px;
            background-color: var(--accent-color-2, #ffab00); /* Laranja/Amarelo */
            color: #111;
            font-weight: bold;
            text-decoration: none;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .arena-dynamic-status .return-link a:hover { filter: brightness(1.1); }


        /* --- Media Queries (Responsividade) --- */

        /* Telas Médias (Tablets, Laptops Pequenos) */
        @media (max-width: 1200px) {
            body { padding: 15px; }
            .container {
                 flex-direction: column; /* Empilha as colunas */
                 align-items: center; /* Centraliza as colunas empilhadas */
                 width: 100%;
                 padding: 0;
                 gap: 15px;
            }
            .coluna {
                width: 100% !important; /* Ocupa largura total */
                max-width: 800px; /* Limita a largura máxima */
                margin-bottom: 15px;
                /* Remove altura fixa */
                height: auto !important;
                max-height: none !important;
            }
            .coluna-direita, .coluna-esquerda { min-width: unset; } /* Remove largura mínima */

            /* Reordena colunas */
            .coluna-central { order: 1; }
            .coluna-esquerda { order: 2; }
            .coluna-direita {
                order: 3;
                padding: 15px !important; /* Ajusta padding */
                background-color: var(--panel-bg) !important; /* Garante fundo */
                max-width: 800px; /* Mesmo max-width das outras */
            }
            .chat-area { margin: 0 -15px -15px -15px; } /* Ajusta margem negativa do chat */
        }

        /* Telas Pequenas (Móveis) */
        @media (max-width: 768px) {
            body {
                padding: 10px; /* Menos padding geral */
                padding-bottom: 75px; /* Espaço para a navegação inferior */
            }
            /* Esconde colunas laterais */
            .coluna-esquerda { display: none !important; }
            .coluna-direita { display: none !important; }
            /* Mostra navegação inferior */
            #bottom-nav { display: flex; }

            .coluna { padding: 15px; } /* Padding interno das colunas */
            .coluna-central {
                 width: 100% !important;
                 max-width: 100%;
            }
            .coluna-central h1 { font-size: 1.8rem; margin-bottom: 15px;} /* Título menor */
            .main-content-scrollable h2 { font-size: 1.3rem; } /* Subtítulos menores */
            .arena-results-box h2 { font-size: 1.2rem; }
            .arena-results-box li { font-size: 0.85rem; flex-direction: column; align-items: flex-start; gap: 5px;} /* Empilha resultados */
            .arena-results-box li .winner-info{ text-align: left; }
            #chat-messages-ajax { max-height: 180px;} /* Caixa de chat menor */
            .chat-area { margin: 0 -15px -15px -15px; } /* Ajusta margem negativa do chat */
            .arena-dynamic-status { padding: 12px 15px; margin-bottom: 20px; }
            .arena-dynamic-status h3 { font-size: 1.1rem; }
            .arena-dynamic-status p { font-size: 0.9rem; }
            .arena-timer { font-size: 1.1rem; min-width: 110px; }
            .arena-dynamic-status button { padding: 8px 18px; font-size: 0.9em; }
        }

        /* Telas Muito Pequenas */
        @media (max-width: 480px) {
            body {
                padding: 8px; /* Ainda menos padding */
                padding-bottom: 70px; /* Ajusta espaço para nav inferior */
            }
            .container { gap: 8px; }
            .coluna { padding: 12px; margin-bottom: 8px; }
            .coluna-central h1 { font-size: 1.6rem;}
            .main-content-scrollable h2 { font-size: 1.1rem; }
            .arena-results-box h2 { font-size: 1.1rem; }
            .arena-results-box li { font-size: 0.8rem;}
            #bottom-nav { height: 55px; } /* Nav inferior um pouco menor */
            .bottom-nav-item .bottom-nav-icon { font-size: 1.2rem; }
            .bottom-nav-item .bottom-nav-label { font-size: 0.6rem; }
            .feedback-container { font-size: 0.85rem; padding: 8px 12px; margin: 0 auto 8px auto;}
            #chat-messages-ajax { max-height: 150px;}
            .chat-area { margin: 0 -12px -12px -12px; } /* Ajusta margem negativa do chat */
            .modal-popup { padding: 20px; } /* Menos padding no modal */
            .modal-popup h3 { font-size: 1.2em; }
            .modal-popup #popup-message { font-size: 0.9rem; }
        }

    </style>
</head>
<body>

    <?php /* --- Container de Feedback --- */ ?>
    <?php if (!empty($mensagem_final_feedback)): ?>
        <div class="feedback-container"><?= $mensagem_final_feedback /* nl2br() removido para usar <br> do PHP */ ?></div>
    <?php endif; ?>

    <?php /* --- Layout Principal --- */ ?>
    <div class="container">

        <?php /* --- Coluna Esquerda (Perfil Rápido) --- */ ?>
        <div class="coluna coluna-esquerda">
            <div class="player-card">
                <img class="foto" src="<?= $caminho_web_foto ?>" alt="Foto de <?= htmlspecialchars($usuario['nome'] ?? '') ?>">
                <div class="player-name"><?= $nome_formatado_usuario // Já formatado ?></div>
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

            <?php /* Botão de Pontos (se houver) */ ?>
            <?php
            $pontos_num = filter_var($usuario['pontos'] ?? 0, FILTER_VALIDATE_INT);
            if ($pontos_num !== false && $pontos_num > 0):
                if (basename($_SERVER['PHP_SELF']) !== 'perfil.php'): // Link se não estiver no perfil
            ?>
                    <a href="perfil.php" class="btn-pontos action-button">
                         <?= formatNumber($pontos_num) ?> Pts <span style="font-size: 0.8em;">(Distribuir)</span>
                    </a>
            <?php else: // Div não clicável se já estiver no perfil ?>
                    <div class="btn-pontos" style="cursor: default;"><?= formatNumber($pontos_num) ?> Pontos (Use acima)</div>
            <?php
                endif;
            endif;
            ?>

            <?php /* Grid de Status Primários */ ?>
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

            <hr class="divider">

            <?php /* Grid de Status Secundários */ ?>
            <div class="stats-grid-secondary">
                <strong>ID</strong><span><?= htmlspecialchars($usuario['id'] ?? 'N/A') ?></span>
                <strong>Tempo Sala</strong><span><?= formatNumber($usuario['tempo_sala'] ?? 0) ?> min</span>
            </div>

            <?php /* Botões Inferiores da Coluna */ ?>
            <div class="left-column-bottom-buttons">
                <a class="action-button btn-perfil" href="perfil.php">Meu Perfil</a>
                <a class="action-button btn-logout" href="sair.php">Sair (Logout)</a>
            </div>
        </div>

        <?php /* --- Coluna Central (Conteúdo Principal) --- */ ?>
        <div class="coluna coluna-central">
            <h1>Goku House</h1>
            <div class="main-content-scrollable">

                <?php /* --- Widget Dinâmico da Arena --- */ ?>
                <div class="arena-dynamic-status">
                     <h3><i class="fas fa-fist-raised icon-h3"></i> Arena Royalle</h3>

                     <?php
                     // Exibe conteúdo baseado no status da arena definido no PHP
                     switch ($arena_data_for_js['status']) {
                         case 'ABERTO':
                         case 'REGISTRADO':
                             echo "<p>Inscrições abertas para a Arena ID: <strong class='arena-id'>" . htmlspecialchars($arena_data_for_js['eventId'] ?? '?') . "</strong>!</p>";
                             echo "<p>Registrados: <strong>" . htmlspecialchars($arena_data_for_js['registeredCount'] ?? '?') . " / " . htmlspecialchars($arena_data_for_js['requiredPlayers'] ?? '?') . "</strong></p>";
                             echo '<p>A arena iniciará em:</p>';
                             echo '<div id="arena-start-timer" class="arena-timer loading">Calculando...</div>';

                             if ($arena_data_for_js['status'] === 'REGISTRADO') {
                                 echo "<p class='registered-message'><i class='fas fa-check-circle'></i> Inscrição confirmada!</p>";
                                 // Botão desabilitado pois já está registrado
                                 echo '<form><button type="button" disabled>Registrado</button></form>';
                             } else {
                                 // Formulário para registrar
                                 echo '<form method="post" action="home.php">';
                                 echo '<input type="hidden" name="register_home" value="1">';
                                 echo '<button type="submit">Registrar Agora!</button>';
                                 echo '</form>';
                             }
                             break;

                         case 'FECHADO':
                             echo "<p>O registro para a Arena Royalle está fechado no momento.</p>";
                             if (isset($arena_data_for_js['secondsToNextOpen']) && $arena_data_for_js['secondsToNextOpen'] !== null) {
                                 echo '<p>Próxima abertura de registro em:</p>';
                                 echo '<div id="arena-next-open-timer" class="arena-timer loading">Calculando...</div>';
                             } else {
                                 echo '<p>Próxima abertura de registro: <strong>Em breve...</strong></p>';
                                 echo '<div id="arena-next-open-timer" class="arena-timer" style="display:none;"></div>'; // Esconde timer se não há tempo
                             }
                             break;

                         case 'EM_ANDAMENTO_OTHER':
                             echo "<p>Uma Arena Royalle (ID: <strong>" . htmlspecialchars($arena_data_for_js['eventId'] ?? '?') . "</strong>) está em andamento.</p>";
                             echo "<p>O registro está fechado.</p>";
                              if (isset($arena_data_for_js['secondsToNextOpen']) && $arena_data_for_js['secondsToNextOpen'] !== null) {
                                 echo '<p>Próxima abertura de registro em:</p>';
                                 echo '<div id="arena-next-open-timer" class="arena-timer loading">Calculando...</div>';
                             } else {
                                  echo '<p>Próxima abertura de registro: <strong>Indefinida</strong></p>';
                                  echo '<div id="arena-next-open-timer" class="arena-timer" style="display:none;"></div>';
                             }
                             break;

                         case 'JOGANDO': // Este caso não deve ocorrer aqui devido ao redirect no topo, mas como fallback
                             echo "<p>Você está atualmente batalhando na Arena (ID: <strong>" . htmlspecialchars($arena_data_for_js['eventId'] ?? '?') . "</strong>)!</p>";
                             $arena_game_url = "script/arena/arenaroyalle.php?event_id=" . htmlspecialchars($arena_data_for_js['eventId'] ?? '');
                             echo '<p class="return-link"><a href="' . $arena_game_url . '">Retornar à Batalha</a></p>';
                              echo '<div id="arena-start-timer" class="arena-timer" style="display:none;"></div>';
                              echo '<div id="arena-next-open-timer" class="arena-timer" style="display:none;"></div>';
                             break;

                         case 'INDISPONIVEL':
                         default:
                             echo "<p>Informações da Arena Royalle não disponíveis no momento.</p>";
                             echo "<p style='font-size: 0.8em; color: var(--text-secondary);'>Verifique novamente mais tarde.</p>";
                             echo '<div id="arena-start-timer" class="arena-timer" style="display:none;"></div>';
                             echo '<div id="arena-next-open-timer" class="arena-timer" style="display:none;"></div>';
                             break;
                     }
                     ?>
                </div> <?php /* Fim .arena-dynamic-status */ ?>


                <?php /* --- Resultados Anteriores --- */ ?>
                <div class="arena-results-box">
                    <h2><i class="fas fa-trophy icon-h2"></i> Resultados Anteriores</h2>
                    <?php if (!empty($past_arena_results)): ?>
                        <ul>
                            <?php foreach ($past_arena_results as $result): ?>
                                <li>
                                    <span class='edition-label'>Edição <?= htmlspecialchars($result['id']) ?>:</span>
                                    <span class='winner-info'>
                                        <?php if (!empty($result['id_vencedor'])): ?>
                                            <span class='winner-icon'>⭐</span><span class='winner-name'><?= htmlspecialchars($result['nome_vencedor'] ?? 'Vencedor Desconhecido') ?></span>
                                        <?php else: ?>
                                            <span><i>Sem Vencedor Registrado</i></span>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="no-results">Nenhum resultado anterior para exibir.</p>
                    <?php endif; ?>
                </div> <?php /* Fim .arena-results-box */ ?>

                 <?php /* --- Área do Chat --- */ ?>
                 <?php /* Removido o echo para descomentar facilmente se necessário */ ?>
                  </div> <?php /* Fim .main-content-scrollable */ ?>
        </div> <?php /* Fim .coluna-central */ ?>


        <?php /* --- Coluna Direita (Navegação) --- */ ?>
        <div class="coluna coluna-direita">
            <?php
             if (isset($sidebarItems) && is_array($sidebarItems)) {
                 $baseCurrentPageNavRight = basename($_SERVER['PHP_SELF']);
                 foreach ($sidebarItems as $item) {
                     $href = $item['href'] ?? '#';
                     $label = htmlspecialchars($item['label'] ?? 'Link');
                     $iconPath = $item['icon'] ?? '';
                     $faIconClass = $item['fa_icon'] ?? 'fas fa-link'; // Ícone padrão FontAwesome
                     $idItem = $item['id'] ?? null;
                     $classe_extra = '';
                     $atributos_extra = '';
                     $title = $label; // Title padrão é o label

                     // Define o HTML do ícone (prioriza imagem, depois FA, depois fallback)
                     $iconHtml = "<i class='{$faIconClass}'></i>"; // Padrão FA
                     if (!empty($iconPath) && file_exists(__DIR__.'/'.$iconPath)) {
                         $iconHtml = '<img src="' . htmlspecialchars($iconPath) . '" class="nav-button-icon" alt="">';
                     } elseif (empty($faIconClass)) {
                         $iconHtml = '<i class="fas fa-question-circle" style="opacity: 0.5;"></i>'; // Fallback se nem FA for definido
                     }

                     // Verifica condição especial para Sala do Tempo
                     if ($idItem === 'sala_tempo') {
                         if (!$pode_entrar_templo_base) {
                             $classe_extra .= ' disabled';
                             $atributos_extra = ' onclick="alert(\''.addslashes(htmlspecialchars($mensagem_bloqueio_templo_base)).'\'); return false;"';
                             $title = htmlspecialchars($mensagem_bloqueio_templo_base); // Title explica o bloqueio
                             $href = '#'; // Link desabilitado
                         }
                     }

                     // Verifica se é a página atual (somente para home.php neste caso)
                     $isCurrent = ($idItem === 'home' && $baseCurrentPageNavRight === 'home.php');
                     if ($isCurrent && strpos($classe_extra, 'disabled') === false) {
                         $classe_extra .= ' active'; // Adiciona classe ativa
                     }

                     $finalHref = htmlspecialchars($href);

                     // Imprime o botão de navegação
                     echo "<a class=\"nav-button{$classe_extra}\" href=\"{$finalHref}\" title=\"{$title}\" {$atributos_extra}>";
                     echo "<div class='nav-button-icon-area'>{$iconHtml}</div>"; // Área do ícone
                     echo "<span>{$label}</span>"; // Texto do botão
                     echo "</a>";
                 }
             } else {
                 echo "<p>Erro: Itens da barra lateral não definidos.</p>";
             }

             // Botão de Sair (sempre no final)
             $sairIconPath = 'img/sair.jpg';
             $sairIconHtml = '<i class="fas fa-sign-out-alt"></i>'; // Ícone FA padrão para sair
             if(file_exists(__DIR__.'/'.$sairIconPath)) {
                 $sairIconHtml = '<img src="'.htmlspecialchars($sairIconPath).'" class="nav-button-icon" alt="Sair">';
             }

             echo '<div style="margin-top: auto; padding-top: 10px; border-top: 1px solid var(--panel-border);">'; // Div para empurrar p/ baixo
             echo '<a class="nav-button logout" href="sair.php" title="Sair do Jogo">';
             echo "<div class='nav-button-icon-area'>{$sairIconHtml}</div>";
             echo '<span>Sair</span></a>';
             echo '</div>';
             ?>
        </div> <?php /* Fim .coluna-direita */ ?>

    </div> <?php /* Fim .container */ ?>


    <?php /* --- Navegação Inferior (Mobile) --- */ ?>
    <nav id="bottom-nav">
        <?php
        if (isset($bottomNavItems) && is_array($bottomNavItems)) {
            $baseCurrentPageBottomNav = basename($_SERVER['PHP_SELF']);
            foreach ($bottomNavItems as $item) {
                $href = $item['href'] ?? '#';
                $label = htmlspecialchars($item['label'] ?? 'Link');
                $icon = $item['icon'] ?? '?'; // Ícone Emoji/Texto
                $idItem = $item['id'] ?? null;
                $classe_extra = '';
                $atributos_extra = '';
                $title = $label;

                // Verifica condição especial para Sala do Tempo
                if ($idItem === 'sala_tempo') {
                     if (!$pode_entrar_templo_base) {
                         $classe_extra .= ' disabled';
                         $atributos_extra = ' onclick="alert(\''.addslashes(htmlspecialchars($mensagem_bloqueio_templo_base)).'\'); return false;"';
                         $title = htmlspecialchars($mensagem_bloqueio_templo_base);
                         $href = '#';
                    }
                }

                // Verifica se é a página atual (comparando basename do href com a página atual)
                $isCurrent = false;
                if ($href !== '#') {
                    $hrefBase = basename($href);
                    // Condição especial para home
                    if ($idItem === 'home' && $baseCurrentPageBottomNav === 'home.php') {
                        $isCurrent = true;
                    // Condição para outras páginas diretas
                    } elseif ($hrefBase === $baseCurrentPageBottomNav && $hrefBase !== 'home.php') {
                         $isCurrent = true;
                    }
                    // Adicionar outras lógicas se necessário (ex: subdiretórios)
                     // Ex: Verificando arena
                     if ($idItem === 'arena' && strpos($_SERVER['PHP_SELF'], 'arena/arenaroyalle.php') !== false) {
                         $isCurrent = true;
                     }
                     // Ex: Verificando treinos
                     if ($idItem === 'treinos' && strpos($_SERVER['PHP_SELF'], 'desafios/treinos.php') !== false) {
                          $isCurrent = true;
                     }
                }

                if ($isCurrent && strpos($classe_extra, 'disabled') === false) {
                    $classe_extra .= ' active';
                }

                $finalHref = htmlspecialchars($href);

                // Imprime o item da navegação inferior
                echo "<a class=\"bottom-nav-item{$classe_extra}\" href=\"{$finalHref}\" title=\"{$title}\" {$atributos_extra}>";
                echo "<span class='bottom-nav-icon'>{$icon}</span>";
                echo "<span class='bottom-nav-label'>{$label}</span>";
                echo "</a>";
            }
        }
        ?>
    </nav>


    <?php /* --- Modal Popup para Notificações --- */ ?>
    <div id="notification-popup-overlay" class="modal-overlay">
        <div class="modal-popup">
            <button class="modal-close-btn" onclick="hideModal()" title="Fechar">&times;</button>
            <h3>Notificação</h3>
            <p id="popup-message">...</p>
            <div id="popup-actions">
                <?php /* Botões serão adicionados via JS */ ?>
            </div>
        </div>
    </div>


    <?php /* --- Scripts JavaScript --- */ ?>
    <script>
        // Passa dados do PHP para o JavaScript de forma segura
        const currentUserId_Notif = <?php echo json_encode((int)$id);?>;
        const currentUserId_Chat = <?php echo json_encode((int)$id);?>;
        const currentUsername_Chat = <?php echo json_encode($usuario['nome'] ?? 'Eu');?>;
        const chatHandlerUrl = 'chat_handler_ajax.php'; // URL do seu handler AJAX de chat
        const currentPageFilename = <?php echo json_encode(basename(__FILE__)); ?>; // Nome do arquivo atual
        const unreadNotifications = <?php echo json_encode($unread_notifications_for_popup); ?>; // Array de notificações
        const arenaData = <?php echo json_encode($arena_data_for_js); ?>; // Dados da Arena

        let currentNotification = null; // Notificação sendo exibida no modal
        let lastMessageId_Chat = 0; // Último ID de mensagem recebida no chat

        // Define o último ID de mensagem baseado no histórico carregado pelo PHP
        <?php
        $lastMId = 0;
        if(!empty($chat_history) && count($chat_history) > 0){
            $lastMsg = end($chat_history);
            if(isset($lastMsg['id'])) $lastMId = (int)$lastMsg['id'];
        }
        echo 'lastMessageId_Chat = '.$lastMId.';';
        ?>

        // Configurações do Chat e Timers
        const chatPollInterval = 5000; // Intervalo para buscar novas mensagens (ms) - Aumentado para 5s
        let chatPollingIntervalId = null; // ID do intervalo do chat
        let isFetchingChat = false; // Flag para evitar buscas simultâneas
        let intervalNextOpen = null; // ID do intervalo do timer "Próxima Abertura"
        let intervalStart = null; // ID do intervalo do timer "Inicia Em"

        // Elementos do DOM (referências)
        const modalOverlay = document.getElementById('notification-popup-overlay');
        const popupMessage = document.getElementById('popup-message');
        const popupActions = document.getElementById('popup-actions');
        const timerNextOpenEl = document.getElementById('arena-next-open-timer');
        const timerStartEl = document.getElementById('arena-start-timer');
        let chatMessagesDiv = null; // Será definido em initializeChatSystem
        let chatForm = null;       // Será definido em initializeChatSystem
        let messageInput = null;  // Será definido em initializeChatSystem

        // --- Funções Utilitárias ---

        /** Escapa HTML para exibição segura */
        function escapeHtml(unsafe){
            if (typeof unsafe !== 'string') return '';
            return unsafe
                 .replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;")
                 .replace(/"/g, "&quot;")
                 .replace(/'/g, "&#039;");
        }

        /** Formata timestamp (do DB, assumed UTC) para HH:MM local */
        function formatTimestamp(timestamp){
            try {
                // Adiciona 'Z' para indicar que o timestamp do DB é UTC
                const date = new Date(timestamp + 'Z');
                if (isNaN(date.getTime())) { // Verifica se a data é inválida
                    throw new Error("Data inválida recebida: " + timestamp);
                }
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                return `${hours}:${minutes}`;
            } catch (e) {
                console.error("Erro ao formatar timestamp:", timestamp, e);
                return '--:--'; // Retorna um placeholder em caso de erro
            }
        }

        /** Formata segundos totais em HH:MM:SS ou MM:SS */
        function formatTime(totalSeconds) {
            if (typeof totalSeconds !== 'number' || totalSeconds < 0) totalSeconds = 0;
            totalSeconds = Math.round(totalSeconds); // Arredonda para inteiro

            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor((totalSeconds % 3600) / 60);
            const seconds = totalSeconds % 60;

            if (hours > 0) {
                return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            } else {
                return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }
        }

        // --- Funções do Modal de Notificação ---

        /** Exibe o modal */
        function showModal() {
            if (!modalOverlay) return;
            modalOverlay.style.display = 'flex'; // Garante que está visível antes da animação
            setTimeout(() => { // Pequeno delay para permitir a transição CSS
                modalOverlay.classList.add('visible');
            }, 10); // 10ms é geralmente suficiente
        }

        /** Esconde o modal */
        function hideModal() {
            if (!modalOverlay) return;
            modalOverlay.classList.remove('visible');
            // Espera a transição de opacidade terminar antes de esconder com display:none
            // O tempo deve corresponder à duração da transição no CSS (0.3s = 300ms)
            setTimeout(() => {
                modalOverlay.style.display = 'none';
                if (popupActions) popupActions.innerHTML = ''; // Limpa botões antigos
            }, 300);
        }

        /** Exibe a próxima notificação não lida */
        function displayNextNotification() {
            if (unreadNotifications.length > 0) {
                currentNotification = unreadNotifications.shift(); // Pega a primeira da fila

                if (popupMessage) {
                    popupMessage.innerHTML = escapeHtml(currentNotification.message_text ?? 'Você tem uma nova notificação.');
                }

                if (popupActions) {
                    popupActions.innerHTML = ''; // Limpa botões anteriores
                    const okBtn = document.createElement('button');
                    okBtn.classList.add('popup-action-btn', 'btn-ok');
                    okBtn.textContent = 'OK';
                    okBtn.onclick = () => {
                        markCurrentNotificationAsRead(); // Marca como lida (via AJAX no futuro)
                        hideModal();
                        // Atraso pequeno antes de mostrar a próxima, se houver
                        setTimeout(displayNextNotification, 350);
                    };
                    popupActions.appendChild(okBtn);
                }
                showModal();
            } else {
                currentNotification = null; // Nenhuma mais para mostrar
            }
        }

        /** Marca a notificação atual como lida (precisa de AJAX) */
        function markCurrentNotificationAsRead() {
            if (currentNotification && currentNotification.id) {
                console.log("Marcando notificação " + currentNotification.id + " como lida (implementar AJAX).");
                // TODO: Implementar chamada AJAX para marcar a notificação como lida no backend
                // fetch('notification_handler.php', {
                //     method: 'POST',
                //     headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                //     body: new URLSearchParams({ action: 'mark_read', notification_id: currentNotification.id })
                // })
                // .then(response => response.json())
                // .then(data => {
                //     if (!data.success) console.error('Erro ao marcar notificação como lida:', data.message);
                // })
                // .catch(error => console.error('Erro de rede ao marcar notificação:', error));
            }
        }

        // --- Funções do Sistema de Chat ---

        /** Rola a caixa de chat para o fundo */
        function scrollToBottomChat(force = false) {
            if (chatMessagesDiv) {
                // Verifica se o usuário não rolou para cima manualmente
                const shouldScroll = force || (chatMessagesDiv.scrollTop + chatMessagesDiv.clientHeight >= chatMessagesDiv.scrollHeight - 50);
                if (shouldScroll) {
                    chatMessagesDiv.scrollTop = chatMessagesDiv.scrollHeight;
                }
            }
        }

        /** Verifica se o chat está vazio e adiciona/remove a mensagem "Sem mensagens" */
        function checkEmptyChat() {
            if (!chatMessagesDiv) return;
            const hasMessages = chatMessagesDiv.querySelector('.chat-message-ajax');
            const emptyMsgEl = chatMessagesDiv.querySelector('.chat-empty');

            if (!hasMessages && !emptyMsgEl) { // Sem mensagens e sem aviso
                const emptyDiv = document.createElement('div');
                emptyDiv.classList.add('chat-empty');
                emptyDiv.textContent = 'Sem mensagens no momento.';
                chatMessagesDiv.appendChild(emptyDiv);
            } else if (hasMessages && emptyMsgEl) { // Com mensagens e com aviso (remover aviso)
                emptyMsgEl.remove();
            }
        }

        /** Adiciona uma mensagem formatada à caixa de chat */
        function addChatMessageToDisplay(msg, isHistoryLoad = false) {
             if (!chatMessagesDiv || !msg || typeof msg !== 'object') return;
             // Evita adicionar duplicatas pelo ID
             if (chatMessagesDiv.querySelector(`[data-message-id='${msg.id}']`)) return;

             checkEmptyChat(); // Remove a mensagem de "vazio" se existir

             const msgElement = document.createElement('div');
             msgElement.classList.add('chat-message-ajax');
             msgElement.classList.add(msg.user_id == currentUserId_Chat ? 'mine' : 'other');
             msgElement.dataset.messageId = msg.id; // Adiciona ID para referência futura

             const senderName = escapeHtml(msg.username ?? 'Desconhecido');
             const messageText = escapeHtml(msg.message_text ?? '');
             const time = formatTimestamp(msg.timestamp);

             let senderHtml = '';
             if (msg.user_id != currentUserId_Chat) {
                 senderHtml = `<span class="sender">${senderName} <small>(${time})</small></span>`;
                 msgElement.innerHTML = `${senderHtml}<span class="msg-text">${messageText}</span>`;
             } else {
                  // Para mensagens próprias, talvez não precise do nome, só o tempo
                 senderHtml = `<span class="sender"><small>(${time})</small></span>`;
                  msgElement.innerHTML = `<span class="msg-text">${messageText}</span>${senderHtml}`; // Tempo depois
             }

             chatMessagesDiv.appendChild(msgElement);

             if (!isHistoryLoad) {
                 scrollToBottomChat(); // Rola para baixo apenas para novas mensagens
             }
         }

        /** Inicializa o sistema de chat (carrega histórico, configura listeners) */
        function initializeChatSystem() {
            chatMessagesDiv = document.getElementById('chat-messages-ajax');
            chatForm = document.getElementById('chat-form-ajax');
            messageInput = document.getElementById('chat-message-input');

            // Só continua se a área de chat existir no HTML
            if (!chatMessagesDiv || !chatForm || !messageInput) {
                 console.log("Área do Chat não encontrada no DOM. Sistema de Chat desativado.");
                 return;
            }

            // Carrega histórico inicial do PHP
            const initialChatHistory = <?php echo json_encode($chat_history); ?>;
            if (initialChatHistory && initialChatHistory.length > 0) {
                initialChatHistory.forEach(msg => addChatMessageToDisplay(msg, true)); // true = é carga histórica
                scrollToBottomChat(true); // Força rolagem inicial
            } else {
                checkEmptyChat(); // Mostra "Sem mensagens" se vazio
            }

            // Adiciona listener para envio de mensagem
            chatForm.addEventListener('submit', handleChatSubmit);

            // Inicia busca por novas mensagens (polling) com delay inicial
            setTimeout(() => {
                fetchNewChatMessages(); // Busca imediatamente ao iniciar
                if (!chatPollingIntervalId) { // Evita múltiplos intervalos
                    chatPollingIntervalId = setInterval(fetchNewChatMessages, chatPollInterval);
                }
            }, 1000); // Delay inicial de 1 segundo

            // Pausa/Retoma polling quando a aba fica inativa/ativa
            document.addEventListener('visibilitychange', handleVisibilityChangeChat);
        }

        /** Controla o polling do chat baseado na visibilidade da aba */
        function handleVisibilityChangeChat() {
            if (document.hidden) { // Aba ficou inativa
                if (chatPollingIntervalId) {
                    clearInterval(chatPollingIntervalId);
                    chatPollingIntervalId = null;
                    console.log('Chat polling pausado (aba inativa)');
                }
            } else { // Aba ficou ativa
                if (!chatPollingIntervalId && chatMessagesDiv) { // Se estava pausado e o chat existe
                    console.log('Chat polling retomado (aba ativa), buscando...');
                    fetchNewChatMessages(); // Busca imediatamente ao voltar
                    chatPollingIntervalId = setInterval(fetchNewChatMessages, chatPollInterval);
                }
            }
        }

        /** Envia uma nova mensagem de chat via AJAX */
        async function handleChatSubmit(event) {
            event.preventDefault(); // Previne recarregamento da página
            if (!messageInput || messageInput.value.trim() === '' || !chatHandlerUrl) {
                return; // Não envia mensagem vazia
            }

            const messageText = messageInput.value.trim();
            messageInput.value = ''; // Limpa o input imediatamente
            messageInput.disabled = true; // Desabilita enquanto envia
            chatForm.querySelector('button').disabled = true;

            try {
                const response = await fetch(chatHandlerUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'send',
                        message: messageText,
                        user_id: currentUserId_Chat // Envia ID do usuário
                        // Poderia enviar username aqui também se o backend não buscar pelo ID
                        // username: currentUsername_Chat
                    })
                });

                // Tenta processar a resposta mesmo se não for OK (pode conter erro JSON)
                const data = await response.json();

                if (!response.ok) {
                    console.error('Erro ao enviar mensagem:', response.status, response.statusText, data.message || '');
                    // Poderia mostrar feedback de erro para o usuário aqui
                    // Ex: alert(`Erro ao enviar: ${data.message || 'Tente novamente'}`);
                    // Restaura o input se falhar
                    messageInput.value = messageText;
                } else {
                    // Sucesso - busca novas mensagens para incluir a que acabou de enviar
                     console.log("Mensagem enviada, buscando atualização...");
                    fetchNewChatMessages();
                }

            } catch (error) {
                console.error('Erro de rede ao enviar mensagem:', error);
                // Poderia mostrar feedback de erro genérico
                // Ex: alert('Erro de conexão ao enviar mensagem.');
                 messageInput.value = messageText; // Restaura
            } finally {
                // Reabilita o input e botão independentemente do resultado
                messageInput.disabled = false;
                chatForm.querySelector('button').disabled = false;
                messageInput.focus(); // Coloca o foco de volta no input
            }
        }

        /** Busca novas mensagens de chat desde o último ID conhecido */
        async function fetchNewChatMessages() {
            // Só executa se não estiver buscando e se o chat estiver ativo
            if (isFetchingChat || !chatMessagesDiv || !chatHandlerUrl) {
                return;
            }
            isFetchingChat = true; // Marca como buscando

            try {
                const response = await fetch(chatHandlerUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'get_new',
                        last_id: lastMessageId_Chat,
                        user_id: currentUserId_Chat // Envia ID para o backend saber quem está pedindo
                    })
                });

                 if (!response.ok) {
                     // Erros 401/403 podem indicar sessão expirada, parar polling.
                     if (response.status === 401 || response.status === 403) {
                         console.error("Chat fetch não autorizado (401/403). Parando polling.");
                         if (chatPollingIntervalId) clearInterval(chatPollingIntervalId);
                         chatPollingIntervalId = null;
                     } else {
                        // Outros erros de servidor
                         console.error('Erro ao buscar novas mensagens:', response.status, response.statusText);
                     }
                     // Não tenta processar JSON se a resposta não foi OK
                     isFetchingChat = false;
                     return;
                 }

                // Processa a resposta JSON
                const messages = await response.json();

                if (messages && Array.isArray(messages) && messages.length > 0) {
                    // Ordena por ID crescente (garantia extra)
                    messages.sort((a, b) => a.id - b.id);

                    // Adiciona cada nova mensagem que ainda não está no DOM
                    messages.forEach(msg => {
                        if (msg && msg.id > lastMessageId_Chat) { // Garante que é mais nova
                             if (!chatMessagesDiv.querySelector(`[data-message-id='${msg.id}']`)) {
                                addChatMessageToDisplay(msg, false); // false = não é carga histórica
                                lastMessageId_Chat = msg.id; // Atualiza o último ID recebido
                             }
                        }
                    });
                }
                 // Após adicionar (ou não), verifica se o chat ficou vazio (improvável aqui, mas seguro)
                 // checkEmptyChat();

            } catch (error) {
                console.error('Erro de rede ao buscar mensagens:', error);
                // Considerar parar o polling após múltiplos erros de rede?
            } finally {
                isFetchingChat = false; // Libera para a próxima busca
            }
        }


        // --- Funções do Timer da Arena ---

        /** Inicia ou atualiza o timer para a PRÓXIMA ABERTURA de registro */
        function startNextOpenTimer(initialSeconds) {
            if (!timerNextOpenEl) return; // Sai se o elemento não existe
            if (intervalStart) { clearInterval(intervalStart); intervalStart = null; } // Para timer de início se estiver rodando
            if (timerStartEl) { timerStartEl.textContent = ""; timerStartEl.style.display = 'none'; } // Esconde timer de início

            if (intervalNextOpen) clearInterval(intervalNextOpen); // Limpa timer anterior de abertura

            let secondsRemaining = initialSeconds;

            // Validação inicial dos segundos
             if (secondsRemaining === null || typeof secondsRemaining !== 'number' || secondsRemaining < 0) {
                 timerNextOpenEl.textContent = "Indefinido";
                 timerNextOpenEl.classList.remove('loading');
                 timerNextOpenEl.style.display = 'inline-block';
                 console.warn("Tempo inválido fornecido para startNextOpenTimer.");
                 // Não tenta recarregar aqui, espera a próxima atualização da página
                 return;
             }
             if (secondsRemaining === 0) { // Se já for 0, mostra "Abrindo..." e recarrega logo
                 timerNextOpenEl.textContent = "Abrindo Registro...";
                 timerNextOpenEl.classList.remove('loading');
                 timerNextOpenEl.style.display = 'inline-block';
                 setTimeout(() => { window.location.reload(); }, 3000); // Recarrega após 3s
                 return;
             }


            // Exibe o tempo inicial formatado
            timerNextOpenEl.textContent = formatTime(secondsRemaining);
            timerNextOpenEl.classList.remove('loading'); // Remove classe de carregamento
            timerNextOpenEl.style.display = 'inline-block'; // Garante visibilidade

            // Inicia o intervalo de 1 segundo
            intervalNextOpen = setInterval(() => {
                secondsRemaining--;
                if (secondsRemaining > 0) { // Enquanto houver tempo
                    timerNextOpenEl.textContent = formatTime(secondsRemaining);
                } else { // Tempo acabou
                    timerNextOpenEl.textContent = "Abrindo Registro...";
                    clearInterval(intervalNextOpen); // Para o timer
                    // Recarrega a página após um pequeno delay para mostrar a mensagem
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000); // Atraso de 3 segundos
                }
            }, 1000);
        }

        /** Inicia ou atualiza o timer para o INÍCIO da arena */
        function startArenaStartTimer(initialSeconds) {
            if (!timerStartEl) return; // Sai se o elemento não existe
            if (intervalNextOpen) { clearInterval(intervalNextOpen); intervalNextOpen = null; } // Para timer de abertura se estiver rodando
            if (timerNextOpenEl) { timerNextOpenEl.textContent = ""; timerNextOpenEl.style.display = 'none'; } // Esconde timer de abertura

            if (intervalStart) clearInterval(intervalStart); // Limpa timer anterior de início

            let secondsRemaining = initialSeconds;

             // Validação inicial dos segundos
             if (secondsRemaining === null || typeof secondsRemaining !== 'number' || secondsRemaining < 0) {
                 timerStartEl.textContent = "Iniciando..."; // Mensagem genérica se tempo inválido
                 timerStartEl.classList.remove('loading');
                 timerStartEl.style.display = 'inline-block';
                 console.warn("Tempo inválido fornecido para startArenaStartTimer. Tentando recarregar/redirecionar logo.");
                 // Tenta a lógica de redirecionamento/reload imediatamente
                 setTimeout(() => handleArenaStart(), 1000);
                 return;
             }
             if (secondsRemaining === 0) { // Se já for 0, inicia imediatamente
                 timerStartEl.textContent = "Iniciando Agora!";
                 timerStartEl.classList.remove('loading');
                 timerStartEl.style.display = 'inline-block';
                 setTimeout(() => handleArenaStart(), 1500); // Delay para mostrar msg
                 return;
             }


            // Exibe o tempo inicial formatado
            timerStartEl.textContent = formatTime(secondsRemaining);
            timerStartEl.classList.remove('loading'); // Remove classe de carregamento
            timerStartEl.style.display = 'inline-block'; // Garante visibilidade

            // Inicia o intervalo de 1 segundo
            intervalStart = setInterval(() => {
                secondsRemaining--;
                if (secondsRemaining > 0) { // Enquanto houver tempo
                    timerStartEl.textContent = formatTime(secondsRemaining);
                } else { // Tempo acabou
                    timerStartEl.textContent = "Iniciando Agora!";
                    clearInterval(intervalStart); // Para o timer
                    // Executa a lógica de início após um pequeno delay
                    setTimeout(() => handleArenaStart(), 1500); // Atraso de 1.5 segundos
                }
            }, 1000);
        }

        /** Lógica a ser executada quando o timer de início da arena chega a zero */
        function handleArenaStart() {
             // Verifica se o usuário estava registrado QUANDO A PÁGINA CARREGOU
             // e se temos um ID de evento válido
             if (arenaData && arenaData.status === 'REGISTRADO' && arenaData.eventId) {
                 console.log(`Arena ${arenaData.eventId} iniciando, usuário registrado. Redirecionando para arenaroyalle.php...`);
                 // Redireciona para a página da arena com o ID do evento
                 window.location.href = `script/arena/arenaroyalle.php?event_id=${arenaData.eventId}`;
             } else {
                 // Se o status não era 'REGISTRADO' (ex: 'ABERTO' e o usuário não se inscreveu)
                 // ou se os dados da arena estão ausentes/inválidos, apenas recarrega a home.
                 // Isso atualizará o status exibido na home (para EM_ANDAMENTO_OTHER, INDISPONIVEL, etc.)
                 console.log("Arena iniciando, mas o usuário não estava registrado neste evento ou dados ausentes. Recarregando home.php.");
                 window.location.reload();
             }
         }


        /** Para e esconde ambos os timers da arena */
        function stopAndHideArenaTimers() {
            if (intervalNextOpen) clearInterval(intervalNextOpen);
            intervalNextOpen = null;
            if (timerNextOpenEl) {
                timerNextOpenEl.textContent = "";
                timerNextOpenEl.classList.remove('loading');
                timerNextOpenEl.style.display = 'none';
            }
            if (intervalStart) clearInterval(intervalStart);
            intervalStart = null;
            if (timerStartEl) {
                timerStartEl.textContent = "";
                timerStartEl.classList.remove('loading');
                timerStartEl.style.display = 'none';
            }
        }

        // --- Inicialização da Página (DOMContentLoaded) ---
        document.addEventListener('DOMContentLoaded', () => {
            const bottomNav = document.getElementById('bottom-nav');
            const rightColumn = document.querySelector('.coluna-direita');

            // Função para mostrar/esconder nav inferior e ajustar padding do body
            function checkBottomNavVisibility() {
                if (!bottomNav || !rightColumn) return; // Sai se elementos não existem

                // Pega o padding original definido no CSS (ou um fallback)
                const bodyComputedStyle = window.getComputedStyle(document.body);
                const originalPaddingValue = bodyComputedStyle.getPropertyValue('--body-original-padding-bottom').trim() || '20px';

                // Verifica se a coluna direita está escondida (indicando layout mobile)
                if (window.getComputedStyle(rightColumn).display === 'none') {
                    bottomNav.style.display = 'flex'; // Mostra nav inferior
                    // Adiciona padding no body para não sobrepor conteúdo
                    // Verifica se a nav está realmente visível antes de calcular altura
                     if(window.getComputedStyle(bottomNav).display !== 'none') {
                        document.body.style.paddingBottom = (bottomNav.offsetHeight + 10) + 'px';
                     } else {
                         document.body.style.paddingBottom = originalPaddingValue; // Fallback
                     }
                } else { // Layout desktop
                    bottomNav.style.display = 'none'; // Esconde nav inferior
                    document.body.style.paddingBottom = originalPaddingValue; // Restaura padding original
                }
            }

            // Guarda o padding-bottom inicial do body na variável CSS
            const initialBodyPadding = window.getComputedStyle(document.body).paddingBottom;
            document.body.style.setProperty('--body-original-padding-bottom', initialBodyPadding);

            // Executa a verificação inicial e adiciona listener para redimensionamento
            checkBottomNavVisibility();
            window.addEventListener('resize', checkBottomNavVisibility);

            // Lógica para esconder feedback após alguns segundos
            const feedbackBox = document.querySelector('.feedback-container');
            if (feedbackBox && window.getComputedStyle(feedbackBox).display !== 'none') {
                setTimeout(() => {
                    feedbackBox.style.transition = 'opacity 0.5s ease-out, margin-bottom 0.5s ease-out';
                    feedbackBox.style.opacity = '0';
                    feedbackBox.style.marginBottom = '0'; // Reduz margem para suavizar desaparecimento
                    setTimeout(() => {
                        feedbackBox.style.display = 'none'; // Esconde completamente após transição
                    }, 500); // Tempo da transição
                }, 8000); // Tempo visível (8 segundos)
            }

            // Inicializa o sistema de chat (se existir no HTML)
            initializeChatSystem();

            // Exibe a primeira notificação não lida (se houver)
            displayNextNotification();

            // Inicializa os timers da Arena baseado nos dados do PHP
            if (arenaData && arenaData.status) {
                 console.log("Arena Status Inicial:", arenaData.status, "Event ID:", arenaData.eventId);
                 switch(arenaData.status) {
                     case 'FECHADO':
                     case 'EM_ANDAMENTO_OTHER': // Também mostra timer para próxima abertura aqui
                         if (timerNextOpenEl && arenaData.secondsToNextOpen !== null) {
                             startNextOpenTimer(arenaData.secondsToNextOpen);
                         } else {
                             stopAndHideArenaTimers(); // Garante que timers estejam parados se não houver tempo
                             if(timerNextOpenEl) {
                                 timerNextOpenEl.textContent = arenaData.status === 'FECHADO' ? 'Em breve...' : 'Indefinido';
                                 timerNextOpenEl.classList.remove('loading');
                                 timerNextOpenEl.style.display = 'inline-block';
                             }
                         }
                         break;
                     case 'ABERTO':
                     case 'REGISTRADO':
                         if (timerStartEl && arenaData.secondsToStart !== null) {
                             startArenaStartTimer(arenaData.secondsToStart);
                         } else {
                             stopAndHideArenaTimers(); // Garante timers parados se não houver tempo
                             if(timerStartEl) { // Mostra mensagem de erro se não há tempo
                                timerStartEl.textContent = 'Erro no timer';
                                timerStartEl.classList.remove('loading');
                                timerStartEl.style.display = 'inline-block';
                                console.error("Status ABERTO/REGISTRADO mas secondsToStart é null.");
                             }
                         }
                         break;
                     case 'INDISPONIVEL':
                     case 'JOGANDO': // JOGANDO não deveria ter timer aqui
                     default:
                         console.log("Nenhum timer da arena ativo para o status:", arenaData.status);
                         stopAndHideArenaTimers(); // Garante que timers estejam parados
                         break;
                 }
            } else {
                console.error("Dados da arena (arenaData) não disponíveis ou inválidos na inicialização do JS.");
                stopAndHideArenaTimers(); // Garante que timers estejam parados em caso de erro
            }
        });
    </script>

</body>
</html>
<?php
// --- Limpeza Final ---
// Fecha conexões PDO para liberar recursos (boa prática)
// A ordem aqui não importa muito, desde que todas as referências sejam anuladas.
if (isset($local_conn_arena) && $local_conn_arena instanceof PDO) {
    $local_conn_arena = null;
    // echo ""; // Debug
}
// Verifica se $conn existe, é PDO e DIFERENTE da conexão local antes de fechar
if (isset($conn) && $conn instanceof PDO && $conn !== $local_conn_arena) {
    $conn = null;
     // echo ""; // Debug
}
// Verifica se $pdo existe, é PDO e DIFERENTE das outras conexões antes de fechar
if (isset($pdo) && $pdo instanceof PDO && $pdo !== $local_conn_arena && (!isset($conn) || $pdo !== $conn)) {
    $pdo = null;
    // echo ""; // Debug
}
// session_write_close(); // Opcional: Escreve dados da sessão e fecha o arquivo imediatamente
?>