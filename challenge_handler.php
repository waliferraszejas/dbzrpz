
<?php
// challenge_handler.php - Manipulador de Requisições AJAX para Desafios PvP (VERSÃO REVISADA PARA RAIZ)
// Localização: /public_html/challenge_handler.php

// --- CONFIGURAÇÃO DE DEPURAÇÃO ---
// Estas linhas são úteis durante o desenvolvimento para ver erros.
// !!! IMPORTANTE: COMENTE ou REMOVA as linhas error_reporting e display_errors
// e ajuste log_errors/error_log para PRODUÇÃO!
error_reporting(E_ALL); // Reporta todos os erros PHP
ini_set('display_errors', 1); // Tenta exibir erros (REMOVER/DESATIVAR em PRODUÇÃO!)
ini_set('log_errors', 1); // Garante que os erros sejam registrados em um arquivo de log
// Define o arquivo de log na mesma pasta (raiz, neste cenário).
// Certifique-se de que a pasta (raiz) tem permissões de escrita para o servidor web.
ini_set('error_log', __DIR__ . '/php_error_challenge_handler.log'); // Log específico para este handler
ini_set('memory_limit', '256M'); // Tente um valor maior
// O restante do código do handler...
// --- FIM DA CONFIGURAÇÃO DE DEPURAÇÃO ---

// session_start() DEVE vir DEPOIS das linhas ini_set
session_start();

// Define o tipo de conteúdo da resposta como JSON imediatamente.
// Isso ajuda o navegador a interpretar a resposta corretamente, mesmo em caso de erro PHP inicial.
header('Content-Type: application/json');

// --- CONEXÃO PDO ---
// IMPORTANTE: Inclui a conexão PDO padrão.
// Assumimos que conexao.php está NA MESMA pasta (raiz).
// Usamos __DIR__ para o caminho absoluto relativo ao arquivo atual.
try {
    require_once("conexao.php"); // Caminho ajustado para a mesma pasta (raiz)
    // Verifica se a inclusão resultou em um objeto PDO válido
    if (!isset($conn) || !($conn instanceof PDO)) {
        // Lança uma RuntimeException se a conexão não estiver disponível ou for inválida.
        throw new RuntimeException("Conexão PDO inválida ou não incluída corretamente por conexao.php.");
    }
    // Configura o modo de error do PDO para lançar exceções (garantia)
    // Esta configuração aqui garante que este handler SEMPRE lance exceções PDO.
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Opcional: Desativar emulações para prepared statements (MAIS SEGURO) se não estiver em conexao.php
    // $conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);


} catch (Throwable $e) { // Captura QUALQUER erro (incluindo require falho)
    // Loga o erro crítico de conexão ou inclusão
    error_log("Erro CRÍTICO no require/conexão em " . basename(__FILE__) . ": " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    // Define um cabeçalho de erro interno do servidor HTTP
    header('HTTP/1.1 500 Internal Server Error');
    // Envia resposta JSON de erro genérica antes de sair.
    // Esta mensagem é para o cliente (navegador) e não deve expor detalhes internos.
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor ao iniciar. Verifique os logs.']);
    exit; // Interrompe a execução imediatamente
}

// Resposta padrão de erro para ações inválidas ou não especificadas
$response = ['success' => false, 'error' => 'Ação inválida ou não especificada.'];

// --- AUTENTICAÇÃO ---
// Verifica se o usuário está logado. Se não, retorna erro JSON.
if (!isset($_SESSION['user_id'])) {
    // Retorna erro JSON em vez de redirecionar, pois é um handler chamado por JS/AJAX
    header('HTTP/1.1 401 Unauthorized'); // Define status HTTP 401
    echo json_encode(['success' => false, 'error' => 'Não autenticado. Por favor, faça login novamente.']);
    exit; // Interrompe a execução
}
$current_user_id = (int)$_SESSION['user_id']; // Converte para int explicitamente por segurança

// Obtém a ação solicitada via GET
$action = $_GET['action'] ?? '';

// --- VERIFICAÇÃO CSRF (Recomendado para requisições POST) ---
// Para proteção contra CSRF, você precisaria gerar um token na página desafios.php
// e incluí-lo nos dados do formulário/AJAX POST, verificando-o aqui.
// Exemplo (adicione isto para segurança em produção):
/*
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? ''; // Ou pega do header
    // Assume que $_SESSION['csrf_token'] foi definido na página que gerou o formulário/AJAX
    if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
        header('HTTP/1.1 403 Forbidden'); // Acesso proibido
        echo json_encode(['success' => false, 'error' => 'Requisição inválida (CSRF).']);
        exit;
    }
     // Opcional: regenerar o token após o uso bem-sucedido de POST
     // unset($_SESSION['csrf_token']); // Para tokens one-time use
     // $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // Gerar novo token
}
*/
// --- FIM DA VERIFICAÇÃO CSRF ---


// ==============================================================
// --- AÇÃO: send (Enviar Novo Desafio) ---
// ==============================================================
if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // Filtra e valida os inputs POST.
    $target_id = filter_input(INPUT_POST, 'target_id', FILTER_VALIDATE_INT);
    $bet_xp = filter_input(INPUT_POST, 'bet_xp', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]); // Garante int e não negativo
    $bet_zeni = filter_input(INPUT_POST, 'bet_zeni', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]); // Garante int e não negativo

    // --- Validações de entrada ---
    if (!$target_id || $target_id <= 0) { // Verifica se é um ID inteiro válido e positivo
        $response = ['success' => false, 'error' => 'ID do alvo inválido.'];
    } elseif ($target_id == $current_user_id) { // Impede desafiar a si mesmo
         $response = ['success' => false, 'error' => 'Você não pode desafiar a si mesmo.'];
    } elseif ($bet_xp === false || $bet_xp === null || $bet_zeni === false || $bet_zeni === null) {
        // Verifica se a filtragem de aposta falhou ou retornou nulo (não era um número válido >= 0)
        $response = ['success' => false, 'error' => 'Valores de aposta inválidos (devem ser números inteiros não negativos).'];
    }
    // Adicionar mais validações aqui se necessário (ex: nível mínimo para PvP)
    else {
        // Define o tempo de expiração do desafio (ex: 5 minutos a partir de agora)
        $expires_at_timestamp = time() + (5 * 60); // 5 minutos
        $expires_at_sql = date('Y-m-d H:i:s', $expires_at_timestamp); // Formato para MySQL

        try {
            // Inicia uma transação de banco de dados.
            // Isso garante que todas as operações (verificaçao, validação, inserção) sejam atômicas.
            // Se qualquer passo falhar, tudo será desfeito (rollback).
            if (!$conn->beginTransaction()) {
                 // Embora com ERRMODE_EXCEPTION isso seja raro, é bom verificar se a transação começou
                throw new PDOException("Não foi possível iniciar a transação.");
            }

            // --- Validação CRÍTICA: O desafiante tem Zeni/XP suficiente para a aposta? ---
            // Bloqueamos a linha do desafiante para evitar problemas de concorrência se ele gastar os fundos
            // entre a verificação e a dedução (que acontece na ação 'respond').
            $sql_check_funds = "SELECT zeni, xp FROM usuarios WHERE id = :user_id FOR UPDATE"; // Lock a linha do desafiante
            $stmt_check_funds = $conn->prepare($sql_check_funds);
            $stmt_check_funds->execute([':user_id' => $current_user_id]);
            $challenger_funds = $stmt_check_funds->fetch(PDO::FETCH_ASSOC);

            if (!$challenger_funds) {
                 // Isso não deveria acontecer se o usuário está logado e seu ID é válido, mas é uma boa checagem de segurança.
                 if ($conn->inTransaction()) $conn->rollBack();
                 $response = ['success' => false, 'error' => 'Seus dados não foram encontrados. Tente logar novamente.'];
                 echo json_encode($response); exit;
            }

            if ($challenger_funds['xp'] < $bet_xp || $challenger_funds['zeni'] < $bet_zeni) {
                 if ($conn->inTransaction()) $conn->rollBack();
                 $response = ['success' => false, 'error' => 'Você não possui XP ou Zeni suficientes para esta aposta.'];
                 echo json_encode($response); exit;
            }
            // Nota: Os fundos NÃO SÃO DEDUZIDOS/BLOQUEADOS AQUI ao ENVIAR o desafio.
            // Isso só acontece QUANDO o desafio é ACEITO (ver ação 'respond').
            // A aposta é apenas registrada no desafio pendente.


            // --- Validação: Já existe um desafio pendente entre esses dois jogadores? ---
            // Verifica em ambas as direções (A desafiou B, ou B desafiou A)
            $sql_check_existing = "SELECT id FROM pvp_challenges
                                   WHERE status = 'pending'
                                   AND ((challenger_id = :user1 AND challenged_id = :user2) OR (challenger_id = :user2_alt AND challenged_id = :user1_alt))
                                   LIMIT 1";
            $stmt_check = $conn->prepare($sql_check_existing);
            $params_check = [
                ':user1' => $current_user_id,
                ':user2' => $target_id,
                ':user2_alt' => $target_id, // Repete para a segunda parte do OR
                ':user1_alt' => $current_user_id // Repete para a segunda parte do OR
            ];
            $stmt_check->execute($params_check);

            if ($stmt_check->fetch()) {
                // Se encontrou um desafio pendente, faz rollback e retorna erro.
                if ($conn->inTransaction()) $conn->rollBack();
                $response = ['success' => false, 'error' => 'Já existe um desafio pendente com este jogador.'];
                echo json_encode($response); exit;
            }

            // --- Validação: O alvo (challenged_id) existe? ---
             $sql_check_target = "SELECT id FROM usuarios WHERE id = :target_id LIMIT 1 FOR UPDATE"; // Lock a linha do alvo também
             $stmt_check_target = $conn->prepare($sql_check_target);
             $stmt_check_target->execute([':target_id' => $target_id]);
             if (!$stmt_check_target->fetch()) {
                  if ($conn->inTransaction()) $conn->rollBack();
                  $response = ['success' => false, 'error' => 'O jogador alvo não foi encontrado.'];
                  echo json_encode($response); exit;
             }


            // --- Inserir o novo desafio na tabela pvp_challenges ---
            $sql_insert = "INSERT INTO pvp_challenges
                           (challenger_id, challenged_id, bet_xp, bet_zeni, expires_at, status, created_at)
                           VALUES
                           (:challenger, :challenged, :bet_xp, :bet_zeni, :expires, 'pending', NOW())"; // NOW() pega a hora atual do DB
            $stmt_insert = $conn->prepare($sql_insert);
            $params_insert = [
                ':challenger' => $current_user_id,
                ':challenged' => $target_id,
                ':bet_xp'     => $bet_xp,
                ':bet_zeni'   => $bet_zeni,
                ':expires'    => $expires_at_sql
            ];

            // Executa a inserção. Com ERRMODE_EXCEPTION, isso lançará exceção se falhar.
            if (!$stmt_insert->execute($params_insert)) {
                 // Esta verificação explícita é um pouco redundante com ERRMODE_EXCEPTION,
                 // mas pode ser útil para debug. A exceção já seria lançada.
                 throw new PDOException("Execute falhou ao inserir o desafio.");
            }

            // Confirma a transação. Se falhar, lança exceção.
            if (!$conn->commit()) {
                 // Raríssimo falhar no commit se o execute foi bem sucedido e o DB está OK.
                 throw new PDOException("Falha ao confirmar o envio do desafio (commit).");
            }

            // Se chegou aqui, tudo ocorreu bem.
            $response = ['success' => true]; // Resposta de sucesso

        } catch (PDOException $e) {
            // Captura erros específicos do PDO
            if ($conn->inTransaction()) {
                $conn->rollBack(); // Desfaz a transação em caso de erro
            }
            // Loga o erro detalhado no log do servidor
            error_log("Erro PDO ao enviar desafio de {$current_user_id} para {$target_id}: " . $e->getMessage() . " | SQLState: " . $e->getCode() . " | Trace: " . $e->getTraceAsString());
            // Resposta de error genérica para o cliente
            $response = ['success' => false, 'error' => 'Erro interno do servidor (DB) ao enviar desafio.'];

        } catch (RuntimeException | Exception $e) {
            // Captura outros erros inesperados (além de PDO)
            if (isset($conn) && $conn->inTransaction()) { // Verifica se a conexão existe antes de tentar rollback
                $conn->rollBack(); // Desfaz a transação
            }
            // Loga o error geral no log do servidor
            error_log("Erro GERAL ao enviar desafio de {$current_user_id} para {$target_id}: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            // Resposta de error genérica para o cliente
            $response = ['success' => false, 'error' => 'Erro inesperado no servidor ao enviar desafio.'];
        }
    }
}


// ==============================================================
// --- AÇÃO: check (Verificar Desafios Pendentes Recebidos) ---
// ==============================================================
elseif ($action === 'check') {

    try {
        // Não é estritamente necessária uma transação aqui, pois é apenas leitura/expiração.

        // Primeiro, expira desafios antigos pendentes para o usuário logado (ou todos para manter a base limpa)
        // Expira apenas os que desafiam o usuário atual E que estão pendentes
        $sql_expire = "UPDATE pvp_challenges
                       SET status = 'expired'
                       WHERE challenged_id = :current_user_id
                       AND status = 'pending'
                       AND expires_at IS NOT NULL
                       AND expires_at < NOW()";
        $stmt_expire = $conn->prepare($sql_expire);
        $stmt_expire->execute([':current_user_id' => $current_user_id]);
        // $conn->exec() também funcionaria se não precisasse de parâmetro WHERE, mas prepare/execute é mais consistente.


        // Busca os desafios pendentes RECEBIDOS pelo usuário logado
        $sql_challenges = "SELECT pc.id AS challenge_id, pc.challenger_id, u.nome AS challenger_name,
                                  pc.bet_xp, pc.bet_zeni, pc.created_at
                           FROM pvp_challenges pc
                           JOIN usuarios u ON pc.challenger_id = u.id
                           WHERE pc.challenged_id = :current_user_id AND pc.status = 'pending'
                           ORDER BY pc.created_at DESC"; // Mostra os mais recentes primeiro

        $stmt_challenges = $conn->prepare($sql_challenges);
        // Executa a query com o ID do usuário logado como desafiado
        $stmt_challenges->execute([':current_user_id' => $current_user_id]);
        // Busca todas as linhas de resultados como um array associativo
        $challenges = $stmt_challenges->fetchAll(PDO::FETCH_ASSOC);

        // --- Formata os dados para a resposta JSON ---
        // Percorre os resultados para adicionar dados formatados
        foreach ($challenges as $key => $challenge) {
             // Formata o nome do desafiante (usando a função externa ou fallback seguro)
             // Assumimos que formatarNomeUsuarioComTag recebe $conn e o ID do usuário.
             if (function_exists('formatarNomeUsuarioComTag')) {
                  // A função DEVE retornar o nome JÁ SANITIZADO com htmlspecialchars
                 $challenges[$key]['challenger_name_formatted'] = formatarNomeUsuarioComTag($conn, $challenge['challenger_id']);
             } else {
                  // Fallback: Sanitiza o nome vindo do BD
                 $challenges[$key]['challenger_name_formatted'] = htmlspecialchars($challenge['challenger_name'] ?? 'Desconhecido');
             }

             // Formata os valores de aposta com separadores de milhar (formato brasileiro)
             // Usa ?? 0 para garantir que number_format receba um número
             $challenges[$key]['bet_xp_formatted'] = number_format($challenge['bet_xp'] ?? 0, 0, ',', '.');
             $challenges[$key]['bet_zeni_formatted'] = number_format($challenge['bet_zeni'] ?? 0, 0, ',', '.');
             // Você pode adicionar outros campos formatados se necessário
        }

        // Prepara a resposta de sucesso com a lista de desafios
        $response = ['success' => true, 'challenges' => $challenges];

    } catch (PDOException $e) {
        // Captura erros específicos do PDO
        error_log("Erro PDO ao verificar desafios para {$current_user_id} em " . basename(__FILE__) . ": " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        // Resposta de error genérica para o cliente
        $response = ['success' => false, 'error' => 'Erro interno do servidor (DB) ao buscar desafios.'];

    } catch (RuntimeException | Exception $e) {
         // Captura outros erros inesperados
        error_log("Erro GERAL ao verificar desafios para {$current_user_id} em " . basename(__FILE__) . ": " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
        // Resposta de error genérica para o cliente
        $response = ['success' => false, 'error' => 'Erro inesperado no servidor ao buscar desafios.'];
    }
}


// ==============================================================
// --- AÇÃO: respond (Responder a um Desafio: Aceitar ou Recusar) ---
// ==============================================================
elseif ($action === 'respond' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    // Filtra e valida os inputs POST
    $challenge_id = filter_input(INPUT_POST, 'challenge_id', FILTER_VALIDATE_INT);
    $response_action = $_POST['response_action'] ?? ''; // Pega a ação (string)

    // --- Validações de entrada ---
    if (!$challenge_id || $challenge_id <= 0) {
        $response = ['success' => false, 'error' => 'ID do desafio inválido.'];
    } elseif (!in_array($response_action, ['accept', 'decline'])) { // Garante que a ação é 'accept' ou 'decline'
        $response = ['success' => false, 'error' => 'Ação de resposta inválida.'];
    }
    // Adicionar mais validações se necessário (ex: verificar se o usuário tem nível para aceitar)
     else {
        try {
            // Inicia uma transação. ESSENCIAL aqui para garantir que a atualização do status,
            // a dedução de fundos E a criação da batalha (se for aceitar) ocorram juntas ou nada ocorra.
            if (!$conn->beginTransaction()) {
                 throw new PDOException("Não foi possível iniciar a transação.");
            }

            // --- Validação e Bloqueio: Buscar o desafio pendente para o usuário logado ---
            // Usamos FOR UPDATE para bloquear esta linha e evitar problemas de concorrência
            $sql_get = "SELECT id, challenger_id, challenged_id, bet_xp, bet_zeni
                        FROM pvp_challenges
                        WHERE id = :challenge_id
                        AND challenged_id = :current_user_id
                        AND status = 'pending'
                        FOR UPDATE"; // Bloqueia a linha
            $stmt_get = $conn->prepare($sql_get);
            $stmt_get->execute([
                ':challenge_id' => $challenge_id,
                ':current_user_id' => $current_user_id // Garante que o usuário logado é o desafiado
            ]);
            $challenge_data = $stmt_get->fetch(PDO::FETCH_ASSOC); // Busca a linha

            // Se o desafio não foi encontrado com essas condições (ID errado, não é para ele, não está pendente)
            if (!$challenge_data) {
                if (isset($conn) && $conn->inTransaction()) $conn->rollBack(); // Desfaz o início da transação se estiver ativa
                $response = ['success' => false, 'error' => 'Desafio inválido, já respondido ou não encontrado.'];
                echo json_encode($response); exit; // Interrompe
            }

            $new_status = ($response_action === 'accept') ? 'accepted' : 'declined'; // Define o novo status

            $redirect_url = null; // Inicializa URL de redirecionamento

            // --- Lógica para ACEITAR o desafio ---
            if ($new_status === 'accepted') {

                // --- Validação CRÍTICA: Ambos jogadores têm a aposta necessária NO MOMENTO da aceitação? ---
                // Bloqueia as linhas dos usuários para verificar e deduzir fundos
                $sql_get_users_funds = "SELECT id, xp, zeni FROM usuarios WHERE id IN (:challenger_id, :challenged_id) FOR UPDATE";
                $stmt_get_users_funds = $conn->prepare($sql_get_users_funds);
                $stmt_get_users_funds->execute([
                     ':challenger_id' => $challenge_data['challenger_id'],
                     ':challenged_id' => $challenge_data['challenged_id']
                ]);
                $users_funds = $stmt_get_users_funds->fetchAll(PDO::FETCH_ASSOC);

                $challenger_funds = null;
                $challenged_funds = null;
                foreach ($users_funds as $user_f) {
                     if ($user_f['id'] == $challenge_data['challenger_id']) $challenger_funds = $user_f;
                     if ($user_f['id'] == $challenge_data['challenged_id']) $challenged_funds = $user_f;
                }

                // Verifica se os dados de ambos os usuários foram encontrados (não deveria falhar se existirem na tabela de desafios)
                if (!$challenger_funds || !$challenged_funds) {
                     if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
                     $response = ['success' => false, 'error' => 'Dados dos jogadores não encontrados.'];
                     echo json_encode($response); exit;
                }

                // Verifica se ambos têm fundos suficientes (a aposta foi definida na criação, mas eles podem ter gasto desde então)
                if ($challenger_funds['xp'] < $challenge_data['bet_xp'] || $challenger_funds['zeni'] < $challenge_data['bet_zeni']) {
                     if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
                     $response = ['success' => false, 'error' => 'O desafiante não possui mais a aposta necessária.'];
                     echo json_encode($response); exit;
                }
                 if ($challenged_funds['xp'] < $challenge_data['bet_xp'] || $challenged_funds['zeni'] < $challenge_data['bet_zeni']) {
                     if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
                     $response = ['success' => false, 'error' => 'Você não possui XP ou Zeni suficientes para cobrir a aposta.'];
                     echo json_encode($response); exit;
                }

                // --- CRÍTICO: DEDUZIR AS APOSTAS ---
                // Deduzimos as apostas de XP e Zeni de AMBOS os jogadores.
                // Esta dedução ocorre DENTRO DA TRANSAÇÃO.
                // !!! AJUSTE ESTA LÓGICA DE DEDUÇÃO CONFORME AS REGRAS DO SEU JOGO !!!
                // Se a aposta deve ser deduzida apenas do perdedor, remova ou ajuste este passo.
                $sql_deduct_bets = "UPDATE usuarios SET xp = xp - :bet_xp, zeni = zeni - :bet_zeni WHERE id = :user_id";
                $stmt_deduct_challenger = $conn->prepare($sql_deduct_bets);
                $stmt_deduct_challenged = $conn->prepare($sql_deduct_bets);

                // Deduz do desafiante
                if (!$stmt_deduct_challenger->execute([':bet_xp' => $challenge_data['bet_xp'], ':bet_zeni' => $challenge_data['bet_zeni'], ':user_id' => $challenge_data['challenger_id']])) {
                     throw new PDOException("Falha ao deduzir aposta do desafiante.");
                }
                 // Deduz do desafiado (você, o usuário logado)
                 if (!$stmt_deduct_challenged->execute([':bet_xp' => $challenge_data['bet_xp'], ':bet_zeni' => $challenge_data['bet_zeni'], ':user_id' => $challenge_data['challenged_id']])) {
                     throw new PDOException("Falha ao deduzir aposta do desafiado.");
                }


                // --- CRÍTICO: CRIAR A ENTRADA DA BATALHA ATIVA ---
                // Você precisa ter uma tabela para batalhas ativas (ex: batalhas_ativas)
                // Esta tabela deve guardar informações sobre a batalha, o estado atual, HP/KI dos jogadores na batalha, etc.
                // A lógica de inicialização da batalha (pegar stats atuais dos jogadores, definir HP/KI inicial)
                // DEVE ACONTECER AQUI DENTRO DESTA TRANSAÇÃO.
                // O challenge_id aceito pode ser usado para ligar a batalha ativa ao desafio original.
                // Exemplo (assumindo uma tabela 'batalhas_ativas' com campos básicos):
                /*
                $sql_create_battle = "INSERT INTO batalhas_ativas
                                      (challenge_id, player1_id, player2_id, player1_hp_atual, player2_hp_atual, player1_ki_atual, player2_ki_atual, current_turn, status, created_at)
                                      -- Busca os stats ATUAIS dos jogadores (após a dedução da aposta, se aplicável)
                                      SELECT
                                          :challenge_id_insert, u1.id, u2.id, u1.hp, u2.hp, u1.ki, u2.ki, u1.id as starting_player, 'active', NOW()
                                      FROM usuarios u1, usuarios u2
                                      WHERE u1.id = :challenger_id_insert AND u2.id = :challenged_id_insert"; // Adapte os campos e lógica de quem começa

                $stmt_create_battle = $conn->prepare($sql_create_battle);
                if (!$stmt_create_battle->execute([
                     ':challenge_id_insert' => $challenge_id,
                     ':challenger_id_insert' => $challenge_data['challenger_id'],
                     ':challenged_id_insert' => $challenge_data['challenged_id']
                     // ... outros parâmetros baseados nos stats de u1 e u2 no momento
                ])) {
                     throw new PDOException("Falha ao criar entrada da batalha ativa.");
                }
                 // Se precisar do ID da batalha recém-criada:
                 // $battle_id = $conn->lastInsertId();
                */
                // !!! IMPLEMENTE SUA LÓGICA DE CRIAÇÃO DE BATALHA AQUI DENTRO DA TRANSAÇÃO !!!


                // Define a URL de redirecionamento para a página da arena
                // Passamos o challenge_id para a página arena.php carregar os dados
                // Assumimos que arena.php também está na raiz.
                $redirect_url = "arena.php?challenge_id=" . $challenge_id; // Caminho ajustado (assumindo arena.php na raiz)

            }
            // --- Fim da lógica para ACEITAR ---


            // --- Atualizar o status do desafio na tabela pvp_challenges ---
            // Esta query é executada tanto para 'accepted' quanto para 'declined'
            $sql_update = "UPDATE pvp_challenges SET status = :new_status WHERE id = :challenge_id";
            $stmt_update = $conn->prepare($sql_update);

            // Executa a atualização do status. Com ERRMODE_EXCEPTION, isso lançará exceção se falhar.
            if (!$stmt_update->execute([':new_status' => $new_status, ':challenge_id' => $challenge_id])) {
                 // Se falhar, lança exceção que será capturada.
                throw new PDOException("Execute falhou ao atualizar o status do desafio.");
            }

            // Confirma a transação. Se qualquer passo anterior falhou (validacao, deducao, criação batalha), o commit não acontecerá.
            if (isset($conn) && $conn->inTransaction()) { // Verifica se a conexão existe e se há transação ativa
                 if (!$conn->commit()) {
                     // Raríssimo falhar no commit se o execute foi bem sucedido.
                    throw new PDOException("Falha ao confirmar a resposta do desafio (commit).");
                 }
            }


            // Se chegou aqui, tudo ocorreu bem.
            // Prepara a resposta de sucesso, incluindo a URL de redirecionamento se foi aceito.
            $response = ['success' => true, 'redirect_url' => $redirect_url];

        } catch (PDOException $e) {
            // Captura erros específicos do PDO durante a resposta
            if (isset($conn) && $conn->inTransaction()) { // Verifica se a conexão existe e se há transação ativa
                $conn->rollBack(); // Desfaz a transação
            }
            // Loga o error detalhado
            error_log("Erro PDO ao responder desafio {$challenge_id} ('{$response_action}') por {$current_user_id} em " . basename(__FILE__) . ": " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            // Resposta de error genérica para o cliente
            $response = ['success' => false, 'error' => 'Erro interno do servidor (DB) ao processar resposta.'];

        } catch (RuntimeException | Exception $e) {
             // Captura outros erros inesperados
            if (isset($conn) && $conn->inTransaction()) { // Verifica se a conexão existe e se há transação ativa
                $conn->rollBack(); // Desfaz a transação
            }
            // Loga o error geral
            error_log("Erro GERAL ao responder desafio {$challenge_id} ('{$response_action}') por {$current_user_id} em " . basename(__FILE__) . ": " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            // Resposta de error genérica para o cliente
            $response = ['success' => false, 'error' => 'Erro inesperado no servidor ao processar resposta.'];
        }
    }
}

// ==============================================================
// --- FIM DAS AÇÕES ---
// ==============================================================

// Se chegou aqui sem uma ação válida ou ocorreu um error tratado, a resposta
// já foi definida na variável $response.

// Envia a resposta final como JSON
echo json_encode($response);
exit; // Termina a execução